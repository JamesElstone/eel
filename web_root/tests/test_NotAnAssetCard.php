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
$harness->run(_not_an_assetCard::class, static function (GeneratedServiceClassTestHarness $harness, _not_an_assetCard $card): void {
    $harness->check(_not_an_assetCard::class, 'declares non-asset candidate service with company settings context', static function () use ($harness, $card): void {
        $services = $card->services();
        $definition = (array)($services[0] ?? []);
        $params = (array)($definition['params'] ?? []);

        $harness->assertSame('nonAssetCandidates', $definition['key'] ?? null);
        $harness->assertSame(\eel_accounts\Service\AssetService::class, $definition['service'] ?? null);
        $harness->assertSame('fetchNonAssetCandidates', $definition['method'] ?? null);
        $harness->assertSame(':company.id', $params['companyId'] ?? null);
        $harness->assertSame(':company.accounting_period_id', $params['accountingPeriodId'] ?? null);
        $harness->assertSame(':company.settings.tools_small_equipment_nominal_id', $params['toolsSmallEquipmentNominalId'] ?? null);
        $harness->assertSame(':company.settings.potential_asset_threshold', $params['threshold'] ?? null);
    });

    $harness->check(_not_an_assetCard::class, 'renders threshold select and candidate rows', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'id' => 7,
                'accounting_period_id' => 22,
                'settings' => [
                    'tools_small_equipment_nominal_id' => 18,
                    'potential_asset_threshold' => 250,
                ],
            ],
            'services' => [
                'nonAssetCandidates' => [
                    'available' => true,
                    'threshold' => 250,
                    'rows' => [[
                        'date' => '2026-07-02',
                        'source' => 'Transaction',
                        'description' => 'Cordless drill',
                        'reference' => 'INV-7',
                        'amount' => 312.50,
                    ]],
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'name="intent" value="save_potential_asset_threshold"'));
        $harness->assertTrue(str_contains($html, '<option value="250" selected>250</option>'));
        $harness->assertTrue(str_contains($html, 'Cordless drill'));
        $harness->assertTrue(str_contains($html, 'INV-7'));
        $harness->assertTrue(str_contains($html, '312.50'));
    });

    $harness->check(_not_an_assetCard::class, 'renders nominal setup helper when Tools and Small Equipment is unconfigured', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'id' => 7,
                'accounting_period_id' => 22,
                'settings' => [
                    'potential_asset_threshold' => 500,
                ],
            ],
            'services' => [
                'nonAssetCandidates' => [
                    'available' => false,
                    'threshold' => 500,
                    'rows' => [],
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, '<option value="500" selected>500</option>'));
        $harness->assertTrue(str_contains($html, 'Set the Tools &amp; Small Equipment nominal on Company Nominals'));
    });
});
