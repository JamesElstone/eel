<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$migrateDbToolPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'migrateDb.php';
ob_start();
require_once $migrateDbToolPath;
$includeOutput = ob_get_clean();

$harness = new GeneratedServiceClassTestHarness();

$harness->check('migrateDb.php', 'loads CLI helper functions without running migrations', function () use ($harness, $includeOutput): void {
    $harness->assertSame('', $includeOutput);
    $harness->assertTrue(function_exists('eel_run_migration_tool'));
    $harness->assertTrue(function_exists('eel_migration_hydrate_empty_database'));
    $harness->assertTrue(function_exists('eel_migration_database_has_no_application_tables'));
    $harness->assertTrue(function_exists('eel_migration_application_tables'));
});

$harness->check('migrateDb.php', 'tracks expected application tables for empty database hydration', function () use ($harness): void {
    $tables = eel_migration_application_tables();

    foreach ([
        'roles',
        'users',
        'role_card_permissions',
        'user_login_rate_limits',
        'user_account_audit',
        'user_logon_history',
        'user_totp',
    ] as $table) {
        $harness->assertTrue(in_array($table, $tables, true));
    }
});

$harness->check('migrateDb.php', 'can parse the baseline schema used to hydrate an empty database', function () use ($harness): void {
    $schemaFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'eelKit.schema.sql';
    $sql = file_get_contents($schemaFile);

    if (!is_string($sql)) {
        throw new RuntimeException('Baseline schema could not be read.');
    }

    $statements = splitMigrationSql($sql);
    $combined = implode("\n", $statements);

    $harness->assertTrue(str_contains($combined, 'CREATE TABLE `users`'));
    $harness->assertTrue(str_contains($combined, 'CREATE TABLE `schema_migrations`'));
    $harness->assertTrue(str_contains($combined, 'INSERT INTO `schema_migrations`'));
});
