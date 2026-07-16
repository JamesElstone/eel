<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'StandardNominalTestFixture.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'UploadedFileTestFixture.php';

if (!class_exists(\eel_accounts\Service\FormattingFramework::class, false)) {
    class_alias(FormattingFramework::class, \eel_accounts\Service\FormattingFramework::class);
}

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\AssetService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\AssetService $service): void {
        $harness->check(\eel_accounts\Service\AssetService::class, 'fixed asset schema is available', static function () use ($harness, $service): void {
            $harness->assertTrue(InterfaceDB::tableExists('asset_register'));
            $harness->assertTrue(InterfaceDB::tableExists('asset_depreciation_entries'));

            $pageData = $service->fetchPageData(0, 0);
            $harness->assertSame(true, $pageData['schema_ready'] ?? false);
            $harness->assertSame(true, $pageData['manual_schema_ready'] ?? false);
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'normalises blank default bank nominal from settings context', static function () use ($harness, $service): void {
            $pageData = $service->fetchPageData(0, 0, '');

            $harness->assertSame(0, $pageData['default_bank_nominal_id'] ?? null);
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'register assets stop at the earlier of today and the accounting period end', static function () use ($harness, $service): void {
            assetServiceTestRequireTaxViewSchema($harness);

            InterfaceDB::beginTransaction();
            try {
                $marker = (string)random_int(100000, 999999);
                $companyId = (int)('61' . $marker);
                $historicalPeriodId = (int)('62' . $marker);
                $futurePeriodId = (int)('63' . $marker);
                $assetNominalId = assetServiceTestInsertNominalWithTreatment('RCA', 'Register Cutoff Asset', 'asset', 'capital');
                $accumNominalId = assetServiceTestInsertNominalWithTreatment('RCD', 'Register Cutoff Accumulated', 'asset', 'capital');
                $today = new \DateTimeImmutable('today');

                InterfaceDB::prepareExecute(
                    'INSERT INTO companies (id, company_name, company_number, is_active)
                     VALUES (:id, :company_name, :company_number, 1)',
                    [
                        'id' => $companyId,
                        'company_name' => 'Register Cutoff Fixture ' . $marker,
                        'company_number' => 'RC' . $marker,
                    ]
                );

                foreach ([
                    [$historicalPeriodId, 'Historical cutoff', '1999-01-01', '2000-12-31'],
                    [$futurePeriodId, 'Future cutoff', '2999-01-01', '2999-12-31'],
                ] as $period) {
                    InterfaceDB::prepareExecute(
                        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
                         VALUES (:id, :company_id, :label, :period_start, :period_end)',
                        [
                            'id' => (int)$period[0],
                            'company_id' => $companyId,
                            'label' => (string)$period[1] . ' ' . $marker,
                            'period_start' => (string)$period[2],
                            'period_end' => (string)$period[3],
                        ]
                    );
                }

                foreach ([
                    [1, 'RC-BEFORE-' . $marker, '2000-12-30'],
                    [2, 'RC-ON-END-' . $marker, '2000-12-31'],
                    [3, 'RC-AFTER-END-' . $marker, '2001-01-01'],
                    [4, 'RC-TODAY-' . $marker, $today->format('Y-m-d')],
                    [5, 'RC-TOMORROW-' . $marker, $today->modify('+1 day')->format('Y-m-d')],
                ] as $asset) {
                    InterfaceDB::prepareExecute(
                        'INSERT INTO asset_register (
                            id, company_id, asset_code, description, category, nominal_account_id,
                            accum_dep_nominal_id, purchase_date, cost, useful_life_years,
                            depreciation_method, residual_value, status
                         ) VALUES (
                            :id, :company_id, :asset_code, :description, :category, :nominal_account_id,
                            :accum_dep_nominal_id, :purchase_date, :cost, :useful_life_years,
                            :depreciation_method, :residual_value, :status
                         )',
                        [
                            'id' => (int)('64' . substr($marker, 0, 5) . (string)$asset[0]),
                            'company_id' => $companyId,
                            'asset_code' => (string)$asset[1],
                            'description' => 'Register cutoff asset ' . (string)$asset[0],
                            'category' => 'tools_equipment',
                            'nominal_account_id' => $assetNominalId,
                            'accum_dep_nominal_id' => $accumNominalId,
                            'purchase_date' => (string)$asset[2],
                            'cost' => 100.00,
                            'useful_life_years' => 3,
                            'depreciation_method' => 'straight_line',
                            'residual_value' => 0.00,
                            'status' => 'active',
                        ]
                    );
                }

                $historicalCodes = array_column(
                    (array)($service->fetchRegisterData($companyId, $historicalPeriodId)['assets'] ?? []),
                    'asset_code'
                );
                $futureCodes = array_column(
                    (array)($service->fetchRegisterData($companyId, $futurePeriodId)['assets'] ?? []),
                    'asset_code'
                );

                $harness->assertSame(
                    ['RC-ON-END-' . $marker, 'RC-BEFORE-' . $marker],
                    $historicalCodes
                );
                $harness->assertTrue(in_array('RC-TODAY-' . $marker, $futureCodes, true));
                $harness->assertFalse(in_array('RC-TOMORROW-' . $marker, $futureCodes, true));
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'asset create categories keep vehicles under motor vehicle', static function () use ($harness): void {
            $allCategories = \eel_accounts\Service\AssetService::assetCategoryOptions();
            $createCategories = \eel_accounts\Service\AssetService::assetCreateCategoryOptions();

            $harness->assertSame('Motor Vehicle', $createCategories['motor_vehicle'] ?? null);
            $harness->assertTrue(array_key_exists('van', $allCategories));
            $harness->assertTrue(array_key_exists('car', $allCategories));
            $harness->assertSame(false, array_key_exists('van', $createCategories));
            $harness->assertSame(false, array_key_exists('car', $createCategories));
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'tax view uses dynamic corporation tax computation from capital allowance runs', static function () use ($harness, $service): void {
            assetServiceTestRequireTaxViewSchema($harness);
            $fixture = assetServiceTestCreateTaxViewFixture();

            $taxView = $service->fetchTaxView($fixture['company_id'], $fixture['accounting_period_id']);

            $harness->assertSame(1800.0, round((float)($taxView['accounting_profit'] ?? 0), 2));
            $harness->assertSame(200.0, round((float)($taxView['disallowable_add_backs'] ?? 0), 2));
            $harness->assertSame(1000.0, round((float)($taxView['capital_allowances'] ?? 0), 2));
            $harness->assertSame(1000.0, round((float)($taxView['taxable_before_losses'] ?? 0), 2));
            $harness->assertSame(1000.0, round((float)($taxView['taxable_profit'] ?? 0), 2));

            $runAllowance = round((float)InterfaceDB::fetchColumn(
                'SELECT COALESCE(SUM(aia_claimed + fya_claimed + wda_claimed + balancing_allowance - balancing_charge), 0)
                 FROM capital_allowance_pool_runs
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id',
                [
                    'company_id' => $fixture['company_id'],
                    'accounting_period_id' => $fixture['accounting_period_id'],
                ]
            ), 2);

            $harness->assertSame(1000.0, $runAllowance);
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'journal source enum supports asset postings', static function () use ($harness): void {
            if (InterfaceDB::driverName() === 'sqlite') {
                $schemaPath = PROJECT_ROOT . 'db_schema' . DIRECTORY_SEPARATOR . 'eel_accounts.schema.sql';
                $columnType = is_file($schemaPath) ? (string)file_get_contents($schemaPath) : '';
            } else {
                $columnType = (string)InterfaceDB::fetchColumn(
                    'SELECT COLUMN_TYPE
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = :table_name
                       AND COLUMN_NAME = :column_name
                     LIMIT 1',
                    ['table_name' => 'journals', 'column_name' => 'source_type']
                );
            }

            foreach (['asset_register', 'asset_depreciation', 'asset_disposal'] as $sourceType) {
                $harness->assertTrue(str_contains($columnType, "'" . $sourceType . "'"));
            }
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'none depreciation method posts no depreciation', static function () use ($harness, $service): void {
            $method = new ReflectionMethod(\eel_accounts\Service\AssetService::class, 'calculateDepreciationAmount');
            $method->setAccessible(true);

            $amount = $method->invoke($service, [
                'id' => 0,
                'depreciation_method' => 'none',
                'cost' => 1200,
                'residual_value' => 100,
                'useful_life_years' => 4,
            ], '2026-01-01', '2026-12-31');

            $harness->assertSame(0.0, $amount);
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'asset page data includes period depreciation for register rows', static function () use ($harness, $service): void {
            assetServiceTestRequireTaxViewSchema($harness);

            InterfaceDB::beginTransaction();
            try {
                $marker = (string)random_int(100000, 999999);
                $companyId = (int)('74' . $marker);
                $accountingPeriodId = (int)('75' . $marker);
                $assetId = (int)('76' . $marker);
                $assetNominalId = assetServiceTestInsertNominalWithTreatment('APD', 'Asset Period Depreciation Asset', 'asset', 'capital');
                $accumNominalId = assetServiceTestInsertNominalWithTreatment('APC', 'Asset Period Depreciation Accumulated', 'asset', 'capital');

                InterfaceDB::prepareExecute(
                    'INSERT INTO companies (id, company_name, company_number, is_active)
                     VALUES (:id, :company_name, :company_number, 1)',
                    [
                        'id' => $companyId,
                        'company_name' => 'Asset Period Depreciation Fixture ' . $marker,
                        'company_number' => 'AP' . $marker,
                    ]
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
                     VALUES (:id, :company_id, :label, :period_start, :period_end)',
                    [
                        'id' => $accountingPeriodId,
                        'company_id' => $companyId,
                        'label' => 'Asset Period Depreciation FY ' . $marker,
                        'period_start' => '2020-01-01',
                        'period_end' => '2020-12-31',
                    ]
                );
                $laterAccountingPeriodId = (int)('77' . $marker);
                InterfaceDB::prepareExecute(
                    'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
                     VALUES (:id, :company_id, :label, :period_start, :period_end)',
                    [
                        'id' => $laterAccountingPeriodId,
                        'company_id' => $companyId,
                        'label' => 'Asset Period Depreciation Later FY ' . $marker,
                        'period_start' => '2025-01-01',
                        'period_end' => '2025-12-31',
                    ]
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO asset_register (
                        id,
                        company_id,
                        asset_code,
                        description,
                        category,
                        nominal_account_id,
                        accum_dep_nominal_id,
                        purchase_date,
                        cost,
                        useful_life_years,
                        depreciation_method,
                        residual_value,
                        status
                     ) VALUES (
                        :id,
                        :company_id,
                        :asset_code,
                        :description,
                        :category,
                        :nominal_account_id,
                        :accum_dep_nominal_id,
                        :purchase_date,
                        :cost,
                        :useful_life_years,
                        :depreciation_method,
                        :residual_value,
                        :status
                     )',
                    [
                        'id' => $assetId,
                        'company_id' => $companyId,
                        'asset_code' => 'APD-' . $marker,
                        'description' => 'Asset period depreciation fixture ' . $marker,
                        'category' => 'tools_equipment',
                        'nominal_account_id' => $assetNominalId,
                        'accum_dep_nominal_id' => $accumNominalId,
                        'purchase_date' => '2020-07-01',
                        'cost' => 1200.00,
                        'useful_life_years' => 3,
                        'depreciation_method' => 'straight_line',
                        'residual_value' => 120.00,
                        'status' => 'active',
                    ]
                );

                $pageData = $service->fetchPageData($companyId, $accountingPeriodId);
                $asset = (array)(($pageData['assets'] ?? [])[0] ?? []);

                $harness->assertSame($assetId, (int)($asset['id'] ?? 0));
                $harness->assertSame('181.48', number_format((float)($asset['period_depreciation'] ?? 0), 2, '.', ''));
                $harness->assertSame('1018.52', number_format((float)($asset['resale_value'] ?? 0), 2, '.', ''));
                $harness->assertSame('120.00', number_format((float)($asset['residual_value'] ?? 0), 2, '.', ''));

                $laterPageData = $service->fetchPageData($companyId, $laterAccountingPeriodId);
                $laterAsset = (array)(($laterPageData['assets'] ?? [])[0] ?? []);
                $harness->assertSame($assetId, (int)($laterAsset['id'] ?? 0));
                $harness->assertSame('0.00', number_format((float)($laterAsset['period_depreciation'] ?? 0), 2, '.', ''));
                $harness->assertSame('120.00', number_format((float)($laterAsset['resale_value'] ?? 0), 2, '.', ''));
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'register period depreciation uses batched opening depreciation totals', static function () use ($harness, $service): void {
            assetServiceTestRequireTaxViewSchema($harness);

            InterfaceDB::beginTransaction();
            try {
                $marker = (string)random_int(100000, 999999);
                $companyId = (int)('84' . $marker);
                $priorPeriodId = (int)('85' . $marker);
                $accountingPeriodId = (int)('86' . $marker);
                $assetId = (int)('87' . $marker);
                $assetNominalId = assetServiceTestInsertNominalWithTreatment('RBA', 'Reducing Balance Asset', 'asset', 'capital');
                $accumNominalId = assetServiceTestInsertNominalWithTreatment('RBD', 'Reducing Balance Accumulated', 'asset', 'capital');

                InterfaceDB::prepareExecute(
                    'INSERT INTO companies (id, company_name, company_number, is_active)
                     VALUES (:id, :company_name, :company_number, 1)',
                    [
                        'id' => $companyId,
                        'company_name' => 'Reducing Balance Fixture ' . $marker,
                        'company_number' => 'RB' . $marker,
                    ]
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
                     VALUES (:id, :company_id, :label, :period_start, :period_end)',
                    [
                        'id' => $priorPeriodId,
                        'company_id' => $companyId,
                        'label' => 'Reducing Balance Prior FY ' . $marker,
                        'period_start' => '2019-01-01',
                        'period_end' => '2019-12-31',
                    ]
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
                     VALUES (:id, :company_id, :label, :period_start, :period_end)',
                    [
                        'id' => $accountingPeriodId,
                        'company_id' => $companyId,
                        'label' => 'Reducing Balance Current FY ' . $marker,
                        'period_start' => '2020-01-01',
                        'period_end' => '2020-12-31',
                    ]
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO asset_register (
                        id,
                        company_id,
                        asset_code,
                        description,
                        category,
                        nominal_account_id,
                        accum_dep_nominal_id,
                        purchase_date,
                        cost,
                        useful_life_years,
                        depreciation_method,
                        residual_value,
                        status
                     ) VALUES (
                        :id,
                        :company_id,
                        :asset_code,
                        :description,
                        :category,
                        :nominal_account_id,
                        :accum_dep_nominal_id,
                        :purchase_date,
                        :cost,
                        :useful_life_years,
                        :depreciation_method,
                        :residual_value,
                        :status
                     )',
                    [
                        'id' => $assetId,
                        'company_id' => $companyId,
                        'asset_code' => 'RBD-' . $marker,
                        'description' => 'Reducing balance register fixture ' . $marker,
                        'category' => 'tools_equipment',
                        'nominal_account_id' => $assetNominalId,
                        'accum_dep_nominal_id' => $accumNominalId,
                        'purchase_date' => '2019-01-01',
                        'cost' => 1200.00,
                        'useful_life_years' => 3,
                        'depreciation_method' => 'reducing_balance',
                        'residual_value' => 0.00,
                        'status' => 'active',
                    ]
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO asset_depreciation_entries (asset_id, accounting_period_id, period_start, period_end, amount)
                     VALUES (:asset_id, :accounting_period_id, :period_start, :period_end, :amount)',
                    [
                        'asset_id' => $assetId,
                        'accounting_period_id' => $priorPeriodId,
                        'period_start' => '2019-01-01',
                        'period_end' => '2019-12-31',
                        'amount' => 400.00,
                    ]
                );

                $pageData = $service->fetchPageData($companyId, $accountingPeriodId);
                $asset = (array)(($pageData['assets'] ?? [])[0] ?? []);

                $harness->assertSame($assetId, (int)($asset['id'] ?? 0));
                $harness->assertSame('400.00', number_format((float)($asset['accumulated_depreciation'] ?? 0), 2, '.', ''));
                $harness->assertSame('266.67', number_format((float)($asset['period_depreciation'] ?? 0), 2, '.', ''));
                $harness->assertSame('533.33', number_format((float)($asset['resale_value'] ?? 0), 2, '.', ''));
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'open accounting period blocks depreciation posting', static function () use ($harness, $service): void {
            assetServiceTestRequireTaxViewSchema($harness);
            $marker = (string)random_int(100000, 999999);
            $companyId = (int)('71' . $marker);
            $accountingPeriodId = (int)('72' . $marker);

            InterfaceDB::prepareExecute(
                'INSERT INTO companies (id, company_name, company_number, is_active)
                 VALUES (:id, :company_name, :company_number, 1)',
                [
                    'id' => $companyId,
                    'company_name' => 'Future Depreciation Fixture ' . $marker,
                    'company_number' => 'FD' . substr($marker, 0, 6),
                ]
            );
            InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
                 VALUES (:id, :company_id, :label, :period_start, :period_end)',
                [
                    'id' => $accountingPeriodId,
                    'company_id' => $companyId,
                    'label' => 'Future depreciation FY ' . $marker,
                    'period_start' => '2999-01-01',
                    'period_end' => '2999-12-31',
                ]
            );

            $result = $service->runDepreciation($companyId, $accountingPeriodId);

            $harness->assertSame(false, (bool)($result['success'] ?? true));
            $harness->assertTrue(str_contains((string)(($result['errors'] ?? [])[0] ?? ''), 'after the accounting period end date'));
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'out-of-order depreciation posting matches each accounting-period preview without catch-up', static function () use ($harness, $service): void {
            assetServiceTestRequireTaxViewSchema($harness);

            InterfaceDB::beginTransaction();
            try {
                StandardNominalTestFixture::ensureNominals(['1300', '1330', '6200']);
                $marker = (string)random_int(100000, 999999);
                $companyId = (int)('91' . $marker);
                $firstPeriodId = (int)('92' . $marker);
                $secondPeriodId = (int)('93' . $marker);
                $assetId = (int)('94' . $marker);
                InterfaceDB::prepareExecute(
                    'INSERT INTO companies (id, company_name, company_number, is_active)
                     VALUES (:id, :company_name, :company_number, 1)',
                    [
                        'id' => $companyId,
                        'company_name' => 'Period-specific depreciation fixture ' . $marker,
                        'company_number' => 'PD' . $marker,
                    ]
                );
                foreach ([
                    [$firstPeriodId, '2022-01-01', '2022-12-31'],
                    [$secondPeriodId, '2023-01-01', '2023-12-31'],
                ] as [$periodId, $periodStart, $periodEnd]) {
                    InterfaceDB::prepareExecute(
                        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
                         VALUES (:id, :company_id, :label, :period_start, :period_end)',
                        [
                            'id' => $periodId,
                            'company_id' => $companyId,
                            'label' => 'Period-specific depreciation ' . $periodEnd,
                            'period_start' => $periodStart,
                            'period_end' => $periodEnd,
                        ]
                    );
                }
                InterfaceDB::prepareExecute(
                    'INSERT INTO asset_register (
                        id, company_id, asset_code, description, category,
                        nominal_account_id, accum_dep_nominal_id, purchase_date,
                        cost, useful_life_years, depreciation_method,
                        residual_value, status
                     ) VALUES (
                        :id, :company_id, :asset_code, :description, :category,
                        :nominal_account_id, :accum_dep_nominal_id, :purchase_date,
                        :cost, :useful_life_years, :depreciation_method,
                        :residual_value, :status
                     )',
                    [
                        'id' => $assetId,
                        'company_id' => $companyId,
                        'asset_code' => 'PD-' . $marker,
                        'description' => 'Period-specific depreciation asset',
                        'category' => 'tools_equipment',
                        'nominal_account_id' => StandardNominalTestFixture::id('1300'),
                        'accum_dep_nominal_id' => StandardNominalTestFixture::id('1330'),
                        'purchase_date' => '2022-01-01',
                        'cost' => 730.00,
                        'useful_life_years' => 2,
                        'depreciation_method' => 'straight_line',
                        'residual_value' => 0.00,
                        'status' => 'active',
                    ]
                );

                $firstPreview = $service->previewDepreciationRun($companyId, $firstPeriodId);
                $secondPreview = $service->previewDepreciationRun($companyId, $secondPeriodId);
                $harness->assertTrue(!empty($firstPreview['success']));
                $harness->assertTrue(!empty($secondPreview['success']));

                $secondPost = $service->runDepreciation($companyId, $secondPeriodId);
                $harness->assertTrue(!empty($secondPost['success']));
                $harness->assertSame(1, (int)($secondPost['created'] ?? 0));
                $secondPostedAmount = (float)InterfaceDB::fetchColumn(
                    'SELECT COALESCE(SUM(amount), 0)
                     FROM asset_depreciation_entries
                     WHERE asset_id = :asset_id
                       AND accounting_period_id = :accounting_period_id',
                    [
                        'asset_id' => $assetId,
                        'accounting_period_id' => $secondPeriodId,
                    ]
                );
                $harness->assertSame(
                    number_format((float)($secondPreview['total_amount'] ?? 0), 2, '.', ''),
                    number_format($secondPostedAmount, 2, '.', '')
                );

                $firstStillPending = $service->previewDepreciationRun($companyId, $firstPeriodId);
                $harness->assertSame(
                    number_format((float)($firstPreview['total_amount'] ?? 0), 2, '.', ''),
                    number_format((float)($firstStillPending['total_amount'] ?? 0), 2, '.', '')
                );
                $firstPost = $service->runDepreciation($companyId, $firstPeriodId);
                $harness->assertTrue(!empty($firstPost['success']));
                $harness->assertSame(1, (int)($firstPost['created'] ?? 0));
                $harness->assertSame(
                    '0.00',
                    number_format(
                        (float)($service->previewDepreciationRun($companyId, $secondPeriodId)['total_amount'] ?? 0),
                        2,
                        '.',
                        ''
                    )
                );
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'partial interval depreciation is topped up by the preview residual and remains idempotent', static function () use ($harness, $service): void {
            assetServiceTestRequireTaxViewSchema($harness);

            InterfaceDB::beginTransaction();
            try {
                $fixture = assetServiceTestCreateDepreciationFixture(
                    'partial-top-up',
                    [
                        ['period_start' => '2022-01-01', 'period_end' => '2022-12-31'],
                    ],
                    '2022-01-01',
                    730.00,
                    2
                );
                $periodId = (int)$fixture['accounting_period_ids'][0];
                $assetId = (int)$fixture['asset_id'];
                $periodStart = '2022-01-01';
                $periodEnd = '2022-12-31';
                $fullPreview = $service->previewDepreciationRun((int)$fixture['company_id'], $periodId);
                $fullAmount = round((float)($fullPreview['total_amount'] ?? 0), 2);
                $partialAmount = 100.00;

                InterfaceDB::prepareExecute(
                    'INSERT INTO journals (
                        company_id, accounting_period_id, source_type, source_ref,
                        journal_date, description, is_posted
                     ) VALUES (
                        :company_id, :accounting_period_id, :source_type, :source_ref,
                        :journal_date, :description, 1
                     )',
                    [
                        'company_id' => $fixture['company_id'],
                        'accounting_period_id' => $periodId,
                        'source_type' => 'asset_depreciation',
                        'source_ref' => 'asset:' . $assetId . ':depreciation:' . $periodId . ':' . $periodStart . ':' . $periodEnd,
                        'journal_date' => $periodEnd,
                        'description' => 'Partial depreciation fixture',
                    ]
                );
                $partialJournalId = (int)InterfaceDB::fetchColumn(
                    'SELECT id
                     FROM journals
                     WHERE company_id = :company_id
                       AND source_ref = :source_ref
                     ORDER BY id DESC
                     LIMIT 1',
                    [
                        'company_id' => $fixture['company_id'],
                        'source_ref' => 'asset:' . $assetId . ':depreciation:' . $periodId . ':' . $periodStart . ':' . $periodEnd,
                    ]
                );
                foreach ([
                    [StandardNominalTestFixture::id('6200'), $partialAmount, 0.00],
                    [StandardNominalTestFixture::id('1330'), 0.00, $partialAmount],
                ] as [$nominalId, $debit, $credit]) {
                    InterfaceDB::prepareExecute(
                        'INSERT INTO journal_lines (
                            journal_id, nominal_account_id, debit, credit, line_description
                         ) VALUES (
                            :journal_id, :nominal_account_id, :debit, :credit, :line_description
                         )',
                        [
                            'journal_id' => $partialJournalId,
                            'nominal_account_id' => $nominalId,
                            'debit' => $debit,
                            'credit' => $credit,
                            'line_description' => 'Partial depreciation fixture',
                        ]
                    );
                }
                InterfaceDB::prepareExecute(
                    'INSERT INTO asset_depreciation_entries (
                        asset_id, accounting_period_id, period_start, period_end, amount, journal_id
                     ) VALUES (
                        :asset_id, :accounting_period_id, :period_start, :period_end, :amount, :journal_id
                     )',
                    [
                        'asset_id' => $assetId,
                        'accounting_period_id' => $periodId,
                        'period_start' => $periodStart,
                        'period_end' => $periodEnd,
                        'amount' => $partialAmount,
                        'journal_id' => $partialJournalId,
                    ]
                );

                $residualPreview = $service->previewDepreciationRun((int)$fixture['company_id'], $periodId);
                $expectedResidual = round($fullAmount - $partialAmount, 2);
                $harness->assertSame(
                    number_format($expectedResidual, 2, '.', ''),
                    number_format((float)($residualPreview['total_amount'] ?? 0), 2, '.', '')
                );

                $posted = $service->runDepreciation((int)$fixture['company_id'], $periodId);
                $harness->assertTrue(!empty($posted['success']));
                $harness->assertSame(1, (int)($posted['created'] ?? 0));

                $entries = InterfaceDB::fetchAll(
                    'SELECT period_start, period_end, amount, journal_id
                     FROM asset_depreciation_entries
                     WHERE asset_id = :asset_id
                       AND accounting_period_id = :accounting_period_id
                     ORDER BY id',
                    [
                        'asset_id' => $assetId,
                        'accounting_period_id' => $periodId,
                    ]
                );
                $harness->assertCount(2, $entries);
                $harness->assertSame(
                    number_format($fullAmount, 2, '.', ''),
                    number_format(array_sum(array_map(
                        static fn(array $row): float => (float)($row['amount'] ?? 0),
                        $entries
                    )), 2, '.', '')
                );
                $harness->assertSame(
                    number_format($expectedResidual, 2, '.', ''),
                    number_format((float)($entries[1]['amount'] ?? 0), 2, '.', '')
                );
                $harness->assertSame($periodEnd, (string)($entries[1]['period_start'] ?? ''));
                $harness->assertSame($periodEnd, (string)($entries[1]['period_end'] ?? ''));
                $harness->assertTrue((int)($entries[1]['journal_id'] ?? 0) > 0);

                $retry = $service->runDepreciation((int)$fixture['company_id'], $periodId);
                $harness->assertTrue(!empty($retry['success']));
                $harness->assertSame(0, (int)($retry['created'] ?? 0));
                $harness->assertSame('0.00', number_format(
                    (float)($service->previewDepreciationRun((int)$fixture['company_id'], $periodId)['total_amount'] ?? 0),
                    2,
                    '.',
                    ''
                ));
                $harness->assertSame(2, (int)InterfaceDB::fetchColumn(
                    'SELECT COUNT(*)
                     FROM asset_depreciation_entries
                     WHERE asset_id = :asset_id',
                    ['asset_id' => $assetId]
                ));
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'manual asset offset nominal must use a funding subtype', static function () use ($harness, $service): void {
            foreach (['nominal_accounts', 'nominal_account_subtypes'] as $table) {
                if (!InterfaceDB::tableExists($table)) {
                    $harness->skip($table . ' table is not available on the default InterfaceDB connection.');
                }
            }

            $bankNominalId = assetServiceTestInsertNominal('BNK', 'Bank Offset Candidate', 'asset', 'bank');
            $fixedAssetNominalId = assetServiceTestInsertNominal('FIX', 'Fixed Asset Offset Candidate', 'asset', 'fixed_asset');
            $expenseNominalId = assetServiceTestInsertNominal('EXP', 'Expense Offset Candidate', 'expense', 'overhead');

            $method = new ReflectionMethod(\eel_accounts\Service\AssetService::class, 'isManualAssetOffsetNominal');
            $method->setAccessible(true);

            $harness->assertSame(true, $method->invoke($service, $bankNominalId));
            $harness->assertSame(false, $method->invoke($service, $fixedAssetNominalId));
            $harness->assertSame(false, $method->invoke($service, $expenseNominalId));
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'manual asset creation stores reason and offset nominal', static function () use ($harness, $service): void {
            assetServiceTestRequireDisposalSchema($harness);
            $fixture = assetServiceTestCreateDisposalFixture('manual-store');
            $offsetNominalId = assetServiceTestInsertNominal('CLR', 'Manual Asset Clearing', 'liability', 'trade_creditor');

            $result = $service->createManualAsset(
                $fixture['company_id'],
                $fixture['accounting_period_id'],
                [
                    'description' => 'Manual drill ' . $fixture['marker'],
                    'category' => 'tools_equipment',
                    'purchase_date' => '2026-07-01',
                    'cost' => '240.00',
                    'useful_life_years' => '3',
                    'depreciation_method' => 'straight_line',
                    'residual_value' => '0.00',
                    'manual_addition_reason' => 'delayed_bank_csv',
                    'manual_asset_legal_acknowledged' => '1',
                ],
                $offsetNominalId,
                UploadedFileTestFixture::jpegUpload()
            );

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $asset = InterfaceDB::fetchOne(
                'SELECT manual_addition_reason, manual_offset_nominal_id, linked_transaction_id, linked_journal_id
                 FROM asset_register
                 WHERE id = :id',
                ['id' => (int)($result['asset']['id'] ?? 0)]
            );
            $harness->assertSame('delayed_bank_csv', (string)($asset['manual_addition_reason'] ?? ''));
            $harness->assertSame($offsetNominalId, (int)($asset['manual_offset_nominal_id'] ?? 0));
            $harness->assertSame(0, (int)($asset['linked_transaction_id'] ?? 0));
            $harness->assertTrue((int)($asset['linked_journal_id'] ?? 0) > 0);
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'manual reconciliation list excludes opening assets', static function () use ($harness, $service): void {
            assetServiceTestRequireDisposalSchema($harness);
            $fixture = assetServiceTestCreateDisposalFixture('manual-list');
            $offsetNominalId = assetServiceTestInsertNominal('CLR', 'Manual Asset Clearing', 'liability', 'trade_creditor');

            $pending = $service->createManualAsset(
                $fixture['company_id'],
                $fixture['accounting_period_id'],
                [
                    'description' => 'Pending supplier asset ' . $fixture['marker'],
                    'category' => 'tools_equipment',
                    'purchase_date' => '2026-07-01',
                    'cost' => '240.00',
                    'useful_life_years' => '3',
                    'depreciation_method' => 'straight_line',
                    'residual_value' => '0.00',
                    'manual_addition_reason' => 'supplier_invoice_pending_payment',
                    'manual_asset_legal_acknowledged' => '1',
                ],
                $offsetNominalId,
                UploadedFileTestFixture::jpegUpload('pending-asset.jpg')
            );
            $opening = $service->createManualAsset(
                $fixture['company_id'],
                $fixture['accounting_period_id'],
                [
                    'description' => 'Opening asset ' . $fixture['marker'],
                    'category' => 'tools_equipment',
                    'purchase_date' => '2026-07-01',
                    'cost' => '150.00',
                    'useful_life_years' => '3',
                    'depreciation_method' => 'straight_line',
                    'residual_value' => '0.00',
                    'manual_addition_reason' => 'opening_or_historical_asset',
                    'manual_asset_legal_acknowledged' => '1',
                ],
                $offsetNominalId,
                UploadedFileTestFixture::jpegUpload('opening-asset.jpg')
            );

            $harness->assertSame(true, (bool)($pending['success'] ?? false));
            $harness->assertSame(true, (bool)($opening['success'] ?? false));

            $assets = $service->fetchManualAssetsNeedingReconciliation($fixture['company_id']);
            $ids = array_map(static fn(array $asset): int => (int)$asset['id'], $assets);

            $harness->assertTrue(in_array((int)($pending['asset']['id'] ?? 0), $ids, true));
            $harness->assertSame(false, in_array((int)($opening['asset']['id'] ?? 0), $ids, true));
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'manual reconciliation categorises transaction and links asset', static function () use ($harness, $service): void {
            assetServiceTestRequireDisposalSchema($harness);
            $fixture = assetServiceTestCreateDisposalFixture('manual-reconcile');
            $offsetNominalId = assetServiceTestInsertNominal('CLR', 'Manual Asset Clearing', 'liability', 'trade_creditor');
            $transactionId = assetServiceTestInsertTransaction($fixture, 1, '2026-07-03', -240.00, 'Manual asset payment');
            $created = $service->createManualAsset(
                $fixture['company_id'],
                $fixture['accounting_period_id'],
                [
                    'description' => 'Reconcilable asset ' . $fixture['marker'],
                    'category' => 'tools_equipment',
                    'purchase_date' => '2026-07-01',
                    'cost' => '240.00',
                    'useful_life_years' => '3',
                    'depreciation_method' => 'straight_line',
                    'residual_value' => '0.00',
                    'manual_addition_reason' => 'delayed_bank_csv',
                    'manual_asset_legal_acknowledged' => '1',
                ],
                $offsetNominalId,
                UploadedFileTestFixture::jpegUpload('reconciliation-asset.jpg')
            );

            $harness->assertSame(true, (bool)($created['success'] ?? false));
            $candidateData = $service->fetchManualAssetReconciliationData($fixture['company_id']);
            $candidateIds = [];
            foreach ((array)($candidateData['assets'] ?? []) as $asset) {
                foreach ((array)($asset['candidates'] ?? []) as $candidate) {
                    $candidateIds[] = (int)($candidate['id'] ?? 0);
                }
            }
            $harness->assertTrue(in_array($transactionId, $candidateIds, true));

            $result = $service->reconcileManualAssetWithTransaction(
                $fixture['company_id'],
                (int)($created['asset']['id'] ?? 0),
                $transactionId,
                $fixture['bank_nominal_id'],
                true
            );

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $transaction = InterfaceDB::fetchOne(
                'SELECT nominal_account_id, category_status
                 FROM transactions
                 WHERE id = :id',
                ['id' => $transactionId]
            );
            $asset = InterfaceDB::fetchOne(
                'SELECT linked_transaction_id
                 FROM asset_register
                 WHERE id = :id',
                ['id' => (int)($created['asset']['id'] ?? 0)]
            );
            $bankJournalId = assetServiceTestJournalId($fixture['company_id'], 'bank_csv', 'transaction:' . $transactionId);

            $harness->assertSame($offsetNominalId, (int)($transaction['nominal_account_id'] ?? 0));
            $harness->assertSame('manual', (string)($transaction['category_status'] ?? ''));
            $harness->assertSame($transactionId, (int)($asset['linked_transaction_id'] ?? 0));
            $harness->assertTrue($bankJournalId > 0);
            $harness->assertSame(240.0, assetServiceTestJournalLineAmount($bankJournalId, $offsetNominalId, 'debit'));
            $harness->assertSame(240.0, assetServiceTestJournalLineAmount($bankJournalId, $fixture['bank_nominal_id'], 'credit'));
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'transaction-created assets do not store manual reason', static function () use ($harness, $service): void {
            assetServiceTestRequireDisposalSchema($harness);
            $fixture = assetServiceTestCreateDisposalFixture('transaction-create');
            $transactionId = assetServiceTestInsertTransaction($fixture, 1, '2026-07-03', -180.00, 'Imported asset payment');

            $result = $service->createAssetFromTransaction(
                $fixture['company_id'],
                $transactionId,
                [
                    'description' => 'Imported asset ' . $fixture['marker'],
                    'category' => 'tools_equipment',
                    'purchase_date' => '2026-07-03',
                    'cost' => '180.00',
                    'useful_life_years' => '3',
                    'depreciation_method' => 'straight_line',
                    'residual_value' => '0.00',
                ],
                $fixture['bank_nominal_id']
            );

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $asset = InterfaceDB::fetchOne(
                'SELECT manual_addition_reason, manual_offset_nominal_id, linked_transaction_id, linked_journal_id
                 FROM asset_register
                 WHERE id = :id',
                ['id' => (int)($result['asset']['id'] ?? 0)]
            );
            $bankJournalId = assetServiceTestJournalId($fixture['company_id'], 'bank_csv', 'transaction:' . $transactionId);

            $harness->assertSame('', (string)($asset['manual_addition_reason'] ?? ''));
            $harness->assertSame(0, (int)($asset['manual_offset_nominal_id'] ?? 0));
            $harness->assertSame($transactionId, (int)($asset['linked_transaction_id'] ?? 0));
            $harness->assertSame($bankJournalId, (int)($asset['linked_journal_id'] ?? 0));
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'split-line asset prefill and creation link exact split line', static function () use ($harness, $service): void {
            assetServiceTestRequireDisposalSchema($harness);
            foreach (['transaction_splits', 'transaction_split_lines'] as $table) {
                if (!InterfaceDB::tableExists($table)) {
                    $harness->skip($table . ' table is not available.');
                }
            }
            if (!InterfaceDB::columnExists('asset_register', 'linked_transaction_split_line_id')) {
                $harness->skip('asset_register.linked_transaction_split_line_id column is not available.');
            }

            $fixture = assetServiceTestCreateDisposalFixture('split-line-asset');
            $transactionId = assetServiceTestInsertTransaction($fixture, 1, '2026-07-03', -146.36, 'AMZNMKTPLACE');
            $toolNominalId = assetServiceTestNominalId('1300');
            $materialsNominalId = assetServiceTestEnsureNominalId('5000', 'Fixture Materials', 'cost_of_sales', 'allowable');
            $splitService = new \eel_accounts\Service\TransactionSplitService();
            $splitService->startSplit($fixture['company_id'], $transactionId);
            $split = (array)$splitService->fetchSplitForTransaction($transactionId);
            $lines = array_values((array)($split['lines'] ?? []));
            $toolLineId = (int)$lines[0]['id'];
            $materialsLineId = (int)$lines[1]['id'];

            $splitService->saveLine($fixture['company_id'], $toolLineId, [
                'split_line_description' => 'AMZNMKTPLACE tool item',
                'split_line_amount' => '89.99',
                'nominal_account_id' => '',
            ]);
            $splitService->saveLine($fixture['company_id'], $materialsLineId, [
                'split_line_description' => 'AMZNMKTPLACE materials',
                'split_line_amount' => '56.37',
                'nominal_account_id' => $materialsNominalId,
            ]);
            $draftSplit = (array)$splitService->fetchSplitForTransaction($transactionId);
            $harness->assertSame('0.00', (string)($draftSplit['difference'] ?? ''));
            $harness->assertSame(0, (int)($draftSplit['is_ready'] ?? 1));

            $createData = $service->fetchCreateData($fixture['company_id'], $fixture['accounting_period_id'], $fixture['bank_nominal_id'], 0, $toolLineId);
            $prefill = (array)($createData['prefill_transaction'] ?? []);
            $harness->assertSame($toolLineId, (int)($prefill['transaction_split_line_id'] ?? 0));
            $harness->assertSame('89.99', (string)($prefill['cost'] ?? ''));
            $harness->assertSame(0, (int)($prefill['nominal_account_id'] ?? -1));

            $result = $service->createAssetFromTransactionSplitLine(
                $fixture['company_id'],
                $toolLineId,
                [
                    'description' => 'AMZNMKTPLACE tool item',
                    'category' => 'tools_equipment',
                    'purchase_date' => '2026-07-03',
                    'cost' => '89.99',
                    'useful_life_years' => '3',
                    'depreciation_method' => 'straight_line',
                    'residual_value' => '0.00',
                ],
                $fixture['bank_nominal_id']
            );

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $asset = InterfaceDB::fetchOne(
                'SELECT linked_transaction_id, linked_transaction_split_line_id, cost, nominal_account_id
                 FROM asset_register
                 WHERE id = :id',
                ['id' => (int)($result['asset']['id'] ?? 0)]
            );
            $journalId = assetServiceTestJournalId($fixture['company_id'], 'bank_csv', 'transaction:' . $transactionId);
            $toolLine = InterfaceDB::fetchOne(
                'SELECT nominal_account_id
                 FROM transaction_split_lines
                 WHERE id = :id',
                ['id' => $toolLineId]
            );

            $harness->assertSame($transactionId, (int)($asset['linked_transaction_id'] ?? 0));
            $harness->assertSame($toolLineId, (int)($asset['linked_transaction_split_line_id'] ?? 0));
            $harness->assertSame('89.99', number_format((float)($asset['cost'] ?? 0), 2, '.', ''));
            $harness->assertSame($toolNominalId, (int)($asset['nominal_account_id'] ?? 0));
            $harness->assertSame($toolNominalId, (int)($toolLine['nominal_account_id'] ?? 0));
            $harness->assertTrue($journalId > 0);
            $harness->assertSame(89.99, assetServiceTestJournalLineAmount($journalId, $toolNominalId, 'debit'));
            $harness->assertSame(56.37, assetServiceTestJournalLineAmount($journalId, $materialsNominalId, 'debit'));
            $harness->assertSame(146.36, assetServiceTestJournalLineAmount($journalId, $fixture['bank_nominal_id'], 'credit'));
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'non-asset transaction conversion routes through transaction asset creation', static function () use ($harness, $service): void {
            assetServiceTestRequireDisposalSchema($harness);
            $fixture = assetServiceTestCreateDisposalFixture('non-asset-transaction');
            $transactionId = assetServiceTestInsertTransaction($fixture, 1, '2026-07-03', -181.00, 'Non asset transaction');

            $result = $service->convertNonAssetToAsset(
                $fixture['company_id'],
                'transaction',
                $transactionId,
                [
                    'description' => 'Non asset transaction ' . $fixture['marker'],
                    'asset_category' => 'tools_equipment',
                    'purchase_date' => '2026-07-03',
                    'cost' => '181.00',
                    'asset_useful_life_years' => '3',
                    'asset_depreciation_method' => 'straight_line',
                    'asset_residual_value' => '0.00',
                ],
                $fixture['bank_nominal_id']
            );

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $asset = InterfaceDB::fetchOne(
                'SELECT linked_transaction_id, nominal_account_id
                 FROM asset_register
                 WHERE id = :id',
                ['id' => (int)($result['asset']['id'] ?? 0)]
            );
            $transaction = InterfaceDB::fetchOne(
                'SELECT nominal_account_id, category_status
                 FROM transactions
                 WHERE id = :id',
                ['id' => $transactionId]
            );

            $harness->assertSame($transactionId, (int)($asset['linked_transaction_id'] ?? 0));
            $harness->assertSame(assetServiceTestNominalId('1300'), (int)($asset['nominal_account_id'] ?? 0));
            $harness->assertSame(assetServiceTestNominalId('1300'), (int)($transaction['nominal_account_id'] ?? 0));
            $harness->assertSame('manual', (string)($transaction['category_status'] ?? ''));
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'disposal receipt search uses one day before and three days after', static function () use ($harness, $service): void {
            assetServiceTestRequireDisposalSchema($harness);
            $fixture = assetServiceTestCreateDisposalFixture('search');
            $insideBeforeId = assetServiceTestInsertTransaction($fixture, 1, '2026-06-30', 100.00, 'Inside before');
            $insideAfterId = assetServiceTestInsertTransaction($fixture, 2, '2026-07-04', 110.00, 'Inside after');
            assetServiceTestInsertTransaction($fixture, 3, '2026-06-29', 120.00, 'Too early');
            assetServiceTestInsertTransaction($fixture, 4, '2026-07-05', 130.00, 'Too late');
            assetServiceTestInsertTransaction($fixture, 5, '2026-07-01', -50.00, 'Outgoing');
            assetServiceTestInsertTransaction($fixture, 6, '2026-07-01', 60.00, 'Transfer', 1);
            $linkedTransactionId = assetServiceTestInsertTransaction($fixture, 7, '2026-07-01', 70.00, 'Already linked');
            InterfaceDB::prepareExecute(
                'INSERT INTO asset_disposal_transaction_links (asset_id, transaction_id, linked_amount)
                 VALUES (:asset_id, :transaction_id, :linked_amount)',
                [
                    'asset_id' => $fixture['asset_id'],
                    'transaction_id' => $linkedTransactionId,
                    'linked_amount' => 70.00,
                ]
            );

            $search = $service->fetchDisposalSearch($fixture['company_id'], '2026-07-01', $fixture['asset_id']);
            $ids = array_map(static fn(array $row): int => (int)$row['id'], (array)($search['candidates'] ?? []));

            $harness->assertSame('2026-06-30', (string)($search['window_start'] ?? ''));
            $harness->assertSame('2026-07-04', (string)($search['window_end'] ?? ''));
            $harness->assertTrue(in_array($insideBeforeId, $ids, true));
            $harness->assertTrue(in_array($insideAfterId, $ids, true));
            $harness->assertSame(2, count($ids));
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'linked disposal uses receipt transaction date and amount', static function () use ($harness, $service): void {
            assetServiceTestRequireDisposalSchema($harness);
            $fixture = assetServiceTestCreateDisposalFixture('linked');
            $transactionId = assetServiceTestInsertTransaction($fixture, 1, '2026-07-02', 300.00, 'Asset sale receipt');

            $result = $service->disposeAssetWithTransaction($fixture['company_id'], $fixture['asset_id'], $transactionId, $fixture['bank_nominal_id']);

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $asset = InterfaceDB::fetchOne(
                'SELECT status, disposal_date, disposal_proceeds, disposal_event_type, disposal_reason
                 FROM asset_register
                 WHERE id = :id',
                ['id' => $fixture['asset_id']]
            );
            $harness->assertSame('disposed', (string)($asset['status'] ?? ''));
            $harness->assertSame('2026-07-02', (string)($asset['disposal_date'] ?? ''));
            $harness->assertSame(300.0, round((float)($asset['disposal_proceeds'] ?? 0), 2));
            $harness->assertSame('sale_receipt', (string)($asset['disposal_event_type'] ?? ''));
            $harness->assertSame('Disposed on receipt of linked sale proceeds transaction #' . $transactionId, (string)($asset['disposal_reason'] ?? ''));

            $linkCount = (int)InterfaceDB::fetchColumn(
                'SELECT COUNT(*)
                 FROM asset_disposal_transaction_links
                 WHERE asset_id = :asset_id
                   AND transaction_id = :transaction_id',
                ['asset_id' => $fixture['asset_id'], 'transaction_id' => $transactionId]
            );
            $harness->assertSame(1, $linkCount);

            $clearingNominalId = assetServiceTestNominalId('1490');
            $bankJournalId = assetServiceTestJournalId($fixture['company_id'], 'bank_csv', 'transaction:' . $transactionId);
            $assetJournalId = assetServiceTestJournalId($fixture['company_id'], 'asset_disposal', 'asset:' . $fixture['asset_id'] . ':disposal');
            $harness->assertTrue($bankJournalId > 0);
            $harness->assertTrue($assetJournalId > 0);
            $harness->assertSame(300.0, assetServiceTestJournalLineAmount($bankJournalId, $clearingNominalId, 'credit'));
            $harness->assertSame(300.0, assetServiceTestJournalLineAmount($assetJournalId, $clearingNominalId, 'debit'));
            $harness->assertSame(0.0, assetServiceTestJournalLineAmount($assetJournalId, $fixture['bank_nominal_id'], 'debit'));
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'nil disposal requires a recorded reason', static function () use ($harness, $service): void {
            assetServiceTestRequireDisposalSchema($harness);
            $fixture = assetServiceTestCreateDisposalFixture('nil');

            $result = $service->disposeAssetAtNilValue($fixture['company_id'], $fixture['asset_id'], '2026-07-03');

            $harness->assertSame(false, (bool)($result['success'] ?? false));
            $harness->assertTrue(in_array('Select a nil-value disposal reason.', (array)($result['errors'] ?? []), true));
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'other nil disposal requires free text detail', static function () use ($harness, $service): void {
            assetServiceTestRequireDisposalSchema($harness);
            $fixture = assetServiceTestCreateDisposalFixture('nil-other');

            $result = $service->disposeAssetAtNilValue($fixture['company_id'], $fixture['asset_id'], '2026-07-03', 'other_nil_value', '');

            $harness->assertSame(false, (bool)($result['success'] ?? false));
            $harness->assertTrue(in_array('Enter the reason for the other nil-value disposal.', (array)($result['errors'] ?? []), true));
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'nil disposal posts reason metadata without transaction link', static function () use ($harness, $service): void {
            assetServiceTestRequireDisposalSchema($harness);
            $fixture = assetServiceTestCreateDisposalFixture('nil-reason');

            $result = $service->disposeAssetAtNilValue(
                $fixture['company_id'],
                $fixture['asset_id'],
                '2026-07-03',
                'scrapped_no_proceeds',
                'Scrapped after inspection; no resale value'
            );

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $asset = InterfaceDB::fetchOne(
                'SELECT status, disposal_date, disposal_proceeds, disposal_event_type, disposal_reason
                 FROM asset_register
                 WHERE id = :id',
                ['id' => $fixture['asset_id']]
            );
            $harness->assertSame('disposed', (string)($asset['status'] ?? ''));
            $harness->assertSame('2026-07-03', (string)($asset['disposal_date'] ?? ''));
            $harness->assertSame(0.0, round((float)($asset['disposal_proceeds'] ?? 0), 2));
            $harness->assertSame('scrapped_no_proceeds', (string)($asset['disposal_event_type'] ?? ''));
            $harness->assertSame('Scrapped after inspection; no resale value', (string)($asset['disposal_reason'] ?? ''));
            $harness->assertSame(0, (int)InterfaceDB::fetchColumn(
                'SELECT COUNT(*)
                 FROM asset_disposal_transaction_links
                 WHERE asset_id = :asset_id',
                ['asset_id' => $fixture['asset_id']]
            ));

            $assetJournalId = assetServiceTestJournalId($fixture['company_id'], 'asset_disposal', 'asset:' . $fixture['asset_id'] . ':disposal');
            $harness->assertTrue($assetJournalId > 0);
            $harness->assertSame(0.0, assetServiceTestJournalLineAmount($assetJournalId, assetServiceTestNominalId('1490'), 'debit'));
            $harness->assertSame(0.0, assetServiceTestJournalLineAmount($assetJournalId, $fixture['bank_nominal_id'], 'debit'));
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'disposal posts exact pending depreciation before derecognition without duplicates', static function () use ($harness, $service): void {
            assetServiceTestRequireDisposalSchema($harness);
            $fixture = assetServiceTestCreateDisposalFixture('pending-depreciation');
            StandardNominalTestFixture::ensureNominals(['6200']);
            InterfaceDB::prepareExecute(
                'UPDATE asset_register
                 SET depreciation_method = :method
                 WHERE id = :id',
                ['method' => 'straight_line', 'id' => $fixture['asset_id']]
            );

            $purchaseDate = new DateTimeImmutable('2026-01-10');
            $disposalDate = new DateTimeImmutable('2026-07-03');
            $lifeEnd = $purchaseDate->modify('+3 years')->modify('-1 day');
            $lifeDays = (int)$purchaseDate->diff($lifeEnd)->days + 1;
            $elapsedDays = (int)$purchaseDate->diff($disposalDate)->days + 1;
            $expectedDepreciation = round(1000.00 * ($elapsedDays / $lifeDays), 2);

            $result = $service->disposeAssetAtNilValue(
                $fixture['company_id'],
                $fixture['asset_id'],
                $disposalDate->format('Y-m-d'),
                'scrapped_no_proceeds',
                'No recoverable value after inspection'
            );
            $harness->assertSame(true, (bool)($result['success'] ?? false));

            $entries = InterfaceDB::fetchAll(
                'SELECT amount, journal_id
                 FROM asset_depreciation_entries
                 WHERE asset_id = :asset_id',
                ['asset_id' => $fixture['asset_id']]
            );
            $harness->assertCount(1, $entries);
            $harness->assertSame(
                number_format($expectedDepreciation, 2, '.', ''),
                number_format((float)($entries[0]['amount'] ?? 0), 2, '.', '')
            );
            $harness->assertTrue((int)($entries[0]['journal_id'] ?? 0) > 0);

            $assetJournalId = assetServiceTestJournalId(
                $fixture['company_id'],
                'asset_disposal',
                'asset:' . $fixture['asset_id'] . ':disposal'
            );
            $harness->assertSame(
                number_format($expectedDepreciation, 2, '.', ''),
                number_format(
                    assetServiceTestJournalLineAmount(
                        $assetJournalId,
                        assetServiceTestNominalId('1330'),
                        'debit'
                    ),
                    2,
                    '.',
                    ''
                )
            );

            $retry = $service->disposeAssetAtNilValue(
                $fixture['company_id'],
                $fixture['asset_id'],
                $disposalDate->format('Y-m-d'),
                'scrapped_no_proceeds',
                'No recoverable value after inspection'
            );
            $harness->assertSame(false, (bool)($retry['success'] ?? true));
            $harness->assertSame(1, (int)InterfaceDB::fetchColumn(
                'SELECT COUNT(*)
                 FROM asset_depreciation_entries
                 WHERE asset_id = :asset_id',
                ['asset_id' => $fixture['asset_id']]
            ));
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'disposal blocks omitted prior-period depreciation instead of moving it into the disposal period', static function () use ($harness, $service): void {
            assetServiceTestRequireDisposalSchema($harness);
            assetServiceTestRequireTaxViewSchema($harness);

            $ownsTransaction = !InterfaceDB::inTransaction();
            if ($ownsTransaction) {
                InterfaceDB::beginTransaction();
            }
            try {
                $fixture = assetServiceTestCreateDepreciationFixture(
                    'disposal-prior-period',
                    [
                        ['period_start' => '2022-01-01', 'period_end' => '2022-12-31'],
                        ['period_start' => '2023-01-01', 'period_end' => '2023-12-31'],
                    ],
                    '2022-01-01',
                    1096.00,
                    3
                );
                $companyId = (int)$fixture['company_id'];
                $assetId = (int)$fixture['asset_id'];
                $priorPeriodId = (int)$fixture['accounting_period_ids'][0];
                $disposalPeriodId = (int)$fixture['accounting_period_ids'][1];
                $disposalDate = '2023-06-30';

                $blocked = $service->disposeAssetAtNilValue(
                    $companyId,
                    $assetId,
                    $disposalDate,
                    'scrapped_no_proceeds',
                    'No recoverable value after inspection'
                );
                $harness->assertSame(false, (bool)($blocked['success'] ?? true));
                $blockedError = implode(' ', array_map('strval', (array)($blocked['errors'] ?? [])));
                $harness->assertTrue(str_contains($blockedError, 'prior accounting period'));
                $harness->assertTrue(str_contains($blockedError, 'will not be moved into the disposal period'));
                $harness->assertSame(0, (int)InterfaceDB::fetchColumn(
                    'SELECT COUNT(*)
                     FROM asset_depreciation_entries
                     WHERE asset_id = :asset_id',
                    ['asset_id' => $assetId]
                ));
                $harness->assertSame('active', (string)InterfaceDB::fetchColumn(
                    'SELECT status
                     FROM asset_register
                     WHERE id = :asset_id',
                    ['asset_id' => $assetId]
                ));

                $priorPost = $service->runDepreciation($companyId, $priorPeriodId);
                $harness->assertTrue(!empty($priorPost['success']));
                $harness->assertSame(1, (int)($priorPost['created'] ?? 0));

                $disposed = $service->disposeAssetAtNilValue(
                    $companyId,
                    $assetId,
                    $disposalDate,
                    'scrapped_no_proceeds',
                    'No recoverable value after inspection'
                );
                $harness->assertSame(true, (bool)($disposed['success'] ?? false));

                $periodAmounts = InterfaceDB::fetchAll(
                    'SELECT accounting_period_id, COALESCE(SUM(amount), 0) AS amount
                     FROM asset_depreciation_entries
                     WHERE asset_id = :asset_id
                     GROUP BY accounting_period_id
                     ORDER BY accounting_period_id',
                    ['asset_id' => $assetId]
                );
                $harness->assertCount(2, $periodAmounts);
                $amountByPeriod = [];
                foreach ($periodAmounts as $row) {
                    $amountByPeriod[(int)($row['accounting_period_id'] ?? 0)] = round((float)($row['amount'] ?? 0), 2);
                }
                $harness->assertSame('365.00', number_format((float)($amountByPeriod[$priorPeriodId] ?? 0), 2, '.', ''));
                $harness->assertSame('181.00', number_format((float)($amountByPeriod[$disposalPeriodId] ?? 0), 2, '.', ''));

                $assetJournalId = assetServiceTestJournalId(
                    $companyId,
                    'asset_disposal',
                    'asset:' . $assetId . ':disposal'
                );
                $harness->assertSame(
                    '546.00',
                    number_format(
                        assetServiceTestJournalLineAmount(
                            $assetJournalId,
                            StandardNominalTestFixture::id('1330'),
                            'debit'
                        ),
                        2,
                        '.',
                        ''
                    )
                );

                $retry = $service->disposeAssetAtNilValue(
                    $companyId,
                    $assetId,
                    $disposalDate,
                    'scrapped_no_proceeds',
                    'No recoverable value after inspection'
                );
                $harness->assertSame(false, (bool)($retry['success'] ?? true));
                $harness->assertSame(2, (int)InterfaceDB::fetchColumn(
                    'SELECT COUNT(*)
                     FROM asset_depreciation_entries
                     WHERE asset_id = :asset_id',
                    ['asset_id' => $assetId]
                ));
            } finally {
                if ($ownsTransaction && InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'non-asset candidates combine unlinked transactions and expense claim lines over threshold', static function () use ($harness, $service): void {
            assetServiceTestRequireTaxViewSchema($harness);
            foreach (['expense_claimants', 'expense_claims', 'expense_claim_lines'] as $table) {
                if (!InterfaceDB::tableExists($table)) {
                    $harness->skip($table . ' table is not available.');
                }
            }
            foreach (['linked_transaction_id', 'linked_expense_claim_line_id'] as $column) {
                if (!InterfaceDB::columnExists('asset_register', $column)) {
                    $harness->skip('asset_register.' . $column . ' column is not available.');
                }
            }

            $fixture = assetServiceTestCreateDisposalFixture('non-asset');
            $nominalId = assetServiceTestInsertNominal('TSE', 'Tools Small Equipment Candidate', 'expense', 'overhead');
            $includedTransactionId = assetServiceTestInsertTransaction($fixture, 1, '2026-07-03', -251.00, 'Candidate transaction');
            $equalTransactionId = assetServiceTestInsertTransaction($fixture, 2, '2026-07-04', -250.00, 'Equal threshold transaction');
            $linkedTransactionId = assetServiceTestInsertTransaction($fixture, 3, '2026-07-05', -500.00, 'Linked transaction');
            foreach ([$includedTransactionId, $equalTransactionId, $linkedTransactionId] as $transactionId) {
                InterfaceDB::prepareExecute(
                    'UPDATE transactions
                     SET nominal_account_id = :nominal_account_id,
                         reference = :reference,
                         category_status = :category_status
                     WHERE id = :id',
                    [
                        'nominal_account_id' => $nominalId,
                        'reference' => 'TX-' . $transactionId,
                        'category_status' => 'manual',
                        'id' => $transactionId,
                    ]
                );
            }
            InterfaceDB::prepareExecute(
                'UPDATE asset_register
                 SET linked_transaction_id = :transaction_id
                 WHERE id = :asset_id',
                [
                    'transaction_id' => $linkedTransactionId,
                    'asset_id' => $fixture['asset_id'],
                ]
            );

            $expenseFixture = assetServiceTestCreateExpenseClaimLineFixture($fixture, $nominalId);
            $linkedAssetId = (int)('87' . substr((string)$fixture['marker'], 0, 5) . '9');
            InterfaceDB::prepareExecute(
                'INSERT INTO asset_register (
                    id,
                    company_id,
                    asset_code,
                    description,
                    category,
                    nominal_account_id,
                    accum_dep_nominal_id,
                    purchase_date,
                    cost,
                    useful_life_years,
                    depreciation_method,
                    residual_value,
                    status,
                    linked_expense_claim_line_id
                 ) VALUES (
                    :id,
                    :company_id,
                    :asset_code,
                    :description,
                    :category,
                    :nominal_account_id,
                    :accum_dep_nominal_id,
                    :purchase_date,
                    :cost,
                    :useful_life_years,
                    :depreciation_method,
                    :residual_value,
                    :status,
                    :linked_expense_claim_line_id
                 )',
                [
                    'id' => $linkedAssetId,
                    'company_id' => $fixture['company_id'],
                    'asset_code' => 'FA-L-' . $fixture['marker'],
                    'description' => 'Linked expense asset ' . $fixture['marker'],
                    'category' => 'tools_equipment',
                    'nominal_account_id' => assetServiceTestNominalId('1300'),
                    'accum_dep_nominal_id' => assetServiceTestNominalId('1330'),
                    'purchase_date' => '2026-07-08',
                    'cost' => 750.00,
                    'useful_life_years' => 3,
                    'depreciation_method' => 'none',
                    'residual_value' => 0.00,
                    'status' => 'active',
                    'linked_expense_claim_line_id' => $expenseFixture['linked_line_id'],
                ]
            );

            $data = $service->fetchNonAssetCandidates($fixture['company_id'], $fixture['accounting_period_id'], $nominalId, 250);
            $rows = (array)($data['rows'] ?? []);
            $descriptions = array_map(static fn(array $row): string => (string)$row['description'], $rows);
            $candidateTransaction = assetServiceTestFindNonAssetCandidate($rows, 'Candidate transaction ' . $fixture['marker'] . ' 1');
            $candidateReceipt = assetServiceTestFindNonAssetCandidate($rows, 'Candidate receipt ' . $fixture['marker']);

            $harness->assertSame(2, (int)($data['count'] ?? 0));
            $harness->assertTrue(in_array('Candidate transaction ' . $fixture['marker'] . ' 1', $descriptions, true));
            $harness->assertTrue(in_array('Candidate receipt ' . $fixture['marker'], $descriptions, true));
            $harness->assertFalse(in_array('Equal threshold transaction ' . $fixture['marker'] . ' 2', $descriptions, true));
            $harness->assertFalse(in_array('Linked transaction ' . $fixture['marker'] . ' 3', $descriptions, true));
            $harness->assertFalse(in_array('Equal receipt ' . $fixture['marker'], $descriptions, true));
            $harness->assertFalse(in_array('Linked receipt ' . $fixture['marker'], $descriptions, true));
            $harness->assertSame('transaction', (string)($candidateTransaction['source_type'] ?? ''));
            $harness->assertSame($includedTransactionId, (int)($candidateTransaction['source_id'] ?? 0));
            $harness->assertSame('expense_claim', (string)($candidateReceipt['source_type'] ?? ''));
            $harness->assertSame($expenseFixture['included_line_id'], (int)($candidateReceipt['source_id'] ?? 0));
            $harness->assertSame($expenseFixture['claim_id'], (int)($candidateReceipt['source_claim_id'] ?? 0));
        });
    }
);

if (InterfaceDB::inTransaction()) {
    InterfaceDB::rollBack();
}
UploadedFileTestFixture::cleanup();

function assetServiceTestFindNonAssetCandidate(array $rows, string $description): array
{
    foreach ($rows as $row) {
        if (is_array($row) && (string)($row['description'] ?? '') === $description) {
            return $row;
        }
    }

    throw new RuntimeException('Unable to find non-asset candidate: ' . $description);
}

function assetServiceTestInsertNominal(string $prefix, string $name, string $accountType, string $subtypeCode = ''): int
{
    $code = $prefix . strtoupper(substr(str_replace('.', '', uniqid('', true)), -5));
    $subtypeId = null;
    if ($subtypeCode !== '') {
        $subtypeId = assetServiceTestSubtypeId($subtypeCode);
    }

    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, account_subtype_id, tax_treatment, is_active, sort_order)
         VALUES (:code, :name, :account_type, :account_subtype_id, :tax_treatment, 1, :sort_order)',
        [
            'code' => $code,
            'name' => $name . ' ' . $code,
            'account_type' => $accountType,
            'account_subtype_id' => $subtypeId,
            'tax_treatment' => 'other',
            'sort_order' => 9900,
        ]
    );

    return (int)InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1',
        ['code' => $code]
    );
}

function assetServiceTestSubtypeId(string $code): int
{
    $id = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_account_subtypes WHERE code = :code LIMIT 1',
        ['code' => $code]
    );
    if ($id <= 0) {
        throw new RuntimeException('Nominal subtype ' . $code . ' is not available.');
    }

    return $id;
}

function assetServiceTestInsertNominalWithTreatment(string $prefix, string $name, string $accountType, string $taxTreatment): int
{
    $code = $prefix . strtoupper(substr(str_replace('.', '', uniqid('', true)), -5));
    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, tax_treatment, is_active, sort_order)
         VALUES (:code, :name, :account_type, :tax_treatment, 1, :sort_order)',
        [
            'code' => $code,
            'name' => $name . ' ' . $code,
            'account_type' => $accountType,
            'tax_treatment' => $taxTreatment,
            'sort_order' => 9900,
        ]
    );

    return (int)InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1',
        ['code' => $code]
    );
}

function assetServiceTestRequireTaxViewSchema(GeneratedServiceClassTestHarness $harness): void
{
    foreach (['companies', 'accounting_periods', 'nominal_accounts', 'journals', 'journal_lines', 'asset_register', 'asset_depreciation_entries', 'asset_disposal_transaction_links', 'capital_allowance_pool_runs', 'capital_allowance_asset_calculations', 'tax_loss_carryforwards'] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' table is not available.');
        }
    }
}

function assetServiceTestCreateTaxViewFixture(): array
{
    $marker = (string)random_int(100000, 999999);
    $companyId = (int)('91' . $marker);
    $accountingPeriodId = (int)('92' . $marker);
    $assetId = (int)('93' . $marker);
    $incomeNominalId = assetServiceTestInsertNominalWithTreatment('ATI', 'Asset Tax Income', 'income', 'allowable');
    $disallowableNominalId = assetServiceTestInsertNominalWithTreatment('ATD', 'Asset Tax Disallowable', 'expense', 'disallowable');
    $assetNominalId = assetServiceTestInsertNominalWithTreatment('ATA', 'Asset Tax Asset', 'asset', 'capital');
    $accumNominalId = assetServiceTestInsertNominalWithTreatment('ATC', 'Asset Tax Accumulated Depreciation', 'asset', 'capital');

    InterfaceDB::prepareExecute(
        'INSERT INTO companies (id, company_name, company_number, is_active)
         VALUES (:id, :company_name, :company_number, 1)',
        [
            'id' => $companyId,
            'company_name' => 'Asset Tax Fixture ' . $marker,
            'company_number' => 'AT' . $marker,
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
         VALUES (:id, :company_id, :label, :period_start, :period_end)',
        [
            'id' => $accountingPeriodId,
            'company_id' => $companyId,
            'label' => 'Asset Tax FY ' . $marker,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (
            company_id,
            accounting_period_id,
            source_type,
            source_ref,
            journal_date,
            description,
            is_posted
         ) VALUES (
            :company_id,
            :accounting_period_id,
            :source_type,
            :source_ref,
            :journal_date,
            :description,
            1
         )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'source_type' => 'manual',
            'source_ref' => 'asset-tax-fixture-' . $marker,
            'journal_date' => '2026-12-31',
            'description' => 'Asset tax fixture ' . $marker,
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id
         FROM journals
         WHERE company_id = :company_id
           AND source_ref = :source_ref
         LIMIT 1',
        [
            'company_id' => $companyId,
            'source_ref' => 'asset-tax-fixture-' . $marker,
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_account_id, 0.00, 2000.00, :line_description)',
        [
            'journal_id' => $journalId,
            'nominal_account_id' => $incomeNominalId,
            'line_description' => 'Fixture income',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_account_id, 200.00, 0.00, :line_description)',
        [
            'journal_id' => $journalId,
            'nominal_account_id' => $disallowableNominalId,
            'line_description' => 'Fixture disallowable expense',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO asset_register (
            id,
            company_id,
            asset_code,
            description,
            category,
            nominal_account_id,
            accum_dep_nominal_id,
            purchase_date,
            cost,
            useful_life_years,
            depreciation_method,
            residual_value,
            status
         ) VALUES (
            :id,
            :company_id,
            :asset_code,
            :description,
            :category,
            :nominal_account_id,
            :accum_dep_nominal_id,
            :purchase_date,
            :cost,
            :useful_life_years,
            :depreciation_method,
            :residual_value,
            :status
         )',
        [
            'id' => $assetId,
            'company_id' => $companyId,
            'asset_code' => 'AT-FIX-' . $marker,
            'description' => 'Asset tax fixture asset ' . $marker,
            'category' => 'tools_equipment',
            'nominal_account_id' => $assetNominalId,
            'accum_dep_nominal_id' => $accumNominalId,
            'purchase_date' => '2026-02-01',
            'cost' => 1000.00,
            'useful_life_years' => 3,
            'depreciation_method' => 'none',
            'residual_value' => 0.00,
            'status' => 'active',
        ]
    );
    assetServiceTestEnsureTaxRateRules();
    (new \eel_accounts\Service\CapitalAllowanceService())->rebuildForCompany($companyId);

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
        'asset_id' => $assetId,
    ];
}

function assetServiceTestRequireDisposalSchema(GeneratedServiceClassTestHarness $harness): void
{
    foreach (['asset_register', 'asset_disposal_transaction_links', 'transactions', 'journals', 'journal_lines', 'nominal_account_subtypes'] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' table is not available.');
        }
    }

    foreach (['manual_addition_reason', 'manual_offset_nominal_id', 'disposal_event_type', 'disposal_reason'] as $column) {
        if (!InterfaceDB::columnExists('asset_register', $column)) {
            $harness->skip('asset_register.' . $column . ' column is not available.');
        }
    }

}

function assetServiceTestCreateDepreciationFixture(
    string $suffix,
    array $periods,
    string $purchaseDate,
    float $cost,
    int $usefulLifeYears
): array {
    StandardNominalTestFixture::ensureNominals(['1300', '1330', '6200', '4200', '6210', '1490']);

    $marker = (string)random_int(100000, 999999);
    $companyId = (int)('95' . $marker);
    $assetId = (int)('96' . $marker);
    $periodIds = [];

    InterfaceDB::prepareExecute(
        'INSERT INTO companies (id, company_name, company_number, is_active)
         VALUES (:id, :company_name, :company_number, 1)',
        [
            'id' => $companyId,
            'company_name' => 'Depreciation ' . $suffix . ' ' . $marker,
            'company_number' => 'DP' . substr($marker, 0, 6),
        ]
    );
    foreach (array_values($periods) as $index => $period) {
        $periodId = (int)('97' . substr($marker, 0, 5) . (string)($index + 1));
        $periodIds[] = $periodId;
        InterfaceDB::prepareExecute(
            'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
             VALUES (:id, :company_id, :label, :period_start, :period_end)',
            [
                'id' => $periodId,
                'company_id' => $companyId,
                'label' => 'Depreciation ' . $suffix . ' ' . (string)($period['period_end'] ?? ''),
                'period_start' => (string)($period['period_start'] ?? ''),
                'period_end' => (string)($period['period_end'] ?? ''),
            ]
        );
    }
    InterfaceDB::prepareExecute(
        'INSERT INTO asset_register (
            id, company_id, asset_code, description, category,
            nominal_account_id, accum_dep_nominal_id, purchase_date,
            cost, useful_life_years, depreciation_method,
            residual_value, status
         ) VALUES (
            :id, :company_id, :asset_code, :description, :category,
            :nominal_account_id, :accum_dep_nominal_id, :purchase_date,
            :cost, :useful_life_years, :depreciation_method,
            :residual_value, :status
         )',
        [
            'id' => $assetId,
            'company_id' => $companyId,
            'asset_code' => 'DP-' . $marker,
            'description' => 'Depreciation fixture asset ' . $suffix,
            'category' => 'tools_equipment',
            'nominal_account_id' => StandardNominalTestFixture::id('1300'),
            'accum_dep_nominal_id' => StandardNominalTestFixture::id('1330'),
            'purchase_date' => $purchaseDate,
            'cost' => round($cost, 2),
            'useful_life_years' => $usefulLifeYears,
            'depreciation_method' => 'straight_line',
            'residual_value' => 0.00,
            'status' => 'active',
        ]
    );

    return [
        'company_id' => $companyId,
        'asset_id' => $assetId,
        'accounting_period_ids' => $periodIds,
        'marker' => $marker,
    ];
}

function assetServiceTestCreateDisposalFixture(string $suffix): array
{
    if (!InterfaceDB::inTransaction()) {
        InterfaceDB::beginTransaction();
    }
    StandardNominalTestFixture::ensureNominals(['1000', '1300', '1330', '1490', '4200', '6210']);
    StandardNominalTestFixture::ensureSubtypes(['trade_creditor']);

    $marker = (string)random_int(100000, 999999);
    $companyId = (int)('81' . $marker);
    $accountingPeriodId = (int)('82' . $marker);
    $accountId = (int)('83' . $marker);
    $uploadId = (int)('84' . $marker);
    $assetId = (int)('85' . $marker);
    $bankNominalId = StandardNominalTestFixture::id('1000');

    InterfaceDB::prepareExecute(
        'INSERT INTO companies (id, company_name, company_number, is_active)
         VALUES (:id, :company_name, :company_number, 1)',
        [
            'id' => $companyId,
            'company_name' => 'Asset Disposal ' . $suffix . ' ' . $marker,
            'company_number' => 'AD' . substr($marker, 0, 6),
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
         VALUES (:id, :company_id, :label, :period_start, :period_end)',
        [
            'id' => $accountingPeriodId,
            'company_id' => $companyId,
            'label' => 'FY ' . $marker,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO company_accounts (id, company_id, account_name, account_type, nominal_account_id, is_active)
         VALUES (:id, :company_id, :account_name, :account_type, :nominal_account_id, 1)',
        [
            'id' => $accountId,
            'company_id' => $companyId,
            'account_name' => 'Test Bank ' . $marker,
            'account_type' => 'bank',
            'nominal_account_id' => $bankNominalId,
        ]
    );
    $settings = new \eel_accounts\Store\CompanySettingsStore($companyId);
    $settings->set('tools_equipment_asset_cost_nominal_id', StandardNominalTestFixture::id('1300'), 'int');
    $settings->set('tools_equipment_accum_dep_nominal_id', StandardNominalTestFixture::id('1330'), 'int');
    $settings->flush();
    InterfaceDB::prepareExecute(
        'INSERT INTO statement_uploads (
            id,
            company_id,
            accounting_period_id,
            account_id,
            statement_month,
            original_filename,
            stored_filename,
            file_sha256
         ) VALUES (
            :id,
            :company_id,
            :accounting_period_id,
            :account_id,
            :statement_month,
            :original_filename,
            :stored_filename,
            :file_sha256
         )',
        [
            'id' => $uploadId,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'account_id' => $accountId,
            'statement_month' => '2026-07-01',
            'original_filename' => 'asset-disposal-' . $marker . '.csv',
            'stored_filename' => 'asset-disposal-' . $marker . '.csv',
            'file_sha256' => hash('sha256', 'asset-disposal-upload-' . $marker),
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO asset_register (
            id,
            company_id,
            asset_code,
            description,
            category,
            nominal_account_id,
            accum_dep_nominal_id,
            purchase_date,
            cost,
            useful_life_years,
            depreciation_method,
            residual_value,
            status
         ) VALUES (
            :id,
            :company_id,
            :asset_code,
            :description,
            :category,
            :nominal_account_id,
            :accum_dep_nominal_id,
            :purchase_date,
            :cost,
            :useful_life_years,
            :depreciation_method,
            :residual_value,
            :status
         )',
        [
            'id' => $assetId,
            'company_id' => $companyId,
            'asset_code' => 'FA-T-' . $marker,
            'description' => 'Disposal fixture asset ' . $marker,
            'category' => 'tools_equipment',
            'nominal_account_id' => assetServiceTestNominalId('1300'),
            'accum_dep_nominal_id' => assetServiceTestNominalId('1330'),
            'purchase_date' => '2026-01-10',
            'cost' => 1000.00,
            'useful_life_years' => 3,
            'depreciation_method' => 'none',
            'residual_value' => 0.00,
            'status' => 'active',
        ]
    );

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
        'account_id' => $accountId,
        'upload_id' => $uploadId,
        'asset_id' => $assetId,
        'bank_nominal_id' => $bankNominalId,
        'marker' => $marker,
    ];
}

function assetServiceTestInsertTransaction(array $fixture, int $offset, string $date, float $amount, string $description, int $isInternalTransfer = 0): int
{
    $transactionId = (int)('86' . substr((string)$fixture['marker'], 0, 5) . $offset);
    InterfaceDB::prepareExecute(
        'INSERT INTO transactions (
            id,
            company_id,
            accounting_period_id,
            statement_upload_id,
            account_id,
            txn_date,
            description,
            amount,
            currency,
            dedupe_hash,
            is_internal_transfer,
            category_status
         ) VALUES (
            :id,
            :company_id,
            :accounting_period_id,
            :statement_upload_id,
            :account_id,
            :txn_date,
            :description,
            :amount,
            :currency,
            :dedupe_hash,
            :is_internal_transfer,
            :category_status
         )',
        [
            'id' => $transactionId,
            'company_id' => $fixture['company_id'],
            'accounting_period_id' => $fixture['accounting_period_id'],
            'statement_upload_id' => $fixture['upload_id'],
            'account_id' => $fixture['account_id'],
            'txn_date' => $date,
            'description' => $description . ' ' . $fixture['marker'] . ' ' . $offset,
            'amount' => $amount,
            'currency' => 'GBP',
            'dedupe_hash' => hash('sha256', 'asset-disposal-transaction-' . $fixture['marker'] . '-' . $offset),
            'is_internal_transfer' => $isInternalTransfer,
            'category_status' => 'uncategorised',
        ]
    );

    return $transactionId;
}

function assetServiceTestEnsureTaxRateRules(): void
{
    (new \eel_accounts\Service\TaxRateRuleService())->ensureSchema();
    $count = (int)\InterfaceDB::fetchColumn(
        'SELECT COUNT(*)
         FROM tax_rate_rules
         WHERE tax_domain = :domain
           AND regime = :regime
           AND rule_key IN (\'aia_annual_limit\', \'main_pool_wda\', \'special_rate_pool_wda\')
           AND is_active = 1',
        ['domain' => 'capital_allowances', 'regime' => 'plant_machinery']
    );
    if ($count >= 3) {
        return;
    }

    \InterfaceDB::prepareExecute(
        'UPDATE tax_rate_rules
         SET is_active = 0
         WHERE tax_domain = :domain
           AND regime = :regime
           AND rule_key IN (\'aia_annual_limit\', \'main_pool_wda\', \'special_rate_pool_wda\')',
        ['domain' => 'capital_allowances', 'regime' => 'plant_machinery']
    );

    foreach ([
        ['aia_annual_limit', 'amount', null, 1000000.0, '2019-01-01', '9999-12-31'],
        ['main_pool_wda', 'rate', 0.18, null, '1900-01-01', '2026-03-31'],
        ['special_rate_pool_wda', 'rate', 0.06, null, '1900-01-01', '9999-12-31'],
    ] as $index => $row) {
        \InterfaceDB::prepareExecute(
            'INSERT INTO tax_rate_rules (
                tax_domain, regime, rule_key, rule_label, period_start, period_end, value_type,
                rate_value, amount_value, fraction_value, source_url, source_checked_at, rule_version, is_active, notes
             ) VALUES (
                :domain, :regime, :rule_key, :rule_label, :period_start, :period_end, :value_type,
                :rate_value, :amount_value, NULL, :source_url, :source_checked_at, :rule_version, 1, :notes
             )',
            [
                'domain' => 'capital_allowances',
                'regime' => 'plant_machinery',
                'rule_key' => (string)$row[0],
                'rule_label' => (string)$row[0],
                'period_start' => (string)$row[4],
                'period_end' => (string)$row[5],
                'value_type' => (string)$row[1],
                'rate_value' => $row[2],
                'amount_value' => $row[3],
                'source_url' => 'https://example.test/rates',
                'source_checked_at' => '2026-07-07',
                'rule_version' => 'asset-fixture-' . (string)$index . '-' . random_int(100000, 999999),
                'notes' => 'Fixture sourced tax rate rule.',
            ]
        );
    }
}

function assetServiceTestCreateExpenseClaimLineFixture(array $fixture, int $nominalId): array
{
    $markerPrefix = substr((string)$fixture['marker'], 0, 5);
    $claimantId = (int)('88' . $markerPrefix);
    $claimId = (int)('89' . $markerPrefix);
    $includedLineId = (int)('90' . $markerPrefix . '1');
    $equalLineId = (int)('90' . $markerPrefix . '2');
    $linkedLineId = (int)('90' . $markerPrefix . '3');

    InterfaceDB::prepareExecute(
        'INSERT INTO expense_claimants (id, company_id, claimant_name, is_active)
         VALUES (:id, :company_id, :claimant_name, 1)',
        [
            'id' => $claimantId,
            'company_id' => $fixture['company_id'],
            'claimant_name' => 'Non Asset Claimant ' . $fixture['marker'],
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO expense_claims (
            id,
            company_id,
            accounting_period_id,
            claimant_id,
            claim_year,
            claim_month,
            period_start,
            period_end,
            claim_reference_code,
            claimed_amount,
            status
         ) VALUES (
            :id,
            :company_id,
            :accounting_period_id,
            :claimant_id,
            :claim_year,
            :claim_month,
            :period_start,
            :period_end,
            :claim_reference_code,
            :claimed_amount,
            :status
         )',
        [
            'id' => $claimId,
            'company_id' => $fixture['company_id'],
            'accounting_period_id' => $fixture['accounting_period_id'],
            'claimant_id' => $claimantId,
            'claim_year' => 2026,
            'claim_month' => 7,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'claim_reference_code' => 'NA-' . $fixture['marker'],
            'claimed_amount' => 1501.00,
            'status' => 'draft',
        ]
    );

    foreach ([
        [$includedLineId, 1, '2026-07-06', 'Candidate receipt ', 501.00, 'RCPT-501'],
        [$equalLineId, 2, '2026-07-07', 'Equal receipt ', 250.00, 'RCPT-250'],
        [$linkedLineId, 3, '2026-07-08', 'Linked receipt ', 750.00, 'RCPT-750'],
    ] as $line) {
        InterfaceDB::prepareExecute(
            'INSERT INTO expense_claim_lines (
                id,
                expense_claim_id,
                line_number,
                expense_date,
                description,
                amount,
                nominal_account_id,
                receipt_reference
             ) VALUES (
                :id,
                :expense_claim_id,
                :line_number,
                :expense_date,
                :description,
                :amount,
                :nominal_account_id,
                :receipt_reference
             )',
            [
                'id' => (int)$line[0],
                'expense_claim_id' => $claimId,
                'line_number' => (int)$line[1],
                'expense_date' => (string)$line[2],
                'description' => (string)$line[3] . $fixture['marker'],
                'amount' => (float)$line[4],
                'nominal_account_id' => $nominalId,
                'receipt_reference' => (string)$line[5],
            ]
        );
    }

    return [
        'claim_id' => $claimId,
        'included_line_id' => $includedLineId,
        'equal_line_id' => $equalLineId,
        'linked_line_id' => $linkedLineId,
    ];
}

function assetServiceTestNominalId(string $code): int
{
    return (int)InterfaceDB::fetchColumn(
        'SELECT id
         FROM nominal_accounts
         WHERE code = :code
         LIMIT 1',
        ['code' => $code]
    );
}

function assetServiceTestEnsureNominalId(string $code, string $name, string $accountType, string $taxTreatment): int
{
    $id = assetServiceTestNominalId($code);
    if ($id > 0) {
        return $id;
    }

    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, tax_treatment, is_active, sort_order)
         VALUES (:code, :name, :account_type, :tax_treatment, 1, :sort_order)',
        [
            'code' => $code,
            'name' => $name,
            'account_type' => $accountType,
            'tax_treatment' => $taxTreatment,
            'sort_order' => (int)$code,
        ]
    );

    return assetServiceTestNominalId($code);
}

function assetServiceTestJournalId(int $companyId, string $sourceType, string $sourceRef): int
{
    return (int)InterfaceDB::fetchColumn(
        'SELECT id
         FROM journals
         WHERE company_id = :company_id
           AND source_type = :source_type
           AND source_ref = :source_ref
         LIMIT 1',
        [
            'company_id' => $companyId,
            'source_type' => $sourceType,
            'source_ref' => $sourceRef,
        ]
    );
}

function assetServiceTestJournalLineAmount(int $journalId, int $nominalAccountId, string $side): float
{
    $side = $side === 'credit' ? 'credit' : 'debit';

    return round((float)InterfaceDB::fetchColumn(
        'SELECT COALESCE(SUM(' . $side . '), 0)
         FROM journal_lines
         WHERE journal_id = :journal_id
           AND nominal_account_id = :nominal_account_id',
        [
            'journal_id' => $journalId,
            'nominal_account_id' => $nominalAccountId,
        ]
    ), 2);
}
