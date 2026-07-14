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
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GoldenLedgerSpecification.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GoldenHmrcCorporationTaxOracle.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GoldenComparisonReporter.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'PageServiceTestFactory.php';

$harness = new GeneratedServiceClassTestHarness();
GoldenAccountsFixture::build();

$harness->check('GoldenYearEndLifecycle', 'performs close tasks and preserves reporting semantics when completed periods are locked', static function () use ($harness): void {
    $companyId = GoldenAccountsFixture::GOLDEN_COMPANY_ID;
    $periods = [9111, 9112, 9113];
    $hmrcExpected = GoldenHmrcCorporationTaxOracle::calculateSequence(GoldenLedgerSpecification::hmrcTaxFacts());

    foreach ($periods as $periodId) {
        $expected = GoldenLedgerSpecification::yearEndAssetExpectations()[$periodId];
        $depreciation = (new \eel_accounts\Service\AssetService())->runDepreciation($companyId, $periodId);
        $harness->assertTrue(!empty($depreciation['success']));
        $harness->assertSame((int)$expected['depreciation_entries'], (int)($depreciation['created'] ?? 0));
        $postedDepreciation = (float)InterfaceDB::fetchColumn(
            'SELECT COALESCE(SUM(ade.amount), 0)
             FROM asset_depreciation_entries ade
             INNER JOIN asset_register ar ON ar.id = ade.asset_id
             WHERE ar.company_id = :company_id AND ade.accounting_period_id = :period_id',
            ['company_id' => $companyId, 'period_id' => $periodId]
        );
        $harness->assertSame(number_format((float)$expected['depreciation'], 2, '.', ''), number_format($postedDepreciation, 2, '.', ''));

        $provision = (new \eel_accounts\Service\CorporationTaxProvisionService())
            ->postProvisionsForAccountingPeriod($companyId, $periodId, 'golden_year_end_test');
        $harness->assertTrue(!empty($provision['success']));

        $checklist = new \eel_accounts\Service\YearEndChecklistService();
        $acknowledgement = $checklist->saveRetainedEarningsCloseAcknowledgement(
            $companyId,
            $periodId,
            true,
            'golden_year_end_test',
            'Golden lifecycle figures agreed after close-task calculations.'
        );
        $harness->assertTrue(!empty($acknowledgement['success']));

        $retainedEarnings = (new \eel_accounts\Service\RetainedEarningsCloseService())
            ->postClose($companyId, $periodId, 'golden_year_end_test');
        $harness->assertTrue(!empty($retainedEarnings['success']));

        $profitLoss = (new \eel_accounts\Service\ProfitLossService())->getProfitLossSummary($companyId, $periodId);
        $harness->assertSame(number_format((float)$expected['depreciation'], 2, '.', ''), number_format((float)($profitLoss['depreciation_expense'] ?? 0), 2, '.', ''));
        $harness->assertSame(number_format((float)$expected['profit_before_tax'], 2, '.', ''), number_format((float)($profitLoss['profit_before_tax'] ?? 0), 2, '.', ''));

        $ctTax = 0.0;
        $ctTaxableProfit = 0.0;
        $ctCapitalAllowances = 0.0;
        $ctPeriods = (new \eel_accounts\Service\CorporationTaxPeriodService())->fetchForAccountingPeriod($companyId, $periodId);
        foreach ($ctPeriods as $ctPeriod) {
            $summary = (new \eel_accounts\Service\CorporationTaxComputationService())->fetchSummaryForCtPeriodId($companyId, (int)$ctPeriod['id']);
            $ctTax += (float)($summary['estimated_corporation_tax'] ?? 0);
            $ctTaxableProfit += (float)($summary['taxable_profit'] ?? 0);
            $ctCapitalAllowances += (float)($summary['capital_allowances'] ?? 0);
        }
        $harness->assertSame(number_format((float)$expected['capital_allowances'], 2, '.', ''), number_format($ctCapitalAllowances, 2, '.', ''));
        $harness->assertSame(number_format((float)$expected['taxable_profit'], 2, '.', ''), number_format($ctTaxableProfit, 2, '.', ''));
        $harness->assertSame(number_format((float)$expected['corporation_tax'], 2, '.', ''), number_format($ctTax, 2, '.', ''));
        $harness->assertSame(number_format((float)$hmrcExpected[$periodId]['capital_allowances'], 2, '.', ''), number_format($ctCapitalAllowances, 2, '.', ''));
        $harness->assertSame(number_format((float)$hmrcExpected[$periodId]['taxable_profit'], 2, '.', ''), number_format($ctTaxableProfit, 2, '.', ''));
        $harness->assertSame(number_format((float)$hmrcExpected[$periodId]['corporation_tax'], 2, '.', ''), number_format($ctTax, 2, '.', ''));

        $beforeLock = goldenYearEndReportingSnapshot($companyId, $periodId);
        InterfaceDB::beginTransaction();
        try {
            $taxPersistence = (new \eel_accounts\Service\CorporationTaxComputationService())
                ->persistSummariesForYearEndLock($companyId, $periodId);
            $harness->assertTrue(!empty($taxPersistence['success']));

            $lock = (new \eel_accounts\Service\YearEndLockService())
                ->lockPeriod($companyId, $periodId, 'golden_year_end_test');
            $harness->assertTrue(!empty($lock['success']));
            InterfaceDB::commit();
        } catch (Throwable $exception) {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
            throw $exception;
        }
        $harness->assertTrue((new \eel_accounts\Service\YearEndLockService())->isLocked($companyId, $periodId));
        $afterLock = goldenYearEndReportingSnapshot($companyId, $periodId);

        if ($beforeLock !== $afterLock) {
            throw new RuntimeException(GoldenComparisonReporter::report([[
                'page' => 'year_end',
                'card' => 'locked_reporting_invariants',
                'period' => $periodId,
                'field' => 'tax_companies_house_dividends_profit_loss',
                'expected' => $beforeLock,
                'actual' => $afterLock,
            ]]));
        }

        if ($periodId === 9111) {
            goldenAssertFirstPeriodLockBoundary($harness, $companyId);
        }
    }

    foreach ($periods as $periodId) {
        $unlock = (new \eel_accounts\Service\YearEndLockService())
            ->unlockPeriod($companyId, $periodId, 'golden_year_end_test_cleanup', 'Restore shared in-memory fixture state after lock assertions.');
        $harness->assertTrue(!empty($unlock['success']));
    }
});

function goldenAssertFirstPeriodLockBoundary(GeneratedServiceClassTestHarness $harness, int $companyId): void
{
    $lockService = new \eel_accounts\Service\YearEndLockService();
    $harness->assertTrue($lockService->isLocked($companyId, 9111));
    foreach ([9112, 9113, 9114] as $openPeriodId) {
        $harness->assertFalse($lockService->isLocked($companyId, $openPeriodId));
        $lockService->assertUnlocked($companyId, $openPeriodId, 'edit this later golden period');
    }

    $periodRepository = new \eel_accounts\Repository\AccountingPeriodRepository();
    $lockedPeriodBefore = $periodRepository->fetchAccountingPeriod($companyId, 9111);
    $periodEditRejected = false;
    try {
        $periodRepository->updatePeriod($companyId, 9111, 'Tampered locked period', '2022-09-06', '2023-09-29');
    } catch (RuntimeException $exception) {
        $periodEditRejected = str_contains($exception->getMessage(), 'locked');
    }
    $harness->assertTrue($periodEditRejected);
    $harness->assertSame($lockedPeriodBefore, $periodRepository->fetchAccountingPeriod($companyId, 9111));

    $openPeriodBefore = $periodRepository->fetchAccountingPeriod($companyId, 9112);
    $periodRepository->updatePeriod(
        $companyId,
        9112,
        (string)$openPeriodBefore['label'] . ' edit check',
        (string)$openPeriodBefore['period_start'],
        (string)$openPeriodBefore['period_end']
    );
    $harness->assertSame((string)$openPeriodBefore['label'] . ' edit check', (string)$periodRepository->fetchAccountingPeriod($companyId, 9112)['label']);
    $periodRepository->updatePeriod(
        $companyId,
        9112,
        (string)$openPeriodBefore['label'],
        (string)$openPeriodBefore['period_start'],
        (string)$openPeriodBefore['period_end']
    );

    $reviewBefore = $lockService->fetchReview($companyId, 9111);
    foreach ([
        static fn(): array => $lockService->saveNotes($companyId, 9111, 'Tampered notes', 'golden_lock_test'),
        static fn(): array => (new \eel_accounts\Service\YearEndChecklistService())->recalculateChecklist($companyId, 9111, 'golden_lock_test'),
    ] as $lockedMutation) {
        $rejected = false;
        try {
            $lockedMutation();
        } catch (RuntimeException $exception) {
            $rejected = str_contains($exception->getMessage(), 'locked');
        }
        $harness->assertTrue($rejected);
    }
    $harness->assertSame($reviewBefore, $lockService->fetchReview($companyId, 9111));

    $uploadBase = testPageServiceUploadBasePath();
    $csvPath = tempnam(sys_get_temp_dir(), 'golden-lock-');
    if (!is_string($csvPath)) {
        throw new RuntimeException('Unable to create the golden upload control file.');
    }
    file_put_contents($csvPath, "Date,Description,Amount,Balance\n2023-10-12,Unlocked upload control,12.34,12.34\n");
    $file = ['name' => 'golden-lock-control.csv', 'tmp_name' => $csvPath, 'error' => UPLOAD_ERR_OK, 'size' => filesize($csvPath), 'type' => 'text/csv'];
    $uploadService = new \eel_accounts\Service\StatementUploadService($uploadBase);
    $lockedUpload = $uploadService->createUploadFromHttpRequest(
        ['company_id' => $companyId, 'account_id' => 9120, 'accounting_period_id' => 9111],
        ['statement_file' => $file]
    );
    $harness->assertFalse(!empty($lockedUpload['success']));
    $harness->assertSame(409, (int)($lockedUpload['http_status'] ?? 0));

    @unlink($csvPath);
    $openUploadHash = hash('sha256', 'golden-open-upload-control');
    InterfaceDB::prepareExecute(
        'INSERT INTO statement_uploads (
            company_id, accounting_period_id, account_id, source_type, workflow_status,
            statement_month, original_filename, stored_filename, file_sha256, rows_parsed
        ) VALUES (
            :company_id, :accounting_period_id, :account_id, :source_type, :workflow_status,
            :statement_month, :original_filename, :stored_filename, :file_sha256, 0
        )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => 9112,
            'account_id' => 9120,
            'source_type' => 'bank_account',
            'workflow_status' => 'uploaded',
            'statement_month' => '2023-10-01',
            'original_filename' => 'golden-open-control.csv',
            'stored_filename' => 'golden-open-control.csv',
            'file_sha256' => $openUploadHash,
        ]
    );
    $openUploadId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM statement_uploads WHERE company_id = :company_id AND file_sha256 = :file_sha256 ORDER BY id DESC LIMIT 1',
        ['company_id' => $companyId, 'file_sha256' => $openUploadHash]
    );
    $harness->assertTrue($openUploadId > 0);
    $harness->assertFalse(!empty($uploadService->fetchUploadLockState($companyId, $openUploadId)['is_locked']));

    InterfaceDB::execute(
        'INSERT INTO statement_import_rows (upload_id, `row_number`, raw_json, accounting_period_id, chosen_txn_date, normalised_description, normalised_amount, normalised_currency, row_hash, validation_status, is_duplicate_within_upload, is_duplicate_existing)
         VALUES (:upload_id, 999, :raw_json, 9111, :txn_date, :description, :amount, :currency, :row_hash, :status, 0, 0)',
        [
            'upload_id' => $openUploadId,
            'raw_json' => '{}',
            'txn_date' => '2023-09-20',
            'description' => 'Locked mixed-period row',
            'amount' => '1.00',
            'currency' => 'GBP',
            'row_hash' => hash('sha256', 'golden-locked-mixed-period-row'),
            'status' => 'valid',
        ]
    );
    $mixedLockState = $uploadService->fetchUploadLockState($companyId, $openUploadId);
    $harness->assertTrue(!empty($mixedLockState['is_locked']));
    $harness->assertSame([9111, 9112], array_map('intval', (array)$mixedLockState['accounting_period_ids']));
    $mixedCommit = $uploadService->commitUpload($companyId, $openUploadId);
    $harness->assertFalse(!empty($mixedCommit['success']));
    $harness->assertSame(409, (int)($mixedCommit['http_status'] ?? 0));
    $harness->assertSame(0, (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM transactions WHERE statement_upload_id = :upload_id', ['upload_id' => $openUploadId]));
    $checksumBefore = (string)InterfaceDB::fetchColumn(
        'SELECT row_hash FROM statement_import_rows WHERE upload_id = :upload_id AND `row_number` = 999',
        ['upload_id' => $openUploadId]
    );
    $checksumResult = $uploadService->recalculateCompanyChecksums($companyId);
    $harness->assertTrue((int)($checksumResult['rows_locked_skipped'] ?? 0) >= 1);
    $harness->assertSame(
        $checksumBefore,
        (string)InterfaceDB::fetchColumn('SELECT row_hash FROM statement_import_rows WHERE upload_id = :upload_id AND `row_number` = 999', ['upload_id' => $openUploadId])
    );

    InterfaceDB::execute('DELETE FROM statement_import_rows WHERE upload_id = :upload_id', ['upload_id' => $openUploadId]);
    InterfaceDB::execute('DELETE FROM statement_import_mappings WHERE upload_id = :upload_id', ['upload_id' => $openUploadId]);
    InterfaceDB::execute('DELETE FROM statement_uploads WHERE id = :upload_id', ['upload_id' => $openUploadId]);

    $cardLockMechanisms = [
        'accounting_periods' => 'selected_period_locked', 'uploads_bank_transactions' => 'isLocked', 'uploads_details' => 'lock_state',
        'uploads_validate_commit' => 'selected_upload_lock_state', 'transactions_imported' => 'is_locked',
        'year_end_empty_month_confirmations' => 'YearEndApprovalRenderer', 'year_end_transaction_tail' => 'YearEndApprovalRenderer',
        'expense_claim_editor' => 'isLocked', 'expense_claim_create' => 'isLocked', 'year_end_expenses_confirmation' => 'YearEndApprovalRenderer',
        'year_end_director_loan_offset' => 'locked', 'dividend_declare' => 'locked', 'dividend_reserve_review' => 'locked',
        'asset_create' => 'isLocked', 'asset_reconcile_manual' => 'selected_period_locked', 'not_an_asset' => 'nonAssetReview',
        'prepayments_review' => 'isLocked', 'year_end_prepayment_approvals' => 'locked', 'journal_cut_offs' => 'isLocked',
        'journal_cut_off_confirmation' => 'YearEndApprovalRenderer', 'year_end_retained_earnings' => 'locked',
        'year_end_companies_house_comparison' => 'YearEndApprovalRenderer', 'year_end_tax_readiness' => 'YearEndApprovalRenderer',
        'year_end_notes' => 'isLocked', 'year_end_state' => 'isLocked',
    ];
    foreach ($cardLockMechanisms as $cardKey => $mechanism) {
        $source = (string)file_get_contents(APP_CARDS . $cardKey . '.php');
        $harness->assertTrue(str_contains($source, $mechanism));
    }
}

/** @return array<string, mixed> */
function goldenYearEndReportingSnapshot(int $companyId, int $periodId): array
{
    $tax = (new \eel_accounts\Service\TaxWorkingsService())->fetchWorkings($companyId, $periodId, 0);
    $companiesHouse = (new \eel_accounts\Service\CompaniesHouseSnapshotService())->fetchSnapshot($companyId, $periodId);
    $dividends = (new \eel_accounts\Service\DividendViewDataService())->fetchCapacityContext($companyId, $periodId);
    $profitLoss = (new \eel_accounts\Service\ProfitLossService())->getProfitLossSummary($companyId, $periodId);
    $companiesHouseFields = goldenYearEndFieldsByKey((array)($companiesHouse['fields'] ?? []));
    $capacity = (array)($dividends['capacity'] ?? []);

    return [
        'tax' => goldenYearEndSelect((array)($tax['summary'] ?? []), [
            'accounting_profit', 'disallowable_add_backs', 'depreciation_add_back', 'capital_allowances',
            'taxable_before_losses', 'taxable_profit', 'taxable_loss', 'estimated_corporation_tax',
            'losses_brought_forward', 'losses_used', 'losses_carried_forward',
        ]),
        'companies_house' => [
            'fixed_assets' => $companiesHouseFields['fixed_assets'] ?? null,
            'current_assets' => $companiesHouseFields['current_assets'] ?? null,
            'creditors_within_one_year' => $companiesHouseFields['creditors_within_one_year'] ?? null,
            'creditors_after_more_than_one_year' => $companiesHouseFields['creditors_after_more_than_one_year'] ?? null,
            'net_assets_liabilities' => $companiesHouseFields['net_assets_liabilities'] ?? null,
            'equity_capital_reserves' => $companiesHouseFields['equity_capital_reserves'] ?? null,
            'balance_equation_difference' => $companiesHouse['balance_equation_difference'] ?? null,
            'is_balance_sheet_balanced' => $companiesHouse['is_balance_sheet_balanced'] ?? null,
        ],
        'dividends' => goldenYearEndSelect($capacity, [
            'retained_earnings_brought_forward', 'distributable_reserves_brought_forward',
            'ledger_current_year_profit_loss', 'posted_corporation_tax_charge',
            'estimated_corporation_tax', 'unposted_corporation_tax_adjustment',
            'current_year_profit_loss_after_tax', 'dividends_declared', 'available_distributable_reserves',
        ]),
        'profit_loss' => goldenYearEndSelect($profitLoss, [
            'income_total', 'cost_of_sales_total', 'gross_profit', 'operating_expense_total',
            'depreciation_expense', 'profit_before_tax', 'posted_corporation_tax_charge',
            'estimated_corporation_tax', 'profit_after_posted_tax', 'profit_after_estimated_tax', 'net_profit',
        ]),
    ];
}

/** @param array<string, mixed> $source @param list<string> $keys @return array<string, mixed> */
function goldenYearEndSelect(array $source, array $keys): array
{
    $selected = [];
    foreach ($keys as $key) {
        $selected[$key] = $source[$key] ?? null;
    }
    return $selected;
}

/** @param list<array<string, mixed>> $fields @return array<string, mixed> */
function goldenYearEndFieldsByKey(array $fields): array
{
    $values = [];
    foreach ($fields as $field) {
        $values[(string)($field['key'] ?? '')] = $field['value'] ?? null;
    }
    return $values;
}
