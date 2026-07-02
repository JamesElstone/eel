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
$harness->run(_asset_reconcile_manualCard::class, static function (GeneratedServiceClassTestHarness $harness, _asset_reconcile_manualCard $card): void {
    $harness->check(_asset_reconcile_manualCard::class, 'declares manual reconciliation data service', static function () use ($harness, $card): void {
        $services = $card->services();
        $service = (array)($services[0] ?? []);
        $params = (array)($service['params'] ?? []);

        $harness->assertSame('manualAssetReconciliation', $service['key'] ?? null);
        $harness->assertSame(\eel_accounts\Service\AssetService::class, $service['service'] ?? null);
        $harness->assertSame('fetchManualAssetReconciliationData', $service['method'] ?? null);
        $harness->assertSame(':company.id', $params['companyId'] ?? null);
    });

    $harness->check(_asset_reconcile_manualCard::class, 'renders manual assets and candidate reconcile action', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'id' => 7,
                'accounting_period_id' => 22,
                'settings' => [
                    'default_bank_nominal_id' => 42,
                ],
            ],
            'services' => [
                'manualAssetReconciliation' => [
                    'assets' => [[
                        'id' => 15,
                        'asset_code' => 'FA-7-001',
                        'description' => 'Cordless drill',
                        'purchase_date' => '2026-06-18',
                        'cost' => 240.00,
                        'manual_addition_reason_label' => 'Delayed bank CSV',
                        'manual_offset_nominal_label' => '2300 Supplier Clearing',
                        'candidates' => [[
                            'id' => 91,
                            'txn_date' => '2026-06-20',
                            'description' => 'Tool supplier payment',
                            'amount' => 240.00,
                            'nominal_label' => 'Unassigned',
                            'has_derived_journal' => 1,
                        ]],
                    ]],
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'Reconcile Manually Created Assets'));
        $harness->assertTrue(str_contains($html, 'FA-7-001'));
        $harness->assertTrue(str_contains($html, 'Cordless drill'));
        $harness->assertTrue(str_contains($html, 'Delayed bank CSV'));
        $harness->assertTrue(str_contains($html, '2300 Supplier Clearing'));
        $harness->assertTrue(str_contains($html, 'Tool supplier payment'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="reconcile_manual_asset_with_transaction"'));
        $harness->assertTrue(str_contains($html, 'name="asset_id" value="15"'));
        $harness->assertTrue(str_contains($html, 'name="transaction_id" value="91"'));
        $harness->assertTrue(str_contains($html, 'name="default_bank_nominal_id" value="42"'));
        $harness->assertTrue(str_contains($html, 'Link &amp; Reconcile'));
        $harness->assertTrue(str_contains($html, 'data-chicken-title="Confirm journal rebuild"'));
        $harness->assertTrue(str_contains($html, 'data-submit-field="confirm_rebuild_journal"'));
    });

    $harness->check(_asset_reconcile_manualCard::class, 'renders empty state', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => ['id' => 7],
            'services' => [
                'manualAssetReconciliation' => [
                    'assets' => [],
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'No manually created assets need reconciliation.'));
    });
});
