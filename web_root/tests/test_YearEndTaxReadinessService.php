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
    \eel_accounts\Service\YearEndTaxReadinessService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\YearEndTaxReadinessService $service): void {
        $harness->check(\eel_accounts\Service\YearEndTaxReadinessService::class, 'uses opening and closing loss balances for CT period totals', static function () use ($harness, $service): void {
            $method = new ReflectionMethod($service, 'totals');
            $method->setAccessible(true);

            $totals = $method->invoke($service, [
                [
                    'accounting_profit' => -135.04,
                    'capital_allowances' => 556.90,
                    'taxable_before_losses' => -691.94,
                    'losses_brought_forward' => 0.00,
                    'losses_used' => 0.00,
                    'losses_carried_forward' => 691.94,
                    'taxable_profit' => 0.00,
                ],
                [
                    'accounting_profit' => -5.64,
                    'capital_allowances' => 0.00,
                    'taxable_before_losses' => -5.64,
                    'losses_brought_forward' => 691.94,
                    'losses_used' => 0.00,
                    'losses_carried_forward' => 697.58,
                    'taxable_profit' => 0.00,
                ],
            ]);

            $harness->assertSame(-140.68, $totals['accounting_profit']);
            $harness->assertSame(556.90, $totals['capital_allowances']);
            $harness->assertSame(-697.58, $totals['taxable_before_losses']);
            $harness->assertSame(0.00, $totals['losses_brought_forward']);
            $harness->assertSame(0.00, $totals['losses_used']);
            $harness->assertSame(697.58, $totals['losses_carried_forward']);
            $harness->assertSame(0.00, $totals['taxable_profit']);
        });
    }
);
