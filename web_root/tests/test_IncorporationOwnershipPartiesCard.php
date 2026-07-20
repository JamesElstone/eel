<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(_incorporation_ownership_partiesCard::class, static function (
    GeneratedServiceClassTestHarness $harness,
    _incorporation_ownership_partiesCard $card
): void {
    $harness->check(_incorporation_ownership_partiesCard::class, 'derives shareholder status from recorded holdings', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => ['id' => 7],
            'services' => ['ownership' => [
                'available' => true,
                'parties' => [[
                    'id' => 12,
                    'legal_name' => 'Example Owner',
                    'party_type' => 'individual',
                    'roles' => [],
                    'holdings' => [[
                        'id' => 31,
                        'quantity' => 100,
                        'share_class' => 'Ordinary',
                        'effective_from' => '2026-01-01',
                        'effective_to' => null,
                    ]],
                    'effective_holdings' => [[
                        'id' => 31,
                    ]],
                ]],
                'directors' => [],
            ]],
        ]);

        $harness->assertTrue(str_contains($html, 'Shareholder (from recorded holdings)'));
    });
});
