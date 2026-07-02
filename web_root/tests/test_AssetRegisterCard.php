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
        $harness->assertSame('fetchPageData', $assetPageDataService['method'] ?? null);
        $harness->assertSame(':company.id', $params['companyId'] ?? null);
        $harness->assertSame(':company.accounting_period_id', $params['accountingPeriodId'] ?? null);
        $harness->assertSame(':company.settings.default_bank_nominal_id', $params['defaultBankNominalId'] ?? null);
        $harness->assertSame(':prefill_transaction_id', $params['prefillTransactionId'] ?? null);
        $harness->assertSame(':asset_disposal_search_date', $params['disposalSearchDate'] ?? null);
        $harness->assertSame(':asset_disposal_search_asset_id', $params['disposalSearchAssetId'] ?? null);
    });

    $harness->check(_asset_registerCard::class, 'renders visible asset service errors', static function () use ($harness, $card): void {
        $message = $card->handleError('assetPageData', ['message' => 'Page context value not found: company.id'], []);

        $harness->assertTrue(str_contains($message, 'Asset data could not be loaded'));
        $harness->assertTrue(str_contains($message, 'company.id'));
    });

    $harness->check(_asset_registerCard::class, 'renders disposal controls on one compact row', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'id' => 7,
                'accounting_period_id' => 22,
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
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'class="asset-disposal-form"'));
        $harness->assertTrue(str_contains($html, 'class="asset-disposal-controls"'));
        $harness->assertTrue(str_contains($html, 'FA-7-1'));
        $harness->assertTrue(str_contains($html, 'Test asset'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="search_asset_disposal_receipts"'));
        $harness->assertTrue(str_contains($html, 'Dispose of at Nil Value'));
    });

    $harness->check(_asset_registerCard::class, 'renders disposal receipt candidates for selected asset', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'id' => 7,
                'accounting_period_id' => 22,
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
        $harness->assertTrue(str_contains($html, 'name="intent" value="dispose_asset_with_transaction"'));
        $harness->assertTrue(str_contains($html, 'Link &amp; Dispose'));
    });
});
