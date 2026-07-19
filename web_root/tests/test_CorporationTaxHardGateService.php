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
    \eel_accounts\Service\CorporationTaxHardGateService::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $harness->check(\eel_accounts\Service\CorporationTaxHardGateService::class, 'accepts a fully classified, cross-cast CT period', static function () use ($harness): void {
            $diagnostics = (new \eel_accounts\Service\CorporationTaxHardGateService())->evaluatePeriod([
                'ct_period_id' => 6,
                'unknown_treatment_amount' => 0.0,
                'other_treatment_amount' => 0.0,
                'warnings' => [],
                'taxable_before_losses' => -100.0,
                'taxable_profit' => 0.0,
                'loss_created_in_period' => 100.0,
                'losses_brought_forward' => 0.0,
                'losses_used' => 0.0,
                'losses_carried_forward' => 100.0,
            ]);

            $harness->assertSame([], $diagnostics);
        });

        $harness->check(\eel_accounts\Service\CorporationTaxHardGateService::class, 'routes journal treatments and asset warnings to correction workflows', static function () use ($harness): void {
            $diagnostics = (new \eel_accounts\Service\CorporationTaxHardGateService())->evaluatePeriod([
                'ct_period_id' => 7,
                'unknown_treatment_amount' => 12.34,
                'other_treatment_amount' => 5.67,
                'warnings' => [
                    'Car asset MV1 is missing CO2 emissions.',
                    'Pooled asset A1 has no disposal value.',
                    'A capital allowance pool warning remains.',
                ],
                'taxable_before_losses' => 0.0,
                'taxable_profit' => 0.0,
                'loss_created_in_period' => 0.0,
                'losses_brought_forward' => 0.0,
                'losses_used' => 0.0,
                'losses_carried_forward' => 0.0,
            ]);

            $categories = array_column($diagnostics, 'category');
            $workflows = array_column($diagnostics, 'workflow_page');
            $harness->assertTrue(count(array_filter($categories, static fn(string $category): bool => $category === 'nominal_treatment')) === 2);
            $harness->assertTrue(in_array('vehicle', $categories, true));
            $harness->assertTrue(in_array('disposal', $categories, true));
            $harness->assertTrue(in_array('capital_allowance', $categories, true));
            $harness->assertTrue(in_array('nominals', $workflows, true));
            $harness->assertTrue(in_array('vehicles', $workflows, true));
            $harness->assertTrue(in_array('assets', $workflows, true));
        });

        $harness->check(\eel_accounts\Service\CorporationTaxHardGateService::class, 'detects loss continuity and cross-cast failures', static function () use ($harness): void {
            $diagnostics = (new \eel_accounts\Service\CorporationTaxHardGateService())->evaluatePeriod([
                'ct_period_id' => 8,
                'taxable_before_losses' => 50.0,
                'taxable_profit' => 30.0,
                'loss_created_in_period' => 10.0,
                'losses_brought_forward' => 40.0,
                'losses_used' => 60.0,
                'losses_carried_forward' => 99.0,
            ], ['losses_carried_forward' => 25.0]);

            $codes = array_column($diagnostics, 'code');
            foreach ([
                'loss_brought_forward_continuity',
                'loss_use_exceeds_available',
                'loss_created_cross_cast',
                'loss_carried_forward_cross_cast',
                'loss_taxable_profit_cross_cast',
            ] as $code) {
                $harness->assertTrue(in_array($code, $codes, true));
            }
        });

        $harness->check(\eel_accounts\Service\CorporationTaxHardGateService::class, 'does not turn generic calculation assumptions into Year End issues', static function () use ($harness): void {
            $diagnostics = (new \eel_accounts\Service\CorporationTaxHardGateService())->evaluatePeriod([
                'ct_period_id' => 8,
                'warnings' => [
                    'Corporation Tax estimate assumes non-ring-fence profits.',
                    'Corporation Tax estimate assumes augmented profits equal taxable profits; review if exempt distributions were received.',
                ],
                'taxable_before_losses' => 0.0,
                'taxable_profit' => 0.0,
                'loss_created_in_period' => 0.0,
                'losses_brought_forward' => 0.0,
                'losses_used' => 0.0,
                'losses_carried_forward' => 0.0,
            ]);

            $harness->assertSame([], $diagnostics);
        });

        $harness->check(\eel_accounts\Service\CorporationTaxHardGateService::class, 'blocks an unavailable locked predecessor computation', static function () use ($harness): void {
            $service = new \eel_accounts\Service\CorporationTaxHardGateService(
                static fn(int $companyId, string $periodStart): array => ['expected' => true, 'available' => false]
            );
            $periods = $service->apply(49, [[
                'ct_period_id' => 9,
                'period_start' => '2024-10-01',
                'taxable_before_losses' => 0.0,
                'taxable_profit' => 0.0,
                'loss_created_in_period' => 0.0,
                'losses_brought_forward' => 0.0,
                'losses_used' => 0.0,
                'losses_carried_forward' => 0.0,
            ]]);

            $codes = array_column((array)($periods[0]['hard_gate_diagnostics'] ?? []), 'code');
            $harness->assertTrue(in_array('loss_predecessor_unavailable', $codes, true));
            $harness->assertSame(false, (bool)($periods[0]['hard_gate_pass'] ?? true));
        });
    }
);
