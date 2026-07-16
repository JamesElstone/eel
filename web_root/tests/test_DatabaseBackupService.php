<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

final class DatabaseBackupDatabaseNameStatement extends PDOStatement
{
    public function __construct(private readonly string $databaseName)
    {
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->databaseName;
    }
}

final class DatabaseBackupRecordingPdo extends PDO
{
    /** @var list<string> */
    public array $statements = [];
    public string $databaseName = '';

    public function __construct()
    {
    }

    public function exec(string $statement): int|false
    {
        $this->statements[] = $statement;

        return 0;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (strcasecmp(trim($query), 'SELECT DATABASE()') === 0 && $this->databaseName !== '') {
            return new DatabaseBackupDatabaseNameStatement($this->databaseName);
        }

        throw new RuntimeException('Recording PDO does not execute queries.');
    }
}

final class DatabaseBackupShortWriteStream
{
    public mixed $context = null;
    public static int $maximumWriteLength = 3;
    public static string $writtenContent = '';

    public function stream_open(
        string $path,
        string $mode,
        int $options,
        ?string &$openedPath
    ): bool {
        self::$writtenContent = '';

        return true;
    }

    public function stream_write(string $data): int
    {
        $length = min(self::$maximumWriteLength, strlen($data));
        if ($length > 0) {
            self::$writtenContent .= substr($data, 0, $length);
        }

        return $length;
    }

    public function stream_stat(): array
    {
        return [];
    }
}

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
        $method->invoke($service, "\xA3 0.00", 'legacy_table', 'metric_value', '2308')
    );

    $backupDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'eel_accounts_backup_service_test_' . getmypid() . '_' . bin2hex(random_bytes(6));
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
        $directoryService->restoreBackup(
            '../eel_accounts_20260706_120000.sql.zip',
            'eel_accounts',
            'eel_accounts'
        );
        throw new RuntimeException('Traversal restore filename was accepted.');
    } catch (RuntimeException $exception) {
        $harness->assertTrue(str_contains($exception->getMessage(), 'valid SQL ZIP backup'));
    }

    try {
        $directoryService->restoreBackup('not-a-backup.sql', 'eel_accounts', 'eel_accounts');
        throw new RuntimeException('Non ZIP restore filename was accepted.');
    } catch (RuntimeException $exception) {
        $harness->assertTrue(str_contains($exception->getMessage(), 'valid SQL ZIP backup'));
    }

    $writeMethod = new ReflectionMethod($directoryService, 'write');
    $writeMethod->setAccessible(true);
    $shortWriteScheme = 'eelbackupshortwrite';
    if (!stream_wrapper_register($shortWriteScheme, DatabaseBackupShortWriteStream::class)) {
        throw new RuntimeException('Unable to register the short-write test stream.');
    }
    try {
        DatabaseBackupShortWriteStream::$maximumWriteLength = 3;
        $shortWriteHandle = fopen($shortWriteScheme . '://success', 'wb');
        if (!is_resource($shortWriteHandle)) {
            throw new RuntimeException('Unable to open the short-write test stream.');
        }
        try {
            $writeMethod->invoke($directoryService, $shortWriteHandle, 'short writes must be retried');
        } finally {
            fclose($shortWriteHandle);
        }
        $harness->assertSame('short writes must be retried', DatabaseBackupShortWriteStream::$writtenContent);

        DatabaseBackupShortWriteStream::$maximumWriteLength = 0;
        $zeroWriteHandle = fopen($shortWriteScheme . '://failure', 'wb');
        if (!is_resource($zeroWriteHandle)) {
            throw new RuntimeException('Unable to open the zero-write test stream.');
        }
        try {
            $writeMethod->invoke($directoryService, $zeroWriteHandle, 'must fail');
            throw new RuntimeException('A zero-byte SQL dump write was accepted.');
        } catch (ReflectionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $harness->assertTrue(str_contains($exception->getMessage(), 'Unable to write SQL dump content'));
        } finally {
            fclose($zeroWriteHandle);
        }
    } finally {
        stream_wrapper_unregister($shortWriteScheme);
    }

    $sqlPath = $backupDirectory . DIRECTORY_SEPARATOR . 'fixture.sql';
    $zipPath = $backupDirectory . DIRECTORY_SEPARATOR . 'fixture.sql.zip';
    file_put_contents(
        $sqlPath,
        "-- EEL Accounts database backup\n"
        . "-- Created: 2026-07-16 12:00:00\n"
        . "-- Database: eel_accounts\n\n"
        . "CREATE TABLE `sample` (`note` varchar(255));\n"
        . "INSERT INTO `sample` (`note`) VALUES ('semi;colon');\n"
        . "-- comment;\n"
        . "INSERT INTO `sample` (`note`) VALUES ('done');\n"
    );

    $publishMethod = new ReflectionMethod($directoryService, 'publishZipAtomically');
    $publishMethod->setAccessible(true);
    $publishMethod->invoke($directoryService, $sqlPath, $zipPath, basename($sqlPath));
    $harness->assertCount(0, glob($zipPath . '.partial-*') ?: []);

    $verifyMethod = new ReflectionMethod($directoryService, 'verifyStoredSqlZip');
    $verifyMethod->setAccessible(true);
    $sqlHash = hash_file('sha256', $sqlPath);
    if (!is_string($sqlHash)) {
        throw new RuntimeException('Unable to hash the SQL fixture.');
    }
    $verifyMethod->invoke($directoryService, $zipPath, $sqlHash);

    $extractMethod = new ReflectionMethod($directoryService, 'extractSqlFromBackup');
    $extractMethod->setAccessible(true);
    $extractedSql = (string)$extractMethod->invoke($directoryService, $zipPath);
    $harness->assertTrue(str_contains($extractedSql, "semi;colon"));

    $corruptZipPath = $backupDirectory . DIRECTORY_SEPARATOR . 'fixture_corrupt.sql.zip';
    $corruptZip = (string)file_get_contents($zipPath);
    $localHeader = unpack(
        'Vsignature/vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed_size/Vuncompressed_size/vname_length/vextra_length',
        substr($corruptZip, 0, 30)
    );
    $dataOffset = 30 + (int)($localHeader['name_length'] ?? 0) + (int)($localHeader['extra_length'] ?? 0);
    $corruptZip[$dataOffset] = chr(ord($corruptZip[$dataOffset]) ^ 1);
    file_put_contents($corruptZipPath, $corruptZip);
    try {
        $extractMethod->invoke($directoryService, $corruptZipPath);
        throw new RuntimeException('A backup with corrupt SQL content passed the ZIP checksum.');
    } catch (ReflectionException $exception) {
        throw $exception;
    } catch (Throwable $exception) {
        $harness->assertTrue(str_contains($exception->getMessage(), 'integrity checksum'));
    }
    try {
        $verifyMethod->invoke($directoryService, $corruptZipPath, $sqlHash);
        throw new RuntimeException('Streaming verification accepted a corrupt SQL entry.');
    } catch (ReflectionException $exception) {
        throw $exception;
    } catch (Throwable $exception) {
        $harness->assertTrue(str_contains($exception->getMessage(), 'integrity checksum'));
    }

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

    $recordingPdo = new DatabaseBackupRecordingPdo();
    $beginSnapshotMethod = new ReflectionMethod($directoryService, 'beginConsistentDumpSnapshot');
    $beginSnapshotMethod->setAccessible(true);
    $endSnapshotMethod = new ReflectionMethod($directoryService, 'endConsistentDumpSnapshot');
    $endSnapshotMethod->setAccessible(true);
    $beginSnapshotMethod->invoke($directoryService, $recordingPdo);
    $endSnapshotMethod->invoke($directoryService, $recordingPdo);

    $harness->assertSame([
        'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
        'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY',
        'ROLLBACK',
    ], $recordingPdo->statements);

    $restorePdo = new DatabaseBackupRecordingPdo();
    $restorePdo->databaseName = 'eel_accounts_ap79_scratch';
    $guardedRestore = new \eel_accounts\Service\DatabaseBackupService(
        [],
        $backupDirectory,
        $restorePdo
    );
    $harness->assertSame('eel_accounts_ap79_scratch', $guardedRestore->currentDatabaseName());
    $restorePdo->statements = [];
    try {
        $guardedRestore->restoreBackup('fixture.sql.zip', '', '');
        throw new RuntimeException('A restore without database identity guards was accepted.');
    } catch (RuntimeException $exception) {
        $harness->assertTrue(str_contains($exception->getMessage(), 'source database name is required'));
    }
    $harness->assertSame([], $restorePdo->statements);

    try {
        $guardedRestore->restoreBackup('fixture.sql.zip', 'eel_accounts', 'eel_accounts');
        throw new RuntimeException('A mismatched restore target database was accepted.');
    } catch (RuntimeException $exception) {
        $harness->assertTrue(str_contains($exception->getMessage(), 'restore target mismatch'));
    }
    $harness->assertSame(['SET NAMES utf8mb4'], $restorePdo->statements);

    $restorePdo->statements = [];
    try {
        $guardedRestore->restoreBackup(
            'fixture.sql.zip',
            'eel_accounts_ap79_scratch',
            'another_database'
        );
        throw new RuntimeException('A mismatched backup source database was accepted.');
    } catch (RuntimeException $exception) {
        $harness->assertTrue(str_contains($exception->getMessage(), 'backup source mismatch'));
    }
    $harness->assertSame([], $restorePdo->statements);

    $restorePdo->statements = [];
    $restored = $guardedRestore->restoreBackup(
        'fixture.sql.zip',
        'eel_accounts_ap79_scratch',
        'eel_accounts'
    );
    $harness->assertSame(3, (int)($restored['statement_count'] ?? 0));
    $harness->assertSame(4, count($restorePdo->statements));
    $harness->assertSame('SET NAMES utf8mb4', $restorePdo->statements[0] ?? '');
    $harness->assertSame('eel_accounts', (string)($restored['source_database'] ?? ''));
    $harness->assertSame('eel_accounts_ap79_scratch', (string)($restored['target_database'] ?? ''));

    $legacyPdo = new DatabaseBackupRecordingPdo();
    $legacyPdo->databaseName = 'eel_accounts';
    $legacyRestore = new \eel_accounts\Service\DatabaseBackupService(
        [],
        $backupDirectory,
        $legacyPdo
    );
    $legacyResult = $legacyRestore->restoreBackup('fixture.sql.zip');
    $harness->assertSame(3, (int)($legacyResult['statement_count'] ?? 0));
    $harness->assertSame('eel_accounts', (string)($legacyResult['source_database'] ?? ''));
    $harness->assertSame('eel_accounts', (string)($legacyResult['target_database'] ?? ''));
    $harness->assertSame(4, count($legacyPdo->statements));

    $selectorDatabaseName = 'fixture-db$ap79';
    $selectorSqlPath = $backupDirectory . DIRECTORY_SEPARATOR . 'selector_fixture.sql';
    $selectorZipPath = $backupDirectory . DIRECTORY_SEPARATOR . 'selector_fixture.sql.zip';
    file_put_contents(
        $selectorSqlPath,
        "-- EEL Accounts database backup\n"
        . "-- Created: 2026-07-16 12:30:00\n"
        . "-- Database: " . $selectorDatabaseName . "\n"
        . "-- Database-Name-Base64: " . base64_encode($selectorDatabaseName) . "\n\n"
        . "CREATE TABLE `selector_sample` (`id` int);\n"
    );
    $publishMethod->invoke(
        $directoryService,
        $selectorSqlPath,
        $selectorZipPath,
        basename($selectorSqlPath)
    );

    $selectorPdo = new DatabaseBackupRecordingPdo();
    $selectorPdo->databaseName = $selectorDatabaseName;
    $selectorRestore = new \eel_accounts\Service\DatabaseBackupService(
        [],
        $backupDirectory,
        $selectorPdo
    );
    $harness->assertSame($selectorDatabaseName, $selectorRestore->currentDatabaseName());
    $selectorResult = $selectorRestore->restoreBackup('selector_fixture.sql.zip');
    $harness->assertSame($selectorDatabaseName, (string)($selectorResult['source_database'] ?? ''));
    $harness->assertSame($selectorDatabaseName, (string)($selectorResult['target_database'] ?? ''));
    $harness->assertSame(1, (int)($selectorResult['statement_count'] ?? 0));

    $selectorPdo->databaseName = 'fixture-dbap79';
    $selectorPdo->statements = [];
    try {
        $selectorRestore->restoreBackup('selector_fixture.sql.zip');
        throw new RuntimeException('A legacy one-argument restore crossed database identities.');
    } catch (RuntimeException $exception) {
        $harness->assertTrue(str_contains($exception->getMessage(), 'backup source mismatch'));
    }
    $harness->assertSame(['SET NAMES utf8mb4'], $selectorPdo->statements);

    $unknownTargetPdo = new DatabaseBackupRecordingPdo();
    $unknownTargetRestore = new \eel_accounts\Service\DatabaseBackupService(
        ['dsn' => 'mysql:host=127.0.0.1;dbname=eel_accounts_ap79_scratch;charset=utf8mb4'],
        $backupDirectory,
        $unknownTargetPdo
    );
    try {
        $unknownTargetRestore->restoreBackup(
            'fixture.sql.zip',
            'eel_accounts_ap79_scratch',
            'eel_accounts'
        );
        throw new RuntimeException('A restore with an unverifiable connected database was accepted.');
    } catch (RuntimeException $exception) {
        $harness->assertTrue(str_contains($exception->getMessage(), 'unknown database'));
    }
    $harness->assertSame(['SET NAMES utf8mb4'], $unknownTargetPdo->statements);
});
