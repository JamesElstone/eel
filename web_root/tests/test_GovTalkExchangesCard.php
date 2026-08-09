<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    _govtalk_exchangesCard::class,
    static function (GeneratedServiceClassTestHarness $harness, _govtalk_exchangesCard $card): void {
        $harness->check(_govtalk_exchangesCard::class, 'defines the XML exchange history card', static function () use ($harness, $card): void {
            $harness->assertSame('govtalk_exchanges', $card->key());
            $harness->assertSame('XML Exchange History', $card->title());
            $harness->assertSame(
                'XML Exchange History shows all GovTalk exchanges for the selected company. Use the filters to narrow the results. Outbound XML may contain authentication details, so downloads are private, integrity-checked and never cached.',
                $card->helper([])
            );
            $harness->assertSame('exchangeHistory', (string)$card->services()[0]['method']);
            $harness->assertTrue(in_array(
                'govtalk.exchanges.selection',
                $card->invalidationFacts(),
                true
            ));
            $source = (string)file_get_contents(
                dirname(__DIR__) . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'cards'
                . DIRECTORY_SEPARATOR . 'govtalk_transmission_history.php'
            );
            $harness->assertTrue(str_contains(
                $source,
                '<form method="post" action="?page=transmit" data-ajax="true">'
            ));
            $harness->assertTrue(str_contains($source, '>Clear Filters</button></form></div>'));
        });
    }
);
