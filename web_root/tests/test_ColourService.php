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

$harness->run(
    \eel_accounts\Service\ColourService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\ColourService $service): void {
        $harness->check(\eel_accounts\Service\ColourService::class, 'returns no colours for empty requests', static function () use ($harness, $service): void {
            $harness->assertSame([], $service->generateColours(0));
            $harness->assertSame([], $service->generateColours(-4));
        });

        $harness->check(\eel_accounts\Service\ColourService::class, 'returns a contrasting palette for small charts', static function () use ($harness, $service): void {
            $harness->assertSame(
                ['#311142', '#825746', '#BAD74A', '#018240', '#A64AD7'],
                $service->generateColours(5)
            );
        });

        $harness->check(\eel_accounts\Service\ColourService::class, 'uses the full project seed palette', static function () use ($harness, $service): void {
            $colours = $service->generateColours(17);

            foreach ([
                '#828146',
                '#825746',
                '#614682',
                '#468278',
                '#D7D7BC',
                '#550182',
                '#017C82',
                '#698207',
                '#823401',
                '#A64AD7',
                '#018240',
                '#825601',
                '#82FFBF',
                '#688201',
                '#311142',
                '#BAD74A',
                '#D382FF',
            ] as $colour) {
                $harness->assertTrue(in_array($colour, $colours, true));
            }
        });

        $harness->check(\eel_accounts\Service\ColourService::class, 'generates a deterministic expanded palette', static function () use ($harness, $service): void {
            $first = $service->generateColours(27);
            $second = $service->generateColours(27);

            $harness->assertSame($first, $second);
            $harness->assertSame(27, count($first));

            foreach ($first as $index => $colour) {
                $harness->assertTrue((bool)preg_match('/^#[0-9A-F]{6}$/', $colour));

                if ($index > 0) {
                    $harness->assertTrue($colour !== $first[$index - 1]);
                    $harness->assertTrue(colourServiceTestDistance($colour, $first[$index - 1]) >= 80.0);
                }
            }

            $harness->assertTrue(colourServiceTestDistance($first[0], $first[26]) >= 80.0);
        });
    }
);

function colourServiceTestDistance(string $left, string $right): float
{
    $leftRgb = sscanf($left, '#%02x%02x%02x');
    $rightRgb = sscanf($right, '#%02x%02x%02x');

    return sqrt(
        (($leftRgb[0] - $rightRgb[0]) ** 2)
        + (($leftRgb[1] - $rightRgb[1]) ** 2)
        + (($leftRgb[2] - $rightRgb[2]) ** 2)
    );
}
