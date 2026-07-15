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
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'PageServiceTestFactory.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(\eel_accounts\Service\VatSupportScopeService::class, static function (
    GeneratedServiceClassTestHarness $harness,
    \eel_accounts\Service\VatSupportScopeService $service
): void {
    $harness->check(\eel_accounts\Service\VatSupportScopeService::class, 'requires LIVE HMRC confirmation before Tax and Year End become read only', static function () use ($harness, $service): void {
        $base = [
            'is_vat_registered' => 1,
            'vat_validation_source' => 'hmrc',
            'vat_validation_mode' => 'LIVE',
            'vat_validation_status' => 'valid',
        ];

        $harness->assertSame(false, (bool)$service->evaluate([])['tax_year_end_read_only']);
        $harness->assertSame(false, (bool)$service->evaluate(array_replace($base, ['is_vat_registered' => 0]))['tax_year_end_read_only']);
        $harness->assertSame(false, (bool)$service->evaluate(array_replace($base, ['vat_validation_source' => 'manual']))['tax_year_end_read_only']);
        $harness->assertSame(false, (bool)$service->evaluate(array_replace($base, ['vat_validation_mode' => 'TEST']))['tax_year_end_read_only']);
        $harness->assertSame(false, (bool)$service->evaluate(array_replace($base, ['vat_validation_mode' => '']))['tax_year_end_read_only']);
        $harness->assertSame(false, (bool)$service->evaluate(array_replace($base, ['vat_validation_status' => 'invalid']))['tax_year_end_read_only']);
        $harness->assertSame(false, (bool)$service->evaluate(array_replace($base, ['vat_validation_status' => 'mismatch_pending']))['tax_year_end_read_only']);
        $harness->assertSame(true, (bool)$service->evaluate($base)['tax_year_end_read_only']);
        $harness->assertSame(true, (bool)$service->evaluate(array_replace($base, ['vat_validation_status' => 'mismatch_override']))['tax_year_end_read_only']);
    });

    $harness->check(\eel_accounts\Service\VatSupportScopeService::class, 'blocks direct CT draft creation without writing a submission row', static function () use ($harness): void {
        GoldenAccountsFixture::build();
        InterfaceDB::beginTransaction();
        try {
            $marker = (string)random_int(100000, 999999);
            $companyId = (int)('99' . $marker);
            InterfaceDB::prepareExecute(
                'INSERT INTO companies (
                    id, company_name, company_number, incorporation_date, company_status, is_active,
                    is_vat_registered, vat_validation_source, vat_validation_mode, vat_validation_status
                 ) VALUES (
                    :id, :company_name, :company_number, :incorporation_date, :company_status, 1,
                    1, :validation_source, :validation_mode, :validation_status
                 )',
                [
                    'id' => $companyId,
                    'company_name' => 'LIVE VAT Scope Fixture Limited',
                    'company_number' => 'VSG' . $marker,
                    'incorporation_date' => '2024-01-01',
                    'company_status' => 'active',
                    'validation_source' => 'hmrc',
                    'validation_mode' => 'LIVE',
                    'validation_status' => 'valid',
                ]
            );
            $before = InterfaceDB::tableExists('hmrc_ct600_submissions')
                ? InterfaceDB::tableRowCount('hmrc_ct600_submissions')
                : 0;
            $result = (new \eel_accounts\Service\HmrcCorporationTaxSubmissionService())
                ->createSubmissionDraft($companyId, 123456, 'TEST');
            $after = InterfaceDB::tableExists('hmrc_ct600_submissions')
                ? InterfaceDB::tableRowCount('hmrc_ct600_submissions')
                : 0;

            $harness->assertSame(false, (bool)$result['success']);
            $harness->assertSame(true, str_contains((string)($result['errors'][0] ?? ''), 'read only'));
            $harness->assertSame($before, $after);
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\VatSupportScopeService::class, 'Tax page does not synchronise CT periods in unsupported LIVE VAT scope', static function () use ($harness): void {
        GoldenAccountsFixture::build();
        InterfaceDB::beginTransaction();
        try {
            $marker = (string)random_int(100000, 999999);
            $companyId = (int)('85' . $marker);
            $accountingPeriodId = (int)('86' . $marker);
            InterfaceDB::prepareExecute(
                'INSERT INTO companies (
                    id, company_name, company_number, incorporation_date, company_status, is_active,
                    is_vat_registered, vat_validation_source, vat_validation_mode, vat_validation_status
                 ) VALUES (
                    :id, :company_name, :company_number, :incorporation_date, :company_status, 1,
                    1, :validation_source, :validation_mode, :validation_status
                 )',
                [
                    'id' => $companyId,
                    'company_name' => 'VAT Tax Page Scope Fixture Limited',
                    'company_number' => 'VTP' . $marker,
                    'incorporation_date' => '2024-01-01',
                    'company_status' => 'active',
                    'validation_source' => 'hmrc',
                    'validation_mode' => 'LIVE',
                    'validation_status' => 'valid',
                ]
            );
            InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
                 VALUES (:id, :company_id, :label, :period_start, :period_end)',
                [
                    'id' => $accountingPeriodId,
                    'company_id' => $companyId,
                    'label' => 'VAT Tax Page ' . $marker,
                    'period_start' => '2024-01-01',
                    'period_end' => '2024-12-31',
                ]
            );

            $page = new _tax();
            $method = new ReflectionMethod($page, 'moduleContext');
            $method->setAccessible(true);
            $context = $method->invoke(
                $page,
                new RequestFramework([], [], ['REQUEST_METHOD' => 'GET'], [], [], null),
                createTestPageServiceFramework(),
                new ActionResultFramework(true),
                ['company' => ['id' => $companyId, 'accounting_period_id' => $accountingPeriodId]]
            );

            $harness->assertSame(true, (bool)$context['vat_support_scope']['tax_year_end_read_only']);
            $harness->assertSame(0, InterfaceDB::countWhere('corporation_tax_periods', [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\VatSupportScopeService::class, 'Tax workings expose persisted summary without mixing in live detail rows', static function () use ($harness): void {
        GoldenAccountsFixture::build();
        InterfaceDB::beginTransaction();
        try {
            $marker = (string)random_int(100000, 999999);
            $companyId = (int)('87' . $marker);
            $accountingPeriodId = (int)('88' . $marker);
            $ctPeriodId = (int)('89' . $marker);
            InterfaceDB::prepareExecute(
                'INSERT INTO companies (
                    id, company_name, company_number, incorporation_date, company_status, is_active,
                    is_vat_registered, vat_validation_source, vat_validation_mode, vat_validation_status
                 ) VALUES (
                    :id, :company_name, :company_number, :incorporation_date, :company_status, 1,
                    1, :validation_source, :validation_mode, :validation_status
                 )',
                [
                    'id' => $companyId,
                    'company_name' => 'VAT Historical Tax Fixture Limited',
                    'company_number' => 'VH' . $marker,
                    'incorporation_date' => '2024-01-01',
                    'company_status' => 'active',
                    'validation_source' => 'hmrc',
                    'validation_mode' => 'LIVE',
                    'validation_status' => 'valid',
                ]
            );
            InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
                 VALUES (:id, :company_id, :label, :period_start, :period_end)',
                [
                    'id' => $accountingPeriodId,
                    'company_id' => $companyId,
                    'label' => 'VAT Historical ' . $marker,
                    'period_start' => '2024-01-01',
                    'period_end' => '2024-12-31',
                ]
            );
            InterfaceDB::prepareExecute(
                'INSERT INTO corporation_tax_periods (
                    id, company_id, accounting_period_id, sequence_no, period_start, period_end, status
                 ) VALUES (
                    :id, :company_id, :accounting_period_id, 1, :period_start, :period_end, :status
                 )',
                [
                    'id' => $ctPeriodId,
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'period_start' => '2024-01-01',
                    'period_end' => '2024-12-31',
                    'status' => 'computed',
                ]
            );

            $summary = [
                'available' => true,
                'accounting_profit' => 1234.56,
                'estimated_corporation_tax' => 234.56,
                'steps' => [['label' => 'Persisted accounting profit', 'amount' => 1234.56]],
                'capital_allowance_breakdown' => ['rows' => []],
                'schedule' => [],
                'ct_rate_bands' => [],
                'warnings' => [],
            ];
            $hash = hash('sha256', 'vat-historical-' . $marker);
            InterfaceDB::prepareExecute(
                'INSERT INTO corporation_tax_computation_runs (
                    company_id, accounting_period_id, ct_period_id, period_start, period_end,
                    status, computation_hash, summary_json
                 ) VALUES (
                    :company_id, :accounting_period_id, :ct_period_id, :period_start, :period_end,
                    :status, :computation_hash, :summary_json
                 )',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'ct_period_id' => $ctPeriodId,
                    'period_start' => '2024-01-01',
                    'period_end' => '2024-12-31',
                    'status' => 'generated',
                    'computation_hash' => $hash,
                    'summary_json' => json_encode($summary, JSON_THROW_ON_ERROR),
                ]
            );
            $runId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM corporation_tax_computation_runs
                 WHERE company_id = :company_id AND computation_hash = :computation_hash
                 ORDER BY id DESC LIMIT 1',
                ['company_id' => $companyId, 'computation_hash' => $hash]
            );
            InterfaceDB::prepareExecute(
                'UPDATE corporation_tax_periods SET latest_computation_run_id = :run_id WHERE id = :id',
                ['run_id' => $runId, 'id' => $ctPeriodId]
            );

            $workings = (new \eel_accounts\Service\TaxWorkingsService())
                ->fetchWorkings($companyId, $accountingPeriodId, $ctPeriodId);

            $harness->assertSame(true, (bool)($workings['available'] ?? false));
            $harness->assertSame(true, (bool)($workings['historical_snapshot_only'] ?? false));
            $harness->assertSame('persisted_historical_snapshot', (string)($workings['summary']['summary_source'] ?? ''));
            $harness->assertSame('1234.56', number_format((float)($workings['summary']['accounting_profit'] ?? 0), 2, '.', ''));
            $harness->assertSame([], (array)($workings['disallowable_add_backs'] ?? null));
            $harness->assertSame([], (array)($workings['depreciation_add_back'] ?? null));
            $harness->assertSame([], (array)($workings['provision'] ?? null));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\VatSupportScopeService::class, 'LIVE HMRC scope blocks direct Year End writers without database changes', static function () use ($harness): void {
        GoldenAccountsFixture::build();
        InterfaceDB::beginTransaction();
        try {
            $marker = (string)random_int(100000, 999999);
            $companyId = (int)('81' . $marker);
            $accountingPeriodId = (int)('82' . $marker);
            InterfaceDB::prepareExecute(
                'INSERT INTO companies (
                    id, company_name, company_number, incorporation_date, company_status, is_active,
                    is_vat_registered, vat_validation_source, vat_validation_mode, vat_validation_status
                 ) VALUES (
                    :id, :company_name, :company_number, :incorporation_date, :company_status, 1,
                    1, :validation_source, :validation_mode, :validation_status
                 )',
                [
                    'id' => $companyId,
                    'company_name' => 'VAT Direct Writer Guard Fixture Limited',
                    'company_number' => 'VG' . $marker,
                    'incorporation_date' => '2023-01-01',
                    'company_status' => 'active',
                    'validation_source' => 'hmrc',
                    'validation_mode' => 'LIVE',
                    'validation_status' => 'valid',
                ]
            );
            InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
                 VALUES (:id, :company_id, :label, :period_start, :period_end)',
                [
                    'id' => $accountingPeriodId,
                    'company_id' => $companyId,
                    'label' => 'VAT Writer Guard ' . $marker,
                    'period_start' => '2023-01-01',
                    'period_end' => '2023-12-31',
                ]
            );

            $trackedTables = array_values(array_filter([
                'journals',
                'journal_lines',
                'accounting_period_month_confirmations',
                'asset_depreciation_entries',
                'capital_allowance_pool_runs',
                'capital_allowance_asset_calculations',
                'dividend_reserve_classification_rules',
                'dividend_reserve_review_snapshots',
                'year_end_audit_log',
            ], static fn(string $table): bool => InterfaceDB::tableExists($table)));
            $before = [];
            foreach ($trackedTables as $table) {
                $before[$table] = InterfaceDB::tableRowCount($table);
            }

            $results = [
                'opening balance' => (new \eel_accounts\Service\OpeningBalanceService())
                    ->saveOpeningBalance($companyId, $accountingPeriodId, []),
                'year end adjustment' => (new \eel_accounts\Service\YearEndAdjustmentService())
                    ->createAdjustment($companyId, $accountingPeriodId, []),
                'empty month confirmation' => (new \eel_accounts\Service\EmptyMonthConfirmationService())
                    ->confirmMonth($companyId, $accountingPeriodId, '2023-01-01'),
                'multiple empty month confirmations' => (new \eel_accounts\Service\EmptyMonthConfirmationService())
                    ->confirmMonths($companyId, $accountingPeriodId, ['2023-01-01']),
                'empty month revocation' => (new \eel_accounts\Service\EmptyMonthConfirmationService())
                    ->revokeMonth($companyId, $accountingPeriodId, '2023-01-01'),
                'upload-triggered empty month revocation' => (new \eel_accounts\Service\EmptyMonthConfirmationService())
                    ->revokeActiveConfirmationsForMonths($companyId, $accountingPeriodId, ['2023-01-01'], 'upload_import'),
                'director loan offset' => (new \eel_accounts\Service\DirectorLoanReconciliationService())
                    ->postOffset($companyId, $accountingPeriodId),
                'depreciation run' => (new \eel_accounts\Service\AssetService())
                    ->runDepreciation($companyId, $accountingPeriodId),
                'asset tax data refresh' => (new \eel_accounts\Service\AssetService())
                    ->refreshTaxData($companyId),
                'capital allowance rebuild' => (new \eel_accounts\Service\CapitalAllowanceService())
                    ->rebuildForCompany($companyId),
                'dividend reserve review' => (new \eel_accounts\Service\DividendReserveClassificationService())
                    ->saveReview($companyId, $accountingPeriodId, []),
            ];

            foreach ($results as $label => $result) {
                $harness->assertSame(false, (bool)($result['success'] ?? true), $label . ' should be blocked');
                $harness->assertSame(403, (int)($result['status'] ?? 0), $label . ' should return forbidden status');
                $harness->assertSame(
                    true,
                    str_contains((string)($result['errors'][0] ?? ''), 'read only'),
                    $label . ' should explain the unsupported scope'
                );
            }

            // The generic period-lock helper remains available to ordinary
            // bookkeeping; only the Year End writers above carry the scope gate.
            (new \eel_accounts\Service\YearEndLockService())->assertUnlocked(
                $companyId,
                $accountingPeriodId,
                'post ordinary bookkeeping'
            );

            $auditBlocked = false;
            try {
                (new \eel_accounts\Service\YearEndLockService())->writeAuditLog(
                    $companyId,
                    $accountingPeriodId,
                    'direct_test',
                    'unit_test'
                );
            } catch (RuntimeException $exception) {
                $auditBlocked = str_contains($exception->getMessage(), 'read only');
            }
            $harness->assertSame(true, $auditBlocked);

            foreach ($trackedTables as $table) {
                $harness->assertSame($before[$table], InterfaceDB::tableRowCount($table), $table . ' must not change');
            }
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\VatSupportScopeService::class, 'LIVE HMRC scope keeps ordinary bookkeeping journals available', static function () use ($harness): void {
        GoldenAccountsFixture::build();
        InterfaceDB::beginTransaction();
        try {
            $marker = (string)random_int(100000, 999999);
            $companyId = (int)('83' . $marker);
            $accountingPeriodId = (int)('84' . $marker);
            InterfaceDB::prepareExecute(
                'INSERT INTO companies (
                    id, company_name, company_number, incorporation_date, company_status, is_active,
                    is_vat_registered, vat_validation_source, vat_validation_mode, vat_validation_status
                 ) VALUES (
                    :id, :company_name, :company_number, :incorporation_date, :company_status, 1,
                    1, :validation_source, :validation_mode, :validation_status
                 )',
                [
                    'id' => $companyId,
                    'company_name' => 'VAT Ordinary Bookkeeping Fixture Limited',
                    'company_number' => 'VB' . $marker,
                    'incorporation_date' => '2024-01-01',
                    'company_status' => 'active',
                    'validation_source' => 'hmrc',
                    'validation_mode' => 'LIVE',
                    'validation_status' => 'valid',
                ]
            );
            InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
                 VALUES (:id, :company_id, :label, :period_start, :period_end)',
                [
                    'id' => $accountingPeriodId,
                    'company_id' => $companyId,
                    'label' => 'VAT Bookkeeping ' . $marker,
                    'period_start' => '2024-01-01',
                    'period_end' => '2024-12-31',
                ]
            );

            $result = (new \eel_accounts\Service\ManualJournalService())->saveTaggedJournal(
                $companyId,
                $accountingPeriodId,
                'ordinary_bookkeeping_test',
                'primary',
                '2024-06-30',
                'Ordinary bookkeeping remains supported',
                [
                    ['nominal_account_id' => 91004, 'debit' => 10.00, 'credit' => 0.00],
                    ['nominal_account_id' => 91001, 'debit' => 0.00, 'credit' => 10.00],
                ],
                'manual',
                null,
                null,
                null,
                'unit_test'
            );

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $harness->assertSame('manual', (string)($result['journal']['source_type'] ?? ''));
            $harness->assertSame(1, InterfaceDB::countWhere('journals', [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\VatSupportScopeService::class, 'both CT600 builders refuse qualifying LIVE HMRC VAT scope without generating a draft', static function () use ($harness): void {
        GoldenAccountsFixture::build();
        InterfaceDB::beginTransaction();
        $expectedPath = '';
        try {
            $marker = (string)random_int(100000, 999999);
            $companyId = (int)('75' . $marker);
            $accountingPeriodId = (int)('76' . $marker);
            $ctPeriodId = (int)('77' . $marker);
            InterfaceDB::prepareExecute(
                'INSERT INTO companies (
                    id, company_name, company_number, incorporation_date, company_status, is_active,
                    is_vat_registered, vat_validation_source, vat_validation_mode, vat_validation_status
                 ) VALUES (
                    :id, :company_name, :company_number, :incorporation_date, :company_status, 1,
                    1, :validation_source, :validation_mode, :validation_status
                 )',
                [
                    'id' => $companyId,
                    'company_name' => 'VAT CT600 Builder Guard Fixture Limited',
                    'company_number' => 'VC' . $marker,
                    'incorporation_date' => '2024-01-01',
                    'company_status' => 'active',
                    'validation_source' => 'hmrc',
                    'validation_mode' => 'LIVE',
                    'validation_status' => 'valid',
                ]
            );
            InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
                 VALUES (:id, :company_id, :label, :period_start, :period_end)',
                [
                    'id' => $accountingPeriodId,
                    'company_id' => $companyId,
                    'label' => 'VAT CT600 Guard ' . $marker,
                    'period_start' => '2024-01-01',
                    'period_end' => '2024-12-31',
                ]
            );
            InterfaceDB::prepareExecute(
                'INSERT INTO corporation_tax_periods (
                    id, company_id, accounting_period_id, sequence_no, period_start, period_end, status
                 ) VALUES (
                    :id, :company_id, :accounting_period_id, 1, :period_start, :period_end, :status
                 )',
                [
                    'id' => $ctPeriodId,
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'period_start' => '2024-01-01',
                    'period_end' => '2024-12-31',
                    'status' => 'pending',
                ]
            );

            $expectedPath = APP_ROOT . 'outbound' . DIRECTORY_SEPARATOR . 'hmrc' . DIRECTORY_SEPARATOR . 'ct600'
                . DIRECTORY_SEPARATOR . 'ct600_' . $companyId . '_' . $accountingPeriodId . '_1.xml';
            $harness->assertSame(false, file_exists($expectedPath));
            $beforePeriods = InterfaceDB::tableRowCount('corporation_tax_periods');
            $beforeRuns = InterfaceDB::tableRowCount('corporation_tax_computation_runs');
            $beforeSubmissions = InterfaceDB::tableRowCount('hmrc_ct600_submissions');

            $builder = new \eel_accounts\Service\Ct600BuilderService();
            foreach (['valid', 'mismatch_override'] as $status) {
                InterfaceDB::prepareExecute(
                    'UPDATE companies SET vat_validation_status = :status WHERE id = :id',
                    ['status' => $status, 'id' => $companyId]
                );
                $results = [
                    $builder->buildCt600Xml($companyId, $accountingPeriodId),
                    $builder->buildCt600XmlForCtPeriod($companyId, $ctPeriodId),
                ];
                foreach ($results as $result) {
                    $harness->assertSame(false, (bool)($result['ok'] ?? true));
                    $harness->assertSame(null, $result['path'] ?? null);
                    $harness->assertSame(403, (int)($result['status'] ?? 0));
                    $harness->assertSame(true, str_contains((string)($result['errors'][0] ?? ''), 'read only'));
                }
            }

            $harness->assertSame($beforePeriods, InterfaceDB::tableRowCount('corporation_tax_periods'));
            $harness->assertSame($beforeRuns, InterfaceDB::tableRowCount('corporation_tax_computation_runs'));
            $harness->assertSame($beforeSubmissions, InterfaceDB::tableRowCount('hmrc_ct600_submissions'));
            $harness->assertSame(false, file_exists($expectedPath));
        } finally {
            if ($expectedPath !== '' && file_exists($expectedPath)) {
                unlink($expectedPath);
            }
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\VatSupportScopeService::class, 'CT computation and period mutations fail closed when scope evaluation throws', static function () use ($harness): void {
        GoldenAccountsFixture::build();
        InterfaceDB::beginTransaction();
        try {
            $marker = (string)random_int(100000, 999999);
            $companyId = (int)('71' . $marker);
            $accountingPeriodId = (int)('72' . $marker);
            $ctPeriodId = (int)('73' . $marker);
            InterfaceDB::prepareExecute(
                'INSERT INTO companies (
                    id, company_name, company_number, incorporation_date, company_status, is_active,
                    is_vat_registered, vat_validation_source, vat_validation_mode, vat_validation_status
                 ) VALUES (
                    :id, :company_name, :company_number, :incorporation_date, :company_status, 1,
                    0, NULL, NULL, NULL
                 )',
                [
                    'id' => $companyId,
                    'company_name' => 'VAT Scope Exception Fixture Limited',
                    'company_number' => 'VE' . $marker,
                    'incorporation_date' => '2024-01-01',
                    'company_status' => 'active',
                ]
            );
            InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
                 VALUES (:id, :company_id, :label, :period_start, :period_end)',
                [
                    'id' => $accountingPeriodId,
                    'company_id' => $companyId,
                    'label' => 'VAT Scope Exception ' . $marker,
                    'period_start' => '2024-01-01',
                    'period_end' => '2024-12-31',
                ]
            );
            InterfaceDB::prepareExecute(
                'INSERT INTO corporation_tax_periods (
                    id, company_id, accounting_period_id, sequence_no, period_start, period_end, status
                 ) VALUES (
                    :id, :company_id, :accounting_period_id, 1, :period_start, :period_end, :status
                 )',
                [
                    'id' => $ctPeriodId,
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'period_start' => '2024-01-01',
                    'period_end' => '2024-12-31',
                    'status' => 'pending',
                ]
            );

            $throwingScope = static function (int $ignoredCompanyId): array {
                throw new RuntimeException('Simulated VAT scope lookup failure for ' . $ignoredCompanyId);
            };
            $beforePeriods = InterfaceDB::tableRowCount('corporation_tax_periods');
            $beforeRuns = InterfaceDB::tableRowCount('corporation_tax_computation_runs');

            $computation = new \eel_accounts\Service\CorporationTaxComputationService(
                vatSupportScopeFetcher: $throwingScope
            );
            $calculation = $computation->calculateSummaryForCtPeriodId($companyId, $ctPeriodId);
            $fetchedSummary = $computation->fetchSummaryForCtPeriodId($companyId, $ctPeriodId);
            $persistence = $computation->persistSummariesForYearEndLock($companyId, $accountingPeriodId);
            $activePeriods = $computation->activeCtPeriodsForAccountingPeriod($companyId, $accountingPeriodId);
            foreach ([$calculation, $fetchedSummary, $persistence] as $result) {
                $harness->assertSame(false, (bool)($result['available'] ?? $result['success'] ?? true));
                $harness->assertSame(
                    true,
                    str_contains((string)($result['errors'][0] ?? ''), 'could not be verified safely')
                );
                $harness->assertSame(true, (bool)($result['vat_support_scope']['scope_evaluation_failed'] ?? false));
            }
            $harness->assertSame([], (array)($activePeriods['periods'] ?? null));
            $harness->assertSame(
                true,
                str_contains((string)($activePeriods['errors'][0] ?? ''), 'could not be verified safely')
            );

            $periodService = new \eel_accounts\Service\CorporationTaxPeriodService($throwingScope);
            $sync = $periodService->syncForAccountingPeriod($companyId, $accountingPeriodId);
            $harness->assertSame(false, (bool)($sync['success'] ?? true));
            $harness->assertSame(true, str_contains((string)($sync['errors'][0] ?? ''), 'could not be verified safely'));

            $computationBlocked = false;
            try {
                $periodService->markLatestComputation($ctPeriodId, 987654);
            } catch (RuntimeException $exception) {
                $computationBlocked = str_contains($exception->getMessage(), 'could not be verified safely');
            }
            $submissionBlocked = false;
            try {
                $periodService->markLatestSubmission($ctPeriodId, 987654, 'accepted');
            } catch (RuntimeException $exception) {
                $submissionBlocked = str_contains($exception->getMessage(), 'could not be verified safely');
            }
            $harness->assertSame(true, $computationBlocked);
            $harness->assertSame(true, $submissionBlocked);

            $period = InterfaceDB::fetchOne(
                'SELECT status, latest_computation_run_id, latest_submission_id
                 FROM corporation_tax_periods WHERE id = :id',
                ['id' => $ctPeriodId]
            );
            $harness->assertSame('pending', (string)($period['status'] ?? ''));
            $harness->assertSame(null, $period['latest_computation_run_id'] ?? null);
            $harness->assertSame(null, $period['latest_submission_id'] ?? null);
            $harness->assertSame($beforePeriods, InterfaceDB::tableRowCount('corporation_tax_periods'));
            $harness->assertSame($beforeRuns, InterfaceDB::tableRowCount('corporation_tax_computation_runs'));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});
