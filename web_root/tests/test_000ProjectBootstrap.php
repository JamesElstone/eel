<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

if (!function_exists('test_tmp_directory')) {
    throw new RuntimeException('The downstream test bootstrap did not provide test_tmp_directory().');
}

$expectedTemporaryDirectory = rtrim(APP_ROOT, '\\/')
    . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'tmp';
$actualTemporaryDirectory = test_tmp_directory();
$normaliseTemporaryPath = static fn(string $path): string => strtolower(
    rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR)
);
if ($normaliseTemporaryPath($actualTemporaryDirectory)
    !== $normaliseTemporaryPath($expectedTemporaryDirectory)) {
    throw new RuntimeException(
        'The configured test temporary directory must resolve to web_root/tests/tmp.'
    );
}

$forbiddenPathFragments = [
    'sys_get_' . 'temp_dir(',
    'APP_' . 'ROOT' . " . 'tests' . DIRECTORY_SEPARATOR . 'tmp'",
    'files/' . 'tmp',
    'files\\' . 'tmp',
    'files/' . 'tests/' . 'tmp',
    'files\\' . 'tests\\' . 'tmp',
    "DIRECTORY_SEPARATOR . 'scripts'",
    '/scripts/',
    '\\scripts\\',
    'output/' . 'ixbrl',
    'output\\' . 'ixbrl',
];
foreach (
    new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            __DIR__,
            FilesystemIterator::SKIP_DOTS
        )
    ) as $testFile
) {
    if (!$testFile instanceof SplFileInfo
        || !$testFile->isFile()
        || strtolower($testFile->getExtension()) !== 'php'
        || str_starts_with(
            $testFile->getPathname(),
            test_tmp_directory() . DIRECTORY_SEPARATOR
        )
        || realpath($testFile->getPathname()) === realpath(__FILE__)) {
        continue;
    }
    $source = file_get_contents($testFile->getPathname());
    if (!is_string($source)) {
        throw new RuntimeException(
            'Unable to inspect test temporary-path policy: '
                . $testFile->getPathname()
        );
    }
    foreach ($forbiddenPathFragments as $fragment) {
        if (str_contains($source, $fragment)) {
            throw new RuntimeException(
                $testFile->getFilename()
                    . ' bypasses uploads.upload_base_dir with: ' . $fragment
            );
        }
    }
}

$harnessSource = (new ReflectionClass(GeneratedServiceClassTestHarness::class))->getFileName();
if (!is_string($harnessSource) || realpath($harnessSource) !== realpath(__DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php')) {
    throw new RuntimeException('The downstream test harness was not loaded before framework tests.');
}

test_output_line('ProjectTestBootstrap: loads downstream test helpers before mixed framework and project tests.');
