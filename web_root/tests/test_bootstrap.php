<?php
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
    'loads database helper functions' => static function (): void {
        if (!function_exists('db')) {
            throw new RuntimeException('bootstrap.php did not load db.php.');
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
