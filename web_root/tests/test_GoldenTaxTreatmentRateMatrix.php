<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'PeriodLedgerTestFixture.php';

/**
 * Fixed rule fixtures keep the matrix independent of the database's current
 * rate-rule rows while exercising the production rate calculations.
 */
function goldenTaxTreatmentRateMatrixRateService(): \eel_accounts\Service\CorporationTaxRateService
{
    return new \eel_accounts\Service\CorporationTaxRateService([
        [
            'financial_year_start' => '2022-04-01',
            'financial_year_end' => '2023-03-31',
            'rule_version' => 'golden-fy2022',
            'main_rate' => 0.19,
            'small_profits_rate' => null,
            'lower_limit' => null,
            'upper_limit' => null,
            'marginal_relief_fraction' => null,
            'source_url' => 'https://example.test/golden-tax-rates',
            'source_checked_at' => '2026-07-15',
            'is_active' => 1,
        ],
        [
            'financial_year_start' => '2023-04-01',
            'financial_year_end' => '2024-03-31',
            'rule_version' => 'golden-fy2023',
            'main_rate' => 0.25,
            'small_profits_rate' => 0.19,
            'lower_limit' => 50000.0,
            'upper_limit' => 250000.0,
            'marginal_relief_fraction' => 0.015,
            'source_url' => 'https://example.test/golden-tax-rates',
            'source_checked_at' => '2026-07-15',
            'is_active' => 1,
        ],
    ]);
}

function goldenTaxTreatmentRateMatrixMoney(mixed $value): string
{
    return number_format((float)$value, 2, '.', '');
}

$harness = new GeneratedServiceClassTestHarness();

$harness->check('GoldenTaxTreatmentRateMatrix', 'preserves all semantic nominal tax-treatment values', static function () use ($harness): void {
    $service = new \eel_accounts\Service\CorporationTaxTreatmentRuleService([]);
    foreach (['allowable', 'disallowable', 'capital', 'other', 'unknown'] as $treatment) {
        $resolved = $service->resolveTaxTreatment([
            'id' => 100,
            'code' => 'GOLDEN-' . strtoupper($treatment),
            'name' => 'Golden ' . $treatment,
            'account_type' => 'expense',
            'tax_treatment' => $treatment,
        ], '2024-01-01', '2024-12-31');

        $harness->assertSame($treatment, (string)($resolved['tax_treatment'] ?? ''));
        $harness->assertSame('nominal_accounts', (string)($resolved['source'] ?? ''));
    }
});

$harness->check('GoldenTaxTreatmentRateMatrix', 'applies dated treatment overrides only inside their effective windows', static function () use ($harness): void {
    $service = new \eel_accounts\Service\CorporationTaxTreatmentRuleService([
        [
            'id' => 1,
            'priority' => 10,
            'nominal_code' => 'GOLDEN-DATED',
            'tax_treatment' => 'disallowable',
            'effective_from' => '2024-01-01',
            'effective_to' => '2024-12-31',
            'is_active' => 1,
        ],
        [
            'id' => 2,
            'priority' => 10,
            'nominal_code' => 'GOLDEN-DATED',
            'tax_treatment' => 'capital',
            'effective_from' => '2025-01-01',
            'effective_to' => null,
            'is_active' => 1,
        ],
    ]);
    $nominal = [
        'id' => 101,
        'code' => 'GOLDEN-DATED',
        'name' => 'Golden dated treatment',
        'account_type' => 'expense',
        'tax_treatment' => 'allowable',
    ];

    $before = $service->resolveTaxTreatment($nominal, '2023-01-01', '2023-12-31');
    $during = $service->resolveTaxTreatment($nominal, '2024-01-01', '2024-12-31');
    $after = $service->resolveTaxTreatment($nominal, '2025-01-01', '2025-12-31');

    $harness->assertSame('allowable', (string)$before['tax_treatment']);
    $harness->assertSame('nominal_accounts', (string)$before['source']);
    $harness->assertSame('disallowable', (string)$during['tax_treatment']);
    $harness->assertSame('corporation_tax_treatment_rules', (string)$during['source']);
    $harness->assertSame('capital', (string)$after['tax_treatment']);
    $harness->assertSame('corporation_tax_treatment_rules', (string)$after['source']);
});

$harness->check('GoldenTaxTreatmentRateMatrix', 'turns ledger treatment semantics into the expected P and L bridge', static function () use ($harness): void {
    InterfaceDB::beginTransaction();
    try {
        $fixture = periodLedgerTestCreateFixture();
        $suffix = (string)$fixture['accounting_period_id'];
        $capitalNominalId = periodLedgerTestInsertNominal('GXCAP' . $suffix, 'ZXQ capital bucket ' . $suffix, 'expense', 'capital');
        $otherNominalId = periodLedgerTestInsertNominal('GXOTH' . $suffix, 'ZXQ review bucket ' . $suffix, 'expense', 'other');
        periodLedgerTestInsertJournal(
            (int)$fixture['company_id'],
            (int)$fixture['accounting_period_id'],
            '2025-04-15',
            'golden-treatment-matrix-' . $suffix,
            [
                [$capitalNominalId, 30.0, 0.0],
                [$otherNominalId, 40.0, 0.0],
                [(int)$fixture['asset_nominal_id'], 0.0, 70.0],
            ]
        );

        $result = (new \eel_accounts\Service\PreTaxProfitLossService(new \eel_accounts\Service\PeriodLedgerReadService()))
            ->calculate(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                '2025-06-30',
                null,
                ['success' => true, 'rows' => []],
                []
            );

        $harness->assertSame('580.00', goldenTaxTreatmentRateMatrixMoney($result['profit_before_tax'] ?? 0));
        $harness->assertSame('50.00', goldenTaxTreatmentRateMatrixMoney($result['disallowable_add_backs'] ?? 0));
        $harness->assertSame('30.00', goldenTaxTreatmentRateMatrixMoney($result['capital_add_backs'] ?? 0));
        $harness->assertSame(1, (int)($result['other_treatment_count'] ?? 0));
        $harness->assertSame(0, (int)($result['unknown_treatment_count'] ?? 0));
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
});

$harness->check('GoldenTaxTreatmentRateMatrix', 'keeps a dated rule override identical before and after prepayment posting', static function () use ($harness): void {
    InterfaceDB::beginTransaction();
    try {
        $harness->assertTrue(InterfaceDB::tableExists('corporation_tax_treatment_rules'));
        $fixture = periodLedgerTestCreateFixture();
        $suffix = (string)$fixture['accounting_period_id'];
        $expenseCode = 'GXPRE' . $suffix;
        $expenseNominalId = periodLedgerTestInsertNominal($expenseCode, 'ZXQ prepaid service ' . $suffix, 'expense', 'allowable');
        $prepaymentNominalId = periodLedgerTestInsertNominal('GXPA' . $suffix, 'ZXQ prepayment asset ' . $suffix, 'asset', 'other');

        InterfaceDB::prepareExecute(
            'INSERT INTO corporation_tax_treatment_rules (
                rule_code, rule_version, priority, nominal_code, tax_treatment,
                effective_from, effective_to, source_url, source_checked_at,
                rationale, review_status, is_active
             ) VALUES (
                :rule_code, :rule_version, :priority, :nominal_code, :tax_treatment,
                :effective_from, :effective_to, :source_url, :source_checked_at,
                :rationale, :review_status, 1
             )',
            [
                'rule_code' => 'golden_prepayment_override_' . $suffix,
                'rule_version' => 'golden-2025-' . $suffix,
                'priority' => 1,
                'nominal_code' => $expenseCode,
                'tax_treatment' => 'disallowable',
                'effective_from' => '2025-01-01',
                'effective_to' => '2025-12-31',
                'source_url' => 'https://example.test/golden-prepayment-treatment',
                'source_checked_at' => '2026-07-15',
                'rationale' => 'Golden parity rule for pending and posted prepayment adjustments.',
                'review_status' => 'reviewed',
            ]
        );

        $pending = (new \eel_accounts\Service\PreTaxProfitLossService(new \eel_accounts\Service\PeriodLedgerReadService()))
            ->calculate(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                '2025-06-30',
                null,
                ['success' => true, 'rows' => []],
                [[
                    'review_id' => 1,
                    'schedule_id' => 1,
                    'amount_pence' => 10000,
                    'debit_nominal_id' => $expenseNominalId,
                    'credit_nominal_id' => $prepaymentNominalId,
                    'journal_date' => '2025-03-31',
                ]]
            );

        periodLedgerTestInsertJournal(
            (int)$fixture['company_id'],
            (int)$fixture['accounting_period_id'],
            '2025-03-31',
            'golden-posted-prepayment-' . $suffix,
            [
                [$expenseNominalId, 100.0, 0.0],
                [$prepaymentNominalId, 0.0, 100.0],
            ]
        );
        $posted = (new \eel_accounts\Service\PreTaxProfitLossService(new \eel_accounts\Service\PeriodLedgerReadService()))
            ->calculate(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                '2025-06-30',
                null,
                ['success' => true, 'rows' => []],
                []
            );

        $harness->assertSame('550.00', goldenTaxTreatmentRateMatrixMoney($posted['profit_before_tax'] ?? 0));
        $harness->assertSame(
            goldenTaxTreatmentRateMatrixMoney($posted['profit_before_tax'] ?? 0),
            goldenTaxTreatmentRateMatrixMoney($pending['profit_before_tax'] ?? 0)
        );
        $harness->assertSame('150.00', goldenTaxTreatmentRateMatrixMoney($posted['disallowable_add_backs'] ?? 0));
        $harness->assertSame('150.00', goldenTaxTreatmentRateMatrixMoney($pending['disallowable_add_backs'] ?? 0));
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
});

$harness->check('GoldenTaxTreatmentRateMatrix', 'adds capital treatment expenses back before Corporation Tax', static function () use ($harness): void {
    $service = new \eel_accounts\Service\CorporationTaxComputationService(
        null,
        goldenTaxTreatmentRateMatrixRateService()
    );
    $summary = $service->fetchCurrentPeriodEstimate(
        0,
        0,
        [
            'id' => 0,
            'label' => 'Golden capital add-back period',
            'period_start' => '2023-04-01',
            'period_end' => '2024-03-31',
        ],
        [
            'profit_before_tax' => 900.0,
            'disallowable_add_backs' => 0.0,
            'capital_add_backs' => 100.0,
            'depreciation_expense' => 0.0,
            'other_treatment_count' => 0,
            'unknown_treatment_count' => 0,
        ]
    );

    $harness->assertTrue(!empty($summary['available']));
    $harness->assertSame('1000.00', goldenTaxTreatmentRateMatrixMoney($summary['taxable_before_losses'] ?? 0));
    $harness->assertSame('190.00', goldenTaxTreatmentRateMatrixMoney($summary['estimated_corporation_tax'] ?? 0));
});

$harness->check('GoldenTaxTreatmentRateMatrix', 'covers flat small-profits marginal-relief and main-rate branches', static function () use ($harness): void {
    $service = goldenTaxTreatmentRateMatrixRateService();
    $flat = $service->calculate('2022-04-01', '2023-03-31', 100000.0);
    $small = $service->calculate('2023-04-01', '2024-03-31', 40000.0);
    $marginal = $service->calculate('2023-04-01', '2024-03-31', 100000.0);
    $main = $service->calculate('2023-04-01', '2024-03-31', 300000.0);

    $harness->assertSame('19000.00', goldenTaxTreatmentRateMatrixMoney($flat['liability'] ?? 0));
    $harness->assertSame('flat_main_rate', (string)($flat['bands'][0]['basis'] ?? ''));
    $harness->assertSame('7600.00', goldenTaxTreatmentRateMatrixMoney($small['liability'] ?? 0));
    $harness->assertSame('small_profits_rate', (string)($small['bands'][0]['basis'] ?? ''));
    $harness->assertSame('22750.00', goldenTaxTreatmentRateMatrixMoney($marginal['liability'] ?? 0));
    $harness->assertSame('main_rate_less_marginal_relief', (string)($marginal['bands'][0]['basis'] ?? ''));
    $harness->assertSame('75000.00', goldenTaxTreatmentRateMatrixMoney($main['liability'] ?? 0));
    $harness->assertSame('main_rate', (string)($main['bands'][0]['basis'] ?? ''));
});

$harness->check('GoldenTaxTreatmentRateMatrix', 'scales marginal-relief limits for associated companies', static function () use ($harness): void {
    $result = goldenTaxTreatmentRateMatrixRateService()->calculate('2023-04-01', '2024-03-31', 60000.0, 1);

    $harness->assertSame('14025.00', goldenTaxTreatmentRateMatrixMoney($result['liability'] ?? 0));
    $harness->assertSame('25000.00', goldenTaxTreatmentRateMatrixMoney($result['bands'][0]['lower_limit'] ?? 0));
    $harness->assertSame('125000.00', goldenTaxTreatmentRateMatrixMoney($result['bands'][0]['upper_limit'] ?? 0));
    $harness->assertSame(1, (int)($result['associated_company_count'] ?? 0));
});

$harness->check('GoldenTaxTreatmentRateMatrix', 'uses augmented profit for marginal relief without changing taxable profit', static function () use ($harness): void {
    $result = goldenTaxTreatmentRateMatrixRateService()->calculate(
        '2023-04-01',
        '2024-03-31',
        40000.0,
        0,
        100000.0
    );

    $harness->assertSame('9100.00', goldenTaxTreatmentRateMatrixMoney($result['liability'] ?? 0));
    $harness->assertSame('40000.00', goldenTaxTreatmentRateMatrixMoney($result['bands'][0]['taxable_profit'] ?? 0));
    $harness->assertSame('100000.00', goldenTaxTreatmentRateMatrixMoney($result['bands'][0]['augmented_profit'] ?? 0));
    $harness->assertSame('main_rate_less_marginal_relief', (string)($result['bands'][0]['basis'] ?? ''));
});

$harness->check('GoldenTaxTreatmentRateMatrix', 'apportions a cross-financial-year period by inclusive days', static function () use ($harness): void {
    $result = goldenTaxTreatmentRateMatrixRateService()->calculate('2023-03-01', '2023-04-30', 61000.0);

    $harness->assertSame('13390.00', goldenTaxTreatmentRateMatrixMoney($result['liability'] ?? 0));
    $harness->assertSame(2, count((array)($result['bands'] ?? [])));
    $harness->assertSame('FY2022', (string)($result['bands'][0]['financial_year'] ?? ''));
    $harness->assertSame('31000.00', goldenTaxTreatmentRateMatrixMoney($result['bands'][0]['taxable_profit'] ?? 0));
    $harness->assertSame('5890.00', goldenTaxTreatmentRateMatrixMoney($result['bands'][0]['liability'] ?? 0));
    $harness->assertSame('FY2023', (string)($result['bands'][1]['financial_year'] ?? ''));
    $harness->assertSame('30000.00', goldenTaxTreatmentRateMatrixMoney($result['bands'][1]['taxable_profit'] ?? 0));
    $harness->assertSame('7500.00', goldenTaxTreatmentRateMatrixMoney($result['bands'][1]['liability'] ?? 0));
});
