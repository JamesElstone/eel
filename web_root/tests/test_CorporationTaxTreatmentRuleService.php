<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(\eel_accounts\Service\CorporationTaxTreatmentRuleService::class, function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(\eel_accounts\Service\CorporationTaxTreatmentRuleService::class, 'overrides nominal treatment with the first matching active rule', function () use ($harness): void {
        $service = new \eel_accounts\Service\CorporationTaxTreatmentRuleService([
            [
                'id' => 2,
                'priority' => 50,
                'nominal_code' => '6130',
                'tax_treatment' => 'allowable',
                'source_url' => 'https://example.test/later',
                'is_active' => 1,
            ],
            [
                'id' => 1,
                'priority' => 10,
                'nominal_code' => '6130',
                'tax_treatment' => 'disallowable',
                'source_url' => 'https://www.gov.uk/hmrc-internal-manuals/business-income-manual/bim45000',
                'is_active' => 1,
            ],
        ]);

        $result = $service->resolveTaxTreatment([
            'id' => 31,
            'code' => '6130',
            'name' => 'Client Entertainment',
            'account_type' => 'expense',
            'tax_treatment' => 'allowable',
        ], '2026-04-01', '2027-03-31');

        $harness->assertSame('disallowable', (string)$result['tax_treatment']);
        $harness->assertSame('corporation_tax_treatment_rules', (string)$result['source']);
        $harness->assertSame(
            'https://www.gov.uk/hmrc-internal-manuals/business-income-manual/bim45000',
            (string)($result['rule']['source_url'] ?? '')
        );
    });

    $harness->check(\eel_accounts\Service\CorporationTaxTreatmentRuleService::class, 'falls back to nominal treatment when no rule matches the period', function () use ($harness): void {
        $service = new \eel_accounts\Service\CorporationTaxTreatmentRuleService([
            [
                'id' => 1,
                'priority' => 10,
                'nominal_code' => '6130',
                'tax_treatment' => 'disallowable',
                'effective_from' => '2028-01-01',
                'is_active' => 1,
            ],
        ]);

        $result = $service->resolveTaxTreatment([
            'id' => 31,
            'code' => '6130',
            'name' => 'Client Entertainment',
            'account_type' => 'expense',
            'tax_treatment' => 'allowable',
        ], '2026-04-01', '2027-03-31');

        $harness->assertSame('allowable', (string)$result['tax_treatment']);
        $harness->assertSame('nominal_accounts', (string)$result['source']);
    });

    $harness->check(\eel_accounts\Service\CorporationTaxTreatmentRuleService::class, 'does not expose deferred tax as an active CT treatment rule', function () use ($harness): void {
        if (!InterfaceDB::tableExists('corporation_tax_treatment_rules')) {
            $harness->skip('corporation_tax_treatment_rules table is not available.');
        }

        $activeCount = (int)InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM corporation_tax_treatment_rules
             WHERE rule_code = :rule_code
               AND is_active = 1',
            ['rule_code' => 'deferred_tax_not_expected']
        );

        $harness->assertSame(0, $activeCount);
    });

    $harness->check(\eel_accounts\Service\CorporationTaxTreatmentRuleService::class, 'keeps exact nominal selectors narrower than account type selectors', function () use ($harness): void {
        $exact = new \eel_accounts\Service\CorporationTaxTreatmentRuleService([[
            'id' => 1,
            'priority' => 1,
            'nominal_account_id' => 6160,
            'nominal_code' => '6160',
            'account_type' => 'expense',
            'tax_treatment' => 'other',
            'is_active' => 1,
        ]]);
        $donation = $exact->resolveTaxTreatment([
            'id' => 6160,
            'code' => '6160',
            'name' => 'Charitable Donations',
            'account_type' => 'expense',
            'tax_treatment' => 'allowable',
        ]);
        $fuel = $exact->resolveTaxTreatment([
            'id' => 6002,
            'code' => '6002',
            'name' => 'Fuel',
            'account_type' => 'expense',
            'tax_treatment' => 'allowable',
        ]);
        $harness->assertSame('other', (string)$donation['tax_treatment']);
        $harness->assertSame('allowable', (string)$fuel['tax_treatment']);

        $typeOnly = new \eel_accounts\Service\CorporationTaxTreatmentRuleService([[
            'id' => 2, 'priority' => 1, 'account_type' => 'expense',
            'tax_treatment' => 'disallowable', 'is_active' => 1,
        ]]);
        $nameOnly = new \eel_accounts\Service\CorporationTaxTreatmentRuleService([[
            'id' => 3, 'priority' => 1, 'name_contains' => 'Legal',
            'tax_treatment' => 'other', 'is_active' => 1,
        ]]);
        $combined = new \eel_accounts\Service\CorporationTaxTreatmentRuleService([[
            'id' => 4, 'priority' => 1, 'account_type' => 'expense', 'name_contains' => 'Professional',
            'tax_treatment' => 'other', 'is_active' => 1,
        ]]);
        $harness->assertSame('disallowable', (string)$typeOnly->resolveTaxTreatment([
            'code' => '6002', 'name' => 'Fuel', 'account_type' => 'expense', 'tax_treatment' => 'allowable',
        ])['tax_treatment']);
        $harness->assertSame('other', (string)$nameOnly->resolveTaxTreatment([
            'code' => '7000', 'name' => 'Legal Costs', 'account_type' => 'expense', 'tax_treatment' => 'allowable',
        ])['tax_treatment']);
        $harness->assertSame('allowable', (string)$combined->resolveTaxTreatment([
            'code' => '7001', 'name' => 'Professional Income', 'account_type' => 'income', 'tax_treatment' => 'allowable',
        ])['tax_treatment']);
        $harness->assertSame('other', (string)$combined->resolveTaxTreatment([
            'code' => '7002', 'name' => 'Professional Fees', 'account_type' => 'expense', 'tax_treatment' => 'allowable',
        ])['tax_treatment']);
    });
});
