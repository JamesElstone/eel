<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'TestOutput.php';

$testsDirectory = __DIR__;
test_output_bootstrap();
$files = glob($testsDirectory . DIRECTORY_SEPARATOR . 'test_*.php');

if ($files === false) {
    $files = [];
}

sort($files);

try {
    foreach ($files as $file) {
        require $file;
    }

    test_output_line('All tests completed.');
} catch (Throwable $exception) {
    if (!headers_sent()) {
        http_response_code(500);
    }

    test_output_failure_line('Test run failed: ' . $exception->getMessage());

    if (PHP_SAPI === 'cli') {
        exit(1);
    }
}
