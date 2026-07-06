<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

ob_start();

function locked_period_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }

    echo 'PASS: ' . $message . PHP_EOL;
}

function locked_period_test_services(): PageServiceFramework
{
    $path = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp';
    if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create test upload directory.');
    }

    return new PageServiceFramework(
        new AppService($path),
        new SiteContextCoordinatorFramework(new \eel_accounts\Service\AccountingContextService(), true)
    );
}

function locked_period_snapshot_field(array $snapshot, string $key): float
{
    foreach ((array)($snapshot['fields'] ?? []) as $field) {
        if ((string)($field['key'] ?? '') === $key) {
            return round((float)($field['value'] ?? 0), 2);
        }
    }

    throw new RuntimeException('Snapshot field not found: ' . $key);
}

$companyId = 49;
$accountingPeriodId = 79;
$periodStart = '2022-09-05';
$periodEnd = '2023-09-30';

$lock = new \eel_accounts\Service\YearEndLockService();
locked_period_assert($lock->isLocked($companyId, $accountingPeriodId), 'fixture period is locked.');

$uploadResult = (new UploadsAction())->handle(
    new RequestFramework([], [
        'intent' => 'upload_account_csv',
        'company_id' => (string)$companyId,
        'accounting_period_id' => (string)$accountingPeriodId,
    ], ['REQUEST_METHOD' => 'POST'], [], []),
    locked_period_test_services()
);
locked_period_assert(!$uploadResult->isSuccess(), 'locked upload action is rejected.');

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
locked_period_assert(!str_contains($approvalHtml, 'Revoke approval'), 'locked approval renderer omits revoke controls.');

$notesHtml = (new _year_end_notesCard())->render([
    'company' => ['id' => $companyId, 'accounting_period_id' => $accountingPeriodId],
    'year_end' => [
        'checklist' => [
            'accounting_period' => ['id' => $accountingPeriodId],
            'review' => ['is_locked' => 1, 'review_notes' => 'Locked note'],
        ],
    ],
]);
locked_period_assert(str_contains($notesHtml, 'readonly'), 'locked Year End notes render read only.');
locked_period_assert(!str_contains($notesHtml, 'Save Notes') && !str_contains($notesHtml, 'Save notes'), 'locked Year End notes omit save button.');

$profitLoss = new \eel_accounts\Service\ProfitLossService();
$summary = $profitLoss->getProfitLossSummary($companyId, $accountingPeriodId);
$expectedProfit = (float)\InterfaceDB::fetchColumn(
    'SELECT ROUND(COALESCE(SUM(
        CASE
            WHEN na.account_type = :income_type THEN COALESCE(jl.credit, 0) - COALESCE(jl.debit, 0)
            WHEN na.account_type IN (:cost_type, :expense_type) THEN 0 - (COALESCE(jl.debit, 0) - COALESCE(jl.credit, 0))
            ELSE 0
        END
    ), 0), 2)
     FROM journals j
     INNER JOIN journal_lines jl ON jl.journal_id = j.id
     INNER JOIN nominal_accounts na ON na.id = jl.nominal_account_id
     LEFT JOIN journal_entry_metadata jem_close
       ON jem_close.journal_id = j.id
      AND jem_close.journal_tag = :close_journal_tag
     WHERE j.company_id = :company_id
       AND j.accounting_period_id = :accounting_period_id
       AND j.is_posted = 1
       AND j.journal_date BETWEEN :period_start AND :period_end
       AND jem_close.id IS NULL
       AND na.account_type IN (:income_type, :cost_type, :expense_type)',
    [
        'income_type' => 'income',
        'cost_type' => 'cost_of_sales',
        'expense_type' => 'expense',
        'close_journal_tag' => \eel_accounts\Service\RetainedEarningsCloseService::JOURNAL_TAG,
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
    ]
);
locked_period_assert(abs((float)($summary['net_profit'] ?? 0) - $expectedProfit) < 0.005, 'P&L summary excludes retained earnings close journals.');

$sourceCoverage = $profitLoss->getSourceCoverage($companyId, $accountingPeriodId);
locked_period_assert(!empty($sourceCoverage['director_loan_offset']['present']), 'source coverage includes director loan offset journals.');

$snapshot = (new \eel_accounts\Service\CompaniesHouseSnapshotService())->fetchSnapshot($companyId, $accountingPeriodId);
locked_period_assert(!empty($snapshot['available']), 'Companies House snapshot is available for the locked period.');
locked_period_assert(abs(locked_period_snapshot_field($snapshot, 'creditors_within_one_year') - 1035.63) < 0.005, 'Companies House creditors are not reduced by debit expense payable balances.');
locked_period_assert(abs(locked_period_snapshot_field($snapshot, 'current_assets') - 1186.54) < 0.005, 'Companies House current assets include debit expense payable balances.');

$validationHtml = (new _trial_balance_validationCard())->render([
    'company' => ['settings' => []],
    'services' => [
        'trialBalanceValidation' => [
            'available' => true,
            'checks' => [[
                'title' => 'Zero metric check',
                'status' => 'pass',
                'detail' => 'Only zero details.',
                'metric_value' => ['empty_value' => 0, 'nested' => ['also_empty' => 0]],
            ]],
        ],
    ],
]);
locked_period_assert(!str_contains($validationHtml, 'Empty Value') && !str_contains($validationHtml, 'Also Empty'), 'trial balance validation suppresses zero-only metric rows.');

$lossesHtml = (new _trial_balance_lossesCard())->render([
    'company' => ['settings' => []],
    'services' => [
        'trialBalancePageData' => [
            'available' => true,
            'summary' => [
                'tax_computation' => [
                    'available' => true,
                    'estimated_corporation_tax' => 0,
                    'taxable_profit' => 0,
                    'loss_created_in_period' => 0,
                    'losses_brought_forward' => 0,
                    'losses_used' => 0,
                    'losses_carried_forward' => 0,
                    'periods' => [],
                    'steps' => [],
                ],
            ],
        ],
    ],
]);
locked_period_assert(!str_contains($lossesHtml, 'Loss created'), 'trial balance losses suppresses zero-only loss cards.');

ob_end_flush();
