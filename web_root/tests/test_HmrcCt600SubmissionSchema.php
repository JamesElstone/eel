<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$root = dirname(__DIR__, 2);
$migration = (string)file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations'
    . DIRECTORY_SEPARATOR . '2026_07_19_007_hmrc_ct600_source_manifest.sql'
);
$securityMigration = (string)file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations'
    . DIRECTORY_SEPARATOR . '2026_07_19_009_hmrc_ct600_submission_security.sql'
);
$master = (string)file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'eel_accounts.schema.sql'
);

$harness->check(
    'HMRC CT600 submission schema',
    'persists authority evidence and numbers cleanup attempts',
    static function () use ($harness, $securityMigration, $master): void {
        foreach ([
            'authority_confirmed',
            'authority_confirmed_at',
            'authority_confirmed_by',
            'cleanup_attempts',
        ] as $column) {
            $harness->assertTrue(InterfaceDB::columnExists('hmrc_ct600_submissions', $column));
            $harness->assertTrue(str_contains($securityMigration, $column));
            $harness->assertTrue(str_contains($master, $column));
        }
    }
);

$harness->check(
    'HMRC CT600 submission schema',
    'freezes source identity and links LIVE attempts to their successful TIL attempt',
    static function () use ($harness, $migration, $master): void {
        foreach (['source_manifest_json', 'source_manifest_sha256', 'test_submission_id'] as $column) {
            $harness->assertTrue(InterfaceDB::columnExists('hmrc_ct600_submissions', $column));
            $harness->assertTrue(str_contains($migration, $column));
            $harness->assertTrue(str_contains($master, $column));
        }
        $harness->assertTrue(str_contains($migration, 'fk_hmrc_ct600_test_submission'));
        $harness->assertTrue(str_contains($master, 'fk_hmrc_ct600_test_submission'));
    }
);
