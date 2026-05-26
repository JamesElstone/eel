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
$harness->run(CorporationTaxTreatmentRuleService::class, function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(CorporationTaxTreatmentRuleService::class, 'overrides nominal treatment with the first matching active rule', function () use ($harness): void {
        $service = new CorporationTaxTreatmentRuleService([
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

    $harness->check(CorporationTaxTreatmentRuleService::class, 'falls back to nominal treatment when no rule matches the period', function () use ($harness): void {
        $service = new CorporationTaxTreatmentRuleService([
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
});
