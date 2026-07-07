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
$harness->run(_asset_taxCard::class, static function (GeneratedServiceClassTestHarness $harness, _asset_taxCard $card): void {
    $harness->check(_asset_taxCard::class, 'uses page accounting period context for asset service', static function () use ($harness, $card): void {
        $services = $card->services();
        $assetPageDataService = (array)($services[0] ?? []);
        $params = (array)($assetPageDataService['params'] ?? []);

        $harness->assertSame('assetPageData', $assetPageDataService['key'] ?? null);
        $harness->assertSame(\eel_accounts\Service\AssetService::class, $assetPageDataService['service'] ?? null);
        $harness->assertSame('fetchTaxData', $assetPageDataService['method'] ?? null);
        $harness->assertSame(':company.id', $params['companyId'] ?? null);
        $harness->assertSame(':company.accounting_period_id', $params['accountingPeriodId'] ?? null);
        $harness->assertSame(false, array_key_exists('defaultBankNominalId', $params));
    });

    $harness->check(_asset_taxCard::class, 'renders tax view without duplicate accounting period controls', static function () use ($harness, $card): void {
        $html = $card->render([
            'accounting_period_id' => 22,
            'page' => [
                'page_id' => 'assets',
                'accounting_period_id' => 22,
            ],
            'company' => [
                'id' => 7,
                'accounting_period_id' => 99,
                'settings' => [
                    'default_currency_symbol' => '&#36;',
                ],
            ],
            'services' => [
                'assetPageData' => [
                    'accounting_period_id' => 22,
                    'tax_view' => [
                        'accounting_profit' => 10000,
                        'disallowable_add_backs' => 125,
                        'depreciation_add_back' => 1500.5,
                        'capital_allowances' => 2500.25,
                        'taxable_before_losses' => 9125.25,
                        'losses_brought_forward' => 1000,
                        'losses_used' => 750.75,
                        'losses_carried_forward' => 249.25,
                        'taxable_profit' => 8374.5,
                    ],
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'Accounting Profit'));
        $harness->assertTrue(str_contains($html, '+ Disallowable Expenses'));
        $harness->assertTrue(str_contains($html, '+ Depreciation'));
        $harness->assertTrue(str_contains($html, '- Capital Allowances'));
        $harness->assertTrue(str_contains($html, '= Taxable Profit Before Losses'));
        $harness->assertTrue(str_contains($html, 'Losses B/F'));
        $harness->assertTrue(str_contains($html, 'Losses Used'));
        $harness->assertTrue(str_contains($html, 'Losses C/F'));
        $harness->assertTrue(str_contains($html, 'Taxable Profit'));
        $harness->assertTrue(str_contains($html, '$ 10,000.00'));
        $harness->assertTrue(str_contains($html, '$ 125.00'));
        $harness->assertTrue(str_contains($html, '$ 1,500.50'));
        $harness->assertTrue(str_contains($html, '$ 2,500.25'));
        $harness->assertTrue(str_contains($html, '$ 9,125.25'));
        $harness->assertTrue(str_contains($html, '$ 1,000.00'));
        $harness->assertTrue(str_contains($html, '$ 750.75'));
        $harness->assertTrue(str_contains($html, '$ 249.25'));
        $harness->assertTrue(str_contains($html, '$ 8,374.50'));
        $harness->assertTrue(!str_contains($html, '<select'));
        $harness->assertTrue(!str_contains($html, 'data-accounting-period-selector'));
        $harness->assertTrue(!str_contains($html, 'name="action" value="set-page-context"'));
        $harness->assertTrue(!str_contains($html, 'name="accounting_period_id"'));
    });

    $harness->check(_asset_taxCard::class, 'renders tax view columns for each CT period and total', static function () use ($harness, $card): void {
        $html = $card->render([
            'page' => [
                'page_id' => 'assets',
                'accounting_period_id' => 79,
            ],
            'company' => [
                'id' => 7,
                'accounting_period_id' => 79,
                'settings' => [
                    'default_currency_symbol' => '&#36;',
                ],
            ],
            'services' => [
                'assetPageData' => [
                    'accounting_period_id' => 79,
                    'tax_view' => [
                        'available' => true,
                        'periods' => [
                            [
                                'period_label' => '05/09/2022 to 04/09/2023',
                                'accounting_profit' => 9000,
                                'disallowable_add_backs' => 100,
                                'depreciation_add_back' => 1500,
                                'capital_allowances' => 2500,
                                'taxable_before_losses' => 8100,
                                'losses_brought_forward' => 1000,
                                'losses_used' => 750,
                                'losses_carried_forward' => 250,
                                'taxable_profit' => 7350,
                            ],
                            [
                                'period_label' => '05/09/2023 to 30/09/2023',
                                'accounting_profit' => 1000,
                                'disallowable_add_backs' => 25,
                                'depreciation_add_back' => 0.5,
                                'capital_allowances' => 0.25,
                                'taxable_before_losses' => 1025.25,
                                'losses_brought_forward' => 250,
                                'losses_used' => 0.75,
                                'losses_carried_forward' => 249.25,
                                'taxable_profit' => 1024.5,
                            ],
                        ],
                        'totals' => [
                            'accounting_profit' => 10000,
                            'disallowable_add_backs' => 125,
                            'depreciation_add_back' => 1500.5,
                            'capital_allowances' => 2500.25,
                            'taxable_before_losses' => 9125.25,
                            'losses_brought_forward' => 1250,
                            'losses_used' => 750.75,
                            'losses_carried_forward' => 499.25,
                            'taxable_profit' => 8374.5,
                        ],
                    ],
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, '05/09/2022 to 04/09/2023'));
        $harness->assertTrue(str_contains($html, '05/09/2023 to 30/09/2023'));
        $harness->assertTrue(str_contains($html, '<th>Total</th>'));
        $harness->assertTrue(str_contains($html, '$ 8,374.50'));
        $harness->assertTrue(!str_contains($html, '<select'));
    });

    $harness->check(_asset_taxCard::class, 'renders unavailable tax view empty state without duplicate accounting period controls', static function () use ($harness, $card): void {
        $html = $card->render([
            'accounting_period_id' => 22,
            'company' => [
                'id' => 7,
                'accounting_period_id' => 99,
            ],
            'services' => [
                'assetPageData' => [
                    'accounting_period_id' => 22,
                    'tax_view' => null,
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'No tax view is available for the selected accounting period.'));
        $harness->assertTrue(!str_contains($html, '<select'));
        $harness->assertTrue(!str_contains($html, 'data-accounting-period-selector'));
        $harness->assertTrue(!str_contains($html, 'name="action" value="set-page-context"'));
        $harness->assertTrue(!str_contains($html, 'name="accounting_period_id"'));
    });

    $harness->check(_asset_taxCard::class, 'renders no accounting period empty state', static function () use ($harness, $card): void {
        $html = $card->render([
            'services' => [
                'assetPageData' => [
                    'accounting_period_id' => 0,
                    'tax_view' => null,
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'Select an accounting period to view tax adjustments.'));
    });

    $harness->check(_asset_taxCard::class, 'renders schema migration empty state', static function () use ($harness, $card): void {
        $html = $card->render([
            'services' => [
                'assetPageData' => [
                    'accounting_period_id' => 22,
                    'tax_view' => null,
                    'schema_ready' => false,
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'Run the fixed asset migrations before viewing tax adjustments.'));
    });
});
