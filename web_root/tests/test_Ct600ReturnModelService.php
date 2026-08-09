<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

/** @return array<string,mixed> */
function ct600_return_model_test_filing(): array
{
    return [
        'available' => true,
        'basis_version' => 'ct-period-filing-model-test-v1',
        'basis_hash' => str_repeat('a', 64),
        'blocking_diagnostics' => [],
        'warning_diagnostics' => [],
        'facts' => [
            'identity.company_name' => 'Example Trading Limited',
            'identity.company_number' => '01234567',
            'filing_identity.utr' => '0123456789',
        ],
        'model' => [
            'supported_return_profile' => [
                'supported' => true,
                'ordinary_trading_company_confirmed' => true,
                'failed_checks' => [],
            ],
            'identity' => [
                'company_id' => 49,
                'company_name' => 'Example Trading Limited',
                'company_number' => '01234567',
            ],
            'filing_identity' => ['utr' => '0123456789'],
            'accounting_period' => [
                'id' => 79,
                'start_date' => '2022-09-05',
                'end_date' => '2023-09-30',
            ],
            'ct_period' => [
                'id' => 6,
                'start_date' => '2022-09-05',
                'end_date' => '2023-09-04',
            ],
            'accounts_facts' => [
                'presentation_currency' => 'GBP',
                'turnover' => 250000.25,
            ],
            'ct_period_facts' => [
                'actual_trading_turnover' => 250000.25,
                'ct600_box_145_turnover' => 250000.0,
                'ct600_turnover_rounding_adjustment' => 0,
                'turnover_basis_version' => \eel_accounts\Service\CtPeriodTurnoverService::BASIS_VERSION,
            ],
            'accounts_report' => [
                'basis_version' => 'accounts-report-test-v1',
                'basis_hash' => str_repeat('b', 64),
            ],
            'approval' => [
                'id' => 91,
                'basis_hash' => str_repeat('c', 64),
            ],
            'computation' => [
                'run_id' => 101,
                'hash' => str_repeat('d', 64),
                'summary' => [
                    'accounting_profit' => 80000.0,
                    'capital_allowances' => 5000.0,
                    'taxable_before_losses' => 75000.0,
                    'taxable_profit' => 70000.0,
                    'taxable_loss' => 0.0,
                    'losses_brought_forward' => 5000.0,
                    'losses_used' => 5000.0,
                    'losses_carried_forward' => 0.0,
                    'loss_created_in_period' => 0.0,
                    'ordinary_corporation_tax' => 13300.0,
                    'estimated_corporation_tax' => 13300.0,
                    's455_tax' => 0.0,
                    'associated_company_count' => 0,
                ],
            ],
            'filing_decisions' => [
                'return_type' => 'new',
                'company_type' => 0,
                'this_period_return' => true,
                'multiple_returns' => true,
                'accounts_attached' => true,
                'accounts_same_period' => false,
                'computations_attached' => true,
                'computations_same_period' => true,
                'supplementary_pages' => [],
                'loss_relief_treatment' => 'trading_brought_forward_against_same_trade_profit',
                'trading_profit_before_losses' => 75000.0,
                'trading_losses_brought_forward_used' => 5000.0,
                'net_trading_profits' => 70000.0,
                'profits_before_other_deductions' => 70000.0,
                'profits_before_donations_group_relief' => 70000.0,
                'associated_company_count' => 0,
                'tax_calculation_bands' => [[
                    'financial_year' => '2022',
                    'profit' => 70000.0,
                    'tax_rate_percent' => 19.0,
                    'gross_tax' => 13300.0,
                    'marginal_relief' => 0.0,
                    'net_tax' => 13300.0,
                    'basis' => 'flat_main_rate',
                ]],
                'aia_claimed_in_trade' => 5000.0,
                'main_pool_capital_allowances' => 5000.0,
                'main_pool_balancing_charges' => 0.0,
                'special_rate_pool_capital_allowances' => 0.0,
                'special_rate_pool_balancing_charges' => 0.0,
                'qualifying_expenditure_other_machinery_plant' => 5000.0,
            ],
        ],
    ];
}

/** @return \eel_accounts\Service\Ct600ReturnModelService */
function ct600_return_model_test_service(array $filing): \eel_accounts\Service\Ct600ReturnModelService
{
    return new \eel_accounts\Service\Ct600ReturnModelService(
        static fn(int $companyId, int $accountingPeriodId, int $ctPeriodId): array => $filing,
        static fn(string $periodStart, string $periodEnd): array => [
            'ok' => true,
            'package_id' => 21,
            'form_version' => 'V3',
            'artifact_version' => 'V1.994',
            'sha256' => str_repeat('e', 64),
            'warnings' => [],
        ],
        static fn(int $packageId): array => [
            'id' => 31,
            'revision_no' => 4,
            'content_hash' => str_repeat('f', 64),
            'rim_package_id' => $packageId,
            'status' => 'active',
            'compatibility_status' => 'compatible',
        ],
        static function (array $mappingInput, array $profile): array {
            if (($mappingInput['facts']['ct600.identity.utr'] ?? null) !== '0123456789') {
                throw new RuntimeException('The derived CT600 aliases were not supplied to the mapper.');
            }
            return [
                'success' => true,
                'errors' => [],
                'monetary_policy_version' => 'test-monetary-policy-v1',
                'mappings' => [
                    [
                        'canonical_key' => 'identity.company_name',
                        'target_xpath' => 'IRenvelope/CompanyTaxReturn/CompanyInformation/CompanyName',
                        'source_value' => 'Example Trading Limited',
                    ],
                    [
                        'canonical_key' => 'computation.summary.ordinary_corporation_tax',
                        'target_xpath' => 'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/NetCorporationTaxChargeable',
                        'source_value' => 13300.0,
                    ],
                ],
                'profile_id' => (int)$profile['id'],
            ];
        }
    );
}

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\Ct600ReturnModelService::class,
    static function (GeneratedServiceClassTestHarness $h): void {
        $h->check(
            \eel_accounts\Service\Ct600ReturnModelService::class,
            'builds the same return and source manifest from the same frozen basis',
            static function () use ($h): void {
                $service = ct600_return_model_test_service(ct600_return_model_test_filing());
                $first = $service->build(49, 79, 6);
                $second = $service->build(49, 79, 6);

                $h->assertSame(true, (bool)$first['ok']);
                $h->assertSame($first['model_json'], $second['model_json']);
                $h->assertSame($first['model_sha256'], $second['model_sha256']);
                $h->assertSame($first['source_manifest_sha256'], $second['source_manifest_sha256']);
                $h->assertSame('0123456789', $first['model']['identity']['utr']);
                $h->assertSame(250000.0, $first['model']['amounts']['turnover']);
                $h->assertSame(21, $first['source_manifest']['rim_package_id']);
                $h->assertSame(31, $first['source_manifest']['mapping_profile_id']);
            }
        );

        $h->check(
            \eel_accounts\Service\Ct600ReturnModelService::class,
            'derives Golden Company AP79 CT600 loss boxes from the frozen loss schedule',
            static function () use ($h): void {
                $first = ct600_return_model_test_filing();
                $first['model']['computation']['summary'] = array_replace(
                    $first['model']['computation']['summary'],
                    [
                        'accounting_profit' => -118.66,
                        'capital_allowances' => 628.84,
                        'taxable_before_losses' => -563.21,
                        'taxable_profit' => 0.00,
                        'taxable_loss' => 563.21,
                        'losses_brought_forward' => 0.00,
                        'losses_used' => 0.00,
                        'losses_carried_forward' => 563.21,
                        'loss_created_in_period' => 563.21,
                        'ordinary_corporation_tax' => 0.00,
                        'estimated_corporation_tax' => 0.00,
                        'loss_restriction' => [
                            'post_2017_trading_losses' => ['brought_forward' => 0.00, 'arising' => 563.21, 'used' => 0.00, 'carried_forward' => 563.21],
                            'carried_forward_loss_relief_claimed' => 0.00,
                        ],
                    ]
                );
                $first['model']['filing_decisions']['main_pool_capital_allowances'] = 628.84;
                $first['model']['filing_decisions']['aia_claimed_in_trade'] = 628.84;
                $first['model']['filing_decisions']['qualifying_expenditure_other_machinery_plant'] = 628.84;
                $first['model']['filing_decisions']['tax_calculation_bands'] = [];
                $firstResult = ct600_return_model_test_service($first)->build(49, 79, 6);
                $h->assertSame(true, (bool)$firstResult['ok']);
                $firstCalculation = (array)$firstResult['model']['calculation'];
                $h->assertSame(0.00, (float)$firstCalculation['trading_profit_before_losses']);
                $h->assertSame(0.00, (float)$firstCalculation['trading_losses_carried_forward_claimed']);
                $h->assertSame(0.00, (float)$firstCalculation['total_deductions_and_reliefs']);
                $h->assertSame(563.21, (float)$firstResult['model']['amounts']['loss_created_in_period']);
                $h->assertSame(false, (bool)($firstResult['model']['ct600a']['required'] ?? false));
                $h->assertSame(0.00, (float)$firstResult['model']['amounts']['tax_payable']);

                $second = $first;
                $second['model']['ct_period'] = ['id' => 7, 'start_date' => '2023-09-05', 'end_date' => '2023-09-30'];
                $second['model']['computation']['summary'] = array_replace(
                    $second['model']['computation']['summary'],
                    [
                        'accounting_profit' => -8.45,
                        'capital_allowances' => 0.00,
                        'taxable_before_losses' => 4.67,
                        'taxable_profit' => 0.00,
                        'taxable_loss' => 0.00,
                        'losses_brought_forward' => 563.21,
                        'losses_used' => 4.67,
                        'losses_carried_forward' => 558.54,
                        'loss_created_in_period' => 0.00,
                        'loss_restriction' => [
                            'post_2017_trading_losses' => ['brought_forward' => 563.21, 'arising' => 0.00, 'used' => 4.67, 'carried_forward' => 558.54],
                            'carried_forward_loss_relief_claimed' => 4.67,
                        ],
                    ]
                );
                $second['model']['filing_decisions']['main_pool_capital_allowances'] = 0.00;
                $second['model']['filing_decisions']['aia_claimed_in_trade'] = 0.00;
                $second['model']['filing_decisions']['qualifying_expenditure_other_machinery_plant'] = 0.00;
                $secondResult = ct600_return_model_test_service($second)->build(49, 79, 7);
                $h->assertSame(true, (bool)$secondResult['ok']);
                $secondCalculation = (array)$secondResult['model']['calculation'];
                $h->assertSame(4.67, (float)$secondCalculation['trading_profit_before_losses']);
                $h->assertSame(0.00, (float)$secondCalculation['trading_losses_brought_forward_used']);
                $h->assertSame(4.67, (float)$secondCalculation['trading_losses_carried_forward_claimed']);
                $h->assertSame(4.67, (float)$secondCalculation['total_deductions_and_reliefs']);
                $h->assertSame(0.00, (float)$secondCalculation['profits_before_donations_group_relief']);
                $h->assertSame(558.54, (float)$secondResult['model']['amounts']['losses_carried_forward']);
                $h->assertSame(false, (bool)($secondResult['model']['ct600a']['required'] ?? false));
                $h->assertSame(0.00, (float)$secondResult['model']['amounts']['tax_payable']);
            }
        );

        $h->check(
            \eel_accounts\Service\Ct600ReturnModelService::class,
            'preserves the Golden donation deduction after brought-forward loss relief',
            static function () use ($h): void {
                $filing = ct600_return_model_test_filing();
                $filing['model']['computation']['summary'] = array_replace(
                    $filing['model']['computation']['summary'],
                    [
                        'accounting_profit' => 1699.62,
                        'taxable_before_losses' => 6953.00,
                        'taxable_profit' => 4837.00,
                        'taxable_loss' => 0.00,
                        'losses_brought_forward' => 1866.00,
                        'losses_used' => 1866.00,
                        'losses_carried_forward' => 0.00,
                        'loss_created_in_period' => 0.00,
                        'profits_before_donations_group_relief' => 5087.00,
                        'qualifying_charitable_donations_claimed' => 250.00,
                        'ordinary_corporation_tax' => 919.03,
                        'estimated_corporation_tax' => 919.03,
                        'loss_restriction' => [
                            'post_2017_trading_losses' => [
                                'brought_forward' => 1866.00,
                                'arising' => 0.00,
                                'used' => 1866.00,
                                'carried_forward' => 0.00,
                            ],
                            'carried_forward_loss_relief_claimed' => 1866.00,
                        ],
                    ]
                );
                $filing['model']['filing_decisions']['tax_calculation_bands'] = [[
                    'financial_year' => '2025',
                    'profit' => 4837.00,
                    'tax_rate_percent' => 19.0,
                    'gross_tax' => 919.03,
                    'marginal_relief' => 0.0,
                    'net_tax' => 919.03,
                    'basis' => 'small_profits_rate',
                ]];

                $result = ct600_return_model_test_service($filing)->build(49, 79, 6);
                $h->assertSame(true, (bool)($result['ok'] ?? false));
                $calculation = (array)$result['model']['calculation'];
                $h->assertSame(1866.00, (float)$calculation['trading_losses_carried_forward_claimed']);
                $h->assertSame(5087.00, (float)$calculation['profits_before_donations_group_relief']);
                $h->assertSame(250.00, (float)$calculation['qualifying_charitable_donations']);
                $h->assertSame(4837.00, (float)$result['model']['amounts']['taxable_profit']);
                $h->assertSame(919.03, (float)$result['model']['amounts']['tax_payable']);
            }
        );

        $h->check(
            \eel_accounts\Service\Ct600ReturnModelService::class,
            'fails closed when the frozen Corporation Tax UTR is invalid',
            static function () use ($h): void {
                $filing = ct600_return_model_test_filing();
                $filing['model']['filing_identity']['utr'] = '12345';
                $result = ct600_return_model_test_service($filing)->build(49, 79, 6);

                $h->assertSame(false, (bool)$result['ok']);
                $h->assertTrue(str_contains(implode(' ', (array)$result['errors']), '10-digit Corporation Tax UTR'));
                $h->assertSame([], $result['model']);
                $h->assertSame('', $result['source_manifest_sha256']);
            }
        );

        $h->check(
            \eel_accounts\Service\Ct600ReturnModelService::class,
            'supports a frozen structured CT600A page without inferring it from s455 alone',
            static function () use ($h): void {
                $filing = ct600_return_model_test_filing();
                $filing['model']['computation']['summary']['s455_tax'] = 25.0;
                $filing['model']['filing_decisions']['supplementary_pages'] = ['CT600A'];
                $filing['model']['filing_decisions']['ct600a_tax_payable'] = 25.0;
                $filing['model']['filing_decisions']['ct600a_relief_due'] = false;
                $filing['model']['ct600a'] = [
                    'required' => true,
                    'before_end_period' => false,
                    'part1' => ['rows' => [['party_id' => 1, 'name' => 'Test Participator', 'amount' => 100.0, 'tax' => 25.0]], 'total_loans' => 100.0, 'tax_chargeable' => 25.0],
                    'part2' => ['rows' => [], 'total_repaid' => 0.0, 'total_released_or_written_off' => 0.0, 'total' => 0.0, 'relief_due' => 0.0],
                    'part3' => ['rows' => [], 'total_repaid' => 0.0, 'total_released_or_written_off' => 0.0, 'total' => 0.0, 'relief_due' => 0.0],
                    'total_loans_outstanding' => 100.0,
                    'tax_payable' => 25.0,
                    'relief_due' => false,
                ];
                $result = ct600_return_model_test_service($filing)->build(49, 79, 6);

                $h->assertSame(true, (bool)$result['ok']);
                $h->assertSame(['CT600A'], $result['model']['attachments']['supplementary_pages']);
                $h->assertSame(25.0, (float)$result['model']['ct600a']['tax_payable']);
            }
        );
    }
);
