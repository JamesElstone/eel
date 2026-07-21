<?php
declare(strict_types=1);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
(new GeneratedServiceClassTestHarness())->run(\eel_accounts\Service\CtFilingMappingService::class, static function (GeneratedServiceClassTestHarness $h, \eel_accounts\Service\CtFilingMappingService $service): void {
    $h->check($service::class, 'exposes independent mapping targets', static function () use ($h): void { $h->assertSame('ct600_rim', \eel_accounts\Service\CtFilingMappingService::TARGET_RIM); $h->assertSame('computation_ixbrl', \eel_accounts\Service\CtFilingMappingService::TARGET_COMPUTATION); });
    $h->check($service::class, 'keys reviewed templates by natural package identity and leaves future packages draft-only', static function () use ($h, $service): void {
        $rim = $service->reviewedTemplate(\eel_accounts\Service\CtFilingMappingService::TARGET_RIM, 'V3', 'V1.994');
        $h->assertTrue(is_array($rim));
        $encoded = (string)json_encode($rim, JSON_UNESCAPED_SLASHES);
        $h->assertFalse(str_contains($encoded, 'package_id'));
        $paths = [];
        foreach ((array)$rim['mappings'] as $mapping) { $paths[(string)$mapping['canonical_key']][] = (string)$mapping['target_xpath']; }
        $h->assertFalse(isset($paths['computation.summary.capital_allowances']));
        $h->assertTrue(in_array(
            'IRenvelope/CompanyTaxReturn/LossesDeficitsAndExcess/AmountArising/LossesOfTradesUK/Arising',
            $paths['computation.summary.loss_created_in_period'],
            true
        ));
        $h->assertTrue(in_array(
            'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/NetCorporationTaxChargeable',
            $paths['computation.summary.ordinary_corporation_tax'],
            true
        ));
        $h->assertTrue(in_array(
            'IRenvelope/CompanyTaxReturn/CalculationOfTaxOutstandingOrOverpaid/NetCorporationTaxLiability',
            $paths['computation.summary.ordinary_corporation_tax'],
            true
        ));
        $h->assertCount(2, $paths['computation.summary.ordinary_corporation_tax']);
        $h->assertTrue(in_array(
            'IRenvelope/CompanyTaxReturn/CalculationOfTaxOutstandingOrOverpaid/TaxChargeable',
            $paths['return_position.tax_payable'],
            true
        ));
        $h->assertCount(2, $paths['return_position.tax_payable']);
        $h->assertSame(
            ['IRenvelope/CompanyTaxReturn/CalculationOfTaxOutstandingOrOverpaid/LoansToParticipators'],
            $paths['return_position.ct600a_a80']
        );
        $h->assertSame(null, $service->reviewedTemplate(\eel_accounts\Service\CtFilingMappingService::TARGET_RIM, 'V3', 'V1.995'));
        $computation = $service->reviewedTemplate(\eel_accounts\Service\CtFilingMappingService::TARGET_COMPUTATION, '2025', 'V1.0.0');
        $h->assertTrue(is_array($computation));
        $computation2024 = $service->reviewedTemplate(\eel_accounts\Service\CtFilingMappingService::TARGET_COMPUTATION, '2024', 'V1.0.0');
        $h->assertTrue(is_array($computation2024));
        $h->assertSame('reviewed_ct_computation_2024_v1_0_0_return_v2', (string)$computation2024['profile_name']);
        $h->assertSame(
            ['taxonomy_version' => '2024', 'artifact_version' => 'V1.0.0'],
            (array)$computation2024['natural_identity']
        );
        $h->assertSame((array)$computation['mappings'], (array)$computation2024['mappings']);
        $computationMappings = [];
        foreach ((array)$computation2024['mappings'] as $mapping) {
            $computationMappings[(string)$mapping['local_name']] = $mapping;
        }
        $h->assertSame(
            \eel_accounts\Service\CtFilingMappingService::CONTEXT_HMRC_CT_UK_TRADE,
            (string)$computationMappings['ProfitLossPerAccounts']['context_profile']
        );
        $h->assertSame(
            \eel_accounts\Service\CtFilingMappingService::CONTEXT_HMRC_CT_COMPANY,
            (string)$computationMappings['NetTaxPayable']['context_profile']
        );
        $h->assertSame(null, $service->reviewedTemplate(\eel_accounts\Service\CtFilingMappingService::TARGET_COMPUTATION, '2026', 'V1.0.0'));
    });
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
    $h->check($service::class, 'blocks an evidenced loss claim without inferring its CT600 claim box', static function () use ($h, $service): void {
        $result = $service->mapFrozenFacts(
            \eel_accounts\Service\CtFilingMappingService::TARGET_RIM,
            [
                'available' => true,
                'basis_version' => 'test-basis-v1',
                'basis_hash' => str_repeat('c', 64),
                'run' => ['run_id' => 93],
                'model' => ['ct_period' => ['id' => 19]],
                'seal' => ['basis_hash' => str_repeat('c', 64)],
                'facts' => [
                    'computation.summary.losses_used' => 100.0,
                    'computation.summary.losses_carried_forward' => 900.0,
                ],
            ],
            [
                'id' => 10,
                'target_type' => \eel_accounts\Service\CtFilingMappingService::TARGET_RIM,
                'rim_package_id' => 4,
                'status' => 'active',
                'compatibility_status' => 'compatible',
            ],
            [[
                'profile_id' => 10,
                'canonical_key' => 'computation.summary.loss_created_in_period',
                'target_xpath' => 'IRenvelope/CompanyTaxReturn/LossesDeficitsAndExcess/AmountArising/LossesOfTradesUK/Arising',
                'value_type' => 'numeric',
                'rim_data_type' => 'ct:CTwholePoundStructure',
                'null_policy' => 'omit',
                'is_required' => 0,
            ]]
        );
        $h->assertSame(false, $result['success']);
        $h->assertTrue(str_contains(implode(' ', (array)$result['errors']), 'box 275'));
        $h->assertCount(3, (array)$result['blocked_claim_targets']);
    });
    $h->check($service::class, 'allows exact explicitly frozen same-trade loss relief at box 160', static function () use ($h, $service): void {
        $result = $service->mapFrozenFacts(
            \eel_accounts\Service\CtFilingMappingService::TARGET_RIM,
            [
                'available' => true,
                'basis_version' => 'test-basis-v1',
                'basis_hash' => str_repeat('d', 64),
                'run' => ['run_id' => 94],
                'model' => ['ct_period' => ['id' => 20]],
                'seal' => ['basis_hash' => str_repeat('d', 64)],
                'facts' => [
                    'computation.summary.losses_used' => 100.0,
                    'filing_decisions.loss_relief_treatment' => 'trading_brought_forward_against_same_trade_profit',
                    'filing_decisions.trading_losses_brought_forward_used' => 100.0,
                ],
            ],
            [
                'id' => 11,
                'target_type' => \eel_accounts\Service\CtFilingMappingService::TARGET_RIM,
                'rim_package_id' => 4,
                'status' => 'active',
                'compatibility_status' => 'compatible',
            ],
            [[
                'profile_id' => 11,
                'canonical_key' => 'filing_decisions.trading_losses_brought_forward_used',
                'target_xpath' => 'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/Income/Trading/LossesBroughtForward',
                'value_type' => 'numeric',
                'rim_data_type' => 'ct:CTwholePoundStructure',
                'sign_multiplier' => 1,
                'null_policy' => 'omit',
                'is_required' => 0,
            ]]
        );
        $h->assertSame(true, $result['success']);
        $h->assertSame('100.00', $result['mappings'][0]['serialized_value']);
    });
});
