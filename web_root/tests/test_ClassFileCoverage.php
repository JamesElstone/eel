<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$classDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes';
$testsDirectory = __DIR__;
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($classDirectory));
$missingTests = [];

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
        continue;
    }

    $expectedTest = $testsDirectory . DIRECTORY_SEPARATOR . 'test_' . $fileInfo->getBasename('.php') . '.php';

    if (!is_file($expectedTest)) {
        $missingTests[] = str_replace(dirname(__DIR__) . DIRECTORY_SEPARATOR, '', $fileInfo->getPathname());
    }
}

sort($missingTests);

if ($missingTests !== []) {
    throw new RuntimeException(
        'Missing matching test files for class PHP files: ' . implode(', ', $missingTests)
    );
}

test_output_line('ClassFileCoverage: every /classes PHP file has a matching test file.');
