<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'TestOutput.php';

/** @param list<string> $files */
function eel_accounts_run_selected_tests(array $files): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'This focused downstream test runner is CLI-only.';
        return;
    }

    test_output_bootstrap();
    $lock = eel_accounts_acquire_selected_test_lock();
    if (!is_resource($lock)) {
        test_output_failure_line('SelectedTestRunner: another application test runner is already active failed.');
        test_output_render();
        exit(1);
    }
    $requestedFiles = array_values(array_unique($files));
    $missingFiles = array_values(array_filter(
        $requestedFiles,
        static fn(string $file): bool => !is_file($file)
    ));
    foreach ($missingFiles as $missingFile) {
        test_output_failure_line('SelectedTestRunner: required test file is missing failed. ' . basename($missingFile));
    }
    $files = array_values(array_filter($requestedFiles, static fn(string $file): bool => is_file($file)));
    if ($files === []) {
        test_output_failure_line('SelectedTestRunner: no test files were selected failed.');
    }
    sort($files);

    foreach ($files as $file) {
        $currentTest = pathinfo($file, PATHINFO_FILENAME);
        $outputBufferLevel = ob_get_level();
        try {
            eel_accounts_reset_selected_test_state();
            ob_start();
            require $file;
            eel_accounts_report_selected_test_output($currentTest, (string)ob_get_clean());
        } catch (Throwable $exception) {
            while (ob_get_level() > $outputBufferLevel) {
                eel_accounts_report_selected_test_output($currentTest, (string)ob_get_clean());
            }
            test_output_result($currentTest, 'loads without an uncaught exception', 'fail', $exception->getMessage());
        }
    }

    test_output_render();
    if (($GLOBALS['test_output_state']['summary']['status'] ?? 'healthy') !== 'healthy') {
        exit(1);
    }
}

/** @return resource|null */
function eel_accounts_acquire_selected_test_lock(): mixed
{
    $frameworkRunner = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'index.php';
    $runnerPath = (string)(realpath($frameworkRunner) ?: $frameworkRunner);
    $lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eelkit-test-runner-'
        . hash('sha256', $runnerPath) . '.lock';
    $handle = @fopen($lockPath, 'c+');
    if (!is_resource($handle) || !flock($handle, LOCK_EX | LOCK_NB)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        return null;
    }
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, 'PID ' . (string)getmypid() . ' since ' . gmdate('c'));
    fflush($handle);
    register_shutdown_function(static function () use ($handle): void {
        flock($handle, LOCK_UN);
        fclose($handle);
    });
    return $handle;
}

function eel_accounts_reset_selected_test_state(): void
{
    $_GET = [];
    $_POST = [];
    $_REQUEST = [];
    $_FILES = [];
    $_COOKIE = [];
    unset($GLOBALS['__request_framework_raw_body']);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['HTTP_X_CLIENT_DEVICE_ID']);
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

function eel_accounts_report_selected_test_output(string $currentTest, string $output): void
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
