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
$harness->run(_asset_registerCard::class, static function (GeneratedServiceClassTestHarness $harness, _asset_registerCard $card): void {
    $harness->check(_asset_registerCard::class, 'declares asset service with selected company context', static function () use ($harness, $card): void {
        $services = $card->services();
        $assetPageDataService = (array)($services[0] ?? []);
        $params = (array)($assetPageDataService['params'] ?? []);

        $harness->assertSame('assetPageData', $assetPageDataService['key'] ?? null);
        $harness->assertSame(\eel_accounts\Service\AssetService::class, $assetPageDataService['service'] ?? null);
        $harness->assertSame('fetchRegisterData', $assetPageDataService['method'] ?? null);
        $harness->assertSame(':company.id', $params['companyId'] ?? null);
        $harness->assertSame(':company.accounting_period_id', $params['accountingPeriodId'] ?? null);
        $harness->assertSame(':company.settings.default_bank_nominal_id', $params['defaultBankNominalId'] ?? null);
        $harness->assertSame(':asset_disposal_search_date', $params['disposalSearchDate'] ?? null);
        $harness->assertSame(':asset_disposal_search_asset_id', $params['disposalSearchAssetId'] ?? null);

        $periodLockService = (array)($services[1] ?? []);
        $lockParams = (array)($periodLockService['params'] ?? []);

        $harness->assertSame('periodLockState', $periodLockService['key'] ?? null);
        $harness->assertSame(\eel_accounts\Service\YearEndLockService::class, $periodLockService['service'] ?? null);
        $harness->assertSame('isLocked', $periodLockService['method'] ?? null);
        $harness->assertSame(':company.id', $lockParams['companyId'] ?? null);
        $harness->assertSame(':company.accounting_period_id', $lockParams['accountingPeriodId'] ?? null);
    });

    $harness->check(_asset_registerCard::class, 'renders visible asset service errors', static function () use ($harness, $card): void {
        $message = $card->handleError('assetPageData', ['message' => 'Page context value not found: company.id'], []);

        $harness->assertTrue(str_contains($message, 'Asset data could not be loaded'));
        $harness->assertTrue(str_contains($message, 'company.id'));
    });

    $harness->check(_asset_registerCard::class, 'renders disposal controls on one compact row', static function () use ($harness, $card): void {
        $html = $card->render([
            'page' => [
                'page_id' => 'assets',
                'page_cards' => ['asset_register'],
            ],
            'accounting_period' => [
                'period_end' => '2020-01-10',
            ],
            'company' => [
                'id' => 7,
                'accounting_period_id' => 22,
                'settings' => [
                    'default_currency_symbol' => '&#36;',
                ],
            ],
            'services' => [
                'assetPageData' => [
                    'assets' => [[
                        'id' => 44,
                        'asset_code' => 'FA-7-1',
                        'description' => 'Test asset',
                        'purchase_date' => '2020-01-01',
                        'period_depreciation' => 12.34,
                        'resale_value' => 87.66,
                        'residual_value' => 10.00,
                        'useful_life_years' => 3,
                        'cost' => 100,
                        'status' => 'active',
                    ]],
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'class="asset-disposal-form"'));
        $harness->assertTrue(str_contains($html, 'class="asset-disposal-controls"'));
        $harness->assertTrue(str_contains($html, 'class="table-scroll asset-register-table"'));
        $harness->assertTrue(str_contains($html, 'AIA eligibility check'));
        $purchaseDateHeaderPosition = strpos($html, '<th class="asset-register-wrap-heading asset-register-purchase-date-heading">Purchase Date</th>');
        $ageHeaderPosition = strpos($html, '<th class="asset-register-wrap-heading asset-register-age-heading">Age (days)</th>');
        $usefulLifeHeaderPosition = strpos($html, '<th class="asset-register-wrap-heading asset-register-useful-life-heading">Useful Life (years)</th>');
        $codeHeaderPosition = strpos($html, '<th>Code</th>');
        $descriptionHeaderPosition = strpos($html, '<th>Description</th>');
        $costHeaderPosition = strpos($html, '<th>Cost</th>');
        $periodDepreciationHeaderPosition = strpos($html, '<th class="asset-register-wrap-heading asset-register-period-depreciation-heading">Depreciation in Period</th>');
        $resaleValueHeaderPosition = strpos($html, '<th>Resale Value</th>');
        $residualValueHeaderPosition = strpos($html, '<th>EOL Value</th>');
        $statusHeaderPosition = strpos($html, '<th>Status</th>');
        $harness->assertSame(false, str_contains($html, '&lt;br&gt;'));
        $harness->assertTrue(str_contains((string)file_get_contents(APP_CSS . 'project.css'), '.asset-register-wrap-heading'));
        $harness->assertTrue($purchaseDateHeaderPosition !== false);
        $harness->assertTrue($ageHeaderPosition !== false);
        $harness->assertTrue($usefulLifeHeaderPosition !== false);
        $harness->assertTrue($codeHeaderPosition !== false);
        $harness->assertTrue($descriptionHeaderPosition !== false);
        $harness->assertTrue($costHeaderPosition !== false);
        $harness->assertTrue($periodDepreciationHeaderPosition !== false);
        $harness->assertTrue($resaleValueHeaderPosition !== false);
        $harness->assertTrue($residualValueHeaderPosition !== false);
        $harness->assertTrue($statusHeaderPosition !== false);
        $harness->assertTrue($purchaseDateHeaderPosition < $ageHeaderPosition);
        $harness->assertTrue($ageHeaderPosition < $usefulLifeHeaderPosition);
        $harness->assertTrue($usefulLifeHeaderPosition < $codeHeaderPosition);
        $harness->assertTrue($codeHeaderPosition < $descriptionHeaderPosition);
        $harness->assertTrue($descriptionHeaderPosition < $costHeaderPosition);
        $harness->assertTrue($costHeaderPosition < $periodDepreciationHeaderPosition);
        $harness->assertTrue($periodDepreciationHeaderPosition < $resaleValueHeaderPosition);
        $harness->assertTrue($resaleValueHeaderPosition < $residualValueHeaderPosition);
        $harness->assertTrue($residualValueHeaderPosition < $statusHeaderPosition);
        $harness->assertTrue(str_contains($html, '<td>01/01/20</td><td class="numeric">10</td><td class="numeric">3</td><td>FA-7-1</td><td><div>Test asset</div></td><td class="numeric">$ 100.00</td><td class="numeric">$ 12.34</td><td class="numeric">$ 87.66</td><td class="numeric">$ 10.00</td>'));
        $harness->assertTrue(str_contains($html, 'FA-7-1'));
        $harness->assertTrue(str_contains($html, 'Test asset'));
        $harness->assertTrue(str_contains($html, '$ 100.00'));
        $harness->assertTrue(str_contains($html, 'name="_table_export_prepare" value="csv"'));
        $harness->assertTrue(str_contains($html, '<th class="asset-register-actions-heading">Disposal Method</th>'));
        $harness->assertTrue(str_contains($html, '<th class="asset-register-actions-heading">Asset Disposal</th>'));
        $harness->assertTrue(str_contains($html, 'class="asset-disposal-method-form" method="post" action="?page=assets" data-ajax="true"'));
        $harness->assertTrue(str_contains($html, 'name="card_action" value="Asset"'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="set_asset_disposal_method"'));
        $harness->assertTrue(str_contains($html, '>Sold Asset</button>'));
        $harness->assertTrue(str_contains($html, 'name="asset_disposal_method" value="at_nil_value"'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="search_asset_disposal_receipts"'));
        $harness->assertTrue(str_contains($html, 'Search Incoming Payments'));
        $harness->assertSame(false, str_contains($html, 'Search Incomming Payments'));
        $harness->assertSame(false, str_contains($html, 'name="disposal_event_type"'));
        $harness->assertSame(false, str_contains($html, 'Dispose of at Nil Value'));
        $harness->assertSame(false, str_contains($html, 'name="intent" value="run_asset_depreciation"'));
        $harness->assertSame(false, str_contains($html, 'Run Depreciation'));
    });

    $harness->check(_asset_registerCard::class, 'renders locked periods read only without disposal forms', static function () use ($harness, $card): void {
        $html = $card->render([
            'page' => [
                'page_id' => 'assets',
                'page_cards' => ['asset_register'],
            ],
            'company' => [
                'id' => 7,
                'accounting_period_id' => 22,
                'settings' => [
                    'default_currency_symbol' => '&#36;',
                ],
            ],
            'services' => [
                'assetPageData' => [
                    'assets' => [[
                        'id' => 44,
                        'asset_code' => 'FA-7-1',
                        'description' => 'Test asset',
                        'cost' => 100,
                        'nbv' => 80,
                        'status' => 'active',
                    ]],
                ],
                'periodLockState' => true,
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'FA-7-1'));
        $harness->assertTrue(str_contains($html, 'Period locked'));
        $harness->assertTrue(str_contains($html, 'Asset disposals are read only.'));
        $harness->assertSame(false, str_contains($html, 'class="asset-disposal-form"'));
        $harness->assertSame(false, str_contains($html, 'class="asset-disposal-method-form"'));
        $harness->assertSame(false, str_contains($html, 'name="intent" value="search_asset_disposal_receipts"'));
        $harness->assertSame(false, str_contains($html, 'name="intent" value="dispose_asset_nil"'));
    });

    $harness->check(_asset_registerCard::class, 'renders nil disposal controls when selected', static function () use ($harness, $card): void {
        $html = $card->render([
            'page' => [
                'page_id' => 'assets',
                'page_cards' => ['asset_register'],
            ],
            'company' => [
                'id' => 7,
                'accounting_period_id' => 22,
                'settings' => [
                    'default_currency_symbol' => '&#36;',
                ],
            ],
            'asset_disposal_method_asset_id' => 44,
            'asset_disposal_method' => 'at_nil_value',
            'services' => [
                'assetPageData' => [
                    'assets' => [[
                        'id' => 44,
                        'asset_code' => 'FA-7-1',
                        'description' => 'Test asset',
                        'cost' => 100,
                        'nbv' => 80,
                        'status' => 'active',
                    ]],
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, '>No Value</button>'));
        $harness->assertTrue(str_contains($html, 'name="asset_disposal_method" value="sell_asset"'));
        $harness->assertTrue(str_contains($html, 'type="date" name="disposal_date"'));
        $harness->assertTrue(str_contains((string)file_get_contents(APP_CSS . 'project.css'), '.asset-disposal-controls [name="disposal_date"]'));
        $harness->assertTrue(str_contains($html, 'name="disposal_event_type"'));
        $harness->assertTrue(str_contains($html, 'name="disposal_event_type" aria-label="Nil value disposal reason" data-no-submit-on-change="true"'));
        $harness->assertTrue(str_contains($html, 'value="scrapped_no_proceeds"'));
        $harness->assertTrue(str_contains($html, 'value="broken_beyond_economical_repair">Broken; Beyond economical repair</option>'));
        $harness->assertTrue(str_contains($html, 'value="stolen_no_compensation">Stolen</option>'));
        $harness->assertTrue(str_contains($html, 'name="disposal_reason"'));
        $harness->assertTrue(str_contains($html, 'maxlength="20"'));
        $harness->assertTrue(str_contains($html, 'size="20"'));
        $harness->assertTrue(str_contains($html, 'Dispose of at Nil Value'));
        $harness->assertSame(false, str_contains($html, 'Search Incoming Payments'));
    });

    $harness->check(_asset_registerCard::class, 'paginates the register table at ten rows', static function () use ($harness, $card): void {
        $assets = [];
        for ($index = 1; $index <= 11; $index++) {
            $assets[] = [
                'id' => $index,
                'asset_code' => 'FA-7-' . $index,
                'description' => 'Test asset ' . $index,
                'cost' => 100 + $index,
                'nbv' => 80 + $index,
                'status' => 'active',
            ];
        }

        $html = $card->render([
            'page' => [
                'page_id' => 'assets',
                'page_cards' => ['asset_register'],
            ],
            'company' => [
                'id' => 7,
                'accounting_period_id' => 22,
                'settings' => [
                    'default_currency_symbol' => '&#36;',
                ],
            ],
            'services' => [
                'assetPageData' => [
                    'assets' => $assets,
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'FA-7-10'));
        $harness->assertSame(false, str_contains($html, 'FA-7-11'));
        $harness->assertTrue(str_contains($html, 'Assets 1-10 of 11'));
    });

    $harness->check(_asset_registerCard::class, 'renders disposal receipt candidates for selected asset', static function () use ($harness, $card): void {
        $html = $card->render([
            'page' => [
                'page_id' => 'assets',
                'page_cards' => ['asset_register'],
            ],
            'company' => [
                'id' => 7,
                'accounting_period_id' => 22,
                'settings' => [
                    'default_currency_symbol' => '&#36;',
                ],
            ],
            'services' => [
                'assetPageData' => [
                    'default_bank_nominal_id' => 1,
                    'disposal_search' => [
                        'asset_id' => 44,
                        'search_date' => '2026-07-01',
                        'window_start' => '2026-06-30',
                        'window_end' => '2026-07-04',
                        'candidates' => [[
                            'id' => 9901,
                            'txn_date' => '2026-07-03',
                            'description' => 'Asset sale receipt',
                            'amount' => 150,
                        ]],
                    ],
                    'assets' => [[
                        'id' => 44,
                        'asset_code' => 'FA-7-1',
                        'description' => 'Test asset',
                        'cost' => 100,
                        'nbv' => 80,
                        'status' => 'active',
                    ]],
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'Receipts from'));
        $harness->assertTrue(str_contains($html, 'Asset sale receipt'));
        $harness->assertTrue(str_contains($html, '$ 150.00'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="dispose_asset_with_transaction"'));
        $harness->assertTrue(str_contains($html, 'Link &amp; Dispose'));
        $harness->assertSame(false, str_contains($html, 'Dispose of at Nil Value'));
    });
});
