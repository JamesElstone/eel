<?php
declare(strict_types=1);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
(new GeneratedServiceClassTestHarness())->run(\eel_accounts\Service\CtFilingMappingService::class, static function (GeneratedServiceClassTestHarness $h, \eel_accounts\Service\CtFilingMappingService $service): void {
    $h->check($service::class, 'exposes independent mapping targets', static function () use ($h): void { $h->assertSame('ct600_rim', \eel_accounts\Service\CtFilingMappingService::TARGET_RIM); $h->assertSame('computation_ixbrl', \eel_accounts\Service\CtFilingMappingService::TARGET_COMPUTATION); });
    $h->check($service::class, 'fails both targets closed without a sealed frozen model', static function () use ($h, $service): void {
        foreach ([\eel_accounts\Service\CtFilingMappingService::TARGET_RIM, \eel_accounts\Service\CtFilingMappingService::TARGET_COMPUTATION] as $target) {
            $result = $service->mapFrozenFacts($target, ['available' => false], []);
            $h->assertSame(false, (bool)($result['success'] ?? true));
            $h->assertSame([], (array)($result['canonical_values'] ?? ['unexpected']));
        }
    });
    $h->check($service::class, 'adds CT600 serialization evidence without mutating the frozen model', static function () use ($h, $service): void {
        $filingModel = [
            'available' => true,
            'basis_version' => 'test-basis-v1',
            'basis_hash' => str_repeat('a', 64),
            'run' => ['run_id' => 91],
            'model' => ['ct_period' => ['id' => 17]],
            'seal' => ['basis_hash' => str_repeat('a', 64)],
            'facts' => ['computation.summary.estimated_corporation_tax' => 123.45],
        ];
        $profile = [
            'id' => 8,
            'target_type' => \eel_accounts\Service\CtFilingMappingService::TARGET_RIM,
            'rim_package_id' => 4,
            'status' => 'active',
            'compatibility_status' => 'compatible',
        ];
        $mappings = [[
            'id' => 1,
            'profile_id' => 8,
            'canonical_key' => 'computation.summary.estimated_corporation_tax',
            'target_xpath' => 'CompanyTaxReturn/CompanyTaxCalculation/CorporationTax',
            'value_type' => 'numeric',
            'rim_data_type' => 'ct:CTpoundPenceStructure',
            'sign_multiplier' => 1.00,
            'null_policy' => 'error',
            'is_required' => 1,
        ]];
        $before = hash('sha256', (string)json_encode($filingModel, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

        $result = $service->mapFrozenFacts(
            \eel_accounts\Service\CtFilingMappingService::TARGET_RIM,
            $filingModel,
            $profile,
            $mappings
        );

        $h->assertSame(true, (bool)$result['success']);
        $h->assertSame(123.45, $result['mappings'][0]['source_value']);
        $h->assertSame('123.45', $result['mappings'][0]['serialized_value']);
        $h->assertSame(
            \eel_accounts\Service\Ct600MonetaryValuePolicyService::POLICY_VERSION,
            $result['mappings'][0]['policy_version']
        );
        $h->assertSame($before, hash('sha256', (string)json_encode($filingModel, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)));
        $h->assertSame(str_repeat('a', 64), $result['basis_hash']);
    });
    $h->check($service::class, 'fails closed when a numeric CT600 target has no resolved RIM datatype', static function () use ($h, $service): void {
        $result = $service->mapFrozenFacts(
            \eel_accounts\Service\CtFilingMappingService::TARGET_RIM,
            [
                'available' => true,
                'basis_version' => 'test-basis-v1',
                'basis_hash' => str_repeat('b', 64),
                'run' => ['run_id' => 92],
                'model' => ['ct_period' => ['id' => 18]],
                'seal' => ['basis_hash' => str_repeat('b', 64)],
                'facts' => ['computation.summary.taxable_profit' => 123.45],
            ],
            [
                'id' => 9,
                'target_type' => \eel_accounts\Service\CtFilingMappingService::TARGET_RIM,
                'rim_package_id' => 4,
                'status' => 'active',
                'compatibility_status' => 'compatible',
            ],
            [[
                'profile_id' => 9,
                'canonical_key' => 'computation.summary.taxable_profit',
                'target_xpath' => 'CompanyTaxReturn/CompanyTaxCalculation/ChargeableProfits',
                'value_type' => 'numeric',
                'rim_data_type' => '',
                'null_policy' => 'error',
                'is_required' => 1,
            ]]
        );

        $h->assertSame(false, (bool)$result['success']);
        $h->assertTrue(str_contains(implode(' ', (array)$result['errors']), 'datatype is unresolved'));
    });
});
