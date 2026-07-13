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

        $harness->check(\eel_accounts\Service\CorporationTaxComputationService::class, 'keeps CT loss values stable across runtime cache rebuilds', static function () use ($harness): void {
            if (!InterfaceDB::tableExists('corporation_tax_periods')) {
                $harness->skip('Corporation Tax periods are not available on the default InterfaceDB connection.');
            }

            $row = InterfaceDB::fetchOne(
                'SELECT company_id, id AS ct_period_id
                 FROM corporation_tax_periods
                 WHERE status <> :superseded_status
                 ORDER BY company_id ASC, accounting_period_id ASC, sequence_no ASC, id ASC
                 LIMIT 1',
                ['superseded_status' => 'superseded']
            );
            if (!is_array($row)) {
                $harness->skip('No Corporation Tax periods are available for cache rebuild testing.');
            }

            $taxService = new \eel_accounts\Service\CorporationTaxComputationService();
            $first = $taxService->fetchSummaryForCtPeriodId((int)$row['company_id'], (int)$row['ct_period_id']);
            $taxService->clearRuntimeCaches();
            $second = $taxService->fetchSummaryForCtPeriodId((int)$row['company_id'], (int)$row['ct_period_id']);

            $harness->assertSame(round((float)($first['losses_brought_forward'] ?? 0), 2), round((float)($second['losses_brought_forward'] ?? 0), 2));
            $harness->assertSame(round((float)($first['losses_used'] ?? 0), 2), round((float)($second['losses_used'] ?? 0), 2));
            $harness->assertSame(round((float)($first['losses_carried_forward'] ?? 0), 2), round((float)($second['losses_carried_forward'] ?? 0), 2));

            $cacheProperty = new ReflectionProperty(\eel_accounts\Service\CorporationTaxComputationService::class, 'ctPeriodSummaryCache');
            $cacheProperty->setAccessible(true);
            $harness->assertSame(true, count((array)$cacheProperty->getValue($taxService)) > 0);
            $harness->assertCount(0, (array)$cacheProperty->getValue(new \eel_accounts\Service\CorporationTaxComputationService()));
        });

    }
);
