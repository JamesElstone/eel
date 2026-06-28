<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$tests = [
    'defines expected application path constants' => static function (): void {
        $required = ['APP_ROOT', 'PROJECT_ROOT', 'APP_CLASSES', 'APP_CONFIG', 'APP_CONTENT', 'APP_PAGES', 'APP_JS', 'APP_CSS'];

        foreach ($required as $constant) {
            if (!defined($constant) || constant($constant) === '') {
                throw new RuntimeException('Expected bootstrap constant was not defined: ' . $constant);
            }
        }
    },
    'autoloads framework helpers required by bootstrap' => static function (): void {
        if (!class_exists('HelperFramework')) {
            throw new RuntimeException('bootstrap.php did not autoload HelperFramework.php.');
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
    'autoloads namespaced downstream project classes from namespace paths' => static function (): void {
        $className = 'ProjectName\\Service\\SomeClassService';
        $directory = APP_CLASSES . 'projectname' . DIRECTORY_SEPARATOR . 'service';
        $file = $directory . DIRECTORY_SEPARATOR . 'SomeClassService.php';

        if (class_exists($className, false)) {
            throw new RuntimeException('Namespaced test class was already loaded before the autoload test.');
        }

        if (class_exists('ProjectName\\Service\\MissingService')) {
            throw new RuntimeException('Autoloader resolved a missing namespaced downstream class.');
        }

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create namespaced autoload test directory.');
        }

        $source = <<<'PHP'
<?php
declare(strict_types=1);

namespace ProjectName\Service;

final class SomeClassService
{
    public function marker(): string
    {
        return 'loaded';
    }
}
PHP;

        if (file_put_contents($file, $source) === false) {
            throw new RuntimeException('Unable to write namespaced autoload test fixture.');
        }

        try {
            if (!class_exists($className)) {
                throw new RuntimeException('Autoloader did not resolve a namespaced downstream class.');
            }

            $instance = new $className();

            if ($instance->marker() !== 'loaded') {
                throw new RuntimeException('Namespaced downstream class fixture did not instantiate correctly.');
            }
        } finally {
            if (is_file($file)) {
                unlink($file);
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }

            $projectDirectory = dirname($directory);

            if (is_dir($projectDirectory)) {
                rmdir($projectDirectory);
            }
        }
    },
    'suggests the migration tool for schema exceptions' => static function (): void {
        $message = eel_public_exception_message(new RuntimeException(
            "SQLSTATE[42S22]: Column not found: Unknown column 'must_change_password' in 'SELECT'"
        ));

        if (!str_contains($message, 'Unknown column')) {
            throw new RuntimeException('Developer exception detail was not preserved.');
        }

        if (!str_contains($message, 'php tools/php/setupDb.php --migrate-only')) {
            throw new RuntimeException('Migration tool hint was not included for a schema exception.');
        }
    },
    'does not suggest the migration tool for unrelated exceptions' => static function (): void {
        $message = eel_public_exception_message(new RuntimeException('The current user could not be resolved.'));

        if (str_contains($message, 'php tools/php/setupDb.php --migrate-only')) {
            throw new RuntimeException('Migration tool hint was included for an unrelated exception.');
        }
    },
    'suggests PDO driver setup for missing driver exceptions' => static function (): void {
        $message = eel_public_exception_message(new PDOException('could not find driver'));

        if (!str_contains($message, 'pdo_odbc')) {
            throw new RuntimeException('PDO driver setup hint was not included for a missing driver exception.');
        }
    },
    'explains first run configuration permission failures without developer options' => static function (): void {
        $path = APP_CONFIG . 'app.php';
        $message = eel_configuration_bootstrap_exception_message(new RuntimeException(
            'Application configuration directory is not writable: ' . dirname($path)
        ));

        if ($message === null || !str_contains($message, 'Application configuration could not be initialised.')) {
            throw new RuntimeException('Configuration bootstrap failure did not get a setup message.');
        }

        if (!str_contains($message, dirname($path))) {
            throw new RuntimeException('Configuration bootstrap failure did not include the failing path.');
        }

        if (!str_contains($message, 'secure/app.php')) {
            throw new RuntimeException('Configuration bootstrap failure did not mention secure/app.php.');
        }
    },
    'does not expose unrelated errors through the configuration bootstrap exception path' => static function (): void {
        $message = eel_configuration_bootstrap_exception_message(new RuntimeException('The current user could not be resolved.'));

        if ($message !== null) {
            throw new RuntimeException('Unrelated exception was exposed as a configuration bootstrap failure.');
        }
    },
];

foreach ($tests as $description => $callback) {
    $callback();
    test_output_line('bootstrap: ' . $description . '.');
}
