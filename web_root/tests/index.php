<?php
/**
 * eelKit Framework
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
    $outputBufferLevel = ob_get_level();

    try {
        eel_tests_reset_process_state_for_file();
        ob_start();
        require $file;
        eel_tests_report_unexpected_output($currentTest, (string)ob_get_clean());
    } catch (Throwable $exception) {
        while (ob_get_level() > $outputBufferLevel) {
            eel_tests_report_unexpected_output($currentTest, (string)ob_get_clean());
        }

        if (!headers_sent()) {
            http_response_code(500);
        }

        test_output_result($currentTest, 'loads without an uncaught exception', 'fail', $exception->getMessage());
    }
}

test_output_render();

if (($GLOBALS['test_output_state']['summary']['status'] ?? 'healthy') !== 'healthy' && PHP_SAPI === 'cli') {
    exit(1);
}

function eel_tests_developer_options_enabled(): bool
{
    $configPath = eel_tests_app_config_path();

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

function eel_tests_app_config_path(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'secure' . DIRECTORY_SEPARATOR . 'app.php';
}

function eel_tests_reset_process_state_for_file(): void
{
    $_GET = [];
    $_POST = [];
    $_REQUEST = [];
    $_FILES = [];
    $_COOKIE = [];
    unset($GLOBALS['__request_framework_raw_body']);

    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset(
        $_SERVER['HTTP_ACCEPT'],
        $_SERVER['HTTP_X_REQUESTED_WITH'],
        $_SERVER['HTTP_X_CLIENT_DEVICE_ID']
    );

    $_SESSION = [];

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
        session_write_close();
    }
    if (session_id() !== '') {
        session_id('');
    }
    $_SESSION = [];

    if (class_exists('AppConfigurationStore', false)) {
        AppConfigurationStore::config(true);
    }
}

function eel_tests_report_unexpected_output(string $currentTest, string $output): void
{
    $output = trim($output);

    if ($output === '') {
        return;
    }

    $output = (string)preg_replace('/\s+/', ' ', $output);

    if (strlen($output) > 500) {
        $output = substr($output, 0, 497) . '...';
    }

    test_output_failure_line($currentTest . ': emitted unexpected output: ' . $output);
}
