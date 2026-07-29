<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

use eel_accounts\Support\Utf8;
use eel_accounts\Support\Utf8Table;

$harness = new GeneratedServiceClassTestHarness();
$harness->run(Utf8::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(Utf8::class, 'preserves valid UTF-8 byte for byte', static function () use ($harness): void {
        foreach (['Citroën', 'Crème brûlée — £12.34', '株式会社', 'Approved 😀'] as $value) {
            $harness->assertSame($value, Utf8::normalize($value));
        }
    });

    $harness->check(Utf8::class, 'recovers Windows-1252 text before HTML and XML escaping', static function () use ($harness): void {
        $legacy = 'Citro' . chr(0xEB) . 'n & Sons <Limited>';
        $harness->assertSame('Citroën & Sons <Limited>', Utf8::normalize($legacy));
        $harness->assertSame('Citroën &amp; Sons &lt;Limited&gt;', Utf8::html($legacy));
        $harness->assertSame('Citroën &amp; Sons &lt;Limited&gt;', Utf8::xml($legacy));

        $malformedUtf8 = "Price \x96 \xFF";
        $harness->assertSame('Price – ÿ', Utf8::normalize($malformedUtf8));
        $harness->assertSame(true, mb_check_encoding(Utf8::normalize($malformedUtf8), 'UTF-8'));
    });

    $harness->check(Utf8::class, 'normalizes nested values and rejects key collisions', static function () use ($harness): void {
        $legacyKey = 'Citro' . chr(0xEB) . 'n';
        $normalized = Utf8::normalizeValue([$legacyKey => ['name' => $legacyKey]]);
        $harness->assertSame(['Citroën' => ['name' => 'Citroën']], $normalized);

        $harness->assertThrows(
            static fn(): mixed => Utf8::normalizeValue(['Citroën' => 1, $legacyKey => 2]),
            \InvalidArgumentException::class
        );
    });

    $harness->check(Utf8::class, 'rejects XML controls and produces valid JSON', static function () use ($harness): void {
        $harness->assertThrows(
            static fn(): string => Utf8::xml("Invalid\x01XML"),
            \InvalidArgumentException::class
        );

        $legacy = 'Citro' . chr(0xEB) . 'n';
        $json = Utf8::json(['asset' => $legacy], JSON_UNESCAPED_UNICODE);
        $harness->assertSame(['asset' => 'Citroën'], json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    });

    $harness->check(Utf8Table::class, 'normalizes table rows before eelKit renders or exports them', static function () use ($harness): void {
        $table = Utf8Table::make('utf8_table_test', [[
            'description' => 'Citro' . chr(0xEB) . 'n',
        ]]);
        $property = (new \ReflectionClass($table))->getProperty('visibleRows');
        $harness->assertSame([['description' => 'Citroën']], $property->getValue($table));
    });
});
