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
        $harness->assertTrue(str_contains($html, '<th>Surname</th><th>First and Middle Names</th>'));
        $harness->assertTrue(str_contains($html, 'name="surname"'));
        $harness->assertTrue(str_contains($html, 'name="first_middle_names"'));
        $harness->assertFalse(str_contains($html, 'name="legal_name"'));
    });

    $harness->check(_incorporation_ownership_partiesCard::class, 'constructs manual legal names on the server', static function () use ($harness): void {
        $action = new IncorporationAction();
        $method = new ReflectionMethod($action, 'manualLegalName');
        $method->setAccessible(true);

        $harness->assertSame('ELSTONE, James William', $method->invoke($action, ' elstone ', 'james william'));
        $harness->assertSame('SMITH-JONES, Alice Mary', $method->invoke($action, 'smith-jones', 'alice mary'));
        $harness->assertSame('', $method->invoke($action, '', 'Alice Mary'));
        $harness->assertSame('', $method->invoke($action, 'Smith', ''));
    });
});
