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
    $harness->assertSame('T', AppConfigurationStore::get('brand-mark'));
    $harness->assertSame('Test strapline', AppConfigurationStore::get('app_strapline'));
    $harness->assertSame('Test strapline', AppConfigurationStore::appStrapline());
});

$harness->check(AppConfigurationStore::class, 'centralises the default application strapline', function () use ($harness): void {
    $method = new ReflectionMethod(AppConfigurationStore::class, 'defaults');
    $defaults = $method->invoke(null);

    $harness->assertSame('Bookkeeping without the fog and panic', $defaults['app_strapline'] ?? null);
});

$harness->check(AppConfigurationStore::class, 'falls back to the default application strapline when configured blank', function () use ($harness): void {
    $path = AppConfigurationStore::configPath();
    $original = file_get_contents($path);

    if (!is_string($original)) {
        throw new RuntimeException('Unable to read fixture config.');
    }

    try {
        $config = require $path;
        if (!is_array($config)) {
            throw new RuntimeException('Fixture config did not return an array.');
        }

        $config['app_strapline'] = '   ';
        file_put_contents($path, "<?php\nreturn " . var_export($config, true) . ";\n", LOCK_EX);

        $harness->assertSame('Bookkeeping without the fog and panic', AppConfigurationStore::appStrapline(true));
    } finally {
        file_put_contents($path, $original, LOCK_EX);
        AppConfigurationStore::config(true);
    }
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

$harness->check(AppConfigurationStore::class, 'updates editable application settings without dropping other config', function () use ($harness): void {
    $path = AppConfigurationStore::configPath();
    $original = file_get_contents($path);

    if (!is_string($original)) {
        throw new RuntimeException('Unable to read fixture config.');
    }

    try {
        $updated = AppConfigurationStore::setEditableApplicationSettings([
            'app_name' => 'Updated Settings Test',
            'app_strapline' => 'Updated strapline',
            'brand-mark' => 'US',
            'developer_options' => false,
            'navigation' => [
                'default_order' => [
                    'dashboard' => 10,
                    'settings' => 920,
                ],
                'developer_only_pages' => [
                    'test',
                ],
                'hide_collapsed_link_initials' => true,
            ],
            'antifraud' => [
                'vendor_license_ids' => 'license-1',
                'vendor_product_name' => 'eelKit',
                'vendor_public_ip' => '203.0.113.10',
                'vendor_version' => 'test-version',
            ],
            'session' => [
                'cookie_secure' => 'true',
                'cookie_samesite' => 'Lax',
            ],
        ]);

        $harness->assertSame('Updated Settings Test', $updated['app_name'] ?? null);
        $harness->assertSame('US', $updated['brand-mark'] ?? null);
        $harness->assertSame(false, $updated['developer_options'] ?? null);
        $harness->assertSame(920, $updated['navigation']['default_order']['settings'] ?? null);
        $harness->assertSame('test', $updated['navigation']['developer_only_pages'][0] ?? null);
        $harness->assertSame(true, $updated['navigation']['hide_collapsed_link_initials'] ?? null);
        $harness->assertSame('203.0.113.10', $updated['antifraud']['vendor_public_ip'] ?? null);
        $harness->assertSame('true', $updated['session']['cookie_secure'] ?? null);
        $harness->assertSame('../db_schema/eelKit.schema.sql', $updated['db']['sqlite_schema'] ?? null);
    } finally {
        file_put_contents($path, $original, LOCK_EX);
        AppConfigurationStore::config(true);
    }
});

$harness->check(AppConfigurationStore::class, 'sets nested configuration paths without dropping other config', function () use ($harness): void {
    $path = AppConfigurationStore::configPath();
    $original = file_get_contents($path);

    if (!is_string($original)) {
        throw new RuntimeException('Unable to read fixture config.');
    }

    try {
        $updated = AppConfigurationStore::set('accounting.hmrc.mode', 'LIVE');
        $stored = require $path;

        if (!is_array($stored)) {
            throw new RuntimeException('Fixture config did not return an array.');
        }

        $harness->assertSame('LIVE', $updated['accounting']['hmrc']['mode'] ?? null);
        $harness->assertSame('LIVE', AppConfigurationStore::get('accounting.hmrc.mode'));
        $harness->assertSame('LIVE', $stored['accounting']['hmrc']['mode'] ?? null);
        $harness->assertSame('../db_schema/eelKit.schema.sql', $updated['db']['sqlite_schema'] ?? null);
    } finally {
        file_put_contents($path, $original, LOCK_EX);
        AppConfigurationStore::config(true);
    }
});

$harness->check(AppConfigurationStore::class, 'sets nested paths over existing scalar values', function () use ($harness): void {
    $path = AppConfigurationStore::configPath();
    $original = file_get_contents($path);

    if (!is_string($original)) {
        throw new RuntimeException('Unable to read fixture config.');
    }

    try {
        AppConfigurationStore::set('accounting', 'disabled');
        $updated = AppConfigurationStore::set('accounting.hmrc.mode', 'TEST');

        $harness->assertSame('TEST', $updated['accounting']['hmrc']['mode'] ?? null);
    } finally {
        file_put_contents($path, $original, LOCK_EX);
        AppConfigurationStore::config(true);
    }
});

$harness->check(AppConfigurationStore::class, 'rejects empty configuration set paths', function () use ($harness): void {
    foreach (['', '   ', 'accounting..hmrc', '.accounting', 'accounting.'] as $path) {
        try {
            AppConfigurationStore::set($path, 'invalid');
        } catch (RuntimeException) {
            continue;
        }

        throw new RuntimeException('Configuration path was accepted: ' . var_export($path, true));
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
