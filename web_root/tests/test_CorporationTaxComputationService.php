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
    \eel_accounts\Service\CorporationTaxComputationService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\CorporationTaxComputationService $service): void {
        $harness->check(\eel_accounts\Service\CorporationTaxComputationService::class, 'keeps brought-forward losses visible when dividend capacity creates a further loss', static function () use ($harness, $service): void {
            $method = new ReflectionMethod($service, 'dividendCapacityLossCalculation');
            $method->setAccessible(true);
            $result = $method->invoke($service, -7594.69, ['brought_forward' => 349.09]);

            $harness->assertSame('349.09', number_format((float)$result['losses_brought_forward'], 2, '.', ''));
            $harness->assertSame('0.00', number_format((float)$result['losses_used'], 2, '.', ''));
            $harness->assertSame('7594.69', number_format((float)$result['loss_created'], 2, '.', ''));
            $harness->assertSame('7943.78', number_format((float)$result['losses_carried_forward'], 2, '.', ''));
            $harness->assertSame('0.00', number_format((float)$result['taxable_profit'], 2, '.', ''));
        });

        $harness->check(\eel_accounts\Service\CorporationTaxComputationService::class, 'keeps expected pre-lock persistence state out of tax warnings', static function () use ($harness, $service): void {
            $method = new ReflectionMethod($service, 'withComputationPersistenceState');
            $method->setAccessible(true);
            $result = $method->invoke($service, 0, 0, [
                'available' => true,
                'warnings' => ['A genuine pre-close tax issue.'],
                'confidence_status' => 'review_required',
                'confidence_label' => 'Review required',
            ]);

            $harness->assertSame('not_persisted', (string)($result['computation_persistence']['status'] ?? ''));
            $harness->assertSame(['A genuine pre-close tax issue.'], (array)($result['warnings'] ?? []));
            $harness->assertSame('review_required', (string)($result['confidence_status'] ?? ''));
            $harness->assertSame('Review required', (string)($result['confidence_label'] ?? ''));
        });

        $harness->check(\eel_accounts\Service\CorporationTaxComputationService::class, 'time apportions pennies by inclusive CT-period days and puts the rounding residual in the final period', static function () use ($harness, $service): void {
            $allocate = new ReflectionMethod($service, 'allocatePenceByInclusiveDays');
            $allocate->setAccessible(true);
            $result = $allocate->invoke($service, 57000, [1 => 365, 2 => 26], 391);

            $harness->assertSame([1 => 53210, 2 => 3790], $result);
            $harness->assertSame(57000, array_sum($result));

            $negative = $allocate->invoke($service, -101, [1 => 200, 2 => 191], 391);
            $harness->assertSame(-101, array_sum($negative));
        });

        $harness->check(\eel_accounts\Service\CorporationTaxComputationService::class, 'counts leap-day boundaries inclusively for CT allocation', static function () use ($harness, $service): void {
            $inclusiveDays = new ReflectionMethod($service, 'inclusiveDays');
            $inclusiveDays->setAccessible(true);

            $harness->assertSame(366, $inclusiveDays->invoke($service, '2023-03-01', '2024-02-29'));
            $harness->assertSame(1, $inclusiveDays->invoke($service, '2024-02-29', '2024-02-29'));
        });

        $harness->check(\eel_accounts\Service\CorporationTaxComputationService::class, 'uses one taxable-before-losses formula including capital add-backs', static function () use ($harness, $service): void {
            $formula = new ReflectionMethod($service, 'taxableBeforeLosses');
            $formula->setAccessible(true);

            $harness->assertSame(
                1175.0,
                $formula->invoke(
                    $service,
                    [
                        'profit_before_tax' => 1000.0,
                        'disallowable_add_backs' => 50.0,
                        'capital_add_backs' => 100.0,
                    ],
                    [
                        'depreciation_add_back' => 75.0,
                        'capital_allowances' => 50.0,
                    ]
                )
            );
        });

        $harness->check(\eel_accounts\Service\CorporationTaxComputationService::class, 'marks an estimate for review when the prepayment preview is unreliable', static function () use ($harness): void {
            $rateService = new \eel_accounts\Service\CorporationTaxRateService([[
                'financial_year_start' => '2025-04-01',
                'financial_year_end' => '2026-03-31',
                'rule_version' => 'prepayment-reliability-test',
                'main_rate' => 0.25,
                'small_profits_rate' => 0.19,
                'lower_limit' => 50000.0,
                'upper_limit' => 250000.0,
                'marginal_relief_fraction' => 0.015,
                'source_url' => 'https://example.test/prepayment-reliability-rate',
                'source_checked_at' => '2026-07-16',
                'is_active' => 1,
            ]]);
            $result = (new \eel_accounts\Service\CorporationTaxComputationService(null, $rateService))
                ->fetchCurrentPeriodEstimate(
                    0,
                    0,
                    [
                        'id' => 0,
                        'label' => 'Prepayment reliability test',
                        'period_start' => '2025-04-01',
                        'period_end' => '2026-03-31',
                    ],
                    [
                        'profit_before_tax' => 1000.0,
                        'disallowable_add_backs' => 0.0,
                        'capital_add_backs' => 0.0,
                        'depreciation_expense' => 0.0,
                        'other_treatment_count' => 0,
                        'unknown_treatment_count' => 0,
                        'prepayment_preview_reliable' => false,
                        'prepayment_preview_warnings' => [
                            'Prepayment schedule #7 no longer matches its linked source amount.',
                        ],
                    ]
                );

            $warnings = implode(' ', (array)($result['warnings'] ?? []));
            $harness->assertSame(false, (bool)($result['prepayment_preview_reliable'] ?? true));
            $harness->assertSame('review_required', (string)($result['confidence_status'] ?? ''));
            $harness->assertSame('Review required', (string)($result['confidence_label'] ?? ''));
            $harness->assertTrue(str_contains($warnings, 'prepayment preview is unreliable'));
            $harness->assertTrue(str_contains($warnings, 'no longer matches its linked source amount'));
        });
    }
);
