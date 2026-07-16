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
    \eel_accounts\Service\PrepaymentAllocationService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\PrepaymentAllocationService $service): void {
        $harness->check(\eel_accounts\Service\PrepaymentAllocationService::class, 'allocates a synthetic 730 pound example by inclusive days', static function () use ($harness, $service): void {
            // Deliberately synthetic fixture: 365 days at exactly 200 pence per day.
            $result = $service->calculateSchedule(73000, '2022-12-30', '2023-12-29', [
                ['id' => 79, 'period_start' => '2022-09-05', 'period_end' => '2023-09-30'],
                ['id' => 80, 'period_start' => '2023-10-01', 'period_end' => '2024-09-30'],
            ]);

            $harness->assertSame(365, $result['total_days']);
            $harness->assertSame(55000, $result['allocations'][0]['expense_pence']);
            $harness->assertSame(18000, $result['allocations'][0]['closing_deferred_pence']);
            $harness->assertSame(18000, $result['allocations'][1]['expense_pence']);
            $harness->assertSame(0, $result['allocations'][1]['closing_deferred_pence']);
            $harness->assertSame(73000, $result['allocated_pence']);
        });

        $harness->check(\eel_accounts\Service\PrepaymentAllocationService::class, 'supports a leap year and four accounting periods without a duration cap', static function () use ($harness, $service): void {
            $result = $service->calculateSchedule(109600, '2022-12-30', '2025-12-29', [
                ['id' => 79, 'period_start' => '2022-09-05', 'period_end' => '2023-09-30'],
                ['id' => 80, 'period_start' => '2023-10-01', 'period_end' => '2024-09-30'],
                ['id' => 81, 'period_start' => '2024-10-01', 'period_end' => '2025-09-30'],
                ['id' => 82, 'period_start' => '2025-10-01', 'period_end' => '2026-09-30'],
            ]);

            $harness->assertSame(1096, $result['total_days']);
            $harness->assertSame([275, 366, 365, 90], array_column($result['allocations'], 'overlap_days'));
            $harness->assertSame([27500, 36600, 36500, 9000], array_column($result['allocations'], 'expense_pence'));
            $harness->assertSame([82100, 45500, 9000, 0], array_column($result['allocations'], 'closing_deferred_pence'));
            $harness->assertSame(0, $result['unallocated_pence']);
        });

        $harness->check(\eel_accounts\Service\PrepaymentAllocationService::class, 'reconciles synthetic multi-period correction movements', static function () use ($harness, $service): void {
            $periods = [
                ['id' => 79, 'period_start' => '2022-09-05', 'period_end' => '2023-09-30'],
                ['id' => 80, 'period_start' => '2023-10-01', 'period_end' => '2024-09-30'],
                ['id' => 81, 'period_start' => '2024-10-01', 'period_end' => '2025-09-30'],
            ];
            $first = $service->calculateSchedule(73000, '2022-12-30', '2023-12-29', $periods);
            $second = $service->calculateSchedule(109800, '2024-01-16', '2025-01-15', $periods);

            $harness->assertSame([55000, 18000], array_column($first['allocations'], 'expense_pence'));
            $harness->assertSame([18000, 0], array_column($first['allocations'], 'closing_deferred_pence'));
            $harness->assertSame([77700, 32100], array_column($second['allocations'], 'expense_pence'));
            $harness->assertSame([32100, 0], array_column($second['allocations'], 'closing_deferred_pence'));

            // Profit/taxable-profit timing changes relative to expensing each purchase in full.
            $harness->assertSame([18000, 14100, -32100], [
                73000 - 55000,
                -18000 + (109800 - 77700),
                -32100,
            ]);
            $harness->assertSame([18000, 32100, 0], [
                $first['allocations'][0]['closing_deferred_pence'],
                $second['allocations'][0]['closing_deferred_pence'],
                $second['allocations'][1]['closing_deferred_pence'],
            ]);
        });

        $harness->check(\eel_accounts\Service\PrepaymentAllocationService::class, 'reconciles cumulative half-up penny rounding exactly', static function () use ($harness, $service): void {
            $result = $service->calculateSchedule(100, '2024-01-01', '2024-01-03', [
                ['id' => 1, 'period_start' => '2024-01-01', 'period_end' => '2024-01-01'],
                ['id' => 2, 'period_start' => '2024-01-02', 'period_end' => '2024-01-02'],
                ['id' => 3, 'period_start' => '2024-01-03', 'period_end' => '2024-01-03'],
            ]);

            $harness->assertSame([33, 34, 33], array_column($result['allocations'], 'expense_pence'));
            $harness->assertSame(100, array_sum(array_column($result['allocations'], 'expense_pence')));
        });

        $harness->check(\eel_accounts\Service\PrepaymentAllocationService::class, 'leaves service days for accounting periods not created yet unallocated', static function () use ($harness, $service): void {
            $result = $service->calculateSchedule(73000, '2022-12-30', '2023-12-29', [
                ['id' => 79, 'period_start' => '2022-09-05', 'period_end' => '2023-09-30'],
            ]);

            $harness->assertSame(55000, $result['allocated_pence']);
            $harness->assertSame(18000, $result['unallocated_pence']);
        });

        $harness->check(\eel_accounts\Service\PrepaymentAllocationService::class, 'creates a full source-period deferral when service starts in a later period', static function () use ($harness, $service): void {
            $result = $service->calculateSchedule(73000, '2023-10-01', '2024-09-30', [
                [
                    'id' => 79,
                    'period_start' => '2022-09-05',
                    'period_end' => '2023-09-30',
                    'force_source_deferral' => true,
                    'is_source_period' => true,
                ],
                ['id' => 80, 'period_start' => '2023-10-01', 'period_end' => '2024-09-30'],
            ]);

            $harness->assertSame(0, $result['allocations'][0]['overlap_days']);
            $harness->assertSame(null, $result['allocations'][0]['overlap_start']);
            $harness->assertSame(0, $result['allocations'][0]['expense_pence']);
            $harness->assertSame(73000, $result['allocations'][0]['closing_deferred_pence']);
            $harness->assertSame(73000, $result['allocations'][1]['opening_deferred_pence']);
            $harness->assertSame(73000, $result['allocations'][1]['expense_pence']);
            $harness->assertSame(0, $result['allocations'][1]['closing_deferred_pence']);
            $harness->assertSame(73000, $result['allocated_pence']);
        });
    }
);
