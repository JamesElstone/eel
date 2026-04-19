<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

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
    'autoloads classes from the classes directory structure' => static function (): void {
        $suggester = new TaxPeriodService();

        if (!$suggester instanceof TaxPeriodService) {
            throw new RuntimeException('Autoloader did not resolve TaxPeriodService.');
        }
    },
];

foreach ($tests as $description => $callback) {
    $callback();
    test_output_line('bootstrap: ' . $description . '.');
}
