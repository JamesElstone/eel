<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'TestOutput.php';

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
