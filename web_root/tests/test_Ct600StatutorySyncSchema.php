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
$root = dirname(__DIR__, 2);
$migration = (string)file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations'
    . DIRECTORY_SEPARATOR . '2026_07_17_006_ct600_statutory_sync.sql'
);
$masterSchema = (string)file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'eel_accounts.schema.sql'
);

$harness->check(
    'CT600 statutory synchronisation schema',
    'fresh SQLite schema exposes the retryable projection fields',
    static function () use ($harness): void {
        foreach (['statutory_sync_state', 'statutory_sync_error', 'statutory_synced_at'] as $column) {
            $harness->assertTrue(InterfaceDB::columnExists('hmrc_ct600_submissions', $column));
        }
    }
);

$harness->check(
    'CT600 statutory synchronisation schema',
    'migration and master schema keep HMRC outcome separate from local projection state',
    static function () use ($harness, $migration, $masterSchema): void {
        foreach ([$migration, $masterSchema] as $schema) {
            foreach ([
                'business_outcome',
                'statutory_sync_state',
                'statutory_sync_error',
                'statutory_synced_at',
                'not_applicable',
                'pending',
                'applied',
                'failed',
            ] as $token) {
                $harness->assertTrue(str_contains($schema, $token));
            }
        }
        $harness->assertTrue(str_contains($migration, "business_outcome = 'live_accepted'"));
        $harness->assertTrue(str_contains($migration, "THEN 'pending'"));
        $harness->assertFalse(str_contains(strtolower($migration), 'password'));
        $harness->assertFalse(str_contains(strtolower($migration), 'credential'));
    }
);
