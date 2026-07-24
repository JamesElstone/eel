<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'dbUnicodeDiagnostic.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->check('dbUnicodeDiagnostic.php', 'compares UTF-8 bytes and code points exactly', function () use ($harness): void {
    $value = 'Crème – 日本語 🚀';
    $harness->assertTrue(eel_db_unicode_diagnostic_same_utf8($value, $value));
    $harness->assertTrue(!eel_db_unicode_diagnostic_same_utf8($value, 'Crème - 日本語 🚀'));
});

$harness->check('dbUnicodeDiagnostic.php', 'reports ODBC charset status without revealing a DSN', function () use ($harness): void {
    $harness->assertSame('not directly verifiable for a named DSN; require CHARSET=utf8mb4 in its ODBC configuration', eel_db_unicode_diagnostic_dsn_status('odbc:production', 'odbc'));
    $harness->assertSame('CHARSET=utf8mb4 present in the inline ODBC connection string', eel_db_unicode_diagnostic_dsn_status('odbc:Driver=MariaDB;CHARSET=utf8mb4;PWD=secret', 'odbc'));
});

$harness->check('dbUnicodeDiagnostic.php', 'creates a byte-stable Unicode JSON payload', function () use ($harness): void {
    $json = json_encode(['samples' => eel_db_unicode_diagnostic_samples()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $harness->assertTrue(eel_db_unicode_diagnostic_same_utf8($json, $json));
    $harness->assertSame(hash('sha256', $json), hash('sha256', $json));
});
