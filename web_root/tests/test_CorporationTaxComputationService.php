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
        $harness->check(\eel_accounts\Service\CorporationTaxComputationService::class, 'reuses final validated summaries when recording year-end evidence', static function () use ($harness): void {
            $source = (string)file_get_contents(
                dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'eel_accounts'
                . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR . 'CorporationTaxComputationService.php'
            );
            $harness->assertSame(true, str_contains($source, '?array $preparedSummaries = null'));
            $harness->assertSame(true, str_contains($source, '$preparedByCtPeriod[$preparedCtPeriodId] = $preparedSummary'));
            $harness->assertSame(true, str_contains($source, '$this->persistCalculatedSummaryWithCurrentCaches($companyId, $summary)'));
            $harness->assertSame(true, str_contains($source, "return_position_model_version'] ?? '') === CorporationTaxReturnPositionService::MODEL_VERSION"));
        });

        $harness->check(\eel_accounts\Service\CorporationTaxComputationService::class, 'keeps the Year End approval and persistence evidence enrichment identical', static function () use ($harness, $service): void {
            $preparedBreakdown = [
                'expected_amount' => 25.00,
                'amount' => 25.00,
                'difference' => 0.00,
                'reconciled' => true,
                'categories' => [['code' => 'business_entertainment', 'amount' => 25.00]],
            ];
            $summaries = $service->withYearEndDisallowableExpenseBreakdowns(0, 0, [[
                'ct_period_id' => 0,
                'disallowable_expense_breakdown' => $preparedBreakdown,
            ]]);
            $harness->assertSame($preparedBreakdown, $summaries[0]['disallowable_expense_breakdown'] ?? null);
        });

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

        $harness->check(\eel_accounts\Service\CorporationTaxComputationService::class, 'preserves qualifying donations before using brought-forward losses', static function () use ($harness, $service): void {
            $method = new ReflectionMethod($service, 'dividendCapacityLossCalculation');
            $method->setAccessible(true);
            $result = $method->invoke($service, 1000.00, ['brought_forward' => 900.00], 250.00);

            $harness->assertSame('750.00', number_format((float)$result['losses_used'], 2, '.', ''));
            $harness->assertSame('250.00', number_format((float)$result['profits_before_donations_group_relief'], 2, '.', ''));
            $harness->assertSame('250.00', number_format((float)$result['qualifying_charitable_donations_claimed'], 2, '.', ''));
            $harness->assertSame('0.00', number_format((float)$result['taxable_profit'], 2, '.', ''));
            $harness->assertSame('150.00', number_format((float)$result['losses_carried_forward'], 2, '.', ''));
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
            $result = $allocate->invoke($service, 73000, [1 => 365, 2 => 26], 391);

            $harness->assertSame([1 => 68146, 2 => 4854], $result);
            $harness->assertSame(73000, array_sum($result));

            $negative = $allocate->invoke($service, -101, [1 => 200, 2 => 191], 391);
            $harness->assertSame(-101, array_sum($negative));
        });

        $harness->check(\eel_accounts\Service\CorporationTaxComputationService::class, 'discloses post-reform carried-forward loss relief and apportions the non-group deductions allowance', static function () use ($harness, $service): void {
            $disclosure = new ReflectionMethod($service, 'lossRestrictionDisclosure');
            $disclosure->setAccessible(true);
            $result = $disclosure->invoke($service, '2023-09-05', '2023-09-30', 563.21, 0.00, 4.67, 558.54, 4.67);

            $post = (array)$result['post_2017_trading_losses'];
            $pre = (array)$result['pre_2017_trading_losses'];
            $harness->assertSame('563.21', number_format((float)$post['brought_forward'], 2, '.', ''));
            $harness->assertSame('4.67', number_format((float)$post['used'], 2, '.', ''));
            $harness->assertSame('558.54', number_format((float)$post['carried_forward'], 2, '.', ''));
            $harness->assertSame('0.00', number_format((float)$pre['brought_forward'], 2, '.', ''));
            $harness->assertSame('356164.38', number_format((float)($result['deduction_allowance']['amount'] ?? 0), 2, '.', ''));
            $harness->assertSame(26, (int)$result['deduction_allowance']['ct_period_days']);
            $harness->assertSame(365, (int)$result['deduction_allowance']['statutory_denominator_days']);
            $harness->assertSame('5000000.00', number_format((float)$result['deduction_allowance']['annual_allowance'], 2, '.', ''));
            $harness->assertSame('356164.38', number_format((float)$result['deduction_allowance']['calculated_allowance'], 2, '.', ''));
            $harness->assertSame(true, $result['deduction_allowance']['apportionment_applied']);
            $harness->assertSame('4.67', number_format((float)$result['qualifying_profits'], 2, '.', ''));
            $harness->assertSame('4.67', number_format((float)$result['carried_forward_loss_relief_claimed'], 2, '.', ''));
            $harness->assertSame('none', (string)$result['loss_restriction']);

            $fullYear = $disclosure->invoke(
                $service,
                '2024-10-01',
                '2025-09-30',
                0.00,
                0.00,
                0.00,
                0.00,
                0.00
            );
            $harness->assertSame(false, $fullYear['deduction_allowance']['apportionment_applied']);
            $harness->assertSame(
                $fullYear['deduction_allowance']['annual_allowance'],
                $fullYear['deduction_allowance']['calculated_allowance']
            );
        });

        $harness->check(\eel_accounts\Service\CorporationTaxComputationService::class, 'apportions the adjusted result before reconciling naturally rounded component disclosures', static function () use ($harness, $service): void {
            $allocate = new ReflectionMethod($service, 'allocateAccountingComponentsByInclusiveDays');
            $allocate->setAccessible(true);
            $result = $allocate->invoke(
                $service,
                [
                    'accounting_profit' => -12711,
                    'disallowable_add_backs' => 0,
                    'capital_add_backs' => 0,
                    'depreciation_add_back' => 19741,
                ],
                [1 => 365, 2 => 26],
                391
            );

            $harness->assertSame(-11866, (int)$result[1]['accounting_profit']);
            $harness->assertSame(-845, (int)$result[2]['accounting_profit']);
            $harness->assertSame(18428, (int)$result[1]['depreciation_add_back']);
            $harness->assertSame(1313, (int)$result[2]['depreciation_add_back']);
            $harness->assertSame(6562, (int)$result[1]['component_subtotal']);
            $harness->assertSame(468, (int)$result[2]['component_subtotal']);
            $harness->assertSame(6563, (int)$result[1]['adjusted_result_before_capital_allowances']);
            $harness->assertSame(467, (int)$result[2]['adjusted_result_before_capital_allowances']);
            $harness->assertSame(1, (int)$result[1]['apportionment_rounding_adjustment']);
            $harness->assertSame(-1, (int)$result[2]['apportionment_rounding_adjustment']);
            $harness->assertSame(
                0,
                array_sum(array_column($result, 'apportionment_rounding_adjustment'))
            );
            $harness->assertSame(
                -12711,
                array_sum(array_column($result, 'accounting_profit'))
            );
            $harness->assertSame(
                19741,
                array_sum(array_column($result, 'depreciation_add_back'))
            );

            $taxable = new ReflectionMethod($service, 'taxableBeforeLossesForCtPeriod');
            $taxable->setAccessible(true);
            $harness->assertSame(
                -563.21,
                $taxable->invoke(
                    $service,
                    [
                        'pnl' => [
                            'profit_before_tax' => -118.66,
                            'disallowable_add_backs' => 0.0,
                            'capital_add_backs' => 0.0,
                        ],
                        'adjusted_result_before_capital_allowances' => 65.63,
                    ],
                    [
                        'depreciation_add_back' => 184.28,
                        'capital_allowances' => 628.84,
                    ]
                )
            );

            $losses = new ReflectionMethod($service, 'dividendCapacityLossCalculation');
            $losses->setAccessible(true);
            $firstLossPosition = $losses->invoke($service, -563.21, ['brought_forward' => 0.0]);
            $harness->assertSame('563.21', number_format((float)$firstLossPosition['loss_created'], 2, '.', ''));
            $harness->assertSame('563.21', number_format((float)$firstLossPosition['losses_carried_forward'], 2, '.', ''));

            $secondLossPosition = $losses->invoke(
                $service,
                4.67,
                ['brought_forward' => (float)$firstLossPosition['losses_carried_forward']]
            );
            $harness->assertSame('563.21', number_format((float)$secondLossPosition['losses_brought_forward'], 2, '.', ''));
            $harness->assertSame('4.67', number_format((float)$secondLossPosition['losses_used'], 2, '.', ''));
            $harness->assertSame('558.54', number_format((float)$secondLossPosition['losses_carried_forward'], 2, '.', ''));
            $harness->assertSame('0.00', number_format((float)$secondLossPosition['taxable_profit'], 2, '.', ''));
        });

        $harness->check(\eel_accounts\Service\CorporationTaxComputationService::class, 'produces no reconciliation adjustment when natural components equal the adjusted-result allocation', static function () use ($harness, $service): void {
            $allocate = new ReflectionMethod($service, 'allocateAccountingComponentsByInclusiveDays');
            $allocate->setAccessible(true);
            $result = $allocate->invoke(
                $service,
                [
                    'accounting_profit' => 73000,
                    'disallowable_add_backs' => 0,
                    'capital_add_backs' => 0,
                    'depreciation_add_back' => 0,
                ],
                [1 => 365, 2 => 26],
                391
            );

            $harness->assertSame(false, array_key_exists('apportionment_rounding_adjustment', $result[1]));
            $harness->assertSame(false, array_key_exists('apportionment_rounding_adjustment', $result[2]));
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

        $harness->check(\eel_accounts\Service\CorporationTaxComputationService::class, 'caches VAT support scope once per company until runtime caches are cleared', static function () use ($harness): void {
            $scopeCalls = 0;
            $scopeFetcher = static function (int $companyId) use (&$scopeCalls): array {
                $scopeCalls++;

                return [
                    'tax_year_end_read_only' => false,
                    'supported' => true,
                    'company_id' => $companyId,
                    'message' => '',
                ];
            };
            $computation = new \eel_accounts\Service\CorporationTaxComputationService(
                vatSupportScopeFetcher: $scopeFetcher
            );
            $scopeMethod = new ReflectionMethod($computation, 'vatSupportScope');
            $scopeMethod->setAccessible(true);

            $first = $scopeMethod->invoke($computation, 77);
            $second = $scopeMethod->invoke($computation, 77);
            $harness->assertSame($first, $second);
            $harness->assertSame(1, $scopeCalls);

            $computation->clearRuntimeCaches();
            $scopeMethod->invoke($computation, 77);
            $harness->assertSame(2, $scopeCalls);
        });
    }
);
