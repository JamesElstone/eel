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

        $harness->check(\eel_accounts\Service\ColourService::class, 'returns the base palette in order', static function () use ($harness, $service): void {
            $harness->assertSame(
                ['#828146', '#825746', '#614682', '#468278', '#D7D7BC'],
                $service->generateColours(5)
            );
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
                }
            }
        });
    }
);
