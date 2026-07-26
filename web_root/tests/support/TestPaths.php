<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

if (!function_exists('test_upload_base_directory')) {
    function test_upload_base_directory(): string
    {
        $projectRoot = defined('PROJECT_ROOT')
            ? rtrim((string)PROJECT_ROOT, '\\/')
            : rtrim(dirname(__DIR__, 3), '\\/');
        $configPath = defined('APP_CONFIG')
            ? rtrim((string)APP_CONFIG, '\\/') . DIRECTORY_SEPARATOR . 'app.php'
            : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures'
                . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
        $config = is_file($configPath) ? require $configPath : [];
        $configuredPath = trim((string)($config['uploads']['upload_base_dir'] ?? ''));
        if ($configuredPath === '') {
            throw new RuntimeException(
                'The test fixture must define uploads.upload_base_dir.'
            );
        }

        $isAbsolute = preg_match('/^(?:[A-Za-z]:[\\\\\\/]|[\\\\\\/]{1,2})/', $configuredPath) === 1;
        $path = $isAbsolute
            ? $configuredPath
            : $projectRoot . DIRECTORY_SEPARATOR . ltrim($configuredPath, '\\/');
        $path = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);

        if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
            throw new RuntimeException(
                'Unable to create the configured test upload directory: ' . $path
            );
        }

        return $path;
    }
}

if (!function_exists('test_tmp_directory')) {
    function test_tmp_directory(): string
    {
        return test_upload_base_directory();
    }
}
