<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(_asset_relationshipsCard::class, static function (GeneratedServiceClassTestHarness $harness, _asset_relationshipsCard $card): void {
    $harness->check(_asset_relationshipsCard::class, 'declares relationship and lock services with selected parent context', static function () use ($harness, $card): void {
        $services = $card->services();
        $relationship = (array)($services[0] ?? []);
        $harness->assertSame('assetRelationshipData', $relationship['key'] ?? null);
        $harness->assertSame(\eel_accounts\Service\AssetService::class, $relationship['service'] ?? null);
        $harness->assertSame('fetchAssetRelationshipData', $relationship['method'] ?? null);
        $harness->assertSame(':asset_relationship_parent_id', ($relationship['params'] ?? [])['selectedParentAssetId'] ?? null);
        $harness->assertSame('periodLockState', ($services[1] ?? [])['key'] ?? null);
    });

    $harness->check(_asset_relationshipsCard::class, 'renders selected relationship candidates and only relationship rows', static function () use ($harness, $card): void {
        $html = $card->render(assetRelationshipsCardTestContext());
        $harness->assertTrue(str_contains($html, 'name="component_asset_ids[]" value="42" checked'));
        $harness->assertTrue(str_contains($html, 'name="component_asset_ids[]" value="43"'));
        $harness->assertTrue(str_contains($html, 'name="detached_available_for_use_dates[42]"'));
        $harness->assertTrue(str_contains($html, 'Generator — Operational parent'));
        $harness->assertTrue(str_contains($html, 'Carburettor — Fitted part (£ 16.14)'));
        $harness->assertFalse(str_contains($html, 'Unrelated standalone asset'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="save_asset_relationship"'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="select_asset_relationship_parent"'));
    });

    $harness->check(_asset_relationshipsCard::class, 'renders locked period relationships read only', static function () use ($harness, $card): void {
        $context = assetRelationshipsCardTestContext();
        $context['services']['periodLockState'] = true;
        $html = $card->render($context);
        $harness->assertTrue(str_contains($html, 'Relationships can be reviewed but not changed'));
        $harness->assertFalse(str_contains($html, 'name="intent" value="save_asset_relationship"'));
        $harness->assertTrue(str_contains($html, 'Read only'));
    });

    $harness->check(_asset_relationshipsCard::class, 'recovers the legacy encoded Citroën asset label without blanking its option', static function () use ($harness, $card): void {
        $context = assetRelationshipsCardTestContext();
        $context['services']['assetRelationshipData']['parent_candidates'][] = [
            'id' => 11,
            'asset_code' => 'FA-49-20260707140545-01',
            'description' => 'Citro' . chr(0xEB) . 'n Dispatch van, registration PK59 ZPJ',
        ];

        $html = $card->render($context);
        $expected = '<option value="11">FA-49-20260707140545-01 — Citroën Dispatch van, registration PK59 ZPJ</option>';
        $harness->assertSame(1, substr_count($html, $expected));
        $harness->assertFalse(str_contains($html, '<option value="11"></option>'));
    });

    $harness->check(_asset_relationshipsCard::class, 'preserves an already valid UTF-8 Citroën asset label', static function () use ($harness, $card): void {
        $context = assetRelationshipsCardTestContext();
        $context['services']['assetRelationshipData']['parent_candidates'][] = [
            'id' => 11,
            'asset_code' => 'FA-49-20260707140545-01',
            'description' => 'Citroën Dispatch van, registration PK59 ZPJ',
        ];

        $html = $card->render($context);
        $expected = '<option value="11">FA-49-20260707140545-01 — Citroën Dispatch van, registration PK59 ZPJ</option>';
        $harness->assertSame(1, substr_count($html, $expected));
        $harness->assertFalse(str_contains($html, '<option value="11"></option>'));
    });
});

function assetRelationshipsCardTestContext(): array
{
    return [
        'company' => ['id' => 7, 'accounting_period_id' => 22, 'settings' => ['default_currency_symbol' => '&#163;']],
        'asset_relationship_parent_id' => 41,
        'services' => [
            'periodLockState' => false,
            'assetRelationshipData' => [
                'schema_ready' => true,
                'selected_parent' => ['id' => 41, 'asset_code' => 'Generator', 'description' => 'Operational parent', 'available_for_use_date' => '2024-02-05', 'available_for_use_evidence' => 'Installed'],
                'parent_candidates' => [['id' => 41, 'asset_code' => 'Generator', 'description' => 'Operational parent']],
                'component_candidates' => [
                    ['id' => 42, 'asset_code' => 'Carburettor', 'description' => 'Fitted part', 'purchase_date' => '2024-02-05', 'cost' => 16.14, 'available_for_use_date' => '', 'linked_to_selected_parent' => true],
                    ['id' => 43, 'asset_code' => 'Kit', 'description' => 'Eligible source cost', 'purchase_date' => '2024-02-04', 'cost' => 9.99, 'linked_to_selected_parent' => false],
                ],
                'relationships' => [[
                    'id' => 41, 'asset_code' => 'Generator', 'description' => 'Operational parent', 'available_for_use_date' => '2024-02-05', 'accounting_cost' => 68.14,
                    'components' => [['asset_code' => 'Carburettor', 'description' => 'Fitted part', 'cost' => 16.14]],
                ]],
            ],
        ],
    ];
}
