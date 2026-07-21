<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

/**
 * Isolated capital-allowance fixtures. Every scenario starts a transaction;
 * GeneratedServiceClassTestHarness rolls it back even when an assertion fails.
 */
final class GoldenCapitalAllowanceMatrixFixture
{
    /**
     * @param list<array{id: int, start: string, end: string}> $periods
     * @return array{bank: int, sales: int, plant: int, car: int, accumulated_depreciation: int, unreviewed_vehicle: int}
     */
    public static function beginScenario(
        int $companyId,
        array $periods,
        string $companyStatus = 'active',
        bool $isActive = true
    ): array {
        InterfaceDB::beginTransaction();
        self::seedCapitalAllowanceRules();

        self::insert('companies', [
            'id' => $companyId,
            'company_name' => 'GOLDEN-TEST Capital Allowance ' . $companyId,
            'company_number' => 'GCA' . $companyId,
            'incorporation_date' => '2098-01-01',
            'company_status' => $companyStatus,
            'is_vat_registered' => 0,
            'is_active' => $isActive ? 1 : 0,
        ]);

        foreach ($periods as $period) {
            self::insert('accounting_periods', [
                'id' => $period['id'],
                'company_id' => $companyId,
                'label' => $period['start'] . ' to ' . $period['end'],
                'period_start' => $period['start'],
                'period_end' => $period['end'],
            ]);
        }

        $nominals = [
            'bank' => self::nominal('GCA-BANK-' . $companyId, 'Golden CA bank', 'asset', 'other'),
            'sales' => self::nominal('GCA-SALES-' . $companyId, 'Golden CA sales', 'income', 'allowable'),
            'plant' => self::nominal('GCA-PLANT-' . $companyId, 'Golden CA plant', 'asset', 'capital'),
            'car' => self::nominal('GCA-CAR-' . $companyId, 'Golden CA cars', 'asset', 'capital'),
            'accumulated_depreciation' => self::nominal('GCA-ACCDEP-' . $companyId, 'Golden CA accumulated depreciation', 'asset', 'capital'),
            'unreviewed_vehicle' => self::nominal('1320', 'Motor Vehicles', 'asset', 'capital'),
        ];
        self::insert('company_settings', [
            'company_id' => $companyId,
            'setting' => 'participator_loan_asset_nominal_id',
            'type' => 'int',
            'value' => (string)self::nominal('GCA-DLA-' . $companyId, 'Golden CA director loan asset', 'asset', 'other'),
        ]);
        self::insert('company_settings', [
            'company_id' => $companyId,
            'setting' => 'participator_loan_liability_nominal_id',
            'type' => 'int',
            'value' => (string)self::nominal('GCA-DLL-' . $companyId, 'Golden CA director loan liability', 'liability', 'other'),
        ]);

        return $nominals;
    }

    public static function addAsset(
        int $id,
        int $companyId,
        int $nominalId,
        int $accumulatedDepreciationNominalId,
        string $assetCode,
        string $category,
        string $purchaseDate,
        float $cost,
        array $overrides = []
    ): void {
        self::insert('asset_register', array_merge([
            'id' => $id,
            'company_id' => $companyId,
            'asset_code' => $assetCode,
            'description' => 'GOLDEN-TEST ' . $assetCode,
            'category' => $category,
            'nominal_account_id' => $nominalId,
            'accum_dep_nominal_id' => $accumulatedDepreciationNominalId,
            'purchase_date' => $purchaseDate,
            'cost' => $cost,
            'useful_life_years' => 5,
            'depreciation_method' => 'straight_line',
            // Keep the CT reconciliation focused on capital allowances rather
            // than a separate depreciation-preview calculation.
            'residual_value' => $cost,
            'status' => 'active',
        ], $overrides));
    }

    public static function addVehicle(
        int $assetId,
        int $companyId,
        string $condition,
        bool $zeroEmission,
        ?int $co2,
        string $firstRegisteredDate = '2098-01-01'
    ): void {
        self::insert('asset_vehicle_details', [
            'asset_id' => $assetId,
            'company_id' => $companyId,
            'vehicle_type' => 'car',
            'first_registered_date' => $firstRegisteredDate,
            'acquisition_condition' => $condition,
            'is_zero_emission' => $zeroEmission ? 1 : 0,
            'co2_emissions_g_km' => $co2,
            'tax_review_status' => 'reviewed',
            'reviewed_at' => '2098-01-01 09:00:00',
            'reviewed_by' => 'golden-test',
        ]);
    }

    public static function setCessationDate(int $companyId, string $date): void
    {
        self::insert('company_settings', [
            'company_id' => $companyId,
            'setting' => 'qualifying_activity_ceased_on',
            'type' => 'char',
            'value' => $date,
        ]);
    }

    public static function addSalesJournal(
        int $id,
        int $companyId,
        int $periodId,
        string $date,
        int $bankNominalId,
        int $salesNominalId,
        float $amount
    ): void {
        self::insert('journals', [
            'id' => $id,
            'company_id' => $companyId,
            'accounting_period_id' => $periodId,
            'source_type' => 'manual',
            'source_ref' => 'GOLDEN-CA-SALES-' . $id,
            'journal_date' => $date,
            'description' => 'GOLDEN-TEST capital allowance reconciliation revenue',
            'is_posted' => 1,
        ]);
        self::insert('journal_lines', [
            'journal_id' => $id,
            'nominal_account_id' => $bankNominalId,
            'debit' => $amount,
            'credit' => 0.0,
        ]);
        self::insert('journal_lines', [
            'journal_id' => $id,
            'nominal_account_id' => $salesNominalId,
            'debit' => 0.0,
            'credit' => $amount,
        ]);
    }

    public static function insertStalePool(int $companyId, int $periodId, int $ctPeriodId): string
    {
        $hash = str_repeat('s', 64);
        self::insert('capital_allowance_pool_runs', [
            'company_id' => $companyId,
            'accounting_period_id' => $periodId,
            'ct_period_id' => $ctPeriodId,
            'pool_type' => 'main_pool',
            'opening_wdv' => 999.0,
            'additions' => 999.0,
            'aia_claimed' => 0.0,
            'fya_claimed' => 0.0,
            'disposal_value' => 0.0,
            'wda_claimed' => 0.0,
            'balancing_charge' => 0.0,
            'balancing_allowance' => 0.0,
            'closing_wdv' => 999.0,
            'warnings_json' => '[]',
            'run_hash' => $hash,
        ]);

        return $hash;
    }

    public static function insertLegacyServicePool(int $companyId, int $periodId, int $ctPeriodId): string
    {
        $payload = [
            'company_id' => $companyId,
            'accounting_period_id' => $periodId,
            'pool_type' => 'main_pool',
            'opening_wdv' => 999.0,
            'additions' => 999.0,
            'aia_claimed' => 0.0,
            'fya_claimed' => 0.0,
            'disposal_value' => 0.0,
            'wda_claimed' => 0.0,
            'balancing_charge' => 0.0,
            'balancing_allowance' => 0.0,
            'closing_wdv' => 999.0,
            'warnings_json' => '[]',
            'ct_period_id' => $ctPeriodId,
        ];
        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES));
        self::insert('capital_allowance_pool_runs', array_merge($payload, ['run_hash' => $hash]));

        return $hash;
    }

    public static function ctPeriodId(int $companyId, int $periodId): int
    {
        return (int)InterfaceDB::fetchColumn(
            'SELECT id
             FROM corporation_tax_periods
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND status <> :superseded
             ORDER BY sequence_no ASC, id ASC
             LIMIT 1',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $periodId,
                'superseded' => 'superseded',
            ]
        );
    }

    /** @return array<string, array<string, mixed>> */
    public static function pools(int $companyId, int $periodId, int $ctPeriodId): array
    {
        $breakdown = (new \eel_accounts\Service\CapitalAllowanceService())
            ->fetchPeriodBreakdown($companyId, $periodId, $ctPeriodId);
        $pools = [];
        foreach ((array)($breakdown['rows'] ?? []) as $row) {
            $pools[(string)$row['pool_type']] = $row;
        }

        return $pools;
    }

    /** @return list<array<string, mixed>> */
    public static function assetCalculations(int $companyId, int $periodId): array
    {
        return InterfaceDB::fetchAll(
            'SELECT asset_id, pool_type, allowance_type, addition_amount, allowance_amount, disposal_value, warning
             FROM capital_allowance_asset_calculations
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             ORDER BY asset_id ASC, id ASC',
            ['company_id' => $companyId, 'accounting_period_id' => $periodId]
        ) ?: [];
    }

    public static function money(mixed $value): float
    {
        return round((float)$value, 2);
    }

    public static function fixedNineteenPercentRateService(): \eel_accounts\Service\CorporationTaxRateService
    {
        $rules = [];
        foreach ([2098, 2099, 2100] as $financialYear) {
            $rules[] = [
                'financial_year_start' => $financialYear . '-04-01',
                'financial_year_end' => ($financialYear + 1) . '-03-31',
                'rule_version' => 'golden-ca-' . $financialYear,
                'main_rate' => 0.19,
                'small_profits_rate' => null,
                'lower_limit' => null,
                'upper_limit' => null,
                'marginal_relief_fraction' => null,
                'source_url' => 'https://example.test/golden-capital-allowances',
                'source_checked_at' => '2026-07-15',
                'is_active' => 1,
            ];
        }

        return new \eel_accounts\Service\CorporationTaxRateService($rules);
    }

    public static function rollbackAfter(callable $scenario): void
    {
        try {
            $scenario();
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    }

    private static function seedCapitalAllowanceRules(): void
    {
        InterfaceDB::prepareExecute(
            'DELETE FROM tax_rate_rules WHERE tax_domain = :tax_domain',
            ['tax_domain' => 'capital_allowances']
        );

        foreach ([
            ['aia_annual_limit', 'Annual investment allowance limit', 'amount', null, 1000.0],
            ['main_pool_wda', 'Main pool writing down allowance', 'rate', 0.18, null],
            ['special_rate_pool_wda', 'Special rate pool writing down allowance', 'rate', 0.06, null],
        ] as [$key, $label, $type, $rate, $amount]) {
            self::insert('tax_rate_rules', [
                'tax_domain' => 'capital_allowances',
                'regime' => 'plant_machinery',
                'rule_key' => $key,
                'rule_label' => $label,
                'period_start' => '2098-01-01',
                'period_end' => '2101-12-31',
                'value_type' => $type,
                'rate_value' => $rate,
                'amount_value' => $amount,
                'source_url' => 'https://example.test/golden-capital-allowances',
                'source_checked_at' => '2026-07-15',
                'rule_version' => 'golden-ca-matrix-' . $key,
                'is_active' => 1,
            ]);
        }
    }

    private static function nominal(string $code, string $name, string $type, string $taxTreatment): int
    {
        $existing = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1',
            ['code' => $code]
        );
        if ($existing > 0) {
            return $existing;
        }

        self::insert('nominal_accounts', [
            'code' => $code,
            'name' => $name,
            'account_type' => $type,
            'tax_treatment' => $taxTreatment,
            'is_active' => 1,
        ]);

        return (int)InterfaceDB::fetchColumn(
            'SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1',
            ['code' => $code]
        );
    }

    /** @param array<string, scalar|null> $values */
    private static function insert(string $table, array $values): void
    {
        $columns = array_keys($values);
        $quoted = array_map(static fn(string $column): string => '`' . $column . '`', $columns);
        $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);
        InterfaceDB::execute(
            'INSERT INTO `' . $table . '` (' . implode(', ', $quoted) . ') VALUES (' . implode(', ', $placeholders) . ')',
            $values
        );
    }
}

$harness = new GeneratedServiceClassTestHarness();
$subject = 'GoldenCapitalAllowanceMatrix';

$harness->check($subject, 'calculates missing and stale open-period pool state without mutating it on read', static function () use ($harness): void {
    GoldenCapitalAllowanceMatrixFixture::rollbackAfter(static function () use ($harness): void {
    $companyId = 98101;
    $periodId = 981011;
    GoldenCapitalAllowanceMatrixFixture::beginScenario($companyId, [
        ['id' => $periodId, 'start' => '2099-01-01', 'end' => '2099-12-31'],
    ]);

    $service = new \eel_accounts\Service\CapitalAllowanceService();
    $missing = $service->fetchPeriodBreakdown($companyId, $periodId);
    $harness->assertSame(true, (bool)$missing['available']);
    $harness->assertSame('transient', (string)($missing['calculation_source'] ?? ''));
    $harness->assertCount(2, (array)$missing['rows']);
    $calculationCache = new ReflectionProperty($service, 'calculationCache');
    $harness->assertSame(1, count((array)$calculationCache->getValue($service)));
    $service->fetchPeriodBreakdown($companyId, $periodId);
    $harness->assertSame(1, count((array)$calculationCache->getValue($service)));
    $service->clearRuntimeCache($companyId);
    $harness->assertSame(0, count((array)$calculationCache->getValue($service)));
    $harness->assertSame(
        0,
        (int)InterfaceDB::fetchColumn(
            'SELECT COUNT(*) FROM capital_allowance_pool_runs WHERE company_id = :company_id',
            ['company_id' => $companyId]
        )
    );
    $harness->assertSame(
        0,
        (int)InterfaceDB::fetchColumn(
            'SELECT COUNT(*) FROM corporation_tax_periods WHERE company_id = :company_id',
            ['company_id' => $companyId]
        )
    );

    $sync = (new \eel_accounts\Service\CorporationTaxPeriodService())->syncForAccountingPeriod($companyId, $periodId);
    $harness->assertSame(true, (bool)($sync['success'] ?? false));
    $ctPeriodId = GoldenCapitalAllowanceMatrixFixture::ctPeriodId($companyId, $periodId);
    $staleHash = GoldenCapitalAllowanceMatrixFixture::insertStalePool($companyId, $periodId, $ctPeriodId);
    $stale = $service->fetchPeriodBreakdown($companyId, $periodId, $ctPeriodId);
    $harness->assertSame('transient', (string)($stale['calculation_source'] ?? ''));
    $harness->assertCount(2, (array)($stale['rows'] ?? []));
    foreach ((array)($stale['rows'] ?? []) as $row) {
        $harness->assertSame(0.0, GoldenCapitalAllowanceMatrixFixture::money($row['closing_wdv'] ?? -1));
    }
    $harness->assertSame(
        1,
        (int)InterfaceDB::fetchColumn(
            'SELECT COUNT(*) FROM capital_allowance_pool_runs WHERE company_id = :company_id AND run_hash = :run_hash',
            ['company_id' => $companyId, 'run_hash' => $staleHash]
        )
    );

    $service->rebuildForCompany($companyId);
    $rebuilt = $service->fetchPeriodBreakdown($companyId, $periodId, $ctPeriodId);
    $harness->assertSame(true, (bool)$rebuilt['available']);
    $harness->assertCount(2, $rebuilt['rows']);
    foreach ($rebuilt['rows'] as $row) {
        $harness->assertSame(0.0, GoldenCapitalAllowanceMatrixFixture::money($row['closing_wdv'] ?? -1));
    }
    $remainingStale = (int)InterfaceDB::fetchColumn(
        'SELECT COUNT(*) FROM capital_allowance_pool_runs WHERE company_id = :company_id AND run_hash = :run_hash',
        ['company_id' => $companyId, 'run_hash' => $staleHash]
    );
    $harness->assertSame(0, $remainingStale);
    });
});

$harness->check($subject, 'leaves recognisable legacy runs untouched on read and replaces them only on explicit rebuild', static function () use ($harness): void {
    GoldenCapitalAllowanceMatrixFixture::rollbackAfter(static function () use ($harness): void {
    $companyId = 98111;
    $periodId = 981111;
    GoldenCapitalAllowanceMatrixFixture::beginScenario($companyId, [
        ['id' => $periodId, 'start' => '2099-01-01', 'end' => '2099-12-31'],
    ]);
    (new \eel_accounts\Service\CorporationTaxPeriodService())->syncForAccountingPeriod($companyId, $periodId);
    $ctPeriodId = GoldenCapitalAllowanceMatrixFixture::ctPeriodId($companyId, $periodId);
    $legacyHash = GoldenCapitalAllowanceMatrixFixture::insertLegacyServicePool(
        $companyId,
        $periodId,
        $ctPeriodId
    );

    $breakdown = (new \eel_accounts\Service\CapitalAllowanceService())
        ->fetchPeriodBreakdown($companyId, $periodId, $ctPeriodId);

    $harness->assertSame(true, (bool)($breakdown['available'] ?? false));
    $harness->assertSame('transient', (string)($breakdown['calculation_source'] ?? ''));
    $harness->assertCount(2, (array)($breakdown['rows'] ?? []));
    foreach ((array)($breakdown['rows'] ?? []) as $row) {
        $harness->assertSame(0.0, GoldenCapitalAllowanceMatrixFixture::money($row['closing_wdv'] ?? -1));
    }
    $harness->assertSame(
        1,
        (int)InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM capital_allowance_pool_runs
             WHERE company_id = :company_id
               AND run_hash = :run_hash',
            ['company_id' => $companyId, 'run_hash' => $legacyHash]
        )
    );
    (new \eel_accounts\Service\CapitalAllowanceService())->rebuildForCompany($companyId);
    $harness->assertSame(
        0,
        (int)InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM capital_allowance_pool_runs
             WHERE company_id = :company_id
               AND run_hash = :run_hash',
            ['company_id' => $companyId, 'run_hash' => $legacyHash]
        )
    );
    $currentHashes = InterfaceDB::fetchAll(
        'SELECT run_hash
         FROM capital_allowance_pool_runs
         WHERE company_id = :company_id
         ORDER BY pool_type ASC',
        ['company_id' => $companyId]
    ) ?: [];
    $harness->assertCount(2, $currentHashes);
    foreach ($currentHashes as $row) {
        $hash = (string)($row['run_hash'] ?? '');
        $harness->assertSame(64, strlen($hash));
        $harness->assertTrue(str_starts_with($hash, 'ca02:'));
    }
    });
});

$harness->check($subject, 'preserves locked prior-period evidence when a later-period asset changes', static function () use ($harness): void {
    GoldenCapitalAllowanceMatrixFixture::rollbackAfter(static function () use ($harness): void {
    $companyId = 98115;
    $firstPeriodId = 981151;
    $secondPeriodId = 981152;
    $nominals = GoldenCapitalAllowanceMatrixFixture::beginScenario($companyId, [
        ['id' => $firstPeriodId, 'start' => '2099-01-01', 'end' => '2099-12-31'],
        ['id' => $secondPeriodId, 'start' => '2100-01-01', 'end' => '2100-12-31'],
    ]);
    GoldenCapitalAllowanceMatrixFixture::addAsset(
        9811501,
        $companyId,
        $nominals['car'],
        $nominals['accumulated_depreciation'],
        'GCA-LOCKED-PRIOR-CAR',
        'car',
        '2099-01-15',
        10000.0
    );
    GoldenCapitalAllowanceMatrixFixture::addVehicle(9811501, $companyId, 'second_hand', false, 75);
    GoldenCapitalAllowanceMatrixFixture::addAsset(
        9811503,
        $companyId,
        $nominals['car'],
        $nominals['accumulated_depreciation'],
        'GCA-LOCKED-PRIOR-FYA',
        'car',
        '2099-02-01',
        2000.0,
        [
            'status' => 'disposed',
            'disposal_date' => '2100-06-30',
            'disposal_proceeds' => 800.0,
            'disposal_event_type' => 'sale',
        ]
    );
    GoldenCapitalAllowanceMatrixFixture::addVehicle(9811503, $companyId, 'new_unused', true, 0);
    GoldenCapitalAllowanceMatrixFixture::addAsset(
        9811504,
        $companyId,
        $nominals['plant'],
        $nominals['accumulated_depreciation'],
        'GCA-LOCKED-PRIOR-AIA',
        'tools_equipment',
        '2099-03-01',
        300.0,
        [
            'status' => 'disposed',
            'disposal_date' => '2100-07-01',
            'disposal_proceeds' => 100.0,
            'disposal_event_type' => 'sale',
        ]
    );
    GoldenCapitalAllowanceMatrixFixture::addAsset(
        9811502,
        $companyId,
        $nominals['plant'],
        $nominals['accumulated_depreciation'],
        'GCA-LATER-ASSET',
        'tools_equipment',
        '2100-02-01',
        500.0
    );

    $service = new \eel_accounts\Service\CapitalAllowanceService();
    $initial = $service->rebuildForCompany($companyId);
    $harness->assertSame(true, (bool)($initial['success'] ?? false));
    InterfaceDB::prepareExecute(
        'UPDATE year_end_reviews
         SET is_locked = 1, locked_at = CURRENT_TIMESTAMP, locked_by = :locked_by
         WHERE company_id = :company_id
           AND accounting_period_id = :accounting_period_id',
        [
            'locked_by' => 'golden-test',
            'company_id' => $companyId,
            'accounting_period_id' => $firstPeriodId,
        ]
    );
    $priorPools = InterfaceDB::fetchAll(
        'SELECT id, ct_period_id, pool_type, opening_wdv, additions, aia_claimed, fya_claimed,
                disposal_value, wda_claimed, balancing_charge, balancing_allowance, closing_wdv,
                warnings_json, run_hash
         FROM capital_allowance_pool_runs
         WHERE company_id = :company_id
           AND accounting_period_id = :accounting_period_id
         ORDER BY id ASC',
        ['company_id' => $companyId, 'accounting_period_id' => $firstPeriodId]
    ) ?: [];
    $priorAssets = InterfaceDB::fetchAll(
        'SELECT id, ct_period_id, asset_id, pool_type, allowance_type,
                addition_amount, allowance_amount, disposal_value, warning
         FROM capital_allowance_asset_calculations
         WHERE company_id = :company_id
           AND accounting_period_id = :accounting_period_id
         ORDER BY id ASC',
        ['company_id' => $companyId, 'accounting_period_id' => $firstPeriodId]
    ) ?: [];

    InterfaceDB::prepareExecute(
        'UPDATE asset_register SET cost = :cost WHERE id = :id',
        ['cost' => 750.0, 'id' => 9811502]
    );
    $rebuilt = $service->rebuildForCompany($companyId);
    $harness->assertSame(true, (bool)($rebuilt['success'] ?? false));
    $harness->assertSame($priorPools, InterfaceDB::fetchAll(
        'SELECT id, ct_period_id, pool_type, opening_wdv, additions, aia_claimed, fya_claimed,
                disposal_value, wda_claimed, balancing_charge, balancing_allowance, closing_wdv,
                warnings_json, run_hash
         FROM capital_allowance_pool_runs
         WHERE company_id = :company_id
           AND accounting_period_id = :accounting_period_id
         ORDER BY id ASC',
        ['company_id' => $companyId, 'accounting_period_id' => $firstPeriodId]
    ) ?: []);
    $harness->assertSame($priorAssets, InterfaceDB::fetchAll(
        'SELECT id, ct_period_id, asset_id, pool_type, allowance_type,
                addition_amount, allowance_amount, disposal_value, warning
         FROM capital_allowance_asset_calculations
         WHERE company_id = :company_id
           AND accounting_period_id = :accounting_period_id
         ORDER BY id ASC',
        ['company_id' => $companyId, 'accounting_period_id' => $firstPeriodId]
    ) ?: []);

    $blocked = $service->persistForAccountingPeriod($companyId, $firstPeriodId);
    $harness->assertSame(false, (bool)($blocked['success'] ?? true));
    $harness->assertTrue(str_contains(
        implode(' ', array_map('strval', (array)($blocked['errors'] ?? []))),
        'locked'
    ));

    $firstSpecial = GoldenCapitalAllowanceMatrixFixture::pools(
        $companyId,
        $firstPeriodId,
        GoldenCapitalAllowanceMatrixFixture::ctPeriodId($companyId, $firstPeriodId)
    )['special_rate_pool'];
    $secondSpecial = GoldenCapitalAllowanceMatrixFixture::pools(
        $companyId,
        $secondPeriodId,
        GoldenCapitalAllowanceMatrixFixture::ctPeriodId($companyId, $secondPeriodId)
    )['special_rate_pool'];
    $harness->assertSame(
        GoldenCapitalAllowanceMatrixFixture::money($firstSpecial['closing_wdv'] ?? 0),
        GoldenCapitalAllowanceMatrixFixture::money($secondSpecial['opening_wdv'] ?? -1)
    );
    $secondMain = GoldenCapitalAllowanceMatrixFixture::pools(
        $companyId,
        $secondPeriodId,
        GoldenCapitalAllowanceMatrixFixture::ctPeriodId($companyId, $secondPeriodId)
    )['main_pool'];
    $harness->assertSame(0.0, GoldenCapitalAllowanceMatrixFixture::money($secondMain['opening_wdv'] ?? -1));
    $harness->assertSame(0.0, GoldenCapitalAllowanceMatrixFixture::money($secondMain['additions'] ?? -1));
    $harness->assertSame(900.0, GoldenCapitalAllowanceMatrixFixture::money($secondMain['disposal_value'] ?? 0));
    $harness->assertSame(900.0, GoldenCapitalAllowanceMatrixFixture::money($secondMain['balancing_charge'] ?? 0));
    $harness->assertSame(0.0, GoldenCapitalAllowanceMatrixFixture::money($secondMain['closing_wdv'] ?? -1));
    });
});

$harness->check($subject, 'reports read calculation failure and rolls back a CT-period synchronisation failure', static function () use ($harness): void {
    GoldenCapitalAllowanceMatrixFixture::rollbackAfter(static function () use ($harness): void {
    $companyId = 98116;
    $periodId = 981161;
    GoldenCapitalAllowanceMatrixFixture::beginScenario($companyId, [
        ['id' => $periodId, 'start' => '2099-01-01', 'end' => '2099-12-31'],
    ]);
    $initialService = new \eel_accounts\Service\CapitalAllowanceService();
    $initial = $initialService->rebuildForCompany($companyId);
    $harness->assertSame(true, (bool)($initial['success'] ?? false));
    $before = InterfaceDB::fetchAll(
        'SELECT id, pool_type, opening_wdv, closing_wdv, run_hash
         FROM capital_allowance_pool_runs
         WHERE company_id = :company_id
         ORDER BY id ASC',
        ['company_id' => $companyId]
    ) ?: [];
    InterfaceDB::prepareExecute(
        'UPDATE corporation_tax_periods
         SET status = :status
         WHERE company_id = :company_id
           AND accounting_period_id = :accounting_period_id',
        [
            'status' => 'submitted',
            'company_id' => $companyId,
            'accounting_period_id' => $periodId,
        ]
    );
    $finalRead = $initialService->fetchPeriodBreakdown(
        $companyId,
        $periodId,
        GoldenCapitalAllowanceMatrixFixture::ctPeriodId($companyId, $periodId)
    );
    $harness->assertSame('persisted', (string)($finalRead['calculation_source'] ?? ''));
    $harness->assertCount(2, (array)($finalRead['rows'] ?? []));
    InterfaceDB::prepareExecute(
        'UPDATE corporation_tax_periods
         SET status = :status
         WHERE company_id = :company_id
           AND accounting_period_id = :accounting_period_id',
        [
            'status' => 'pending',
            'company_id' => $companyId,
            'accounting_period_id' => $periodId,
        ]
    );
    InterfaceDB::prepareExecute(
        'UPDATE tax_rate_rules
         SET is_active = 0
         WHERE tax_domain = :tax_domain',
        ['tax_domain' => 'capital_allowances']
    );
    $failedRead = $initialService->fetchPeriodBreakdown(
        $companyId,
        $periodId,
        GoldenCapitalAllowanceMatrixFixture::ctPeriodId($companyId, $periodId)
    );
    $harness->assertSame(false, (bool)($failedRead['available'] ?? true));
    $harness->assertSame('calculation_failed', (string)($failedRead['calculation_source'] ?? ''));
    $harness->assertSame([], (array)($failedRead['rows'] ?? ['unexpected']));
    InterfaceDB::prepareExecute(
        'UPDATE tax_rate_rules
         SET is_active = 1
         WHERE tax_domain = :tax_domain',
        ['tax_domain' => 'capital_allowances']
    );

    $failingCtPeriodService = new \eel_accounts\Service\CorporationTaxPeriodService(
        static fn(int $candidateCompanyId): array => [
            'tax_year_end_read_only' => $candidateCompanyId === $companyId,
            'message' => 'Synthetic CT synchronisation failure.',
        ]
    );
    $service = new \eel_accounts\Service\CapitalAllowanceService(null, $failingCtPeriodService);
    $failed = $service->rebuildForCompany($companyId);
    $harness->assertSame(false, (bool)($failed['success'] ?? true));
    $harness->assertTrue(str_contains(
        implode(' ', array_map('strval', (array)($failed['errors'] ?? []))),
        'Synthetic CT synchronisation failure'
    ));
    $harness->assertSame($before, InterfaceDB::fetchAll(
        'SELECT id, pool_type, opening_wdv, closing_wdv, run_hash
         FROM capital_allowance_pool_runs
         WHERE company_id = :company_id
         ORDER BY id ASC',
        ['company_id' => $companyId]
    ) ?: []);
    });
});

$harness->check($subject, 'pro-rates and exhausts AIA then reconciles asset rows, pools and CT summary', static function () use ($harness): void {
    GoldenCapitalAllowanceMatrixFixture::rollbackAfter(static function () use ($harness): void {
    $companyId = 98102;
    $periodId = 981021;
    $nominals = GoldenCapitalAllowanceMatrixFixture::beginScenario($companyId, [
        ['id' => $periodId, 'start' => '2099-01-01', 'end' => '2099-06-30'],
    ]);
    GoldenCapitalAllowanceMatrixFixture::addAsset(
        9810201,
        $companyId,
        $nominals['plant'],
        $nominals['accumulated_depreciation'],
        'GCA-AIA-PRORATED',
        'tools_equipment',
        '2099-01-10',
        1000.0
    );
    GoldenCapitalAllowanceMatrixFixture::addSalesJournal(
        9810291,
        $companyId,
        $periodId,
        '2099-02-01',
        $nominals['bank'],
        $nominals['sales'],
        2000.0
    );

    $service = new \eel_accounts\Service\CapitalAllowanceService();
    $result = $service->rebuildForCompany($companyId);
    $ctPeriodId = GoldenCapitalAllowanceMatrixFixture::ctPeriodId($companyId, $periodId);
    $pools = GoldenCapitalAllowanceMatrixFixture::pools($companyId, $periodId, $ctPeriodId);
    $main = $pools['main_pool'];

    $harness->assertSame(495.89, GoldenCapitalAllowanceMatrixFixture::money($main['aia_claimed']));
    $harness->assertSame(504.11, GoldenCapitalAllowanceMatrixFixture::money($main['additions']));
    $harness->assertSame(45.0, GoldenCapitalAllowanceMatrixFixture::money($main['wda_claimed']));
    $harness->assertSame(459.11, GoldenCapitalAllowanceMatrixFixture::money($main['closing_wdv']));
    $harness->assertSame(540.89, GoldenCapitalAllowanceMatrixFixture::money($result[$periodId]['net_capital_allowances'] ?? 0));

    $assetRows = GoldenCapitalAllowanceMatrixFixture::assetCalculations($companyId, $periodId);
    $harness->assertCount(2, $assetRows);
    $harness->assertSame('aia', (string)$assetRows[0]['allowance_type']);
    $harness->assertSame(1000.0, GoldenCapitalAllowanceMatrixFixture::money($assetRows[0]['addition_amount']));
    $harness->assertSame(495.89, GoldenCapitalAllowanceMatrixFixture::money($assetRows[0]['allowance_amount']));
    $harness->assertSame('main_pool_addition', (string)$assetRows[1]['allowance_type']);
    $harness->assertSame(504.11, GoldenCapitalAllowanceMatrixFixture::money($assetRows[1]['addition_amount']));

    $assetAllowance = array_sum(array_map(
        static fn(array $row): float => GoldenCapitalAllowanceMatrixFixture::money($row['allowance_amount'] ?? 0),
        $assetRows
    ));
    $poolNet = round(
        (float)$main['aia_claimed']
        + (float)$main['fya_claimed']
        + (float)$main['wda_claimed']
        + (float)$main['balancing_allowance']
        - (float)$main['balancing_charge'],
        2
    );
    $harness->assertSame(540.89, round($assetAllowance + (float)$main['wda_claimed'], 2));
    $harness->assertSame(540.89, $poolNet);

    $ctService = new \eel_accounts\Service\CorporationTaxComputationService(
        null,
        GoldenCapitalAllowanceMatrixFixture::fixedNineteenPercentRateService()
    );
    test_confirm_ct_period_facts($companyId, $periodId);
    $summary = $ctService->fetchSummaryForCtPeriodId($companyId, $ctPeriodId);
    $harness->assertSame(true, (bool)($summary['available'] ?? false));
    $harness->assertSame(2000.0, GoldenCapitalAllowanceMatrixFixture::money($summary['accounting_profit'] ?? 0));
    $harness->assertSame(0.0, GoldenCapitalAllowanceMatrixFixture::money($summary['depreciation_add_back'] ?? -1));
    $harness->assertSame($poolNet, GoldenCapitalAllowanceMatrixFixture::money($summary['capital_allowances'] ?? 0));
    $harness->assertSame(1459.11, GoldenCapitalAllowanceMatrixFixture::money($summary['taxable_profit'] ?? 0));
    $harness->assertSame(277.23, GoldenCapitalAllowanceMatrixFixture::money($summary['estimated_corporation_tax'] ?? 0));
    });
});

$harness->check($subject, 'applies FYA and CO2 pool rates while retaining review warnings', static function () use ($harness): void {
    GoldenCapitalAllowanceMatrixFixture::rollbackAfter(static function () use ($harness): void {
    $companyId = 98103;
    $periodId = 981031;
    $nominals = GoldenCapitalAllowanceMatrixFixture::beginScenario($companyId, [
        ['id' => $periodId, 'start' => '2099-01-01', 'end' => '2099-12-31'],
    ]);
    $accumulatedDepreciation = $nominals['accumulated_depreciation'];

    GoldenCapitalAllowanceMatrixFixture::addAsset(9810301, $companyId, $nominals['car'], $accumulatedDepreciation, 'GCA-FYA-ZERO', 'car', '2099-01-02', 10000.0);
    GoldenCapitalAllowanceMatrixFixture::addVehicle(9810301, $companyId, 'new_unused', true, 0);
    GoldenCapitalAllowanceMatrixFixture::addAsset(9810302, $companyId, $nominals['car'], $accumulatedDepreciation, 'GCA-MAIN-CO2-50', 'car', '2099-01-03', 10000.0);
    GoldenCapitalAllowanceMatrixFixture::addVehicle(9810302, $companyId, 'used', false, 50);
    GoldenCapitalAllowanceMatrixFixture::addAsset(9810303, $companyId, $nominals['car'], $accumulatedDepreciation, 'GCA-SPECIAL-CO2-51', 'car', '2099-01-04', 10000.0);
    GoldenCapitalAllowanceMatrixFixture::addVehicle(9810303, $companyId, 'used', false, 51);
    GoldenCapitalAllowanceMatrixFixture::addAsset(9810304, $companyId, $nominals['car'], $accumulatedDepreciation, 'GCA-MISSING-CO2', 'car', '2099-01-05', 5000.0);
    GoldenCapitalAllowanceMatrixFixture::addVehicle(9810304, $companyId, 'used', false, null);
    GoldenCapitalAllowanceMatrixFixture::addAsset(9810305, $companyId, $nominals['unreviewed_vehicle'], $accumulatedDepreciation, 'GCA-UNREVIEWED-1320', 'motor_vehicle', '2099-01-06', 4000.0);

    $service = new \eel_accounts\Service\CapitalAllowanceService();
    $result = $service->rebuildForCompany($companyId);
    $ctPeriodId = GoldenCapitalAllowanceMatrixFixture::ctPeriodId($companyId, $periodId);
    $pools = GoldenCapitalAllowanceMatrixFixture::pools($companyId, $periodId, $ctPeriodId);
    $main = $pools['main_pool'];
    $special = $pools['special_rate_pool'];

    $harness->assertSame(10000.0, GoldenCapitalAllowanceMatrixFixture::money($main['fya_claimed']));
    $harness->assertSame(10000.0, GoldenCapitalAllowanceMatrixFixture::money($main['additions']));
    $harness->assertSame(1800.0, GoldenCapitalAllowanceMatrixFixture::money($main['wda_claimed']));
    $harness->assertSame(8200.0, GoldenCapitalAllowanceMatrixFixture::money($main['closing_wdv']));
    $harness->assertSame(15000.0, GoldenCapitalAllowanceMatrixFixture::money($special['additions']));
    $harness->assertSame(900.0, GoldenCapitalAllowanceMatrixFixture::money($special['wda_claimed']));
    $harness->assertSame(14100.0, GoldenCapitalAllowanceMatrixFixture::money($special['closing_wdv']));
    $harness->assertSame(12700.0, GoldenCapitalAllowanceMatrixFixture::money($result[$periodId]['net_capital_allowances'] ?? 0));

    $warnings = (array)($result[$periodId]['warnings'] ?? []);
    $harness->assertCount(2, $warnings);
    $warningText = implode(' ', $warnings);
    $harness->assertTrue(str_contains($warningText, 'GCA-MISSING-CO2'));
    $harness->assertTrue(str_contains($warningText, 'GCA-UNREVIEWED-1320'));

    $assetRows = GoldenCapitalAllowanceMatrixFixture::assetCalculations($companyId, $periodId);
    $types = array_count_values(array_map(static fn(array $row): string => (string)$row['allowance_type'], $assetRows));
    $harness->assertSame(1, (int)($types['fya'] ?? 0));
    $harness->assertSame(1, (int)($types['main_pool_addition'] ?? 0));
    $harness->assertSame(2, (int)($types['special_rate_pool_addition'] ?? 0));
    $harness->assertSame(2, (int)($types['warning'] ?? 0));
    $perAssetAllowances = array_sum(array_map(
        static fn(array $row): float => GoldenCapitalAllowanceMatrixFixture::money($row['allowance_amount'] ?? 0),
        $assetRows
    ));
    $harness->assertSame(12700.0, round($perAssetAllowances + (float)$main['wda_claimed'] + (float)$special['wda_claimed'], 2));
    });
});

$harness->check($subject, 'carries WDV through disposal and recognises a balancing charge', static function () use ($harness): void {
    GoldenCapitalAllowanceMatrixFixture::rollbackAfter(static function () use ($harness): void {
    $companyId = 98104;
    $firstPeriodId = 981041;
    $secondPeriodId = 981042;
    $nominals = GoldenCapitalAllowanceMatrixFixture::beginScenario($companyId, [
        ['id' => $firstPeriodId, 'start' => '2099-01-01', 'end' => '2099-12-31'],
        ['id' => $secondPeriodId, 'start' => '2100-01-01', 'end' => '2100-12-31'],
    ]);
    GoldenCapitalAllowanceMatrixFixture::addAsset(
        9810401,
        $companyId,
        $nominals['car'],
        $nominals['accumulated_depreciation'],
        'GCA-DISPOSAL-CHARGE',
        'car',
        '2099-01-01',
        10000.0,
        [
            'status' => 'disposed',
            'disposal_date' => '2100-06-30',
            'disposal_proceeds' => 9000.0,
            'disposal_event_type' => 'sale',
        ]
    );
    GoldenCapitalAllowanceMatrixFixture::addVehicle(9810401, $companyId, 'used', false, 50);

    $result = (new \eel_accounts\Service\CapitalAllowanceService())->rebuildForCompany($companyId);
    $firstCtPeriodId = GoldenCapitalAllowanceMatrixFixture::ctPeriodId($companyId, $firstPeriodId);
    $secondCtPeriodId = GoldenCapitalAllowanceMatrixFixture::ctPeriodId($companyId, $secondPeriodId);
    $firstMain = GoldenCapitalAllowanceMatrixFixture::pools($companyId, $firstPeriodId, $firstCtPeriodId)['main_pool'];
    $secondMain = GoldenCapitalAllowanceMatrixFixture::pools($companyId, $secondPeriodId, $secondCtPeriodId)['main_pool'];

    $harness->assertSame(8200.0, GoldenCapitalAllowanceMatrixFixture::money($firstMain['closing_wdv']));
    $harness->assertSame(8200.0, GoldenCapitalAllowanceMatrixFixture::money($secondMain['opening_wdv']));
    $harness->assertSame(9000.0, GoldenCapitalAllowanceMatrixFixture::money($secondMain['disposal_value']));
    $harness->assertSame(800.0, GoldenCapitalAllowanceMatrixFixture::money($secondMain['balancing_charge']));
    $harness->assertSame(0.0, GoldenCapitalAllowanceMatrixFixture::money($secondMain['wda_claimed']));
    $harness->assertSame(0.0, GoldenCapitalAllowanceMatrixFixture::money($secondMain['closing_wdv']));
    $harness->assertSame(-800.0, GoldenCapitalAllowanceMatrixFixture::money($result[$secondPeriodId]['net_capital_allowances'] ?? 0));

    $disposalRows = GoldenCapitalAllowanceMatrixFixture::assetCalculations($companyId, $secondPeriodId);
    $harness->assertCount(1, $disposalRows);
    $harness->assertSame('disposal_value', (string)$disposalRows[0]['allowance_type']);
    $harness->assertSame(9000.0, GoldenCapitalAllowanceMatrixFixture::money($disposalRows[0]['disposal_value']));
    });
});

$harness->check($subject, 'recognises the residual pool as a balancing allowance on cessation', static function () use ($harness): void {
    GoldenCapitalAllowanceMatrixFixture::rollbackAfter(static function () use ($harness): void {
    $companyId = 98105;
    $firstPeriodId = 981051;
    $finalPeriodId = 981052;
    $laterPeriodId = 981053;
    $nominals = GoldenCapitalAllowanceMatrixFixture::beginScenario($companyId, [
        ['id' => $firstPeriodId, 'start' => '2099-01-01', 'end' => '2099-12-31'],
        ['id' => $finalPeriodId, 'start' => '2100-01-01', 'end' => '2100-12-31'],
        ['id' => $laterPeriodId, 'start' => '2101-01-01', 'end' => '2101-12-31'],
    ]);
    GoldenCapitalAllowanceMatrixFixture::setCessationDate($companyId, '2100-06-30');
    GoldenCapitalAllowanceMatrixFixture::addAsset(
        9810501,
        $companyId,
        $nominals['car'],
        $nominals['accumulated_depreciation'],
        'GCA-CESSATION-ALLOWANCE',
        'car',
        '2099-01-01',
        10000.0,
        [
            'status' => 'disposed',
            'disposal_date' => '2100-06-30',
            'disposal_proceeds' => 2000.0,
            'disposal_event_type' => 'sale',
            'disposal_reason' => 'GOLDEN-TEST final disposal on cessation',
        ]
    );
    GoldenCapitalAllowanceMatrixFixture::addVehicle(9810501, $companyId, 'used', false, 50);

    $result = (new \eel_accounts\Service\CapitalAllowanceService())->rebuildForCompany($companyId);
    $finalCtPeriodId = GoldenCapitalAllowanceMatrixFixture::ctPeriodId($companyId, $finalPeriodId);
    $finalMain = GoldenCapitalAllowanceMatrixFixture::pools($companyId, $finalPeriodId, $finalCtPeriodId)['main_pool'];

    $expected = [
        'opening_wdv' => 8200.0,
        'disposal_value' => 2000.0,
        'wda_claimed' => 0.0,
        'balancing_allowance' => 6200.0,
        'closing_wdv' => 0.0,
        'net_capital_allowances' => 6200.0,
    ];
    $actual = [
        'opening_wdv' => GoldenCapitalAllowanceMatrixFixture::money($finalMain['opening_wdv']),
        'disposal_value' => GoldenCapitalAllowanceMatrixFixture::money($finalMain['disposal_value']),
        'wda_claimed' => GoldenCapitalAllowanceMatrixFixture::money($finalMain['wda_claimed']),
        'balancing_allowance' => GoldenCapitalAllowanceMatrixFixture::money($finalMain['balancing_allowance']),
        'closing_wdv' => GoldenCapitalAllowanceMatrixFixture::money($finalMain['closing_wdv']),
        'net_capital_allowances' => GoldenCapitalAllowanceMatrixFixture::money($result[$finalPeriodId]['net_capital_allowances'] ?? 0),
    ];
    if ($expected !== $actual) {
        throw new RuntimeException(
            'Cessation pool mismatch. Expected ' . json_encode($expected, JSON_UNESCAPED_SLASHES)
            . ' but received ' . json_encode($actual, JSON_UNESCAPED_SLASHES) . '.'
        );
    }

    $laterCtPeriodId = GoldenCapitalAllowanceMatrixFixture::ctPeriodId($companyId, $laterPeriodId);
    $laterPools = GoldenCapitalAllowanceMatrixFixture::pools($companyId, $laterPeriodId, $laterCtPeriodId);
    foreach (['main_pool', 'special_rate_pool'] as $poolType) {
        $harness->assertSame(0.0, GoldenCapitalAllowanceMatrixFixture::money($laterPools[$poolType]['opening_wdv'] ?? -1));
        $harness->assertSame(0.0, GoldenCapitalAllowanceMatrixFixture::money($laterPools[$poolType]['wda_claimed'] ?? -1));
        $harness->assertSame(0.0, GoldenCapitalAllowanceMatrixFixture::money($laterPools[$poolType]['closing_wdv'] ?? -1));
    }
    });
});

$harness->check($subject, 'recognises a balancing charge on cessation when disposal values exceed the pool', static function () use ($harness): void {
    GoldenCapitalAllowanceMatrixFixture::rollbackAfter(static function () use ($harness): void {
    $companyId = 98106;
    $firstPeriodId = 981061;
    $finalPeriodId = 981062;
    $nominals = GoldenCapitalAllowanceMatrixFixture::beginScenario($companyId, [
        ['id' => $firstPeriodId, 'start' => '2099-01-01', 'end' => '2099-12-31'],
        ['id' => $finalPeriodId, 'start' => '2100-01-01', 'end' => '2100-12-31'],
    ]);
    GoldenCapitalAllowanceMatrixFixture::setCessationDate($companyId, '2100-06-30');
    GoldenCapitalAllowanceMatrixFixture::addAsset(
        9810601,
        $companyId,
        $nominals['car'],
        $nominals['accumulated_depreciation'],
        'GCA-CESSATION-CHARGE',
        'car',
        '2099-01-01',
        10000.0,
        [
            'status' => 'disposed',
            'disposal_date' => '2100-06-30',
            'disposal_proceeds' => 9000.0,
            'disposal_event_type' => 'sale',
        ]
    );
    GoldenCapitalAllowanceMatrixFixture::addVehicle(9810601, $companyId, 'used', false, 50);

    $result = (new \eel_accounts\Service\CapitalAllowanceService())->rebuildForCompany($companyId);
    $finalCtPeriodId = GoldenCapitalAllowanceMatrixFixture::ctPeriodId($companyId, $finalPeriodId);
    $finalMain = GoldenCapitalAllowanceMatrixFixture::pools($companyId, $finalPeriodId, $finalCtPeriodId)['main_pool'];

    $harness->assertSame(0.0, GoldenCapitalAllowanceMatrixFixture::money($finalMain['wda_claimed']));
    $harness->assertSame(800.0, GoldenCapitalAllowanceMatrixFixture::money($finalMain['balancing_charge']));
    $harness->assertSame(0.0, GoldenCapitalAllowanceMatrixFixture::money($finalMain['balancing_allowance']));
    $harness->assertSame(0.0, GoldenCapitalAllowanceMatrixFixture::money($finalMain['closing_wdv']));
    $harness->assertSame(-800.0, GoldenCapitalAllowanceMatrixFixture::money($result[$finalPeriodId]['net_capital_allowances'] ?? 0));
    });
});

$harness->check($subject, 'requires disposal valuations for every pooled asset before claiming a cessation allowance', static function () use ($harness): void {
    GoldenCapitalAllowanceMatrixFixture::rollbackAfter(static function () use ($harness): void {
    $companyId = 98107;
    $firstPeriodId = 981071;
    $finalPeriodId = 981072;
    $laterPeriodId = 981073;
    $nominals = GoldenCapitalAllowanceMatrixFixture::beginScenario($companyId, [
        ['id' => $firstPeriodId, 'start' => '2099-01-01', 'end' => '2099-12-31'],
        ['id' => $finalPeriodId, 'start' => '2100-01-01', 'end' => '2100-12-31'],
        ['id' => $laterPeriodId, 'start' => '2101-01-01', 'end' => '2101-12-31'],
    ]);
    GoldenCapitalAllowanceMatrixFixture::setCessationDate($companyId, '2100-06-30');
    GoldenCapitalAllowanceMatrixFixture::addAsset(
        9810701,
        $companyId,
        $nominals['car'],
        $nominals['accumulated_depreciation'],
        'GCA-CESSATION-MISSING-VALUE',
        'car',
        '2099-01-01',
        10000.0
    );
    GoldenCapitalAllowanceMatrixFixture::addVehicle(9810701, $companyId, 'used', false, 50);

    $result = (new \eel_accounts\Service\CapitalAllowanceService())->rebuildForCompany($companyId);
    $finalCtPeriodId = GoldenCapitalAllowanceMatrixFixture::ctPeriodId($companyId, $finalPeriodId);
    $finalMain = GoldenCapitalAllowanceMatrixFixture::pools($companyId, $finalPeriodId, $finalCtPeriodId)['main_pool'];
    $warningText = implode(' ', (array)($result[$finalPeriodId]['warnings'] ?? []));

    $harness->assertSame(0.0, GoldenCapitalAllowanceMatrixFixture::money($finalMain['wda_claimed']));
    $harness->assertSame(0.0, GoldenCapitalAllowanceMatrixFixture::money($finalMain['balancing_allowance']));
    $harness->assertSame(8200.0, GoldenCapitalAllowanceMatrixFixture::money($finalMain['closing_wdv']));
    $harness->assertTrue(str_contains($warningText, 'GCA-CESSATION-MISSING-VALUE'));
    $harness->assertTrue(str_contains($warningText, 'no disposal value dated on or before cessation'));

    $taxSummary = (new \eel_accounts\Service\CorporationTaxComputationService(
        null,
        GoldenCapitalAllowanceMatrixFixture::fixedNineteenPercentRateService()
    ));
    test_confirm_ct_period_facts($companyId, $finalPeriodId);
    $taxSummary = $taxSummary->fetchSummaryForCtPeriodId($companyId, $finalCtPeriodId);
    $harness->assertSame('review_required', (string)($taxSummary['confidence_status'] ?? ''));
    $harness->assertTrue(str_contains(
        implode(' ', array_map('strval', (array)($taxSummary['warnings'] ?? []))),
        'GCA-CESSATION-MISSING-VALUE'
    ));

    $laterCtPeriodId = GoldenCapitalAllowanceMatrixFixture::ctPeriodId($companyId, $laterPeriodId);
    $laterMain = GoldenCapitalAllowanceMatrixFixture::pools($companyId, $laterPeriodId, $laterCtPeriodId)['main_pool'];
    $harness->assertSame(0.0, GoldenCapitalAllowanceMatrixFixture::money($laterMain['opening_wdv']));
    $harness->assertSame(0.0, GoldenCapitalAllowanceMatrixFixture::money($laterMain['closing_wdv']));
    });
});

$harness->check($subject, 'finalises the special-rate pool without a cessation-period WDA', static function () use ($harness): void {
    GoldenCapitalAllowanceMatrixFixture::rollbackAfter(static function () use ($harness): void {
    $companyId = 98108;
    $firstPeriodId = 981081;
    $finalPeriodId = 981082;
    $nominals = GoldenCapitalAllowanceMatrixFixture::beginScenario($companyId, [
        ['id' => $firstPeriodId, 'start' => '2099-01-01', 'end' => '2099-12-31'],
        ['id' => $finalPeriodId, 'start' => '2100-01-01', 'end' => '2100-12-31'],
    ]);
    GoldenCapitalAllowanceMatrixFixture::setCessationDate($companyId, '2100-06-30');
    GoldenCapitalAllowanceMatrixFixture::addAsset(
        9810801,
        $companyId,
        $nominals['car'],
        $nominals['accumulated_depreciation'],
        'GCA-CESSATION-SPECIAL',
        'car',
        '2099-01-01',
        10000.0,
        [
            'status' => 'disposed',
            'disposal_date' => '2100-06-30',
            'disposal_proceeds' => 2000.0,
            'disposal_event_type' => 'sale',
        ]
    );
    GoldenCapitalAllowanceMatrixFixture::addVehicle(9810801, $companyId, 'used', false, 51);

    $result = (new \eel_accounts\Service\CapitalAllowanceService())->rebuildForCompany($companyId);
    $finalCtPeriodId = GoldenCapitalAllowanceMatrixFixture::ctPeriodId($companyId, $finalPeriodId);
    $special = GoldenCapitalAllowanceMatrixFixture::pools($companyId, $finalPeriodId, $finalCtPeriodId)['special_rate_pool'];

    $harness->assertSame(9400.0, GoldenCapitalAllowanceMatrixFixture::money($special['opening_wdv']));
    $harness->assertSame(2000.0, GoldenCapitalAllowanceMatrixFixture::money($special['disposal_value']));
    $harness->assertSame(0.0, GoldenCapitalAllowanceMatrixFixture::money($special['wda_claimed']));
    $harness->assertSame(7400.0, GoldenCapitalAllowanceMatrixFixture::money($special['balancing_allowance']));
    $harness->assertSame(0.0, GoldenCapitalAllowanceMatrixFixture::money($special['closing_wdv']));
    $harness->assertSame(7400.0, GoldenCapitalAllowanceMatrixFixture::money($result[$finalPeriodId]['net_capital_allowances'] ?? 0));
    });
});

$harness->check($subject, 'pools final-period additions without AIA or FYA before the cessation balancing adjustment', static function () use ($harness): void {
    GoldenCapitalAllowanceMatrixFixture::rollbackAfter(static function () use ($harness): void {
    $companyId = 98109;
    $finalPeriodId = 981091;
    $nominals = GoldenCapitalAllowanceMatrixFixture::beginScenario($companyId, [
        ['id' => $finalPeriodId, 'start' => '2100-01-01', 'end' => '2100-12-31'],
    ]);
    GoldenCapitalAllowanceMatrixFixture::setCessationDate($companyId, '2100-06-30');
    GoldenCapitalAllowanceMatrixFixture::addAsset(
        9810901,
        $companyId,
        $nominals['plant'],
        $nominals['accumulated_depreciation'],
        'GCA-CESSATION-AIA-EXCLUDED',
        'tools_equipment',
        '2100-03-01',
        1000.0,
        [
            'status' => 'disposed',
            'disposal_date' => '2100-06-30',
            'disposal_proceeds' => 400.0,
            'disposal_event_type' => 'sale',
        ]
    );
    GoldenCapitalAllowanceMatrixFixture::addAsset(
        9810902,
        $companyId,
        $nominals['car'],
        $nominals['accumulated_depreciation'],
        'GCA-CESSATION-FYA-EXCLUDED',
        'car',
        '2100-03-01',
        2000.0,
        [
            'status' => 'disposed',
            'disposal_date' => '2100-06-30',
            'disposal_proceeds' => 800.0,
            'disposal_event_type' => 'sale',
        ]
    );
    GoldenCapitalAllowanceMatrixFixture::addVehicle(9810902, $companyId, 'new_unused', true, 0, '2100-03-01');

    $result = (new \eel_accounts\Service\CapitalAllowanceService())->rebuildForCompany($companyId);
    $finalCtPeriodId = GoldenCapitalAllowanceMatrixFixture::ctPeriodId($companyId, $finalPeriodId);
    $main = GoldenCapitalAllowanceMatrixFixture::pools($companyId, $finalPeriodId, $finalCtPeriodId)['main_pool'];

    $harness->assertSame(3000.0, GoldenCapitalAllowanceMatrixFixture::money($main['additions']));
    $harness->assertSame(0.0, GoldenCapitalAllowanceMatrixFixture::money($main['aia_claimed']));
    $harness->assertSame(0.0, GoldenCapitalAllowanceMatrixFixture::money($main['fya_claimed']));
    $harness->assertSame(1200.0, GoldenCapitalAllowanceMatrixFixture::money($main['disposal_value']));
    $harness->assertSame(0.0, GoldenCapitalAllowanceMatrixFixture::money($main['wda_claimed']));
    $harness->assertSame(1800.0, GoldenCapitalAllowanceMatrixFixture::money($main['balancing_allowance']));
    $harness->assertSame(0.0, GoldenCapitalAllowanceMatrixFixture::money($main['closing_wdv']));
    $harness->assertSame(1800.0, GoldenCapitalAllowanceMatrixFixture::money($result[$finalPeriodId]['net_capital_allowances'] ?? 0));

    $allowanceTypes = array_map(
        static fn(array $row): string => (string)($row['allowance_type'] ?? ''),
        GoldenCapitalAllowanceMatrixFixture::assetCalculations($companyId, $finalPeriodId)
    );
    $harness->assertTrue(!in_array('aia', $allowanceTypes, true));
    $harness->assertTrue(!in_array('fya', $allowanceTypes, true));
    });
});

$harness->check($subject, 'brings a fully relieved FYA asset disposal into the main pool as a balancing charge', static function () use ($harness): void {
    GoldenCapitalAllowanceMatrixFixture::rollbackAfter(static function () use ($harness): void {
    $companyId = 98110;
    $firstPeriodId = 981101;
    $finalPeriodId = 981102;
    $nominals = GoldenCapitalAllowanceMatrixFixture::beginScenario($companyId, [
        ['id' => $firstPeriodId, 'start' => '2099-01-01', 'end' => '2099-12-31'],
        ['id' => $finalPeriodId, 'start' => '2100-01-01', 'end' => '2100-12-31'],
    ]);
    GoldenCapitalAllowanceMatrixFixture::setCessationDate($companyId, '2100-06-30');
    GoldenCapitalAllowanceMatrixFixture::addAsset(
        9811001,
        $companyId,
        $nominals['car'],
        $nominals['accumulated_depreciation'],
        'GCA-FYA-CESSATION-DISPOSAL',
        'car',
        '2099-01-01',
        10000.0,
        [
            'status' => 'disposed',
            'disposal_date' => '2100-06-30',
            'disposal_proceeds' => 4000.0,
            'disposal_event_type' => 'sale',
        ]
    );
    GoldenCapitalAllowanceMatrixFixture::addVehicle(9811001, $companyId, 'new_unused', true, 0, '2099-01-01');

    $result = (new \eel_accounts\Service\CapitalAllowanceService())->rebuildForCompany($companyId);
    $firstCtPeriodId = GoldenCapitalAllowanceMatrixFixture::ctPeriodId($companyId, $firstPeriodId);
    $finalCtPeriodId = GoldenCapitalAllowanceMatrixFixture::ctPeriodId($companyId, $finalPeriodId);
    $firstMain = GoldenCapitalAllowanceMatrixFixture::pools($companyId, $firstPeriodId, $firstCtPeriodId)['main_pool'];
    $finalMain = GoldenCapitalAllowanceMatrixFixture::pools($companyId, $finalPeriodId, $finalCtPeriodId)['main_pool'];

    $harness->assertSame(10000.0, GoldenCapitalAllowanceMatrixFixture::money($firstMain['fya_claimed']));
    $harness->assertSame(0.0, GoldenCapitalAllowanceMatrixFixture::money($firstMain['closing_wdv']));
    $harness->assertSame(4000.0, GoldenCapitalAllowanceMatrixFixture::money($finalMain['disposal_value']));
    $harness->assertSame(4000.0, GoldenCapitalAllowanceMatrixFixture::money($finalMain['balancing_charge']));
    $harness->assertSame(0.0, GoldenCapitalAllowanceMatrixFixture::money($finalMain['wda_claimed']));
    $harness->assertSame(0.0, GoldenCapitalAllowanceMatrixFixture::money($finalMain['closing_wdv']));
    $harness->assertSame(-4000.0, GoldenCapitalAllowanceMatrixFixture::money($result[$finalPeriodId]['net_capital_allowances'] ?? 0));
    });
});

$harness->check($subject, 'caps ordinary pooled disposal value at qualifying expenditure', static function () use ($harness): void {
    GoldenCapitalAllowanceMatrixFixture::rollbackAfter(static function () use ($harness): void {
    $companyId = 98112;
    $firstPeriodId = 981121;
    $secondPeriodId = 981122;
    $nominals = GoldenCapitalAllowanceMatrixFixture::beginScenario($companyId, [
        ['id' => $firstPeriodId, 'start' => '2099-01-01', 'end' => '2099-12-31'],
        ['id' => $secondPeriodId, 'start' => '2100-01-01', 'end' => '2100-12-31'],
    ]);
    GoldenCapitalAllowanceMatrixFixture::addAsset(
        9811201,
        $companyId,
        $nominals['car'],
        $nominals['accumulated_depreciation'],
        'GCA-DISPOSAL-CAP-NORMAL',
        'car',
        '2099-01-01',
        10000.0,
        [
            'status' => 'disposed',
            'disposal_date' => '2100-06-30',
            'disposal_proceeds' => 15000.0,
            'disposal_event_type' => 'sale',
        ]
    );
    GoldenCapitalAllowanceMatrixFixture::addVehicle(9811201, $companyId, 'used', false, 50);

    $result = (new \eel_accounts\Service\CapitalAllowanceService())->rebuildForCompany($companyId);
    $secondCtPeriodId = GoldenCapitalAllowanceMatrixFixture::ctPeriodId($companyId, $secondPeriodId);
    $main = GoldenCapitalAllowanceMatrixFixture::pools($companyId, $secondPeriodId, $secondCtPeriodId)['main_pool'];
    $assetRows = GoldenCapitalAllowanceMatrixFixture::assetCalculations($companyId, $secondPeriodId);

    $harness->assertSame(10000.0, GoldenCapitalAllowanceMatrixFixture::money($main['disposal_value']));
    $harness->assertSame(1800.0, GoldenCapitalAllowanceMatrixFixture::money($main['balancing_charge']));
    $harness->assertSame(-1800.0, GoldenCapitalAllowanceMatrixFixture::money($result[$secondPeriodId]['net_capital_allowances'] ?? 0));
    $harness->assertSame(10000.0, GoldenCapitalAllowanceMatrixFixture::money($assetRows[0]['disposal_value'] ?? 0));
    });
});

$harness->check($subject, 'caps fully relieved AIA and FYA disposal values at each asset cost', static function () use ($harness): void {
    GoldenCapitalAllowanceMatrixFixture::rollbackAfter(static function () use ($harness): void {
    $companyId = 98113;
    $firstPeriodId = 981131;
    $secondPeriodId = 981132;
    $nominals = GoldenCapitalAllowanceMatrixFixture::beginScenario($companyId, [
        ['id' => $firstPeriodId, 'start' => '2099-01-01', 'end' => '2099-12-31'],
        ['id' => $secondPeriodId, 'start' => '2100-01-01', 'end' => '2100-12-31'],
    ]);
    GoldenCapitalAllowanceMatrixFixture::addAsset(
        9811301,
        $companyId,
        $nominals['plant'],
        $nominals['accumulated_depreciation'],
        'GCA-DISPOSAL-CAP-AIA',
        'tools_equipment',
        '2099-01-01',
        1000.0,
        [
            'status' => 'disposed',
            'disposal_date' => '2100-06-30',
            'disposal_proceeds' => 5000.0,
            'disposal_event_type' => 'sale',
        ]
    );
    GoldenCapitalAllowanceMatrixFixture::addAsset(
        9811302,
        $companyId,
        $nominals['car'],
        $nominals['accumulated_depreciation'],
        'GCA-DISPOSAL-CAP-FYA',
        'car',
        '2099-01-01',
        2000.0,
        [
            'status' => 'disposed',
            'disposal_date' => '2100-06-30',
            'disposal_proceeds' => 5000.0,
            'disposal_event_type' => 'sale',
        ]
    );
    GoldenCapitalAllowanceMatrixFixture::addVehicle(9811302, $companyId, 'new_unused', true, 0, '2099-01-01');

    $result = (new \eel_accounts\Service\CapitalAllowanceService())->rebuildForCompany($companyId);
    $secondCtPeriodId = GoldenCapitalAllowanceMatrixFixture::ctPeriodId($companyId, $secondPeriodId);
    $main = GoldenCapitalAllowanceMatrixFixture::pools($companyId, $secondPeriodId, $secondCtPeriodId)['main_pool'];
    $assetRows = GoldenCapitalAllowanceMatrixFixture::assetCalculations($companyId, $secondPeriodId);

    $harness->assertSame(3000.0, GoldenCapitalAllowanceMatrixFixture::money($main['disposal_value']));
    $harness->assertSame(3000.0, GoldenCapitalAllowanceMatrixFixture::money($main['balancing_charge']));
    $harness->assertSame(-3000.0, GoldenCapitalAllowanceMatrixFixture::money($result[$secondPeriodId]['net_capital_allowances'] ?? 0));
    $harness->assertSame(1000.0, GoldenCapitalAllowanceMatrixFixture::money($assetRows[0]['disposal_value'] ?? 0));
    $harness->assertSame(2000.0, GoldenCapitalAllowanceMatrixFixture::money($assetRows[1]['disposal_value'] ?? 0));
    });
});

$harness->check($subject, 'caps disposal value before the final cessation balancing calculation', static function () use ($harness): void {
    GoldenCapitalAllowanceMatrixFixture::rollbackAfter(static function () use ($harness): void {
    $companyId = 98114;
    $firstPeriodId = 981141;
    $finalPeriodId = 981142;
    $nominals = GoldenCapitalAllowanceMatrixFixture::beginScenario($companyId, [
        ['id' => $firstPeriodId, 'start' => '2099-01-01', 'end' => '2099-12-31'],
        ['id' => $finalPeriodId, 'start' => '2100-01-01', 'end' => '2100-12-31'],
    ]);
    GoldenCapitalAllowanceMatrixFixture::setCessationDate($companyId, '2100-06-30');
    GoldenCapitalAllowanceMatrixFixture::addAsset(
        9811401,
        $companyId,
        $nominals['car'],
        $nominals['accumulated_depreciation'],
        'GCA-DISPOSAL-CAP-CESSATION',
        'car',
        '2099-01-01',
        10000.0,
        [
            'status' => 'disposed',
            'disposal_date' => '2100-06-30',
            'disposal_proceeds' => 15000.0,
            'disposal_event_type' => 'sale',
        ]
    );
    GoldenCapitalAllowanceMatrixFixture::addVehicle(9811401, $companyId, 'used', false, 50);

    $result = (new \eel_accounts\Service\CapitalAllowanceService())->rebuildForCompany($companyId);
    $finalCtPeriodId = GoldenCapitalAllowanceMatrixFixture::ctPeriodId($companyId, $finalPeriodId);
    $main = GoldenCapitalAllowanceMatrixFixture::pools($companyId, $finalPeriodId, $finalCtPeriodId)['main_pool'];

    $harness->assertSame(10000.0, GoldenCapitalAllowanceMatrixFixture::money($main['disposal_value']));
    $harness->assertSame(1800.0, GoldenCapitalAllowanceMatrixFixture::money($main['balancing_charge']));
    $harness->assertSame(0.0, GoldenCapitalAllowanceMatrixFixture::money($main['balancing_allowance']));
    $harness->assertSame(-1800.0, GoldenCapitalAllowanceMatrixFixture::money($result[$finalPeriodId]['net_capital_allowances'] ?? 0));
    });
});
