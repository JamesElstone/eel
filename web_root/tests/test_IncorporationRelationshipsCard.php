<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(_incorporation_relationshipsCard::class, static function (
    GeneratedServiceClassTestHarness $harness,
    _incorporation_relationshipsCard $card
): void {
    $harness->check(_incorporation_relationshipsCard::class, 'keeps shareholder status derived from share allocations', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => ['id' => 7],
            'services' => ['ownership' => [
                'available' => true,
                'parties' => [[
                    'id' => 12,
                    'legal_name' => 'Example Owner',
                    'roles' => [[
                        'id' => 3,
                        'role_type' => 'participator',
                        'effective_from' => '2026-01-01',
                        'effective_to' => null,
                    ]],
                    'effective_holdings' => [[
                        'id' => 8,
                        'effective_from' => '2026-01-01',
                    ]],
                ]],
            ]],
        ]);

        $harness->assertSame(
            'Record effective Participator and Associate relationships. Shareholder status is derived from effective share allocations.',
            $card->helper([])
        );
        $harness->assertTrue(str_contains($html, '<option value="participator">Participator</option>'));
        $harness->assertTrue(str_contains($html, '<option value="associate">Associate</option>'));
        $harness->assertFalse(str_contains($html, '<option value="shareholder">Shareholder</option>'));
        $harness->assertTrue(str_contains($html, 'Shareholder (calculated)'));
        $harness->assertTrue(str_contains($html, '<th>Manage</th>'));
        $harness->assertTrue(str_contains($html, 'aria-label="Last effective date"'));
        $harness->assertTrue(str_contains($html, '>End role</button>'));
        $harness->assertFalse(str_contains($html, 'End an ownership role'));
    });
});
