<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$tests = [
    'defines expected application path constants' => static function (): void {
        $required = ['APP_ROOT', 'APP_CLASSES', 'APP_CONFIG', 'APP_CONTENT', 'APP_PAGES', 'APP_JS', 'APP_CSS'];

        foreach ($required as $constant) {
            if (!defined($constant) || constant($constant) === '') {
                throw new RuntimeException('Expected bootstrap constant was not defined: ' . $constant);
            }
        }
    },
    'loads framework helpers directly required by bootstrap' => static function (): void {
        if (!class_exists('HelperFramework', false)) {
            throw new RuntimeException('bootstrap.php did not load HelperFramework.php.');
        }

        if (!method_exists('HelperFramework', 'escape')) {
            throw new RuntimeException('HelperFramework::escape() was not available after bootstrap.');
        }
    },
    'autoloads the configuration and database classes used by the new db architecture' => static function (): void {
        if (!class_exists('AppConfigurationStore')) {
            throw new RuntimeException('Autoloader did not resolve AppConfigurationStore.');
        }

        if (!class_exists('PdoDB')) {
            throw new RuntimeException('Autoloader did not resolve PdoDB.');
        }

        if (!class_exists('InterfaceDB')) {
            throw new RuntimeException('Autoloader did not resolve InterfaceDB.');
        }

        if (!method_exists('InterfaceDB', 'driverName')) {
            throw new RuntimeException('InterfaceDB::driverName() was not available after bootstrap.');
        }

        if (!method_exists('InterfaceDB', 'fetchAll')) {
            throw new RuntimeException('InterfaceDB::fetchAll() was not available after bootstrap.');
        }
    },
    'suggests the migration tool for schema exceptions' => static function (): void {
        $message = eel_public_exception_message(new RuntimeException(
            "SQLSTATE[42S22]: Column not found: Unknown column 'must_change_password' in 'SELECT'"
        ));

        if (!str_contains($message, 'Unknown column')) {
            throw new RuntimeException('Developer exception detail was not preserved.');
        }

        if (!str_contains($message, 'php tools/migrateDb.php')) {
            throw new RuntimeException('Migration tool hint was not included for a schema exception.');
        }
    },
    'does not suggest the migration tool for unrelated exceptions' => static function (): void {
        $message = eel_public_exception_message(new RuntimeException('The current user could not be resolved.'));

        if (str_contains($message, 'php tools/migrateDb.php')) {
            throw new RuntimeException('Migration tool hint was included for an unrelated exception.');
        }
    },
];

foreach ($tests as $description => $callback) {
    $callback();
    test_output_line('bootstrap: ' . $description . '.');
}
