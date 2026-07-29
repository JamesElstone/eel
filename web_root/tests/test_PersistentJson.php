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
$harness->run(\eel_accounts\Support\PersistentJson::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(\eel_accounts\Support\PersistentJson::class, 'keeps persisted Unicode JSON ASCII-safe and reversible', static function () use ($harness): void {
        $value = [
            'dash' => 'Current account – group company',
            'accented' => 'Crème brûlée',
            'cjk' => '株式会社',
            'supplementary' => 'Approved 😀',
        ];
        $json = \eel_accounts\Support\PersistentJson::encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );

        $harness->assertSame(true, mb_check_encoding($json, 'ASCII'));
        $harness->assertSame(false, str_contains($json, '–'));
        $harness->assertSame(true, str_contains($json, '\\u2013'));
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $reencoded = \eel_accounts\Support\PersistentJson::encode(
            $decoded,
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        $harness->assertSame($value, $decoded);
        $harness->assertSame(hash('sha256', $json), hash('sha256', $reencoded));
    });

    $harness->check(\eel_accounts\Support\PersistentJson::class, 'removes an accidental unescaped Unicode flag', static function () use ($harness): void {
        $json = \eel_accounts\Support\PersistentJson::encode(
            ['name' => 'Café'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $harness->assertSame(false, str_contains($json, 'é'));
        $harness->assertSame(true, str_contains($json, '\\u00e9'));
    });

    $harness->check(\eel_accounts\Support\PersistentJson::class, 'recovers legacy Windows-1252 text deterministically', static function () use ($harness): void {
        $json = \eel_accounts\Support\PersistentJson::encode([
            'asset' => 'Citro' . chr(0xEB) . 'n',
        ]);

        $harness->assertSame('{"asset":"Citro\\u00ebn"}', $json);
        $harness->assertSame(['asset' => 'Citroën'], json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    });
});
