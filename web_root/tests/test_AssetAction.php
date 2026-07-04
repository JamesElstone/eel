<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'PageServiceTestFactory.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(AssetAction::class, static function (GeneratedServiceClassTestHarness $harness, AssetAction $action): void {
    $harness->check(AssetAction::class, 'implements the action interface', static function () use ($harness, $action): void {
        $harness->assertSame(true, $action instanceof ActionInterfaceFramework);
    });

    $harness->check(AssetAction::class, 'search action preserves disposal search context', static function () use ($harness, $action): void {
        $request = new RequestFramework(
            [],
            [
                'card_action' => 'Asset',
                'intent' => 'search_asset_disposal_receipts',
                'company_id' => '49',
                'asset_id' => '12',
                'disposal_search_date' => '2026-07-01',
            ],
            ['REQUEST_METHOD' => 'POST'],
            [],
            [],
            null
        );

        $result = $action->handle($request, createTestPageServiceFramework());

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertSame(['asset.create', 'asset.reconcile_manual', 'asset.register', 'asset.tax', 'asset.not_an_asset', 'expense.claim.editor', 'expenses.state', 'transactions.imported', 'page.context', 'year.end.checklist'], $result->changedFacts());
        $harness->assertSame('2026-07-01', (string)($result->context()['asset_disposal_search_date'] ?? ''));
        $harness->assertSame(12, (int)($result->context()['asset_disposal_search_asset_id'] ?? 0));
    });
});
