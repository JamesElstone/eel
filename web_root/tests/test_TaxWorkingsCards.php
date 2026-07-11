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

$cardClasses = [
    _tax_corporation_tax_summaryCard::class,
    _tax_taxable_profit_bridgeCard::class,
    _tax_disallowable_add_backsCard::class,
    _tax_depreciation_add_backCard::class,
    _tax_capital_allowances_summaryCard::class,
    _tax_aia_allocationCard::class,
    _tax_main_rate_poolCard::class,
    _tax_special_rate_poolCard::class,
    _tax_car_co2_treatmentCard::class,
    _tax_disposals_balancingCard::class,
    _tax_lossesCard::class,
    _tax_rate_bandsCard::class,
    _tax_warningsCard::class,
];

foreach ($cardClasses as $className) {
    $harness->run($className, static function (GeneratedServiceClassTestHarness $harness, CardInterfaceFramework $card) use ($className): void {
        $harness->check($className, 'declares shared tax workings service', static function () use ($harness, $card): void {
            $services = $card->services();
            $service = (array)($services[0] ?? []);
            $params = (array)($service['params'] ?? []);

            $harness->assertSame('taxWorkings', $service['key'] ?? null);
            $harness->assertSame(\eel_accounts\Service\TaxWorkingsService::class, $service['service'] ?? null);
            $harness->assertSame('fetchWorkings', $service['method'] ?? null);
            $harness->assertSame(':company.id', $params['companyId'] ?? null);
            $harness->assertSame(':company.accounting_period_id', $params['accountingPeriodId'] ?? null);
            $harness->assertSame(':tax.selected_ct_period_id', $params['ctPeriodId'] ?? null);
        });

        $harness->check($className, 'renders read-only tax content with HMRC guidance link', static function () use ($harness, $card): void {
            $html = $card->render(taxWorkingsCardsContext());

            $harness->assertTrue(str_contains($html, 'HMRC guidance'));
            $harness->assertTrue(str_contains($html, 'target="_blank"'));
            $harness->assertTrue(str_contains($html, 'rel="noopener noreferrer"'));
            $harness->assertSame(false, str_contains($html, 'name="card_action"'));
            $harness->assertSame(false, str_contains($html, '<form'));
        });

        $harness->check($className, 'renders unavailable state', static function () use ($harness, $card): void {
            $html = $card->render([
                'services' => [
                    'taxWorkings' => [
                        'available' => false,
                        'errors' => ['Select a company and accounting period to inspect tax workings.'],
                    ],
                ],
            ]);

            $harness->assertTrue(str_contains($html, 'Select a company and accounting period'));
        });

        if ($className === _tax_capital_allowances_summaryCard::class) {
            $harness->check($className, 'renders capital allowances calculation workings', static function () use ($harness, $card): void {
                $context = taxWorkingsCardsContext();
                $context['services']['taxWorkings']['capital_allowances_summary']['aia_claimed'] = 500;
                $context['services']['taxWorkings']['capital_allowances_summary']['fya_claimed'] = 0;
                $context['services']['taxWorkings']['capital_allowances_summary']['wda_claimed'] = 56.90;
                $context['services']['taxWorkings']['capital_allowances_summary']['balancing_charge'] = 0;
                $context['services']['taxWorkings']['capital_allowances_summary']['balancing_allowance'] = 0;
                $context['services']['taxWorkings']['capital_allowances_summary']['net_capital_allowances'] = 556.90;
                $context['services']['taxWorkings']['aia_allocation'] = [
                    ['purchase_date' => '2026-04-20', 'asset_code' => 'FA-1', 'description' => 'Tooling', 'addition_amount' => 500, 'allowance_amount' => 500],
                ];

                $html = $card->render($context);

                $harness->assertTrue(str_contains($html, 'Calculation'));
                $harness->assertTrue(str_contains($html, 'FA-1 Tooling bought 2026-04-20 from addition $ 500.00'));
                $harness->assertTrue(str_contains($html, 'capital_allowances_summary.wda_claimed'));
                $harness->assertTrue(str_contains($html, '$ 556.90'));
            });
        }

        if ($className === _tax_corporation_tax_summaryCard::class) {
            $harness->check($className, 'keeps CT provision posting as a year-end close task', static function () use ($harness, $card): void {
                $context = taxWorkingsCardsContext();
                $context['tax'] = ['selected_ct_period_id' => 56];
                $context['services']['taxWorkings']['provision'] = [
                    'available' => true,
                    'estimated_corporation_tax' => 2280,
                    'posted_corporation_tax_charge' => 0,
                    'unposted_corporation_tax_adjustment' => 2280,
                    'status' => 'not_posted',
                ];

                $html = $card->render($context);

                $harness->assertSame(true, str_contains($html, 'Unposted P&amp;L impact'));
                $harness->assertSame(false, str_contains($html, 'Post CT provision'));
                $harness->assertSame(false, str_contains($html, 'post_ct_provision'));
                $harness->assertSame(false, str_contains($html, '<form'));
            });
        }
    });
}

function taxWorkingsCardsContext(): array
{
    return [
        'company' => [
            'id' => 12,
            'accounting_period_id' => 34,
            'settings' => [
                'default_currency_symbol' => '&#36;',
            ],
        ],
        'services' => [
            'taxWorkings' => [
                'available' => true,
                'summary' => [
                    'calculation_status' => 'estimate',
                    'confidence_status' => 'review_required',
                    'confidence_label' => 'Review required',
                    'taxable_profit' => 12000,
                    'taxable_loss' => 0,
                    'estimated_corporation_tax' => 2280,
                    'estimated_rate' => 0.19,
                    'associated_company_count' => 0,
                ],
                'bridge' => [
                    ['label' => 'Accounting profit or loss', 'amount' => 10000],
                    ['label' => 'Add back depreciation', 'amount' => 1500],
                    ['label' => 'Estimated corporation tax', 'amount' => 2280],
                ],
                'disallowable_add_backs' => [
                    ['nominal_code' => '7400', 'nominal_name' => 'Entertaining', 'tax_treatment' => 'disallowable', 'amount' => 500],
                ],
                'depreciation_add_back' => [
                    ['asset_code' => 'FA-1', 'description' => 'Laptop', 'direction' => 'add', 'amount' => 1500],
                ],
                'capital_allowances_summary' => [
                    'aia_claimed' => 1000,
                    'fya_claimed' => 5000,
                    'wda_claimed' => 600,
                    'balancing_charge' => 100,
                    'balancing_allowance' => 0,
                    'net_capital_allowances' => 6500,
                ],
                'aia_allocation' => [
                    ['purchase_date' => '2026-04-20', 'asset_code' => 'VAN-1', 'description' => 'Van', 'addition_amount' => 1000, 'allowance_amount' => 1000],
                ],
                'main_rate_pool' => [
                    'opening_wdv' => 0,
                    'additions' => 1000,
                    'aia_claimed' => 1000,
                    'fya_claimed' => 5000,
                    'disposal_value' => 0,
                    'wda_claimed' => 0,
                    'balancing_charge' => 0,
                    'closing_wdv' => 0,
                ],
                'special_rate_pool' => [
                    'opening_wdv' => 10000,
                    'additions' => 0,
                    'disposal_value' => 0,
                    'wda_claimed' => 600,
                    'balancing_charge' => 0,
                    'closing_wdv' => 9400,
                ],
                'car_co2_treatment' => [
                    ['asset_code' => 'CAR-1', 'description' => 'Car', 'registration_mark' => 'AB12 CDE', 'co2_emissions_g_km' => 120, 'acquisition_condition' => 'second_hand', 'is_zero_emission' => 0, 'pool_type' => 'special_rate_pool', 'warnings' => []],
                ],
                'disposals_balancing' => [
                    ['asset_code' => 'FA-OLD', 'description' => 'Old asset', 'pool_type' => 'main_pool', 'disposal_date' => '2026-07-01', 'disposal_value' => 250, 'allowance_type' => 'disposal_value'],
                ],
                'losses' => [
                    ['label' => 'FY 2026', 'loss_brought_forward' => 1000, 'loss_created' => 0, 'loss_utilised' => 750, 'loss_carried_forward' => 250],
                ],
                'rate_bands' => [
                    ['financial_year' => 'FY2026', 'taxable_profit' => 12000, 'main_rate' => 0.25, 'small_profits_rate' => 0.19, 'marginal_relief' => 0, 'liability' => 2280, 'basis' => 'small_profits_rate'],
                ],
                'warnings' => [
                    ['message' => 'Car asset CAR-1 is missing CO2 emissions.', 'workflow_label' => 'Open Vehicles', 'workflow_url' => '?page=vehicles'],
                ],
            ],
        ],
    ];
}
