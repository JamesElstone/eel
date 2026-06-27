<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(SecurityStore::class);

$testTempDirectory = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp';
if (!is_dir($testTempDirectory)) {
    mkdir($testTempDirectory, 0777, true);
}

$harness->check(SecurityStore::class, 'parses API key catalogs with environments and defaults', function () use ($harness, $testTempDirectory): void {
    $path = $testTempDirectory . DIRECTORY_SEPARATOR . 'security-store-api-' . bin2hex(random_bytes(8)) . '.csv';
    file_put_contents(
        $path,
        "# comment row\n"
        . "PROVIDER,TAG,ENVIRONMENT,SCHEMA,URL,API_KEY\n"
        . "ACME,LOOKUP,LIVE,BEARER,https://live.example.test,live-key\n"
        . "ACME,LOOKUP,BEARER,https://default.example.test,default-key\n"
    );

    try {
        $catalog = SecurityStore::credentialCatalog($path);
        $live = SecurityStore::loadCredential('acme', 'lookup', 'live', $path);
        $test = SecurityStore::loadCredential('ACME', 'LOOKUP', 'TEST', $path);

        $harness->assertTrue(isset($catalog['ACME']['LOOKUP']['LIVE']));
        $harness->assertSame('live-key', $live['api_key']);
        $harness->assertSame('TEST', $test['environment']);
        $harness->assertSame('default-key', $test['api_key']);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

$harness->check(SecurityStore::class, 'reports missing API credentials', function () use ($harness, $testTempDirectory): void {
    $path = $testTempDirectory . DIRECTORY_SEPARATOR . 'security-store-missing-' . bin2hex(random_bytes(8)) . '.csv';
    file_put_contents($path, "PROVIDER,TAG,ENVIRONMENT,SCHEMA,URL,API_KEY\nACME,LOOKUP,TEST,BEARER,https://example.test,key\n");

    try {
        SecurityStore::loadCredential('ACME', 'OTHER', 'TEST', $path);
    } catch (RuntimeException $exception) {
        if (is_file($path)) {
            unlink($path);
        }

        $harness->assertTrue(str_contains($exception->getMessage(), 'API credential not found'));
        return;
    }

    if (is_file($path)) {
        unlink($path);
    }

    throw new RuntimeException('Missing credential did not throw.');
});

$harness->check(SecurityStore::class, 'loads and preserves generated security facts', function () use ($harness, $testTempDirectory): void {
    $path = $testTempDirectory . DIRECTORY_SEPARATOR . 'security-store-facts-' . bin2hex(random_bytes(8)) . '.csv';

    try {
        $first = SecurityStore::ensureFact('Pepper', $path);
        $second = SecurityStore::ensureFact(' pepper ', $path);

        $harness->assertSame($first, $second);
        $harness->assertSame($first, SecurityStore::loadFact('PEPPER', $path));
        $harness->assertSame(64, strlen($first));
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

$harness->check(SecurityStore::class, 'keeps generated security fact files private where supported', function () use ($harness, $testTempDirectory): void {
    $path = $testTempDirectory . DIRECTORY_SEPARATOR . 'security-store-private-' . bin2hex(random_bytes(8)) . '.keys';

    try {
        SecurityStore::ensureFact('Pepper', $path);
        $harness->assertTrue(is_file($path));

        if (DIRECTORY_SEPARATOR === '\\') {
            $harness->skip('POSIX file modes are not available on Windows.');
        }

        $mode = fileperms($path);
        if ($mode === false) {
            throw new RuntimeException('Unable to read generated security key file mode.');
        }

        $harness->assertSame('0600', substr(sprintf('%04o', $mode), -4));
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

$harness->check(SecurityStore::class, 'rejects blank security fact keys', function () use ($harness, $testTempDirectory): void {
    try {
        SecurityStore::ensureFact('   ', $testTempDirectory . DIRECTORY_SEPARATOR . 'unused-security.keys');
    } catch (RuntimeException $exception) {
        $harness->assertTrue(str_contains($exception->getMessage(), 'Security fact key is required'));
        return;
    }

    throw new RuntimeException('Blank fact key did not throw.');
});

$harness->check(SecurityStore::class, 'resolves relative security key paths from the application root', function () use ($harness): void {
    $resolved = SecurityStore::securityKeysPath('tests/tmp/relative-security.keys');

    $harness->assertSame(APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'relative-security.keys', $resolved);
});
