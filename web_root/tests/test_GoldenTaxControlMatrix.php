<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GoldenAccountsFixture.php';

$harness = new GeneratedServiceClassTestHarness();
GoldenAccountsFixture::build();

$harness->check('GoldenTaxControlMatrix', 'distinguishes uncategorised work from categorised but unposted transactions', static function () use ($harness): void {
    InterfaceDB::beginTransaction();
    try {
        $before = (new \eel_accounts\Service\YearEndChecklistService())
            ->fetchChecklist(GoldenAccountsFixture::WARNING_COMPANY_ID, 9311);
        $uncategorisedBefore = goldenTaxControlFindCheck($before, 'uncategorised_transactions');
        $postedBefore = goldenTaxControlFindCheck($before, 'posted_transactions_integrity');
        $harness->assertSame('fail', (string)($uncategorisedBefore['status'] ?? ''));
        $harness->assertSame('1', (string)($uncategorisedBefore['metric_value'] ?? ''));
        $harness->assertSame('pass', (string)($postedBefore['status'] ?? ''));

        InterfaceDB::prepareExecute(
            'UPDATE transactions
             SET category_status = :category_status,
                 nominal_account_id = :nominal_account_id
             WHERE id = :id AND company_id = :company_id',
            [
                'category_status' => 'manual',
                'nominal_account_id' => 91004,
                'id' => 9360,
                'company_id' => GoldenAccountsFixture::WARNING_COMPANY_ID,
            ]
        );

        $after = (new \eel_accounts\Service\YearEndChecklistService())
            ->fetchChecklist(GoldenAccountsFixture::WARNING_COMPANY_ID, 9311);
        $uncategorisedAfter = goldenTaxControlFindCheck($after, 'uncategorised_transactions');
        $postedAfter = goldenTaxControlFindCheck($after, 'posted_transactions_integrity');
        $lockAfter = goldenTaxControlFindCheck($after, 'lock_readiness_checklist');
        $harness->assertSame('pass', (string)($uncategorisedAfter['status'] ?? ''));
        $harness->assertSame('fail', (string)($postedAfter['status'] ?? ''));
        $harness->assertSame('1 transaction(s)', (string)($postedAfter['metric_value'] ?? ''));
        $harness->assertSame('fail', (string)($lockAfter['status'] ?? ''));
        $harness->assertSame('Not ready', (string)($lockAfter['metric_value'] ?? ''));
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
});

$harness->check('GoldenTaxControlMatrix', 'raises s455 and lock-readiness review when the director owes the company', static function () use ($harness): void {
    InterfaceDB::beginTransaction();
    try {
        goldenTaxControlPostJournal(
            9114,
            's455_receivable',
            '2026-07-01',
            'GOLDEN-TEST director loan receivable control',
            91006,
            91001,
            2000.00
        );

        $review = (new \eel_accounts\Service\DirectorLoanService())
            ->fetchTaxReviewSummary(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9114);
        $harness->assertTrue(!empty($review['available']));
        $harness->assertTrue(!empty($review['review_required']));
        $harness->assertSame('800.00', goldenTaxControlMoney($review['exposure_amount'] ?? 0));
        $harness->assertSame(
            GoldenAccountsFixture::GOLDEN_DIRECTOR_ID,
            (int)($review['director_flags'][0]['director_id'] ?? 0)
        );

        $checklist = (new \eel_accounts\Service\YearEndChecklistService())
            ->fetchChecklist(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9114);
        $taxReview = goldenTaxControlFindCheck($checklist, 'director_loan_year_end_review');
        $lock = goldenTaxControlFindCheck($checklist, 'lock_readiness_checklist');
        $harness->assertSame('warning', (string)($taxReview['status'] ?? ''));
        $harness->assertTrue(str_contains(strtolower((string)($taxReview['detail_text'] ?? '')), 's455'));
        $harness->assertSame('fail', (string)($lock['status'] ?? ''));
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
});

$harness->check('GoldenTaxControlMatrix', 'posts CT provision deltas, reversals, and an idempotent no-op', static function () use ($harness): void {
    InterfaceDB::beginTransaction();
    try {
        $companyId = GoldenAccountsFixture::GOLDEN_COMPANY_ID;
        $accountingPeriodId = 9114;
        $computation = new \eel_accounts\Service\CorporationTaxComputationService();
        $periods = (array)($computation->activeCtPeriodsForAccountingPeriod($companyId, $accountingPeriodId)['periods'] ?? []);
        $harness->assertCount(1, $periods);
        $ctPeriodId = (int)($periods[0]['id'] ?? 0);
        $provision = new \eel_accounts\Service\CorporationTaxProvisionService();

        $initial = $provision->fetchPosition($companyId, $accountingPeriodId, $ctPeriodId);
        $harness->assertSame('1356.03', goldenTaxControlMoney($initial['estimated_corporation_tax'] ?? 0));
        $harness->assertSame('1356.03', goldenTaxControlMoney($initial['unposted_corporation_tax_adjustment'] ?? 0));
        $harness->assertSame('not_posted', (string)($initial['status'] ?? ''));

        goldenTaxControlRequireSuccess($provision->postProvision($companyId, $accountingPeriodId, $ctPeriodId, 'golden_tax_control'));
        $journalCount = goldenTaxControlProvisionJournalCount($companyId, $accountingPeriodId);
        $harness->assertSame(1, $journalCount);

        $repeat = $provision->postProvision($companyId, $accountingPeriodId, $ctPeriodId, 'golden_tax_control');
        goldenTaxControlRequireSuccess($repeat);
        $harness->assertTrue(!empty($repeat['skipped']));
        $harness->assertSame($journalCount, goldenTaxControlProvisionJournalCount($companyId, $accountingPeriodId));

        goldenTaxControlPostJournal(
            $accountingPeriodId,
            'provision_extra_sale',
            '2026-07-02',
            'GOLDEN-TEST CT delta sale',
            91001,
            91002,
            1000.00
        );
        $increase = $provision->fetchPosition($companyId, $accountingPeriodId, $ctPeriodId);
        $harness->assertSame('1546.03', goldenTaxControlMoney($increase['estimated_corporation_tax'] ?? 0));
        $harness->assertSame('190.00', goldenTaxControlMoney($increase['unposted_corporation_tax_adjustment'] ?? 0));
        goldenTaxControlRequireSuccess($provision->postProvision($companyId, $accountingPeriodId, $ctPeriodId, 'golden_tax_control'));

        goldenTaxControlPostJournal(
            $accountingPeriodId,
            'provision_expense_reduction',
            '2026-07-03',
            'GOLDEN-TEST CT partial reversal expense',
            91004,
            91001,
            500.00
        );
        $partialReversal = $provision->fetchPosition($companyId, $accountingPeriodId, $ctPeriodId);
        $harness->assertSame('1451.03', goldenTaxControlMoney($partialReversal['estimated_corporation_tax'] ?? 0));
        $harness->assertSame('-95.00', goldenTaxControlMoney($partialReversal['unposted_corporation_tax_adjustment'] ?? 0));
        goldenTaxControlRequireSuccess($provision->postProvision($companyId, $accountingPeriodId, $ctPeriodId, 'golden_tax_control'));

        goldenTaxControlPostJournal(
            $accountingPeriodId,
            'provision_full_reversal',
            '2026-07-04',
            'GOLDEN-TEST CT full reversal expense',
            91004,
            91001,
            7637.00
        );
        $fullReversal = $provision->fetchPosition($companyId, $accountingPeriodId, $ctPeriodId);
        $harness->assertSame('0.00', goldenTaxControlMoney($fullReversal['estimated_corporation_tax'] ?? 0));
        $harness->assertSame('-1451.03', goldenTaxControlMoney($fullReversal['unposted_corporation_tax_adjustment'] ?? 0));
        goldenTaxControlRequireSuccess($provision->postProvision($companyId, $accountingPeriodId, $ctPeriodId, 'golden_tax_control'));

        $final = $provision->fetchPosition($companyId, $accountingPeriodId, $ctPeriodId);
        $harness->assertSame('0.00', goldenTaxControlMoney($final['posted_corporation_tax_charge'] ?? 0));
        $harness->assertSame('0.00', goldenTaxControlMoney($final['unposted_corporation_tax_adjustment'] ?? 0));
        $harness->assertSame('not_required', (string)($final['status'] ?? ''));

        $rows = InterfaceDB::fetchAll(
            'SELECT jl.debit - jl.credit AS charge
             FROM journal_entry_metadata jem
             INNER JOIN journals j ON j.id = jem.journal_id
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE jem.company_id = :company_id
               AND jem.accounting_period_id = :accounting_period_id
               AND jem.journal_tag = :journal_tag
               AND jl.nominal_account_id = :nominal_account_id
               AND j.is_posted = 1
             ORDER BY j.id ASC',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'journal_tag' => \eel_accounts\Service\CorporationTaxProvisionService::JOURNAL_TAG,
                'nominal_account_id' => 91008,
            ]
        );
        $charges = array_map(static fn(array $row): string => goldenTaxControlMoney($row['charge'] ?? 0), $rows);
        $harness->assertSame(['1356.03', '190.00', '-95.00', '-1451.03'], $charges);
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
});

$harness->check('GoldenTaxControlMatrix', 'persists the exact loss checkpoint used by the Year End lock route', static function () use ($harness): void {
    InterfaceDB::beginTransaction();
    try {
        $companyId = GoldenAccountsFixture::GOLDEN_COMPANY_ID;
        foreach ([9111, 9112] as $accountingPeriodId) {
            goldenTaxControlRequireSuccess(
                (new \eel_accounts\Service\AssetService())->runDepreciation($companyId, $accountingPeriodId)
            );
        }

        $computation = new \eel_accounts\Service\CorporationTaxComputationService();
        $periods = (array)($computation->activeCtPeriodsForAccountingPeriod($companyId, 9112)['periods'] ?? []);
        $harness->assertCount(1, $periods);
        $ctPeriodId = (int)($periods[0]['id'] ?? 0);
        $computation->preloadCtPeriodLossPositionsForAccountingPeriod($companyId, 9112);
        $summary = $computation->fetchSummaryForCtPeriodId($companyId, $ctPeriodId);

        $harness->assertSame('1506.81', goldenTaxControlMoney($summary['accounting_profit'] ?? 0));
        $harness->assertSame('600.00', goldenTaxControlMoney($summary['disallowable_add_backs'] ?? 0));
        $harness->assertSame('5027.19', goldenTaxControlMoney($summary['depreciation_add_back'] ?? 0));
        $harness->assertSame('9000.00', goldenTaxControlMoney($summary['capital_allowances'] ?? 0));
        $harness->assertSame('-1866.00', goldenTaxControlMoney($summary['taxable_before_losses'] ?? 0));
        $harness->assertSame('0.00', goldenTaxControlMoney($summary['taxable_profit'] ?? 0));
        $harness->assertSame('1866.00', goldenTaxControlMoney($summary['loss_created_in_period'] ?? 0));
        $harness->assertSame('1866.00', goldenTaxControlMoney($summary['losses_carried_forward'] ?? 0));
        $harness->assertSame('not_persisted', (string)($summary['computation_persistence']['status'] ?? ''));

        $persisted = $computation->persistSummariesForYearEndLock($companyId, 9112);
        goldenTaxControlRequireSuccess($persisted);
        $harness->assertCount(1, (array)($persisted['summaries'] ?? []));
        $persistedSummary = (array)($persisted['summaries'][0] ?? []);
        $harness->assertSame('current', (string)($persistedSummary['computation_persistence']['status'] ?? ''));

        $history = InterfaceDB::fetchOne(
            'SELECT computation_hash,
                    loss_created,
                    loss_brought_forward,
                    loss_utilised,
                    loss_carried_forward,
                    taxable_before_losses,
                    taxable_profit
             FROM tax_loss_movement_history
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND ct_period_id = :ct_period_id',
            ['company_id' => $companyId, 'accounting_period_id' => 9112, 'ct_period_id' => $ctPeriodId]
        );
        $harness->assertSame((string)($persistedSummary['computation_hash'] ?? ''), (string)($history['computation_hash'] ?? ''));
        $harness->assertSame('1866.00', goldenTaxControlMoney($history['loss_created'] ?? 0));
        $harness->assertSame('0.00', goldenTaxControlMoney($history['loss_brought_forward'] ?? 0));
        $harness->assertSame('0.00', goldenTaxControlMoney($history['loss_utilised'] ?? 0));
        $harness->assertSame('1866.00', goldenTaxControlMoney($history['loss_carried_forward'] ?? 0));
        $harness->assertSame('-1866.00', goldenTaxControlMoney($history['taxable_before_losses'] ?? 0));
        $harness->assertSame('0.00', goldenTaxControlMoney($history['taxable_profit'] ?? 0));
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
});

$harness->check('GoldenTaxControlMatrix', 'keeps CT600 submission disabled for an unpersisted computation', static function () use ($harness): void {
    InterfaceDB::beginTransaction();
    try {
        $companyId = GoldenAccountsFixture::GOLDEN_COMPANY_ID;
        $accountingPeriodId = 9114;
        $computation = new \eel_accounts\Service\CorporationTaxComputationService();
        $periods = (array)($computation->activeCtPeriodsForAccountingPeriod($companyId, $accountingPeriodId)['periods'] ?? []);
        $harness->assertCount(1, $periods);
        $ctPeriod = $periods[0];
        $ctPeriodId = (int)($ctPeriod['id'] ?? 0);
        $summary = $computation->fetchSummaryForCtPeriodId($companyId, $ctPeriodId);
        $harness->assertSame('not_persisted', (string)($summary['computation_persistence']['status'] ?? ''));

        $submissions = new \eel_accounts\Service\HmrcCorporationTaxSubmissionService();
        $disabled = $submissions->validatePackage($companyId, $ctPeriodId, 'TEST');
        $harness->assertFalse((bool)($disabled['success'] ?? true));
        $harness->assertSame('CT600 submission is not implemented.', (string)($disabled['errors'][0] ?? ''));
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
});

$harness->check('GoldenTaxControlMatrix', 'enforces CT period sequence while CT600 submission remains disabled', static function () use ($harness): void {
    InterfaceDB::beginTransaction();
    try {
        $companyId = GoldenAccountsFixture::GOLDEN_COMPANY_ID;
        $periodService = new \eel_accounts\Service\CorporationTaxPeriodService();
        $sync = $periodService->syncForAccountingPeriod($companyId, 9111);
        goldenTaxControlRequireSuccess($sync);
        $periods = (array)($sync['periods'] ?? []);
        $harness->assertCount(2, $periods);
        $firstId = (int)($periods[0]['id'] ?? 0);
        $secondId = (int)($periods[1]['id'] ?? 0);

        $blocked = $periodService->canSubmit($companyId, $secondId);
        $harness->assertFalse((bool)($blocked['ok'] ?? true));
        $harness->assertTrue(goldenTaxControlErrorsContain($blocked, 'must be accepted'));

        InterfaceDB::prepareExecute(
            'UPDATE corporation_tax_periods SET status = :status WHERE id = :id',
            ['status' => 'accepted', 'id' => $firstId]
        );
        $harness->assertTrue((bool)($periodService->canSubmit($companyId, $secondId)['ok'] ?? false));

        InterfaceDB::prepareExecute(
            'UPDATE corporation_tax_periods SET status = :status WHERE id = :id',
            ['status' => 'rejected', 'id' => $firstId]
        );
        $harness->assertFalse((bool)($periodService->canSubmit($companyId, $secondId)['ok'] ?? true));
        $harness->assertTrue((bool)($periodService->canSubmit($companyId, $firstId)['ok'] ?? false));

        $submissions = new \eel_accounts\Service\HmrcCorporationTaxSubmissionService();
        $disabled = $submissions->createSubmissionDraft($companyId, $firstId, 'TEST');
        $harness->assertFalse((bool)($disabled['success'] ?? true));
        $harness->assertSame('CT600 submission is not implemented.', (string)($disabled['errors'][0] ?? ''));
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
});

$harness->check('GoldenTaxControlMatrix', 'keeps an incomplete CT600 package disabled', static function () use ($harness): void {
    InterfaceDB::beginTransaction();
    try {
        $companyId = GoldenAccountsFixture::EMPTY_COMPANY_ID;
        $accountingPeriodId = 99901;
        $ctPeriodId = 99902;
        InterfaceDB::prepareExecute(
            'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
             VALUES (:id, :company_id, :label, :period_start, :period_end)',
            [
                'id' => $accountingPeriodId,
                'company_id' => $companyId,
                'label' => 'GOLDEN-TEST leap-year CT period',
                'period_start' => '2023-03-01',
                'period_end' => '2024-02-29',
            ]
        );
        InterfaceDB::prepareExecute(
            'INSERT INTO corporation_tax_periods
                (id, company_id, accounting_period_id, sequence_no, period_start, period_end, status)
             VALUES
                (:id, :company_id, :accounting_period_id, :sequence_no, :period_start, :period_end, :status)',
            [
                'id' => $ctPeriodId,
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'sequence_no' => 1,
                'period_start' => '2023-03-01',
                'period_end' => '2024-02-29',
                'status' => 'pending',
            ]
        );

        $result = (new \eel_accounts\Service\HmrcCorporationTaxSubmissionService())
            ->validatePackage($companyId, $ctPeriodId, 'TEST');
        $harness->assertFalse((bool)($result['success'] ?? true));
        $harness->assertSame('CT600 submission is not implemented.', (string)($result['errors'][0] ?? ''));
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
});

/** @return array<string, mixed> */
function goldenTaxControlFindCheck(array $checklist, string $checkCode): array
{
    foreach ((array)($checklist['checks_flat'] ?? []) as $check) {
        if (is_array($check) && (string)($check['check_code'] ?? '') === $checkCode) {
            return $check;
        }
    }

    throw new RuntimeException('Golden tax checklist check not found: ' . $checkCode);
}

function goldenTaxControlPostJournal(
    int $accountingPeriodId,
    string $journalKey,
    string $journalDate,
    string $description,
    int $debitNominalId,
    int $creditNominalId,
    float $amount
): array {
    $result = (new \eel_accounts\Service\ManualJournalService())->saveTaggedJournal(
        GoldenAccountsFixture::GOLDEN_COMPANY_ID,
        $accountingPeriodId,
        'golden_tax_control_matrix',
        $journalKey,
        $journalDate,
        $description,
        [
            [
                'nominal_account_id' => $debitNominalId,
                'director_id' => in_array($debitNominalId, [91005, 91006], true)
                    ? GoldenAccountsFixture::GOLDEN_DIRECTOR_ID
                    : null,
                'debit' => $amount,
                'credit' => 0.0,
                'line_description' => $description,
            ],
            [
                'nominal_account_id' => $creditNominalId,
                'director_id' => in_array($creditNominalId, [91005, 91006], true)
                    ? GoldenAccountsFixture::GOLDEN_DIRECTOR_ID
                    : null,
                'debit' => 0.0,
                'credit' => $amount,
                'line_description' => $description,
            ],
        ],
        'manual',
        null,
        null,
        'Synthetic golden tax-control matrix entry.',
        'golden_tax_control'
    );
    goldenTaxControlRequireSuccess($result);

    return $result;
}

function goldenTaxControlRequireSuccess(array $result): void
{
    if (empty($result['success'])) {
        throw new RuntimeException(implode(' ', array_map('strval', (array)($result['errors'] ?? ['Golden tax operation failed.']))));
    }
}

function goldenTaxControlMoney(mixed $amount): string
{
    return number_format(round((float)$amount, 2), 2, '.', '');
}

function goldenTaxControlProvisionJournalCount(int $companyId, int $accountingPeriodId): int
{
    return (int)InterfaceDB::fetchColumn(
        'SELECT COUNT(*)
         FROM journal_entry_metadata jem
         INNER JOIN journals j ON j.id = jem.journal_id
         WHERE jem.company_id = :company_id
           AND jem.accounting_period_id = :accounting_period_id
           AND jem.journal_tag = :journal_tag
           AND j.is_posted = 1',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'journal_tag' => \eel_accounts\Service\CorporationTaxProvisionService::JOURNAL_TAG,
        ]
    );
}

function goldenTaxControlErrorsContain(array $result, string $needle): bool
{
    return str_contains(
        strtolower(implode(' ', array_map('strval', (array)($result['errors'] ?? [])))),
        strtolower($needle)
    );
}
