<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\VehicleService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\VehicleService $service): void {
        $harness->check(\eel_accounts\Service\VehicleService::class, 'asset category nominal mapping separates cars and vans', static function () use ($harness): void {
            $harness->assertSame('1321', \eel_accounts\Service\AssetService::assetNominalCodesForCategory('car')['cost']);
            $harness->assertSame('1322', \eel_accounts\Service\AssetService::assetNominalCodesForCategory('van')['cost']);
            $harness->assertSame('1320', \eel_accounts\Service\AssetService::assetNominalCodesForCategory('unreviewed_vehicle')['cost']);
        });

        $harness->check(\eel_accounts\Service\VehicleService::class, 'saving car details updates linked transaction asset and journal nominal', static function () use ($harness, $service): void {
            vehicleServiceFixture('car-transaction', static function (array $fixture) use ($harness, $service): void {
                $result = $service->saveVehicleDetails(
                    (int)$fixture['company_id'],
                    (int)$fixture['transaction_asset_id'],
                    [
                        'vehicle_type' => 'car',
                        'registration_mark' => 'ab12 cde',
                        'make_model' => 'Test Car',
                        'colour' => 'blue',
                        'acquisition_condition' => 'second_hand',
                        'co2_emissions_g_km' => '75',
                    ],
                    vehicleServiceNominalId('1000'),
                    'test'
                );

                $harness->assertSame(true, (bool)($result['success'] ?? false));
                $carNominalId = vehicleServiceNominalId('1321');
                $rebuiltJournalId = (int)\InterfaceDB::fetchColumn(
                    'SELECT id FROM journals WHERE company_id = :company_id AND source_type = :source_type AND source_ref = :source_ref ORDER BY id DESC LIMIT 1',
                    [
                        'company_id' => $fixture['company_id'],
                        'source_type' => 'bank_csv',
                        'source_ref' => 'transaction:' . $fixture['transaction_id'],
                    ]
                );
                $harness->assertSame($carNominalId, (int)\InterfaceDB::fetchColumn('SELECT nominal_account_id FROM asset_register WHERE id = :id', ['id' => $fixture['transaction_asset_id']]));
                $harness->assertSame($carNominalId, (int)\InterfaceDB::fetchColumn('SELECT nominal_account_id FROM transactions WHERE id = :id', ['id' => $fixture['transaction_id']]));
                $harness->assertSame($carNominalId, (int)\InterfaceDB::fetchColumn('SELECT nominal_account_id FROM journal_lines WHERE journal_id = :id AND debit = 20000.00 LIMIT 1', ['id' => $rebuiltJournalId]));
                $harness->assertSame('AB12 CDE', (string)\InterfaceDB::fetchColumn('SELECT registration_mark FROM asset_vehicle_details WHERE asset_id = :id', ['id' => $fixture['transaction_asset_id']]));
                $harness->assertSame('Blue', (string)\InterfaceDB::fetchColumn('SELECT colour FROM asset_vehicle_details WHERE asset_id = :id', ['id' => $fixture['transaction_asset_id']]));
            });
        });

        $harness->check(\eel_accounts\Service\VehicleService::class, 'saving van details updates linked expense line asset and journal nominal', static function () use ($harness, $service): void {
            vehicleServiceFixture('van-expense', static function (array $fixture) use ($harness, $service): void {
                $result = $service->saveVehicleDetails(
                    (int)$fixture['company_id'],
                    (int)$fixture['expense_asset_id'],
                    [
                        'vehicle_type' => 'van',
                        'registration_mark' => 'VN26 TAX',
                        'make_model' => 'Test Van',
                        'payload_kg' => '1200',
                        'acquisition_condition' => 'new_unused',
                    ],
                    0,
                    'test'
                );

                $harness->assertSame(true, (bool)($result['success'] ?? false));
                $vanNominalId = vehicleServiceNominalId('1322');
                $harness->assertSame($vanNominalId, (int)\InterfaceDB::fetchColumn('SELECT nominal_account_id FROM asset_register WHERE id = :id', ['id' => $fixture['expense_asset_id']]));
                $harness->assertSame($vanNominalId, (int)\InterfaceDB::fetchColumn('SELECT nominal_account_id FROM expense_claim_lines WHERE id = :id', ['id' => $fixture['expense_line_id']]));
                $harness->assertSame($vanNominalId, (int)\InterfaceDB::fetchColumn('SELECT nominal_account_id FROM journal_lines WHERE journal_id = :id AND debit = 18000.00 LIMIT 1', ['id' => $fixture['expense_journal_id']]));
            });
        });

        $harness->check(\eel_accounts\Service\VehicleService::class, 'saving unreviewed vehicle keeps neutral motor vehicle bucket', static function () use ($harness, $service): void {
            vehicleServiceFixture('unreviewed', static function (array $fixture) use ($harness, $service): void {
                $result = $service->saveVehicleDetails(
                    (int)$fixture['company_id'],
                    (int)$fixture['transaction_asset_id'],
                    ['vehicle_type' => 'unreviewed'],
                    0,
                    'test'
                );

                $vehicleNominalId = vehicleServiceNominalId('1320');
                $harness->assertSame(true, (bool)($result['success'] ?? false));
                $harness->assertSame($vehicleNominalId, (int)\InterfaceDB::fetchColumn('SELECT nominal_account_id FROM asset_register WHERE id = :id', ['id' => $fixture['transaction_asset_id']]));
                $harness->assertSame('motor_vehicle', (string)\InterfaceDB::fetchColumn('SELECT category FROM asset_register WHERE id = :id', ['id' => $fixture['transaction_asset_id']]));
            });
        });

        $harness->check(\eel_accounts\Service\VehicleService::class, 'rejects vehicle colours outside the DVLA register set', static function () use ($harness, $service): void {
            vehicleServiceFixture('invalid-colour', static function (array $fixture) use ($harness, $service): void {
                $result = $service->saveVehicleDetails(
                    (int)$fixture['company_id'],
                    (int)$fixture['transaction_asset_id'],
                    [
                        'vehicle_type' => 'car',
                        'colour' => 'Midnight pearl',
                        'acquisition_condition' => 'second_hand',
                    ],
                    0,
                    'test'
                );

                $harness->assertSame(false, (bool)($result['success'] ?? true));
                $harness->assertSame(['Choose a valid DVLA vehicle colour.'], (array)($result['errors'] ?? []));
                $harness->assertSame(0, (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM asset_vehicle_details WHERE asset_id = :id', ['id' => $fixture['transaction_asset_id']]));
            });
        });

        $harness->check(\eel_accounts\Service\VehicleService::class, 'saving visible vehicle facts preserves hidden contract date', static function () use ($harness, $service): void {
            vehicleServiceFixture('contract-date-preserved', static function (array $fixture) use ($harness, $service): void {
                \InterfaceDB::prepareExecute(
                    'INSERT INTO asset_vehicle_details (asset_id, company_id, vehicle_type, acquisition_condition, contract_date, is_zero_emission, tax_review_status)
                     VALUES (:asset_id, :company_id, :vehicle_type, :condition, :contract_date, 0, :status)',
                    [
                        'asset_id' => $fixture['transaction_asset_id'],
                        'company_id' => $fixture['company_id'],
                        'vehicle_type' => 'car',
                        'condition' => 'second_hand',
                        'contract_date' => '2026-03-15',
                        'status' => 'reviewed',
                    ]
                );

                $result = $service->saveVehicleDetails(
                    (int)$fixture['company_id'],
                    (int)$fixture['transaction_asset_id'],
                    [
                        'vehicle_type' => 'car',
                        'acquisition_condition' => 'second_hand',
                        'co2_emissions_g_km' => '90',
                    ],
                    0,
                    'test'
                );

                $harness->assertSame(true, (bool)($result['success'] ?? false));
                $harness->assertSame('2026-03-15', (string)\InterfaceDB::fetchColumn('SELECT contract_date FROM asset_vehicle_details WHERE asset_id = :id', ['id' => $fixture['transaction_asset_id']]));
            });
        });

        $harness->check(\eel_accounts\Service\VehicleService::class, 'source recategorisation away from vehicle nominal removes vehicle details', static function () use ($harness, $service): void {
            vehicleServiceFixture('cleanup', static function (array $fixture) use ($harness, $service): void {
                $service->saveVehicleDetails(
                    (int)$fixture['company_id'],
                    (int)$fixture['transaction_asset_id'],
                    ['vehicle_type' => 'car', 'acquisition_condition' => 'second_hand', 'co2_emissions_g_km' => '45'],
                    0,
                    'test'
                );
                \InterfaceDB::prepareExecute(
                    'UPDATE transactions SET nominal_account_id = :nominal_account_id WHERE id = :id',
                    ['nominal_account_id' => vehicleServiceNominalId('1300'), 'id' => $fixture['transaction_id']]
                );

                $service->cleanupVehicleDetailsForTransaction((int)$fixture['transaction_id']);

                $harness->assertSame(0, (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM asset_vehicle_details WHERE asset_id = :id', ['id' => $fixture['transaction_asset_id']]));
            });
        });

        $harness->check(\eel_accounts\Service\CapitalAllowanceService::class, 'capital allowance run applies AIA car FYA and special rate WDA', static function () use ($harness): void {
            vehicleServiceFixture('allowances', static function (array $fixture) use ($harness): void {
                $companyId = (int)$fixture['company_id'];
                vehicleServiceInsertAsset($companyId, (int)$fixture['period_id'], 'VAN-AIA', 'van', '1322', 1000.00, '2026-04-20');
                $electricId = vehicleServiceInsertAsset($companyId, (int)$fixture['period_id'], 'CAR-FYA', 'car', '1321', 5000.00, '2026-05-20');
                vehicleServiceInsertVehicleDetail($companyId, $electricId, 'car', 'new_unused', 1, 0);
                $specialCarId = vehicleServiceInsertAsset($companyId, (int)$fixture['period_id'], 'CAR-SPECIAL', 'car', '1321', 10000.00, '2026-06-20');
                vehicleServiceInsertVehicleDetail($companyId, $specialCarId, 'car', 'second_hand', 0, 120);

                $runs = (new \eel_accounts\Service\CapitalAllowanceService())->rebuildForCompany($companyId);
                $periodRun = (array)($runs[(int)$fixture['period_id']] ?? []);
                $breakdown = (new \eel_accounts\Service\CapitalAllowanceService())->fetchPeriodBreakdown($companyId, (int)$fixture['period_id']);
                $special = vehicleServiceFindPool((array)$breakdown['rows'], 'special_rate_pool');

                $harness->assertSame(6600.0, round((float)($periodRun['allowance'] ?? 0), 2));
                $harness->assertSame(600.0, round((float)($special['wda_claimed'] ?? 0), 2));
                $harness->assertSame(9400.0, round((float)($special['closing_wdv'] ?? 0), 2));
            });
        });
    }
);

function vehicleServiceFixture(string $label, callable $callback): void
{
    \InterfaceDB::beginTransaction();
    try {
        $marker = (string)random_int(100000, 999999);
        $companyId = (int)('81' . substr($marker, 0, 4));
        $periodId = (int)('82' . substr($marker, 0, 4));
        $uploadId = (int)('83' . substr($marker, 0, 4));
        $accountId = (int)('84' . substr($marker, 0, 4));
        $transactionId = (int)('85' . substr($marker, 0, 4));
        $transactionJournalId = (int)('86' . substr($marker, 0, 4));
        $expenseJournalId = (int)('87' . substr($marker, 0, 4));
        $expenseClaimId = (int)('88' . substr($marker, 0, 4));
        $expenseLineId = (int)('89' . substr($marker, 0, 4));
        $transactionAssetId = (int)('90' . substr($marker, 0, 4));
        $expenseAssetId = (int)('91' . substr($marker, 0, 4));
        $bankNominalId = vehicleServiceNominalId('1000');
        $vehicleNominalId = vehicleServiceNominalId('1320');
        vehicleServiceNominalId('1321');
        vehicleServiceNominalId('1322');
        $payableNominalId = vehicleServiceNominalId('2110');
        vehicleServiceEnsureTaxRateRules();

        \InterfaceDB::prepareExecute('INSERT INTO companies (id, company_name, company_number, is_active) VALUES (:id, :name, :number, 1)', [
            'id' => $companyId,
            'name' => 'Vehicle Fixture ' . $label . ' ' . $marker,
            'number' => 'VH' . substr($marker, 0, 6),
        ]);
        \InterfaceDB::prepareExecute('INSERT INTO accounting_periods (id, company_id, label, period_start, period_end) VALUES (:id, :company_id, :label, :start, :end)', [
            'id' => $periodId,
            'company_id' => $companyId,
            'label' => 'Vehicle FY ' . $marker,
            'start' => '2026-04-01',
            'end' => '2027-03-31',
        ]);
        \InterfaceDB::prepareExecute('INSERT INTO company_accounts (id, company_id, account_name, account_type, nominal_account_id, is_active) VALUES (:id, :company_id, :name, :type, :nominal, 1)', [
            'id' => $accountId,
            'company_id' => $companyId,
            'name' => 'Bank ' . $marker,
            'type' => 'bank',
            'nominal' => $bankNominalId,
        ]);
        \InterfaceDB::prepareExecute('INSERT INTO statement_uploads (id, company_id, accounting_period_id, account_id, statement_month, original_filename, stored_filename, file_sha256) VALUES (:id, :company_id, :period_id, :account_id, :month, :original, :stored, :sha)', [
            'id' => $uploadId,
            'company_id' => $companyId,
            'period_id' => $periodId,
            'account_id' => $accountId,
            'month' => '2026-04-01',
            'original' => 'vehicle.csv',
            'stored' => 'vehicle-' . $marker . '.csv',
            'sha' => hash('sha256', 'vehicle-' . $marker),
        ]);
        \InterfaceDB::prepareExecute('INSERT INTO transactions (id, company_id, accounting_period_id, statement_upload_id, account_id, txn_date, description, amount, dedupe_hash, nominal_account_id, category_status) VALUES (:id, :company_id, :period_id, :upload_id, :account_id, :date, :description, :amount, :dedupe_hash, :nominal_id, :status)', [
            'id' => $transactionId,
            'company_id' => $companyId,
            'period_id' => $periodId,
            'upload_id' => $uploadId,
            'account_id' => $accountId,
            'date' => '2026-04-15',
            'description' => 'Vehicle purchase ' . $marker,
            'amount' => -20000.00,
            'dedupe_hash' => hash('sha256', 'transaction-' . $marker),
            'nominal_id' => $vehicleNominalId,
            'status' => 'manual',
        ]);

        vehicleServiceInsertJournal($transactionJournalId, $companyId, $periodId, 'bank_csv', 'transaction:' . $transactionId, '2026-04-15', 'Vehicle transaction');
        vehicleServiceInsertJournalLine($transactionJournalId, $vehicleNominalId, 20000.00, 0.00, 'Vehicle purchase');
        vehicleServiceInsertJournalLine($transactionJournalId, $bankNominalId, 0.00, 20000.00, 'Bank');
        vehicleServiceInsertAssetWithId($transactionAssetId, $companyId, $periodId, 'TX-VEH-' . $marker, 'van', $vehicleNominalId, 20000.00, '2026-04-15', $transactionJournalId, $transactionId, null);

        \InterfaceDB::prepareExecute('INSERT INTO expense_claimants (id, company_id, claimant_name, is_active) VALUES (:id, :company_id, :name, 1)', [
            'id' => $expenseClaimId,
            'company_id' => $companyId,
            'name' => 'Claimant ' . $marker,
        ]);
        vehicleServiceInsertJournal($expenseJournalId, $companyId, $periodId, 'expense_register', 'EC-' . $marker, '2026-05-31', 'Expense claim');
        \InterfaceDB::prepareExecute('INSERT INTO expense_claims (id, company_id, accounting_period_id, claimant_id, claim_year, claim_month, period_start, period_end, claim_reference_code, status, posted_journal_id) VALUES (:id, :company_id, :period_id, :claimant_id, 2026, 5, :start, :end, :ref, :status, :journal_id)', [
            'id' => $expenseClaimId,
            'company_id' => $companyId,
            'period_id' => $periodId,
            'claimant_id' => $expenseClaimId,
            'start' => '2026-05-01',
            'end' => '2026-05-31',
            'ref' => 'EC-' . $marker,
            'status' => 'posted',
            'journal_id' => $expenseJournalId,
        ]);
        \InterfaceDB::prepareExecute('INSERT INTO expense_claim_lines (id, expense_claim_id, line_number, expense_date, description, amount, nominal_account_id) VALUES (:id, :claim_id, 1, :date, :description, :amount, :nominal_id)', [
            'id' => $expenseLineId,
            'claim_id' => $expenseClaimId,
            'date' => '2026-05-12',
            'description' => 'Expense vehicle ' . $marker,
            'amount' => 18000.00,
            'nominal_id' => $vehicleNominalId,
        ]);
        vehicleServiceInsertJournalLine($expenseJournalId, $vehicleNominalId, 18000.00, 0.00, 'Expense vehicle');
        vehicleServiceInsertJournalLine($expenseJournalId, $payableNominalId, 0.00, 18000.00, 'Payable');
        vehicleServiceInsertAssetWithId($expenseAssetId, $companyId, $periodId, 'EX-VEH-' . $marker, 'van', $vehicleNominalId, 18000.00, '2026-05-12', $expenseJournalId, null, $expenseLineId);
        \InterfaceDB::prepareExecute('INSERT INTO expense_claim_line_assets (expense_claim_line_id, category, generated_asset_id) VALUES (:line_id, :category, :asset_id)', [
            'line_id' => $expenseLineId,
            'category' => 'van',
            'asset_id' => $expenseAssetId,
        ]);

        $callback([
            'company_id' => $companyId,
            'period_id' => $periodId,
            'transaction_id' => $transactionId,
            'transaction_journal_id' => $transactionJournalId,
            'transaction_asset_id' => $transactionAssetId,
            'expense_journal_id' => $expenseJournalId,
            'expense_claim_id' => $expenseClaimId,
            'expense_line_id' => $expenseLineId,
            'expense_asset_id' => $expenseAssetId,
        ]);
    } finally {
        if (\InterfaceDB::inTransaction()) {
            \InterfaceDB::rollBack();
        }
    }
}

function vehicleServiceNominalId(string $code): int
{
    $id = (int)\InterfaceDB::fetchColumn('SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1', ['code' => $code]);
    if ($id > 0) {
        return $id;
    }

    \InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, tax_treatment, is_active, sort_order) VALUES (:code, :name, :type, :tax, 1, :sort)',
        ['code' => $code, 'name' => 'Fixture ' . $code, 'type' => $code === '2110' ? 'liability' : 'asset', 'tax' => 'capital', 'sort' => (int)$code]
    );

    return (int)\InterfaceDB::fetchColumn('SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1', ['code' => $code]);
}

function vehicleServiceInsertJournal(int $id, int $companyId, int $periodId, string $sourceType, string $sourceRef, string $date, string $description): void
{
    \InterfaceDB::prepareExecute(
        'INSERT INTO journals (id, company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted) VALUES (:id, :company_id, :period_id, :source_type, :source_ref, :date, :description, 1)',
        ['id' => $id, 'company_id' => $companyId, 'period_id' => $periodId, 'source_type' => $sourceType, 'source_ref' => $sourceRef, 'date' => $date, 'description' => $description]
    );
}

function vehicleServiceInsertJournalLine(int $journalId, int $nominalId, float $debit, float $credit, string $description): void
{
    \InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description) VALUES (:journal_id, :nominal_id, :debit, :credit, :description)',
        ['journal_id' => $journalId, 'nominal_id' => $nominalId, 'debit' => $debit, 'credit' => $credit, 'description' => $description]
    );
}

function vehicleServiceInsertAsset(int $companyId, int $periodId, string $code, string $category, string $nominalCode, float $cost, string $purchaseDate): int
{
    $assetId = random_int(1000000, 1999999);
    vehicleServiceInsertAssetWithId($assetId, $companyId, $periodId, $code . '-' . $assetId, $category, vehicleServiceNominalId($nominalCode), $cost, $purchaseDate, null, null, null);

    return $assetId;
}

function vehicleServiceInsertAssetWithId(int $assetId, int $companyId, int $periodId, string $code, string $category, int $nominalId, float $cost, string $purchaseDate, ?int $journalId, ?int $transactionId, ?int $expenseLineId): void
{
    \InterfaceDB::prepareExecute(
        'INSERT INTO asset_register (id, company_id, asset_code, description, category, nominal_account_id, accum_dep_nominal_id, purchase_date, cost, useful_life_years, depreciation_method, residual_value, status, linked_journal_id, linked_transaction_id, linked_expense_claim_line_id)
         VALUES (:id, :company_id, :asset_code, :description, :category, :nominal_account_id, :accum_dep_nominal_id, :purchase_date, :cost, 3, :method, 0.00, :status, :journal_id, :transaction_id, :expense_line_id)',
        [
            'id' => $assetId,
            'company_id' => $companyId,
            'asset_code' => $code,
            'description' => $code,
            'category' => $category,
            'nominal_account_id' => $nominalId,
            'accum_dep_nominal_id' => vehicleServiceNominalId('1350'),
            'purchase_date' => $purchaseDate,
            'cost' => $cost,
            'method' => 'straight_line',
            'status' => 'active',
            'journal_id' => $journalId,
            'transaction_id' => $transactionId,
            'expense_line_id' => $expenseLineId,
        ]
    );
}

function vehicleServiceInsertVehicleDetail(int $companyId, int $assetId, string $type, string $condition, int $zeroEmission, int $co2): void
{
    \InterfaceDB::prepareExecute(
        'INSERT INTO asset_vehicle_details (asset_id, company_id, vehicle_type, acquisition_condition, is_zero_emission, co2_emissions_g_km, tax_review_status)
         VALUES (:asset_id, :company_id, :vehicle_type, :condition, :zero, :co2, :status)',
        ['asset_id' => $assetId, 'company_id' => $companyId, 'vehicle_type' => $type, 'condition' => $condition, 'zero' => $zeroEmission, 'co2' => $co2, 'status' => 'reviewed']
    );
}

function vehicleServiceFindPool(array $rows, string $pool): array
{
    foreach ($rows as $row) {
        if ((string)($row['pool_type'] ?? '') === $pool) {
            return $row;
        }
    }

    return [];
}

function vehicleServiceEnsureTaxRateRules(): void
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
        ['main_pool_wda', 'rate', 0.14, null, '2026-04-01', '9999-12-31'],
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
                'rule_version' => 'vehicle-fixture-' . (string)$index . '-' . random_int(100000, 999999),
                'notes' => 'Fixture sourced tax rate rule.',
            ]
        );
    }
}
