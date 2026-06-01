<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$toolsPhpDirectory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'php';

$migrateDbToolPath = $toolsPhpDirectory . DIRECTORY_SEPARATOR . 'migrateDb.php';
ob_start();
require_once $migrateDbToolPath;
$includeOutput = ob_get_clean();

$setupDbToolPath = $toolsPhpDirectory . DIRECTORY_SEPARATOR . 'setupDb.php';
ob_start();
require_once $setupDbToolPath;
$setupIncludeOutput = ob_get_clean();

$setDbConfigToolPath = $toolsPhpDirectory . DIRECTORY_SEPARATOR . 'setDbConfig.php';
ob_start();
require_once $setDbConfigToolPath;
$setDbConfigIncludeOutput = ob_get_clean();

$harness = new GeneratedServiceClassTestHarness();

$harness->check('migrateDb.php', 'loads CLI helper functions without running migrations', function () use ($harness, $includeOutput): void {
    $harness->assertSame('', $includeOutput);
    $harness->assertTrue(function_exists('eel_run_migration_tool'));
    $harness->assertTrue(function_exists('eel_migration_hydrate_empty_database'));
    $harness->assertTrue(function_exists('eel_migration_database_has_no_application_tables'));
    $harness->assertTrue(function_exists('eel_migration_application_tables'));
});

$harness->check('setupDb.php', 'loads CLI helper functions without running setup', function () use ($harness, $setupIncludeOutput): void {
    $harness->assertSame('', $setupIncludeOutput);
    $harness->assertTrue(function_exists('eel_run_database_setup_tool'));
    $harness->assertTrue(function_exists('eel_database_setup_arguments'));
    $harness->assertTrue(function_exists('eel_database_setup_has_database_options'));
});

$harness->check('setDbConfig.php', 'loads CLI helper functions without running config update', function () use ($harness, $setDbConfigIncludeOutput): void {
    $harness->assertSame('', $setDbConfigIncludeOutput);
    $harness->assertTrue(function_exists('eel_set_db_config_run_tool'));
    $harness->assertTrue(function_exists('eel_set_db_config_arguments'));
    $harness->assertTrue(function_exists('eel_set_db_config_build_dsn'));
    $harness->assertTrue(function_exists('eel_set_db_config_default_mysql_host'));
});

$harness->check('setDbConfig.php', 'parses named and positional connection arguments', function () use ($harness): void {
    $named = eel_set_db_config_arguments([
        'setDbConfig.php',
        '--dsn=odbc:named',
        '--user=named_user',
        '--password=named_password',
    ]);

    $harness->assertSame('odbc:named', $named['dsn']);
    $harness->assertSame('named_user', $named['user']);
    $harness->assertSame('named_password', $named['password']);

    $positional = eel_set_db_config_arguments([
        'setDbConfig.php',
        'odbc:positional',
        'positional_user',
        'positional_password',
    ]);

    $harness->assertSame('odbc:positional', $positional['dsn']);
    $harness->assertSame('positional_user', $positional['user']);
    $harness->assertSame('positional_password', $positional['password']);
});

$harness->check('setupDb.php', 'keeps setup-only options separate from database config options', function () use ($harness): void {
    $arguments = eel_database_setup_arguments([
        'setupDb.php',
        '--skip-external-ip',
        '--driver=mysql',
        '--host=db.example.test',
        '--database=eelkit',
    ]);

    $harness->assertTrue($arguments['skip_external_ip']);
    $harness->assertSame([
        'setupDb.php',
        '--driver=mysql',
        '--host=db.example.test',
        '--database=eelkit',
    ], $arguments['db_argv']);
    $harness->assertSame('mysql', $arguments['db_arguments']['driver']);
    $harness->assertTrue(eel_database_setup_has_database_options($arguments['db_arguments']));
});

$harness->check('setDbConfig.php', 'builds DSNs for supported database choices', function () use ($harness): void {
    $harness->assertSame('odbc:eelkit_prod', eel_set_db_config_build_dsn([
        'driver' => 'odbc',
        'odbc_name' => 'eelkit_prod',
    ]));

    $harness->assertSame('mysql:host=db.example.test;port=3307;dbname=eelkit;charset=utf8mb4', eel_set_db_config_build_dsn([
        'driver' => 'mysql',
        'host' => 'db.example.test',
        'port' => '3307',
        'database' => 'eelkit',
    ]));

    $harness->assertSame('mysql:host=localhost;dbname=eelkit;charset=utf8mb4', eel_set_db_config_build_dsn([
        'driver' => 'mariadb',
        'host' => 'localhost',
        'database' => 'eelkit',
    ]));

    $harness->assertSame('sqlite:../secure/eelkit.sqlite', eel_set_db_config_build_dsn([
        'driver' => 'sqlite',
        'sqlite_path' => '../secure/eelkit.sqlite',
    ]));

    $harness->assertSame('pgsql:host=localhost;dbname=eelkit', eel_set_db_config_build_dsn([
        'driver' => 'custom',
        'dsn' => 'pgsql:host=localhost;dbname=eelkit',
    ]));
});

$harness->check('setDbConfig.php', 'defaults MySQL builder prompts to TCP localhost', function () use ($harness): void {
    $harness->assertSame('127.0.0.1', eel_set_db_config_default_mysql_host());
});

$harness->check('migrateDb.php', 'tracks expected application tables for empty database hydration', function () use ($harness): void {
    $tables = eel_migration_application_tables();

    foreach ([
        'roles',
        'mobile_country_codes',
        'users',
        'role_card_permissions',
        'user_login_rate_limits',
        'application_activity_flash_history',
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
