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

$harness->run(\eel_accounts\Service\DatabaseBackupService::class, static function (
    GeneratedServiceClassTestHarness $harness,
    \eel_accounts\Service\DatabaseBackupService $service
): void {
    $status = $service->fetchBackupStatus();

    $harness->assertTrue(is_array($status));
    $harness->assertTrue(array_key_exists('directory', $status));
    $harness->assertTrue(array_key_exists('zip_available', $status));
    $harness->assertTrue(array_key_exists('recent_backups', $status));

    $method = new ReflectionMethod($service, 'sqlLiteral');
    $method->setAccessible(true);

    $value = "O'Brien\\Tools\nBackup £ “quote” – dash\0end";
    $harness->assertSame(
        "'O\\'Brien\\\\Tools\\nBackup £ “quote” – dash\\0end'",
        $method->invoke($service, $value)
    );

    $harness->assertSame(
        "'£ 0.00'",
        $method->invoke($service, "\xA3 0.00", 'year_end_check_results', 'metric_value', '2308')
    );

    $backupDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eel_accounts_backup_service_test_' . getmypid();
    if (!is_dir($backupDirectory)) {
        mkdir($backupDirectory, 0755, true);
    }

    $older = $backupDirectory . DIRECTORY_SEPARATOR . 'eel_accounts_20260705_120000.sql.zip';
    $newer = $backupDirectory . DIRECTORY_SEPARATOR . 'eel_accounts_20260706_120000.sql.zip';
    file_put_contents($older, 'older');
    file_put_contents($newer, 'newer');
    touch($older, strtotime('2026-07-05 12:00:00'));
    touch($newer, strtotime('2026-07-06 12:00:00'));

    $directoryService = new \eel_accounts\Service\DatabaseBackupService([], $backupDirectory);
    $directoryStatus = $directoryService->fetchBackupStatus();
    $available = $directoryService->fetchAvailableBackups();

    $harness->assertSame('eel_accounts_20260706_120000.sql.zip', (string)($available[0]['filename'] ?? ''));
    $harness->assertSame('eel_accounts_20260705_120000.sql.zip', (string)($available[1]['filename'] ?? ''));
    $harness->assertSame(2, count((array)($directoryStatus['recent_backups'] ?? [])));
    $harness->assertTrue(isset($available[0]['restore_key']));

    try {
        $directoryService->restoreBackup('../eel_accounts_20260706_120000.sql.zip');
        throw new RuntimeException('Traversal restore filename was accepted.');
    } catch (RuntimeException $exception) {
        $harness->assertTrue(str_contains($exception->getMessage(), 'valid SQL ZIP backup'));
    }

    try {
        $directoryService->restoreBackup('not-a-backup.sql');
        throw new RuntimeException('Non ZIP restore filename was accepted.');
    } catch (RuntimeException $exception) {
        $harness->assertTrue(str_contains($exception->getMessage(), 'valid SQL ZIP backup'));
    }

    $sqlPath = $backupDirectory . DIRECTORY_SEPARATOR . 'fixture.sql';
    $zipPath = $backupDirectory . DIRECTORY_SEPARATOR . 'fixture.sql.zip';
    file_put_contents($sqlPath, "CREATE TABLE `sample` (`note` varchar(255));\nINSERT INTO `sample` (`note`) VALUES ('semi;colon');\n-- comment;\nINSERT INTO `sample` (`note`) VALUES ('done');\n");

    $zipMethod = new ReflectionMethod($directoryService, 'zipSqlDump');
    $zipMethod->setAccessible(true);
    $zipMethod->invoke($directoryService, $sqlPath, $zipPath, basename($sqlPath));

    $extractMethod = new ReflectionMethod($directoryService, 'extractSqlFromBackup');
    $extractMethod->setAccessible(true);
    $extractedSql = (string)$extractMethod->invoke($directoryService, $zipPath);
    $harness->assertTrue(str_contains($extractedSql, "semi;colon"));

    $splitMethod = new ReflectionMethod($directoryService, 'splitSqlStatements');
    $splitMethod->setAccessible(true);
    $statements = $splitMethod->invoke($directoryService, $extractedSql);
    $harness->assertSame(3, count($statements));
    $harness->assertTrue(str_contains((string)$statements[1], "semi;colon"));

    $skipMethod = new ReflectionMethod($directoryService, 'shouldSkipRestoreStatement');
    $skipMethod->setAccessible(true);
    $harness->assertSame(true, $skipMethod->invoke($directoryService, 'SET NAMES utf8mb4'));
    $harness->assertSame(true, $skipMethod->invoke($directoryService, "-- EEL Accounts database backup\n-- Created: 2026-07-06 12:00:00\nSET NAMES utf8mb4"));
    $harness->assertSame(false, $skipMethod->invoke($directoryService, 'SET FOREIGN_KEY_CHECKS=0'));
});
