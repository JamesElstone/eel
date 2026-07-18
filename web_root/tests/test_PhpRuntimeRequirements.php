<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();

$requiredExtensions = [
    'curl',
    'dom',
    'fileinfo',
    'filter',
    'gd',
    'hash',
    'json',
    'libxml',
    'mbstring',
    'openssl',
    'pcre',
    'PDO',
    'pdo_odbc',
    'random',
    'session',
    'xsl',
    'zlib',
];

$harness->check(
    'PHP runtime',
    'loads all extensions required by PHP_REQUIREMENTS.md',
    static function () use ($requiredExtensions): void {
        $missingExtensions = array_values(array_filter(
            $requiredExtensions,
            static fn(string $extension): bool => !extension_loaded($extension)
        ));

        if ($missingExtensions === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Missing required PHP extension(s) in the running %s runtime: %s',
            PHP_SAPI,
            implode(', ', $missingExtensions)
        ));
    }
);

$harness->check('PHP runtime', 'provides the configured ODBC PDO driver', static function (): void {
    if (!in_array('odbc', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException(sprintf(
            'The ODBC PDO driver is unavailable in the %s runtime.',
            PHP_SAPI
        ));
    }
});

$requiredCapabilities = [
    'curl_init' => 'curl',
    'finfo' => 'fileinfo',
    'imagecreatetruecolor' => 'gd',
    'mb_strlen' => 'mbstring',
    'openssl_encrypt' => 'openssl',
    'gzdeflate' => 'zlib',
];

$harness->check(
    'PHP runtime',
    'provides required extension functions and classes',
    static function () use ($requiredCapabilities): void {
        $missingCapabilities = [];

        foreach ($requiredCapabilities as $capability => $extension) {
            if (!function_exists($capability) && !class_exists($capability)) {
                $missingCapabilities[] = $capability . ' (' . $extension . ')';
            }
        }

        foreach ([DOMDocument::class, XSLTProcessor::class] as $className) {
            if (!class_exists($className)) {
                $missingCapabilities[] = $className;
            }
        }

        if ($missingCapabilities !== []) {
            throw new RuntimeException(
                'Missing required PHP runtime capabilities: ' . implode(', ', $missingCapabilities)
            );
        }

        $fileInfoReflection = new ReflectionClass(finfo::class);
        if (!$fileInfoReflection->isInternal()) {
            throw new RuntimeException('The finfo class must be provided by PHP ext-fileinfo, not a userland test double.');
        }
    }
);
