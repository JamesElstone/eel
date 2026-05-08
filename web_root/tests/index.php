<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !eel_tests_developer_options_enabled()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Test runner is disabled because developer options are off.';
    return;
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'TestOutput.php';

$testsDirectory = __DIR__;
test_output_bootstrap();
$files = glob($testsDirectory . DIRECTORY_SEPARATOR . 'test_*.php');

if ($files === false) {
    $files = [];
}

sort($files);

foreach ($files as $file) {
    $currentTest = pathinfo($file, PATHINFO_FILENAME);

    try {
        require $file;
    } catch (Throwable $exception) {
        if (!headers_sent()) {
            http_response_code(500);
        }

        test_output_failure_line($currentTest . ': ' . $exception->getMessage());
    }
}

test_output_render();

if (($GLOBALS['test_output_state']['summary']['status'] ?? 'healthy') !== 'healthy' && PHP_SAPI === 'cli') {
    exit(1);
}

function eel_tests_developer_options_enabled(): bool
{
    $configPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';

    if (!is_file($configPath) || !is_readable($configPath)) {
        return false;
    }

    try {
        $config = require $configPath;
    } catch (Throwable) {
        return false;
    }

    return is_array($config) && (bool)($config['developer_options'] ?? false);
}
