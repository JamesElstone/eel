<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'PageServiceTestFactory.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GoldenAccountsFixture.php';

$manifest = GoldenAccountsFixture::build();
$companyId = (int)($manifest['companies']['golden'] ?? 0);
$accountingPeriodId = 9111;
$period = (array)($manifest['periods'][(string)$accountingPeriodId] ?? []);
$periodStart = (string)($period['period_start'] ?? '');
$periodEnd = (string)($period['period_end'] ?? '');
$harness = new GeneratedServiceClassTestHarness();

$harness->check('LockedPeriodReporting', 'deterministic fixture is locked and mutation actions are rejected', static function () use ($harness, $companyId, $accountingPeriodId, $periodEnd): void {
    InterfaceDB::beginTransaction();
    try {
        lockedPeriodReportingLockFixture($companyId, $accountingPeriodId);
        $lock = new \eel_accounts\Service\YearEndLockService();
        $harness->assertTrue($lock->isLocked($companyId, $accountingPeriodId));

        $uploadResult = (new UploadsAction())->handle(
            new RequestFramework([], [
                'intent' => 'upload_account_csv',
                'company_id' => (string)$companyId,
                'accounting_period_id' => (string)$accountingPeriodId,
            ], ['REQUEST_METHOD' => 'POST'], [], []),
            createTestPageServiceFramework()
        );
        $harness->assertFalse($uploadResult->isSuccess());

        $expenseResult = (new ExpenseAction())->handle(
            new RequestFramework([], [
                'card_action' => 'Expense',
                'intent' => 'create_claim',
                'company_id' => (string)$companyId,
                'accounting_period_id' => (string)$accountingPeriodId,
                'claimant_id' => '9130',
                'claim_year' => substr($periodEnd, 0, 4),
                'claim_month' => substr($periodEnd, 5, 2),
            ], ['REQUEST_METHOD' => 'POST'], [], []),
            createTestPageServiceFramework()
        );
        $harness->assertFalse($expenseResult->isSuccess());
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
});

$harness->check('LockedPeriodReporting', 'locked workflow cards render read-only controls from deterministic context', static function () use ($harness, $companyId, $accountingPeriodId, $periodStart, $periodEnd): void {
    InterfaceDB::beginTransaction();
    try {
        lockedPeriodReportingLockFixture($companyId, $accountingPeriodId);

        $approvalHtml = \eel_accounts\Renderer\YearEndApprovalRenderer::render([
            'subject' => 'test approval',
            'companyId' => $companyId,
            'accountingPeriodId' => $accountingPeriodId,
            'acknowledged' => true,
            'acknowledgedAt' => '2026-07-06 10:00:00',
            'acknowledgedBy' => 'test',
            'intent' => 'acknowledge_review_check',
            'revokeIntent' => 'reopen_review_check',
            'approveFields' => ['check_code' => 'test'],
            'revokeFields' => ['check_code' => 'test'],
        ]);
        $harness->assertFalse(str_contains($approvalHtml, 'Revoke approval'));

        $notesHtml = (new _year_end_notesCard())->render([
            'company' => ['id' => $companyId, 'accounting_period_id' => $accountingPeriodId],
            'year_end' => [
                'checklist' => [
                    'accounting_period' => ['id' => $accountingPeriodId],
                    'review' => ['is_locked' => 1, 'review_notes' => 'Deterministic locked note'],
                ],
            ],
        ]);
        $harness->assertTrue(str_contains($notesHtml, 'readonly'));
        $harness->assertFalse(str_contains($notesHtml, 'Save Notes') || str_contains($notesHtml, 'Save notes'));

        $expenseCreateHtml = (new _expense_claim_createCard())->render([
            'company' => ['id' => $companyId, 'accounting_period_id' => $accountingPeriodId, 'settings' => []],
            'accounting_period' => [
                'id' => $accountingPeriodId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ],
            'services' => [
                'expensesPageData' => [
                    'claimants' => [[
                        'id' => 9130,
                        'claimant_name' => 'Synthetic Claimant',
                        'is_active' => 1,
                    ]],
                    'active_claimant_count' => 1,
                ],
            ],
        ]);
        $harness->assertTrue(str_contains($expenseCreateHtml, 'Period locked. Expense claims can be reviewed but not created or changed.'));
        $harness->assertTrue(str_contains($expenseCreateHtml, 'disabled>Create Expense Claim</button>'));
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
});

$harness->check('LockedPeriodReporting', 'locked periods remain available to deterministic financial reports', static function () use ($harness, $companyId, $accountingPeriodId): void {
    InterfaceDB::beginTransaction();
    try {
        lockedPeriodReportingLockFixture($companyId, $accountingPeriodId);

        $profitLoss = (new \eel_accounts\Service\ProfitLossService())
            ->getProfitLossSummary($companyId, $accountingPeriodId);
        $harness->assertTrue(array_key_exists('net_profit', $profitLoss));
        $harness->assertTrue(is_numeric($profitLoss['net_profit'] ?? null));

        $snapshot = (new \eel_accounts\Service\CompaniesHouseSnapshotService())
            ->fetchSnapshot($companyId, $accountingPeriodId);
        $harness->assertTrue(!empty($snapshot['available']));
        $harness->assertTrue((array)($snapshot['fields'] ?? []) !== []);

        $validation = (new \eel_accounts\Service\TrialBalanceValidationService())
            ->fetchValidation($companyId, $accountingPeriodId);
        $harness->assertTrue(!empty($validation['available']));
        $harness->assertTrue(is_array($validation['checks'] ?? null));
        $harness->assertTrue((new \eel_accounts\Service\YearEndLockService())->isLocked($companyId, $accountingPeriodId));
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
});

function lockedPeriodReportingLockFixture(int $companyId, int $accountingPeriodId): void
{
    $existingId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM year_end_reviews
         WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id
         LIMIT 1',
        ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
    );
    $now = '2026-07-15 12:00:00';

    if ($existingId > 0) {
        InterfaceDB::prepareExecute(
            'UPDATE year_end_reviews
             SET is_locked = 1, locked_at = :locked_at, locked_by = :locked_by, updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $existingId,
                'locked_at' => $now,
                'locked_by' => 'locked_period_fixture',
                'updated_at' => $now,
            ]
        );
        return;
    }

    InterfaceDB::prepareExecute(
        'INSERT INTO year_end_reviews (
            company_id, accounting_period_id, is_locked,
            locked_at, locked_by, review_notes, created_at, updated_at
         ) VALUES (
            :company_id, :accounting_period_id, 1,
            :locked_at, :locked_by, :review_notes, :created_at, :updated_at
         )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'locked_at' => $now,
            'locked_by' => 'locked_period_fixture',
            'review_notes' => 'Deterministic locked-period reporting fixture.',
            'created_at' => $now,
            'updated_at' => $now,
        ]
    );
}
