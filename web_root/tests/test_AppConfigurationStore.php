<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->check(AppConfigurationStore::class, 'loads configuration from the test fixture', function () use ($harness): void {
    $harness->assertSame('eelKit Framework Test', AppConfigurationStore::get('app_name'));
    $harness->assertSame('Test strapline', AppConfigurationStore::get('app_strapline'));
});

$harness->check(AppConfigurationStore::class, 'exposes the active application config file path', function () use ($harness): void {
    $harness->assertSame(APP_CONFIG . 'app.php', AppConfigurationStore::configPath());
    $harness->assertTrue(is_file(AppConfigurationStore::configPath()));
});

$harness->check(AppConfigurationStore::class, 'does not generate a default database DSN', function () use ($harness): void {
    $method = new ReflectionMethod(AppConfigurationStore::class, 'defaults');
    $defaults = $method->invoke(null);

    $harness->assertSame('', $defaults['db']['dsn'] ?? null);
});

$harness->check(AppConfigurationStore::class, 'updates database connection settings without dropping other db options', function () use ($harness): void {
    $path = AppConfigurationStore::configPath();
    $original = file_get_contents($path);

    if (!is_string($original)) {
        throw new RuntimeException('Unable to read fixture config.');
    }

    try {
        $updated = AppConfigurationStore::setDatabaseConfig('odbc:test_dsn', 'test_user', 'test_password');

        $harness->assertSame('odbc:test_dsn', $updated['db']['dsn'] ?? null);
        $harness->assertSame('test_user', $updated['db']['user'] ?? null);
        $harness->assertSame('test_password', $updated['db']['pass'] ?? null);
        $harness->assertSame('../db_schema/eelKit.schema.sql', $updated['db']['sqlite_schema'] ?? null);
    } finally {
        file_put_contents($path, $original, LOCK_EX);
        AppConfigurationStore::config(true);
    }
});

$harness->check(AppConfigurationStore::class, 'throws without emitting warnings when config writes fail', function () use ($harness): void {
    $method = new ReflectionMethod(AppConfigurationStore::class, 'writeConfigFile');
    $targetDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eelkit-config-write-target';

    if (!is_dir($targetDirectory) && !mkdir($targetDirectory) && !is_dir($targetDirectory)) {
        $harness->skip('Unable to create temporary write target.');
    }

    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = $severity . ': ' . $message;
        return true;
    });

    try {
        $method->invoke(null, $targetDirectory, ['app_name' => 'write failure test']);
    } catch (ReflectionException $exception) {
        restore_error_handler();
        throw $exception;
    } catch (RuntimeException $exception) {
        restore_error_handler();
        $harness->assertSame([], $warnings);

        if (!str_contains($exception->getMessage(), $targetDirectory)) {
            throw new RuntimeException('Write failure did not include the target path.');
        }

        return;
    }

    restore_error_handler();
    throw new RuntimeException('Config write failure did not throw.');
});
