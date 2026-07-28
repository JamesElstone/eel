<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

use DateTimeImmutable;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

final class DatabaseBackupService implements \eel_accounts\Contract\DatabaseBackupCreatorInterface
{
    public const TRIGGER_MANUAL = 'Manual';
    public const TRIGGER_YEAR_END_PRE_LOCK = 'Automatic - Year End pre-lock';
    public const TRIGGER_YEAR_END_PRE_UNLOCK = 'Automatic - Year End pre-unlock';
    public const TRIGGER_UNKNOWN = 'Unknown';

    private array $dbConfig;
    private string $backupDirectory;
    private readonly ?string $backupDirectoryOverride;
    private readonly ?string $secureDirectoryOverride;
    private ?PDO $connection;

    public function __construct(?array $dbConfig = null, ?string $backupDirectory = null, ?PDO $connection = null, ?string $secureDirectory = null)
    {
        $this->dbConfig = $dbConfig ?? (array)\AppConfigurationStore::get('db', []);
        $this->backupDirectoryOverride = $backupDirectory;
        $this->secureDirectoryOverride = $secureDirectory;
        $this->backupDirectory = $backupDirectory ?? $this->defaultBackupDirectory();
        $this->connection = $connection;
    }

    public function fetchBackupStatus(int $companyId = 0): array
    {
        $directory = $companyId > 0 ? $this->companyBackupDirectory($companyId) : $this->backupDirectory;
        $directoryExists = is_dir($directory);

        return [
            'directory' => $directory,
            'directory_exists' => $directoryExists,
            'directory_writable' => $directoryExists && is_writable($this->backupDirectory),
            'zip_available' => true,
            // Status consumers need only lightweight file metadata. Inspecting
            // every archive to read its trigger can make an otherwise simple
            // card render take tens of seconds when a long backup history exists.
            'recent_backups' => $this->fetchBackupMetadata($companyId, 5),
        ];
    }

    public function fetchAvailableBackups(int $companyId = 0): array
    {
        $files = $this->backupFiles($companyId > 0 ? $this->companyBackupDirectory($companyId) : $this->backupDirectory);
        $legacyFiles = $this->backupDirectoryOverride === null
            ? $this->backupFiles($this->defaultBackupDirectory())
            : [];

        $files = array_merge($files, $legacyFiles);
        usort($files, static function (string $left, string $right): int {
            $timeComparison = (filemtime($right) ?: 0) <=> (filemtime($left) ?: 0);

            return $timeComparison !== 0 ? $timeComparison : strcmp(basename($right), basename($left));
        });

        $backups = [];
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $filename = basename($file);
            $legacy = in_array($file, $legacyFiles, true);
            $backups[] = [
                'filename' => $filename,
                'path' => $file,
                'restore_key' => hash('sha256', $filename),
                'size_bytes' => (int)(filesize($file) ?: 0),
                'created_at' => date('Y-m-d H:i:s', (int)(filemtime($file) ?: time())),
                'trigger' => $this->backupTrigger($file),
                'scope' => $legacy ? 'legacy' : 'company',
                'legacy' => $legacy,
            ];
        }

        return $backups;
    }

    /**
     * Returns the newest backup files without opening each archive. This is
     * intended for status displays; the backup-history view still uses
     * fetchAvailableBackups() so it can show the recorded trigger.
     *
     * @return list<array{filename: string, path: string, restore_key: string, size_bytes: int, created_at: string, trigger: string, scope: string, legacy: bool}>
     */
    private function fetchBackupMetadata(int $companyId, int $limit): array
    {
        $files = $this->backupFiles($companyId > 0 ? $this->companyBackupDirectory($companyId) : $this->backupDirectory);
        $legacyFiles = $this->backupDirectoryOverride === null
            ? $this->backupFiles($this->defaultBackupDirectory())
            : [];

        $files = array_merge($files, $legacyFiles);
        usort($files, static function (string $left, string $right): int {
            $timeComparison = (filemtime($right) ?: 0) <=> (filemtime($left) ?: 0);

            return $timeComparison !== 0 ? $timeComparison : strcmp(basename($right), basename($left));
        });

        $backups = [];
        foreach (array_slice($files, 0, max(0, $limit)) as $file) {
            if (!is_file($file)) {
                continue;
            }

            $filename = basename($file);
            $legacy = in_array($file, $legacyFiles, true);
            $backups[] = [
                'filename' => $filename,
                'path' => $file,
                'restore_key' => hash('sha256', $filename),
                'size_bytes' => (int)(filesize($file) ?: 0),
                'created_at' => date('Y-m-d H:i:s', (int)(filemtime($file) ?: time())),
                'trigger' => self::TRIGGER_UNKNOWN,
                'scope' => $legacy ? 'legacy' : 'company',
                'legacy' => $legacy,
            ];
        }

        return $backups;
    }

    public function createBackup(int $companyId, string $trigger = self::TRIGGER_MANUAL): array
    {
        $trigger = $this->normaliseTrigger($trigger);
        $this->backupDirectory = $this->companyBackupDirectory($companyId);
        $this->ensureBackupDirectory();
        $pdo = $this->connect();
        $databaseName = $this->databaseName($pdo);
        $timestamp = (new DateTimeImmutable())->format('Ymd_His_u');
        $baseName = $this->safeFilename($databaseName !== '' ? $databaseName : 'database')
            . '_' . $timestamp
            . '_' . bin2hex(random_bytes(4));
        $sqlPath = $this->backupDirectory . DIRECTORY_SEPARATOR . $baseName . '.sql';
        $zipPath = $this->backupDirectory . DIRECTORY_SEPARATOR . $baseName . '.sql.zip';

        try {
            $tableCount = $this->writeSqlDump($pdo, $sqlPath, $databaseName, $trigger);
            $this->publishFullBackupAtomically($sqlPath, $zipPath, basename($sqlPath), $companyId);
        } finally {
            if (is_file($sqlPath)) {
                @unlink($sqlPath);
            }
        }

        clearstatcache(true, $zipPath);

        return [
            'file' => $zipPath,
            'filename' => basename($zipPath),
            'directory' => $this->backupDirectory,
            'size_bytes' => is_file($zipPath) ? (int)filesize($zipPath) : 0,
            'table_count' => $tableCount,
            'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'trigger' => $trigger,
            'company_id' => $companyId,
            'includes_secure' => true,
        ];
    }

    public function restoreBackup(
        int $companyId,
        string $filename,
        ?string $expectedTargetDatabase = null,
        ?string $expectedSourceDatabase = null,
        ?\Closure $progress = null,
        string $scope = 'company'
    ): array
    {
        $backupPath = $this->resolveBackupPath($companyId, $filename, $scope);
        $progress?->__invoke('Verifying the database backup integrity…', 10);
        $archive = $this->inspectBackupArchive($backupPath, $progress);
        $entry = $archive['sql_entry'];
        $progress?->__invoke('Checking the backup database identity…', 25);
        $sourceDatabase = $this->databaseNameFromSqlDump($this->readStoredSqlPrefix($backupPath, $entry));
        $pdo = null;

        if ($expectedTargetDatabase === null || $expectedSourceDatabase === null) {
            $pdo = $this->connect();
            $connectedDatabase = $this->requireConnectedDatabaseName($pdo);
            $expectedTargetDatabase ??= $connectedDatabase;
            $expectedSourceDatabase ??= $connectedDatabase;
        }

        $this->assertExpectedBackupSource($sourceDatabase, $expectedSourceDatabase);
        $progress?->__invoke('Confirming the database restore target…', 32);
        $pdo ??= $this->connect();
        $this->assertExpectedRestoreTarget($pdo, $expectedTargetDatabase);
        $progress?->__invoke('Restoring database SQL…', 35);
        $executed = $this->executeStoredSqlZip($pdo, $backupPath, $entry, $progress);
        if ($executed === 0) {
            throw new RuntimeException('The selected backup does not contain SQL statements to restore.');
        }
        $secureRestored = false;
        if (!empty($archive['includes_secure'])) {
            $progress?->__invoke('Restoring protected application configuration…', 96);
            $this->restoreSecureDirectory($backupPath, (array)$archive['entries']);
            $secureRestored = true;
        }
        $progress?->__invoke('Finalising the database restore…', 98);

        clearstatcache(true, $backupPath);

        return [
            'file' => $backupPath,
            'filename' => basename($backupPath),
            'directory' => $this->backupDirectory,
            'size_bytes' => is_file($backupPath) ? (int)filesize($backupPath) : 0,
            'statement_count' => $executed,
            'source_database' => $sourceDatabase,
            'target_database' => $this->connectedDatabaseName($pdo),
            'restored_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'legacy' => !empty($archive['legacy']),
            'secure_restored' => $secureRestored,
        ];
    }

    public function currentDatabaseName(): string
    {
        return $this->requireConnectedDatabaseName($this->connect());
    }

    public function backupFileForDownload(int $companyId, string $filename, string $scope = 'company'): array
    {
        $backupPath = $this->resolveBackupPath($companyId, $filename, $scope);

        return [
            'file' => $backupPath,
            'filename' => basename($backupPath),
            'size_bytes' => is_file($backupPath) ? (int)filesize($backupPath) : 0,
        ];
    }

    private function writeSqlDump(PDO $pdo, string $sqlPath, string $databaseName, string $trigger): int
    {
        $handle = @fopen($sqlPath, 'wb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Unable to create SQL dump file: ' . $sqlPath);
        }

        $tables = [];
        $snapshotStarted = false;
        $dumpException = null;

        try {
            $this->beginConsistentDumpSnapshot($pdo);
            $snapshotStarted = true;
            $tables = $this->tableNames($pdo, $databaseName);

            $this->write($handle, "-- EEL Accounts database backup\n");
            $this->write($handle, '-- Created: ' . (new DateTimeImmutable())->format('Y-m-d H:i:s') . "\n");
            $this->write($handle, '-- Trigger: ' . $trigger . "\n");
            $this->write(
                $handle,
                '-- Database: ' . $this->databaseNameForComment($databaseName !== '' ? $databaseName : 'unknown') . "\n"
            );
            $this->write(
                $handle,
                '-- Database-Name-Base64: ' . base64_encode($databaseName) . "\n\n"
            );
            $this->write($handle, "-- Data snapshot: REPEATABLE READ consistent snapshot (transactional tables)\n\n");
            $this->write($handle, "SET NAMES utf8mb4;\n");
            $this->write($handle, "SET FOREIGN_KEY_CHECKS=0;\n");
            $this->write($handle, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

            foreach ($tables as $tableName) {
                $this->writeTable($pdo, $handle, $tableName);
            }

            $this->write($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        } catch (Throwable $exception) {
            $dumpException = $exception;
            throw $exception;
        } finally {
            $snapshotException = null;
            if ($snapshotStarted) {
                try {
                    $this->endConsistentDumpSnapshot($pdo);
                } catch (Throwable $exception) {
                    $snapshotException = $exception;
                }
            }
            fclose($handle);
            if (!$dumpException instanceof Throwable && $snapshotException instanceof Throwable) {
                throw $snapshotException;
            }
        }

        return count($tables);
    }

    private function beginConsistentDumpSnapshot(PDO $pdo): void
    {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    }

    private function endConsistentDumpSnapshot(PDO $pdo): void
    {
        $pdo->exec('ROLLBACK');
    }

    private function writeTable(PDO $pdo, mixed $handle, string $tableName): void
    {
        $quotedTable = $this->quoteIdentifier($tableName);
        $createSql = $this->createTableSql($pdo, $tableName);

        $this->write($handle, "\n-- Table: " . $tableName . "\n");
        $this->write($handle, 'DROP TABLE IF EXISTS ' . $quotedTable . ";\n");
        $this->write($handle, $createSql . ";\n\n");

        $stmt = $pdo->query('SELECT * FROM ' . $quotedTable);
        if (!$stmt instanceof PDOStatement) {
            return;
        }

        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($row) || $row === []) {
                continue;
            }

            $rowKey = (string)($row['id'] ?? '');
            $columns = [];
            $values = [];

            foreach ($row as $column => $value) {
                $column = (string)$column;
                $columns[] = $this->quoteIdentifier($column);
                $values[] = $this->sqlLiteral($value, $tableName, $column, $rowKey);
            }

            $this->write(
                $handle,
                'INSERT INTO ' . $quotedTable . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n"
            );
        }

        $this->write($handle, "\n");
    }

    private function tableNames(PDO $pdo, string $databaseName): array
    {
        $tables = [];

        if ($databaseName !== '') {
            try {
                $stmt = $pdo->prepare(
                    "SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE' ORDER BY table_name"
                );
                if ($stmt instanceof PDOStatement) {
                    $stmt->execute([$databaseName]);
                    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
                }
            } catch (Throwable) {
                $tables = [];
            }
        }

        if ($tables === []) {
            $stmt = $pdo->query('SHOW FULL TABLES');
            if ($stmt instanceof PDOStatement) {
                foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
                    if (($row[1] ?? 'BASE TABLE') === 'VIEW') {
                        continue;
                    }
                    $tables[] = (string)($row[0] ?? '');
                }
            }
        }

        $tables = array_values(array_filter(array_unique(array_map('strval', $tables))));
        sort($tables, SORT_NATURAL | SORT_FLAG_CASE);

        if ($tables === []) {
            throw new RuntimeException('No database tables were available to dump.');
        }

        return $tables;
    }

    private function createTableSql(PDO $pdo, string $tableName): string
    {
        $stmt = $pdo->query('SHOW CREATE TABLE ' . $this->quoteIdentifier($tableName));
        if (!$stmt instanceof PDOStatement) {
            throw new RuntimeException('Unable to inspect table definition for ' . $tableName . '.');
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Unable to fetch table definition for ' . $tableName . '.');
        }

        $createSql = (string)($row['Create Table'] ?? array_values($row)[1] ?? '');
        if (trim($createSql) === '') {
            throw new RuntimeException('Empty table definition returned for ' . $tableName . '.');
        }

        return $createSql;
    }

    private function zipSqlDump(string $sqlPath, string $zipPath, string $entryName): void
    {
        $fileSize = (int)(filesize($sqlPath) ?: 0);
        if ($fileSize > 0xFFFFFFFF) {
            throw new RuntimeException('SQL dump is too large for the built-in ZIP writer.');
        }

        $crcHex = hash_file('crc32b', $sqlPath);
        if (!is_string($crcHex)) {
            throw new RuntimeException('Unable to calculate SQL dump checksum.');
        }

        $crc = (int)hexdec($crcHex);
        $entryName = str_replace('\\', '/', $entryName);
        $entryLength = strlen($entryName);
        $localHeaderOffset = 0;
        $localHeader = pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
            0,
            0,
            0,
            0,
            $crc,
            $fileSize,
            $fileSize,
            $entryLength,
            0
        ) . $entryName;

        $zipHandle = @fopen($zipPath, 'wb');
        if (!is_resource($zipHandle)) {
            throw new RuntimeException('Unable to create backup ZIP file: ' . $zipPath);
        }

        $sqlHandle = @fopen($sqlPath, 'rb');
        if (!is_resource($sqlHandle)) {
            fclose($zipHandle);
            throw new RuntimeException('Unable to read SQL dump file for zipping.');
        }

        try {
            $this->write($zipHandle, $localHeader);
            while (!feof($sqlHandle)) {
                $chunk = fread($sqlHandle, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('Unable to read SQL dump content for zipping.');
                }
                if ($chunk !== '') {
                    $this->write($zipHandle, $chunk);
                }
            }

            $centralDirectoryOffset = 30 + $entryLength + $fileSize;
            $centralDirectory = pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                0,
                0,
                0,
                0,
                $crc,
                $fileSize,
                $fileSize,
                $entryLength,
                0,
                0,
                0,
                0,
                0,
                $localHeaderOffset
            ) . $entryName;
            $centralDirectorySize = strlen($centralDirectory);

            $endOfCentralDirectory = pack(
                'VvvvvVVv',
                0x06054b50,
                0,
                0,
                1,
                1,
                $centralDirectorySize,
                $centralDirectoryOffset,
                0
            );

            $this->write($zipHandle, $centralDirectory);
            $this->write($zipHandle, $endOfCentralDirectory);
        } finally {
            fclose($sqlHandle);
            fclose($zipHandle);
        }
    }

    private function publishZipAtomically(string $sqlPath, string $zipPath, string $entryName): void
    {
        $temporaryZipPath = $zipPath . '.partial-' . bin2hex(random_bytes(8));

        try {
            $this->zipSqlDump($sqlPath, $temporaryZipPath, $entryName);
            $expectedHash = hash_file('sha256', $sqlPath);
            if (!is_string($expectedHash)) {
                throw new RuntimeException('Unable to calculate SQL dump verification hash.');
            }
            $this->verifyStoredSqlZip($temporaryZipPath, $expectedHash);
            if (file_exists($zipPath)) {
                throw new RuntimeException('A database backup with this timestamp already exists.');
            }
            if (!@rename($temporaryZipPath, $zipPath)) {
                throw new RuntimeException('Unable to publish the completed backup ZIP atomically.');
            }
        } finally {
            if (is_file($temporaryZipPath)) {
                @unlink($temporaryZipPath);
            }
        }
    }

    private function publishFullBackupAtomically(string $sqlPath, string $zipPath, string $entryName, int $companyId): void
    {
        $temporaryZipPath = $zipPath . '.partial-' . bin2hex(random_bytes(8));
        $manifestPath = $this->backupDirectory . DIRECTORY_SEPARATOR . '.backup-manifest-' . bin2hex(random_bytes(8)) . '.json';
        try {
            $entries = [['name' => 'database/' . basename($entryName), 'path' => $sqlPath]];
            foreach ($this->secureFiles() as $file) {
                $entries[] = $file;
            }
            $manifestEntries = [];
            foreach ($entries as $entry) {
                $hash = hash_file('sha256', (string)$entry['path']);
                if (!is_string($hash)) {
                    throw new RuntimeException('Unable to hash backup entry: ' . (string)$entry['name']);
                }
                $manifestEntries[] = [
                    'path' => (string)$entry['name'],
                    'size_bytes' => (int)(filesize((string)$entry['path']) ?: 0),
                    'sha256' => $hash,
                ];
            }
            $manifest = json_encode([
                'format' => 'eel_accounts_full_backup_v1',
                'company_id' => $companyId,
                'created_at' => (new DateTimeImmutable())->format(DATE_ATOM),
                'entries' => $manifestEntries,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($manifestPath, $manifest, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write backup manifest.');
            }
            $entries[] = ['name' => 'backup-manifest.json', 'path' => $manifestPath];
            $this->writeStoredZip($entries, $temporaryZipPath);
            $this->inspectFullBackupArchive($temporaryZipPath);
            if (file_exists($zipPath)) {
                throw new RuntimeException('A database backup with this timestamp already exists.');
            }
            if (!@rename($temporaryZipPath, $zipPath)) {
                throw new RuntimeException('Unable to publish the completed backup ZIP atomically.');
            }
        } finally {
            if (is_file($temporaryZipPath)) {
                @unlink($temporaryZipPath);
            }
            if (is_file($manifestPath)) {
                @unlink($manifestPath);
            }
        }
    }

    /** @param list<array{name:string,path:string}> $entries */
    private function writeStoredZip(array $entries, string $zipPath): void
    {
        $handle = @fopen($zipPath, 'wb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Unable to create backup ZIP file: ' . $zipPath);
        }
        $offset = 0;
        $central = '';
        try {
            foreach ($entries as $entry) {
                $name = str_replace('\\', '/', (string)$entry['name']);
                $path = (string)$entry['path'];
                if (!$this->isSafeArchivePath($name) || !is_file($path)) {
                    throw new RuntimeException('Backup contains an invalid file entry.');
                }
                $size = (int)(filesize($path) ?: 0);
                if ($size < 0 || $size > 0xFFFFFFFF) {
                    throw new RuntimeException('Backup entry is too large for the built-in ZIP writer.');
                }
                $crcHex = hash_file('crc32b', $path);
                if (!is_string($crcHex)) {
                    throw new RuntimeException('Unable to checksum backup entry: ' . $name);
                }
                $crc = (int)hexdec($crcHex);
                $nameLength = strlen($name);
                $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLength, 0) . $name;
                $this->write($handle, $local);
                $input = @fopen($path, 'rb');
                if (!is_resource($input)) {
                    throw new RuntimeException('Unable to read backup entry: ' . $name);
                }
                try {
                    while (!feof($input)) {
                        $chunk = fread($input, 1024 * 1024);
                        if (!is_string($chunk)) {
                            throw new RuntimeException('Unable to read backup entry: ' . $name);
                        }
                        if ($chunk !== '') {
                            $this->write($handle, $chunk);
                        }
                    }
                } finally {
                    fclose($input);
                }
                $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLength, 0, 0, 0, 0, 0, $offset) . $name;
                $offset += strlen($local) + $size;
            }
            $this->write($handle, $central);
            $this->write($handle, pack('VvvvvVVv', 0x06054b50, 0, 0, count($entries), count($entries), strlen($central), $offset, 0));
        } finally {
            fclose($handle);
        }
    }

    /** @return list<array{name:string,path:string}> */
    private function secureFiles(): array
    {
        $root = $this->secureDirectory();
        if (!is_dir($root)) {
            throw new RuntimeException('The protected secure directory is unavailable.');
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->isLink()) {
                if ($file instanceof \SplFileInfo && $file->isLink()) {
                    throw new RuntimeException('The protected secure directory must not contain symbolic links.');
                }
                continue;
            }
            if (!$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            $relative = substr($path, strlen(rtrim($root, '\\/')) + 1);
            $name = 'secure/' . str_replace('\\', '/', $relative);
            if (!$this->isSafeArchivePath($name)) {
                throw new RuntimeException('The protected secure directory contains an invalid path.');
            }
            $files[] = ['name' => $name, 'path' => $path];
        }
        return $files;
    }

    private function verifyStoredSqlZip(string $zipPath, string $expectedSqlHash): void
    {
        $handle = @fopen($zipPath, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Unable to read the completed backup ZIP.');
        }

        try {
            $localHeaderContent = $this->readExactly(
                $handle,
                30,
                'The completed backup ZIP header is malformed.'
            );
            $localHeader = unpack(
                'Vsignature/vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed_size/Vuncompressed_size/vname_length/vextra_length',
                $localHeaderContent
            );
            if (!is_array($localHeader) || (int)($localHeader['signature'] ?? 0) !== 0x04034b50) {
                throw new RuntimeException('The completed backup ZIP header is malformed.');
            }

            $flags = (int)$localHeader['flags'];
            $method = (int)$localHeader['method'];
            $compressedSize = (int)$localHeader['compressed_size'];
            $uncompressedSize = (int)$localHeader['uncompressed_size'];
            $nameLength = (int)$localHeader['name_length'];
            $extraLength = (int)$localHeader['extra_length'];
            if (
                $flags !== 0
                || $method !== 0
                || $compressedSize < 0
                || $compressedSize !== $uncompressedSize
                || $nameLength <= 0
                || $extraLength < 0
            ) {
                throw new RuntimeException('The completed backup ZIP entry is malformed.');
            }

            $entryName = $this->readExactly(
                $handle,
                $nameLength,
                'The completed backup ZIP entry name is malformed.'
            );
            if (!str_ends_with(strtolower($entryName), '.sql')) {
                throw new RuntimeException('The completed backup ZIP does not contain a SQL dump.');
            }
            if ($extraLength > 0) {
                $this->readExactly(
                    $handle,
                    $extraLength,
                    'The completed backup ZIP entry metadata is malformed.'
                );
            }

            $shaContext = hash_init('sha256');
            $crcContext = hash_init('crc32b');
            $remaining = $compressedSize;
            while ($remaining > 0) {
                $chunk = fread($handle, min(1024 * 1024, $remaining));
                if (!is_string($chunk) || $chunk === '') {
                    throw new RuntimeException('The completed backup ZIP SQL entry is truncated.');
                }

                hash_update($shaContext, $chunk);
                hash_update($crcContext, $chunk);
                $remaining -= strlen($chunk);
            }

            $expectedCrc = sprintf('%08x', (int)$localHeader['crc']);
            $actualCrc = hash_final($crcContext);
            if (!hash_equals($expectedCrc, $actualCrc)) {
                throw new RuntimeException('The completed backup ZIP failed its integrity checksum.');
            }

            $actualHash = hash_final($shaContext);
            if (!hash_equals($expectedSqlHash, $actualHash)) {
                throw new RuntimeException('The completed backup ZIP did not match the SQL dump.');
            }

            $tail = stream_get_contents($handle);
            if (!is_string($tail) || strlen($tail) < 68) {
                throw new RuntimeException('The completed backup ZIP directory is malformed.');
            }

            $endOffset = strlen($tail) - 22;
            $endRecord = unpack(
                'Vsignature/vdisk/vcentral_disk/ventries_disk/ventries_total/Vcentral_size/Vcentral_offset/vcomment_length',
                substr($tail, $endOffset, 22)
            );
            if (
                !is_array($endRecord)
                || (int)($endRecord['signature'] ?? 0) !== 0x06054b50
                || (int)$endRecord['disk'] !== 0
                || (int)$endRecord['central_disk'] !== 0
                || (int)$endRecord['entries_disk'] !== 1
                || (int)$endRecord['entries_total'] !== 1
                || (int)$endRecord['comment_length'] !== 0
            ) {
                throw new RuntimeException('The completed backup ZIP directory is malformed.');
            }

            $centralHeader = unpack(
                'Vsignature/vversion_made/vversion_needed/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed_size/Vuncompressed_size/vname_length/vextra_length/vcomment_length/vdisk_start/vinternal_attributes/Vexternal_attributes/Vlocal_offset',
                substr($tail, 0, 46)
            );
            if (!is_array($centralHeader) || (int)($centralHeader['signature'] ?? 0) !== 0x02014b50) {
                throw new RuntimeException('The completed backup ZIP directory is malformed.');
            }

            $centralNameLength = (int)$centralHeader['name_length'];
            $centralExtraLength = (int)$centralHeader['extra_length'];
            $centralCommentLength = (int)$centralHeader['comment_length'];
            $expectedCentralSize = 46 + $centralNameLength + $centralExtraLength + $centralCommentLength;
            $expectedCentralOffset = 30 + $nameLength + $extraLength + $compressedSize;
            $centralEntryName = substr($tail, 46, $centralNameLength);
            if (
                (int)$endRecord['central_size'] !== $expectedCentralSize
                || (int)$endRecord['central_offset'] !== $expectedCentralOffset
                || $endOffset !== $expectedCentralSize
                || (int)$centralHeader['flags'] !== $flags
                || (int)$centralHeader['method'] !== $method
                || (int)$centralHeader['crc'] !== (int)$localHeader['crc']
                || (int)$centralHeader['compressed_size'] !== $compressedSize
                || (int)$centralHeader['uncompressed_size'] !== $uncompressedSize
                || (int)$centralHeader['local_offset'] !== 0
                || $centralEntryName !== $entryName
            ) {
                throw new RuntimeException('The completed backup ZIP directory is malformed.');
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return array{sql_entry: array{name:string,data_offset:int,data_size:int},entries: array<string,array{name:string,data_offset:int,data_size:int,sha256:string}>,includes_secure:bool,legacy:bool} */
    private function inspectBackupArchive(string $zipPath, ?\Closure $progress = null): array
    {
        try {
            return $this->inspectFullBackupArchive($zipPath);
        } catch (RuntimeException $exception) {
            $entry = $this->inspectStoredSqlZip($zipPath, $progress);
            return [
                'sql_entry' => $entry,
                'entries' => [$entry['name'] => $entry + ['sha256' => '']],
                'includes_secure' => false,
                'legacy' => true,
            ];
        }
    }

    /** @return array{sql_entry: array{name:string,data_offset:int,data_size:int},entries: array<string,array{name:string,data_offset:int,data_size:int,sha256:string}>,includes_secure:bool,legacy:bool} */
    private function inspectFullBackupArchive(string $zipPath): array
    {
        $handle = @fopen($zipPath, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('The selected backup file is empty or unreadable.');
        }
        $entries = [];
        try {
            while (true) {
                $signatureContent = fread($handle, 4);
                if ($signatureContent === false || $signatureContent === '') {
                    break;
                }
                $signature = unpack('Vsignature', $signatureContent);
                if (!is_array($signature) || (int)($signature['signature'] ?? 0) === 0x02014b50) {
                    break;
                }
                if ((int)($signature['signature'] ?? 0) !== 0x04034b50) {
                    throw new RuntimeException('The selected backup ZIP header is malformed.');
                }
                $header = unpack(
                    'vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed_size/Vuncompressed_size/vname_length/vextra_length',
                    $this->readExactly($handle, 26, 'The selected backup ZIP header is malformed.')
                );
                if (!is_array($header) || (int)$header['flags'] !== 0 || (int)$header['method'] !== 0 || (int)$header['compressed_size'] !== (int)$header['uncompressed_size']) {
                    throw new RuntimeException('The selected backup ZIP uses an unsupported compression method.');
                }
                $name = $this->readExactly($handle, (int)$header['name_length'], 'The selected backup ZIP entry name is malformed.');
                if (!$this->isSafeArchivePath($name) || isset($entries[$name])) {
                    throw new RuntimeException('The selected backup ZIP contains an invalid or duplicate entry.');
                }
                if ((int)$header['extra_length'] > 0) {
                    $this->readExactly($handle, (int)$header['extra_length'], 'The selected backup ZIP entry metadata is malformed.');
                }
                $offset = ftell($handle);
                if (!is_int($offset) || $offset < 0) {
                    throw new RuntimeException('The selected backup ZIP entry is malformed.');
                }
                $size = (int)$header['compressed_size'];
                $sha = hash_init('sha256');
                $crc = hash_init('crc32b');
                $remaining = $size;
                while ($remaining > 0) {
                    $chunk = fread($handle, min(1024 * 1024, $remaining));
                    if (!is_string($chunk) || $chunk === '') {
                        throw new RuntimeException('The selected backup ZIP entry is truncated.');
                    }
                    hash_update($sha, $chunk);
                    hash_update($crc, $chunk);
                    $remaining -= strlen($chunk);
                }
                if (!hash_equals(sprintf('%08x', (int)$header['crc']), hash_final($crc))) {
                    throw new RuntimeException('The selected backup ZIP failed its integrity checksum.');
                }
                $entries[$name] = ['name' => $name, 'data_offset' => $offset, 'data_size' => $size, 'sha256' => hash_final($sha)];
            }
        } finally {
            fclose($handle);
        }
        $manifestEntry = $entries['backup-manifest.json'] ?? null;
        if (!is_array($manifestEntry)) {
            throw new RuntimeException('The selected backup is a legacy SQL-only archive.');
        }
        $manifestContent = $this->readArchiveEntry($zipPath, $manifestEntry, 2 * 1024 * 1024);
        try {
            $manifest = json_decode($manifestContent, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('The selected backup manifest is invalid.');
        }
        if (!is_array($manifest) || ($manifest['format'] ?? '') !== 'eel_accounts_full_backup_v1' || !is_array($manifest['entries'] ?? null)) {
            throw new RuntimeException('The selected backup manifest is invalid.');
        }
        $declared = [];
        foreach ($manifest['entries'] as $manifestEntryRow) {
            if (!is_array($manifestEntryRow)) {
                throw new RuntimeException('The selected backup manifest is invalid.');
            }
            $name = (string)($manifestEntryRow['path'] ?? '');
            $hash = (string)($manifestEntryRow['sha256'] ?? '');
            $size = (int)($manifestEntryRow['size_bytes'] ?? -1);
            $entry = $entries[$name] ?? null;
            if (!$this->isSafeArchivePath($name) || !is_array($entry) || $hash === '' || $size !== (int)$entry['data_size'] || !hash_equals($hash, (string)$entry['sha256'])) {
                throw new RuntimeException('The selected backup manifest does not match its archive entries.');
            }
            $declared[$name] = true;
        }
        if (count($entries) !== count($declared) + 1) {
            throw new RuntimeException('The selected backup contains unexpected archive entries.');
        }
        $sqlEntries = array_values(array_filter($entries, static fn(array $entry): bool => str_starts_with((string)$entry['name'], 'database/') && str_ends_with(strtolower((string)$entry['name']), '.sql')));
        if (count($sqlEntries) !== 1) {
            throw new RuntimeException('The selected backup does not contain exactly one database SQL dump.');
        }
        foreach (array_keys($declared) as $name) {
            if (!str_starts_with($name, 'database/') && !str_starts_with($name, 'secure/')) {
                throw new RuntimeException('The selected backup contains an unexpected archive entry.');
            }
        }
        return ['sql_entry' => $sqlEntries[0], 'entries' => $entries, 'includes_secure' => true, 'legacy' => false];
    }

    /** @param array{data_offset:int,data_size:int} $entry */
    private function readArchiveEntry(string $zipPath, array $entry, int $maximumBytes = PHP_INT_MAX): string
    {
        if ((int)$entry['data_size'] > $maximumBytes) {
            throw new RuntimeException('The selected backup archive entry is too large.');
        }
        $handle = @fopen($zipPath, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('The selected backup file is empty or unreadable.');
        }
        try {
            if (fseek($handle, (int)$entry['data_offset']) !== 0) {
                throw new RuntimeException('The selected backup ZIP entry is malformed.');
            }
            return $this->readExactly($handle, (int)$entry['data_size'], 'The selected backup ZIP entry is truncated.');
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string,array{name:string,data_offset:int,data_size:int,sha256:string}> $entries */
    private function restoreSecureDirectory(string $zipPath, array $entries): void
    {
        $live = $this->secureDirectory();
        $parent = dirname($live);
        $stage = $parent . DIRECTORY_SEPARATOR . '.secure-restore-' . bin2hex(random_bytes(8));
        $previous = $parent . DIRECTORY_SEPARATOR . '.secure-before-restore-' . bin2hex(random_bytes(8));
        if (!@mkdir($stage, 0700, true)) {
            throw new RuntimeException('Unable to prepare protected configuration restore storage.');
        }
        try {
            foreach ($entries as $entry) {
                $name = (string)$entry['name'];
                if (!str_starts_with($name, 'secure/')) {
                    continue;
                }
                $relative = substr($name, strlen('secure/'));
                if ($relative === '' || !$this->isSafeArchivePath('secure/' . $relative)) {
                    throw new RuntimeException('The selected backup contains an invalid protected file path.');
                }
                $target = $stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $targetDirectory = dirname($target);
                if (!is_dir($targetDirectory) && !@mkdir($targetDirectory, 0700, true)) {
                    throw new RuntimeException('Unable to prepare protected configuration restore storage.');
                }
                $contents = $this->readArchiveEntry($zipPath, $entry);
                if (file_put_contents($target, $contents, LOCK_EX) === false || !hash_equals((string)$entry['sha256'], (string)hash_file('sha256', $target))) {
                    throw new RuntimeException('Unable to restore a protected configuration file.');
                }
                if (DIRECTORY_SEPARATOR !== '\\') {
                    @chmod($target, 0600);
                }
            }
            if (!is_dir($live) || !@rename($live, $previous)) {
                throw new RuntimeException('Unable to stage the current protected configuration for replacement.');
            }
            if (!@rename($stage, $live)) {
                @rename($previous, $live);
                throw new RuntimeException('Unable to replace the protected configuration directory.');
            }
            $this->removeDirectory($previous);
        } catch (Throwable $exception) {
            $this->removeDirectory($stage);
            throw $exception;
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $item->isDir() && !$item->isLink() ? @rmdir($path) : @unlink($path);
        }
        @rmdir($directory);
    }

    private function connect(): PDO
    {
        if ($this->connection instanceof PDO) {
            $this->configureDumpConnection($this->connection);

            return $this->connection;
        }

        $dsn = trim((string)($this->dbConfig['dsn'] ?? ''));
        if ($dsn === '') {
            throw new RuntimeException('Database DSN is not configured.');
        }

        try {
            $pdo = new PDO(
                $dsn,
                trim((string)($this->dbConfig['user'] ?? '')) !== '' ? (string)$this->dbConfig['user'] : null,
                trim((string)($this->dbConfig['pass'] ?? '')) !== '' ? (string)$this->dbConfig['pass'] : null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
            $this->configureDumpConnection($pdo);
            $this->connection = $pdo;

            return $pdo;
        } catch (PDOException $exception) {
            throw new RuntimeException('Database backup connection failed: ' . $exception->getMessage(), 0, $exception);
        }
    }

    private function configureDumpConnection(PDO $pdo): void
    {
        try {
            $pdo->exec('SET NAMES utf8mb4');
        } catch (Throwable) {
        }
    }

    private function databaseName(PDO $pdo): string
    {
        $connectedDatabase = $this->connectedDatabaseName($pdo);
        if ($connectedDatabase !== '') {
            return $connectedDatabase;
        }

        if (preg_match('/(?:^|;)dbname=([^;]+)/i', (string)($this->dbConfig['dsn'] ?? ''), $matches) === 1) {
            return (string)$matches[1];
        }

        return '';
    }

    private function connectedDatabaseName(PDO $pdo): string
    {
        try {
            $stmt = $pdo->query('SELECT DATABASE()');
            if ($stmt instanceof PDOStatement) {
                $databaseName = $stmt->fetchColumn();

                return is_string($databaseName) ? $databaseName : '';
            }
        } catch (Throwable) {
        }

        return '';
    }

    private function requireConnectedDatabaseName(PDO $pdo): string
    {
        $databaseName = $this->connectedDatabaseName($pdo);
        if ($databaseName === '') {
            throw new RuntimeException('The connected database name could not be verified.');
        }
        $this->assertValidDatabaseIdentity($databaseName, 'connected database');

        return $databaseName;
    }

    private function assertExpectedRestoreTarget(PDO $pdo, string $expectedTargetDatabase): void
    {
        $this->assertValidDatabaseIdentity($expectedTargetDatabase, 'expected restore target database');

        $actualDatabase = $this->connectedDatabaseName($pdo);
        if ($actualDatabase !== '') {
            $this->assertValidDatabaseIdentity($actualDatabase, 'connected database');
        }
        if ($actualDatabase === '' || !hash_equals($expectedTargetDatabase, $actualDatabase)) {
            throw new RuntimeException(
                'Database restore target mismatch: expected '
                . $this->databaseIdentityForMessage($expectedTargetDatabase)
                . ', connected to '
                . ($actualDatabase !== '' ? $this->databaseIdentityForMessage($actualDatabase) : 'an unknown database')
                . '.'
            );
        }
    }

    private function databaseNameFromSqlDump(string $sql): string
    {
        $header = substr($sql, 0, 4096);
        if (preg_match('/^-- Database-Name-Base64:\s*([A-Za-z0-9+\/]*={0,2})\s*$/mi', $header, $matches) === 1) {
            $databaseName = base64_decode((string)$matches[1], true);
            if (!is_string($databaseName)) {
                throw new RuntimeException('The selected backup records an invalid source database identity.');
            }
            $this->assertValidDatabaseIdentity($databaseName, 'backup source database');

            return $databaseName;
        }

        if (preg_match('/^-- Database:[ \t](.*)$/mi', $header, $matches) !== 1) {
            return '';
        }

        $databaseName = rtrim((string)$matches[1], " \t\r");
        $this->assertValidDatabaseIdentity($databaseName, 'backup source database');

        return $databaseName;
    }

    private function assertExpectedBackupSource(string $sourceDatabase, string $expectedSourceDatabase): void
    {
        $this->assertValidDatabaseIdentity($expectedSourceDatabase, 'expected backup source database');
        if ($sourceDatabase !== '') {
            $this->assertValidDatabaseIdentity($sourceDatabase, 'backup source database');
        }
        if ($sourceDatabase === '' || !hash_equals($expectedSourceDatabase, $sourceDatabase)) {
            throw new RuntimeException(
                'Database backup source mismatch: expected '
                . $this->databaseIdentityForMessage($expectedSourceDatabase)
                . ', backup records '
                . ($sourceDatabase !== '' ? $this->databaseIdentityForMessage($sourceDatabase) : 'an unknown database')
                . '.'
            );
        }
    }

    private function assertValidDatabaseIdentity(string $databaseName, string $label): void
    {
        if ($databaseName === '') {
            throw new RuntimeException('The ' . $label . ' name is required.');
        }
        if (str_contains($databaseName, "\0") || !$this->isValidUtf8($databaseName)) {
            throw new RuntimeException('The ' . $label . ' name is invalid.');
        }
    }

    private function databaseNameForComment(string $databaseName): string
    {
        return str_replace(["\r", "\n"], ['\\r', '\\n'], $databaseName);
    }

    private function databaseIdentityForMessage(string $databaseName): string
    {
        $encoded = json_encode($databaseName, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '[invalid database name]';
    }

    private function ensureBackupDirectory(): void
    {
        if (!is_dir($this->backupDirectory) && !@mkdir($this->backupDirectory, 0700, true) && !is_dir($this->backupDirectory)) {
            throw new RuntimeException('Unable to create SQL dump directory: ' . $this->backupDirectory);
        }

        if (DIRECTORY_SEPARATOR !== '\\') {
            @chmod($this->backupDirectory, 0700);
        }

        if (!is_writable($this->backupDirectory)) {
            throw new RuntimeException('SQL dump directory is not writable: ' . $this->backupDirectory);
        }
    }

    private function resolveBackupPath(int $companyId, string $filename, string $scope): string
    {
        $filename = trim(str_replace('\\', '/', $filename));
        if ($filename === '' || basename($filename) !== $filename || !str_ends_with(strtolower($filename), '.sql.zip')) {
            throw new RuntimeException('Select a valid SQL ZIP backup file to restore.');
        }

        $scope = trim($scope);
        if (!in_array($scope, ['company', 'legacy'], true)) {
            throw new RuntimeException('Select a valid backup storage location.');
        }
        if ($scope === 'legacy' && $this->backupDirectoryOverride !== null) {
            throw new RuntimeException('Legacy backups are not available in this environment.');
        }
        $directory = realpath($scope === 'legacy' ? $this->defaultBackupDirectory() : $this->companyBackupDirectory($companyId));
        if ($directory === false || !is_dir($directory)) {
            throw new RuntimeException('The SQL dump directory is not available.');
        }

        $path = realpath($directory . DIRECTORY_SEPARATOR . $filename);
        if ($path === false || !is_file($path)) {
            throw new RuntimeException('The selected backup file was not found.');
        }

        $directoryPrefix = rtrim($directory, '\\/') . DIRECTORY_SEPARATOR;
        if (!str_starts_with($path, $directoryPrefix)) {
            throw new RuntimeException('The selected backup file is outside the SQL dump directory.');
        }

        return $path;
    }

    /** @return list<string> */
    private function backupFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $files = glob(rtrim($directory, '\\/') . DIRECTORY_SEPARATOR . '*.sql.zip');
        return $files === false ? [] : array_values(array_filter($files, 'is_file'));
    }

    private function companyBackupDirectory(int $companyId): string
    {
        if ($this->backupDirectoryOverride !== null) {
            return $this->backupDirectoryOverride;
        }
        if ($companyId <= 0) {
            throw new RuntimeException('Select a company with a registered number before creating or restoring backups.');
        }
        $company = (new \eel_accounts\Repository\CompanyRepository())->fetchCompanyDetails($companyId);
        $number = preg_replace('/[^A-Za-z0-9]/', '', trim((string)($company['company_number'] ?? '')));
        if ($number === '') {
            throw new RuntimeException('The selected company must have a registered company number before backups can be used.');
        }
        $uploads = \eel_accounts\Store\AccountingConfigurationStore::uploads();
        $base = trim((string)($uploads['upload_base_dir'] ?? ''));
        if ($base === '') {
            throw new RuntimeException('The configured upload base directory is required for backups.');
        }
        return rtrim($base, '\\/') . DIRECTORY_SEPARATOR . $number . DIRECTORY_SEPARATOR . 'backups';
    }

    private function secureDirectory(): string
    {
        if ($this->secureDirectoryOverride !== null) {
            return rtrim($this->secureDirectoryOverride, '\\/');
        }
        return rtrim(defined('APP_CONFIG') ? (string)APP_CONFIG : rtrim((string)PROJECT_ROOT, '\\/') . DIRECTORY_SEPARATOR . 'secure', '\\/');
    }

    private function isSafeArchivePath(string $path): bool
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0") || str_contains($path, '../') || str_starts_with($path, '..')) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    private function extractSqlFromBackup(string $zipPath): string
    {
        $entry = $this->inspectStoredSqlZip($zipPath);
        $handle = @fopen($zipPath, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('The selected backup file is empty or unreadable.');
        }

        try {
            if (fseek($handle, (int)$entry['data_offset']) !== 0) {
                throw new RuntimeException('The selected backup ZIP entry is malformed.');
            }
            $sql = $this->readExactly(
                $handle,
                (int)$entry['data_size'],
                'The selected backup ZIP SQL entry is truncated.'
            );
        } finally {
            fclose($handle);
        }

        if (trim($sql) === '') {
            throw new RuntimeException('The selected backup SQL dump is empty.');
        }

        return $sql;
    }

    /**
     * Inspect the single, stored (uncompressed) SQL entry without loading it into memory.
     *
     * @return array{name: string, data_offset: int, data_size: int}
     */
    private function inspectStoredSqlZip(string $zipPath, ?\Closure $progress = null): array
    {
        $handle = @fopen($zipPath, 'rb');
        $fileSize = is_file($zipPath) ? (int)(filesize($zipPath) ?: 0) : 0;
        if (!is_resource($handle) || $fileSize <= 0) {
            throw new RuntimeException('The selected backup file is empty or unreadable.');
        }

        try {
            $localHeader = unpack(
                'Vsignature/vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed_size/Vuncompressed_size/vname_length/vextra_length',
                $this->readExactly($handle, 30, 'The selected backup ZIP header is malformed.')
            );
            if (!is_array($localHeader) || (int)($localHeader['signature'] ?? 0) !== 0x04034b50) {
                throw new RuntimeException('The selected backup ZIP header is malformed.');
            }

            $flags = (int)$localHeader['flags'];
            $method = (int)$localHeader['method'];
            $compressedSize = (int)$localHeader['compressed_size'];
            $uncompressedSize = (int)$localHeader['uncompressed_size'];
            $nameLength = (int)$localHeader['name_length'];
            $extraLength = (int)$localHeader['extra_length'];
            if (
                $flags !== 0
                || $method !== 0
                || $compressedSize <= 0
                || $compressedSize !== $uncompressedSize
                || $nameLength <= 0
                || $extraLength < 0
            ) {
                throw new RuntimeException('The selected backup ZIP uses an unsupported compression method.');
            }

            $entryName = $this->readExactly($handle, $nameLength, 'The selected backup ZIP entry name is malformed.');
            if (!str_ends_with(strtolower($entryName), '.sql')) {
                throw new RuntimeException('The selected backup ZIP does not contain a SQL dump.');
            }
            if ($extraLength > 0) {
                $this->readExactly($handle, $extraLength, 'The selected backup ZIP entry metadata is malformed.');
            }

            $dataOffset = 30 + $nameLength + $extraLength;
            $dataEnd = $dataOffset + $compressedSize;
            if ($dataEnd >= $fileSize) {
                throw new RuntimeException('The selected backup ZIP entry is malformed.');
            }

            $crcContext = hash_init('crc32b');
            $hasSqlContent = false;
            $remaining = $compressedSize;
            $lastProgressPercent = 10;
            while ($remaining > 0) {
                $chunk = fread($handle, min(1024 * 1024, $remaining));
                if (!is_string($chunk) || $chunk === '') {
                    throw new RuntimeException('The selected backup ZIP SQL entry is truncated.');
                }
                hash_update($crcContext, $chunk);
                $hasSqlContent = $hasSqlContent || preg_match('/\S/', $chunk) === 1;
                $remaining -= strlen($chunk);
                $percent = 10 + (int)floor((($compressedSize - $remaining) / $compressedSize) * 15);
                if ($percent >= $lastProgressPercent + 5) {
                    $progress?->__invoke('Verifying the database backup integrity…', $percent);
                    $lastProgressPercent = $percent;
                }
            }
            if (!$hasSqlContent) {
                throw new RuntimeException('The selected backup SQL dump is empty.');
            }

            $expectedCrc = sprintf('%08x', (int)$localHeader['crc']);
            if (!hash_equals($expectedCrc, hash_final($crcContext))) {
                throw new RuntimeException('The selected backup ZIP failed its integrity checksum.');
            }

            $centralHeaderContent = $this->readExactly($handle, 46, 'The selected backup ZIP directory is malformed.');
            $centralHeader = unpack(
                'Vsignature/vversion_made/vversion_needed/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed_size/Vuncompressed_size/vname_length/vextra_length/vcomment_length/vdisk_start/vinternal_attributes/Vexternal_attributes/Vlocal_offset',
                $centralHeaderContent
            );
            if (!is_array($centralHeader) || (int)($centralHeader['signature'] ?? 0) !== 0x02014b50) {
                throw new RuntimeException('The selected backup ZIP directory is malformed.');
            }

            $centralNameLength = (int)$centralHeader['name_length'];
            $centralExtraLength = (int)$centralHeader['extra_length'];
            $centralCommentLength = (int)$centralHeader['comment_length'];
            $centralSize = 46 + $centralNameLength + $centralExtraLength + $centralCommentLength;
            $centralEntryName = $this->readExactly(
                $handle,
                $centralNameLength,
                'The selected backup ZIP directory is malformed.'
            );
            if ($centralExtraLength + $centralCommentLength > 0) {
                $this->readExactly(
                    $handle,
                    $centralExtraLength + $centralCommentLength,
                    'The selected backup ZIP directory is malformed.'
                );
            }

            $endRecord = unpack(
                'Vsignature/vdisk/vcentral_disk/ventries_disk/ventries_total/Vcentral_size/Vcentral_offset/vcomment_length',
                $this->readExactly($handle, 22, 'The selected backup ZIP directory is malformed.')
            );
            if (
                !is_array($endRecord)
                || (int)($endRecord['signature'] ?? 0) !== 0x06054b50
                || (int)$endRecord['disk'] !== 0
                || (int)$endRecord['central_disk'] !== 0
                || (int)$endRecord['entries_disk'] !== 1
                || (int)$endRecord['entries_total'] !== 1
                || (int)$endRecord['comment_length'] !== 0
                || $fileSize !== $dataEnd + $centralSize + 22
                || (int)$endRecord['central_size'] !== $centralSize
                || (int)$endRecord['central_offset'] !== $dataEnd
                || (int)$centralHeader['flags'] !== $flags
                || (int)$centralHeader['method'] !== $method
                || (int)$centralHeader['crc'] !== (int)$localHeader['crc']
                || (int)$centralHeader['compressed_size'] !== $compressedSize
                || (int)$centralHeader['uncompressed_size'] !== $uncompressedSize
                || (int)$centralHeader['local_offset'] !== 0
                || $centralEntryName !== $entryName
            ) {
                throw new RuntimeException('The selected backup ZIP directory is malformed.');
            }

            return [
                'name' => $entryName,
                'data_offset' => $dataOffset,
                'data_size' => $compressedSize,
            ];
        } finally {
            fclose($handle);
        }
    }

    /** @param array{name: string, data_offset: int, data_size: int} $entry */
    private function readStoredSqlPrefix(string $zipPath, array $entry): string
    {
        $handle = @fopen($zipPath, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('The selected backup file is empty or unreadable.');
        }

        try {
            if (fseek($handle, (int)$entry['data_offset']) !== 0) {
                throw new RuntimeException('The selected backup ZIP entry is malformed.');
            }

            return $this->readExactly(
                $handle,
                min(4096, (int)$entry['data_size']),
                'The selected backup ZIP SQL entry is truncated.'
            );
        } finally {
            fclose($handle);
        }
    }

    private function backupTrigger(string $backupPath): string
    {
        try {
            $archive = $this->inspectBackupArchive($backupPath);
            $entry = $archive['sql_entry'];
            $header = $this->readStoredSqlPrefix($backupPath, $entry);
            if (preg_match('/^-- Trigger:[ \t]*([^\r\n]+)[ \t]*$/mi', $header, $matches) !== 1) {
                return self::TRIGGER_UNKNOWN;
            }

            return $this->normaliseTrigger((string)$matches[1], self::TRIGGER_UNKNOWN);
        } catch (Throwable) {
            return self::TRIGGER_UNKNOWN;
        }
    }

    private function normaliseTrigger(string $trigger, string $fallback = self::TRIGGER_MANUAL): string
    {
        $trigger = trim(str_replace(["\r", "\n"], ' ', $trigger));
        if ($trigger === '') {
            return $fallback;
        }

        return substr($trigger, 0, 255);
    }

    /** @param array{name: string, data_offset: int, data_size: int} $entry */
    private function executeStoredSqlZip(PDO $pdo, string $zipPath, array $entry, ?\Closure $progress = null): int
    {
        $handle = @fopen($zipPath, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('The selected backup file is empty or unreadable.');
        }

        $buffer = '';
        $pending = '';
        $quote = null;
        $lineComment = false;
        $blockComment = false;
        $executed = 0;
        $emitStatement = function () use (&$buffer, &$executed, $pdo): void {
            $statement = trim($buffer);
            $buffer = '';
            if ($statement === '' || $this->shouldSkipRestoreStatement($statement)) {
                return;
            }

            $pdo->exec($statement);
            $executed++;
        };
        $consume = function (string $content, bool $final) use (
            &$buffer,
            &$pending,
            &$quote,
            &$lineComment,
            &$blockComment,
            $emitStatement
        ): void {
            $length = strlen($content);
            for ($index = 0; $index < $length; $index++) {
                $char = $content[$index];
                $next = $index + 1 < $length ? $content[$index + 1] : '';

                if ($lineComment) {
                    $buffer .= $char;
                    if ($char === "\n") {
                        $lineComment = false;
                    }
                    continue;
                }

                if ($blockComment) {
                    if ($char === '*' && $next === '' && !$final) {
                        $pending = '*';
                        return;
                    }
                    $buffer .= $char;
                    if ($char === '*' && $next === '/') {
                        $buffer .= $next;
                        $index++;
                        $blockComment = false;
                    }
                    continue;
                }

                if ($quote !== null) {
                    if ($char === '\\' && $next === '' && !$final) {
                        $pending = '\\';
                        return;
                    }
                    $buffer .= $char;
                    if ($char === '\\' && $next !== '') {
                        $buffer .= $next;
                        $index++;
                        continue;
                    }
                    if ($char === $quote) {
                        $quote = null;
                    }
                    continue;
                }

                if ($char === '-' && $next === '' && !$final) {
                    $pending = '-';
                    return;
                }
                if (($char === '-' && $next === '-' && ($index + 2 >= $length || preg_match('/\s/', $content[$index + 2]) === 1)) || $char === '#') {
                    $lineComment = true;
                    $buffer .= $char;
                    if ($char === '-') {
                        $buffer .= $next;
                        $index++;
                    }
                    continue;
                }

                if ($char === '/' && $next === '' && !$final) {
                    $pending = '/';
                    return;
                }
                if ($char === '/' && $next === '*') {
                    $blockComment = true;
                    $buffer .= $char . $next;
                    $index++;
                    continue;
                }

                if ($char === "'" || $char === '"' || $char === '`') {
                    $quote = $char;
                    $buffer .= $char;
                    continue;
                }

                if ($char === ';') {
                    $emitStatement();
                    continue;
                }

                $buffer .= $char;
            }
        };

        try {
            if (fseek($handle, (int)$entry['data_offset']) !== 0) {
                throw new RuntimeException('The selected backup ZIP entry is malformed.');
            }

            $remaining = (int)$entry['data_size'];
            $totalSize = $remaining;
            $lastProgressPercent = 35;
            while ($remaining > 0) {
                $chunk = fread($handle, min(1024 * 1024, $remaining));
                if (!is_string($chunk) || $chunk === '') {
                    throw new RuntimeException('The selected backup ZIP SQL entry is truncated.');
                }
                $remaining -= strlen($chunk);
                $content = $pending . $chunk;
                $pending = '';
                $consume($content, $remaining === 0);

                $processedSize = $totalSize - $remaining;
                $percent = 35 + (int)floor(($processedSize / $totalSize) * 60);
                if ($percent >= $lastProgressPercent + 5 || $remaining === 0) {
                    $progress?->__invoke(
                        'Restoring database SQL… ' . $percent . '% — '
                        . $this->formatBackupByteCount($processedSize) . ' of '
                        . $this->formatBackupByteCount($totalSize) . ' read; '
                        . number_format($executed) . ' '
                        . ($executed === 1 ? 'statement' : 'statements') . ' applied.',
                        $percent
                    );
                    $lastProgressPercent = $percent;
                }
            }
            if ($pending !== '') {
                $content = $pending;
                $pending = '';
                $consume($content, true);
            }

            $emitStatement();
        } finally {
            fclose($handle);
        }

        return $executed;
    }

    private function formatBackupByteCount(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return number_format($bytes / (1024 * 1024 * 1024), 1) . ' GB';
        }
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }

        return number_format($bytes) . ' bytes';
    }

    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $quote = null;
        $lineComment = false;
        $blockComment = false;

        for ($index = 0; $index < $length; $index++) {
            $char = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : '';

            if ($lineComment) {
                $buffer .= $char;
                if ($char === "\n") {
                    $lineComment = false;
                }
                continue;
            }

            if ($blockComment) {
                $buffer .= $char;
                if ($char === '*' && $next === '/') {
                    $buffer .= $next;
                    $index++;
                    $blockComment = false;
                }
                continue;
            }

            if ($quote !== null) {
                $buffer .= $char;
                if ($char === '\\' && $next !== '') {
                    $buffer .= $next;
                    $index++;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if (($char === '-' && $next === '-' && ($index + 2 >= $length || preg_match('/\s/', $sql[$index + 2]) === 1)) || $char === '#') {
                $lineComment = true;
                $buffer .= $char;
                if ($char === '-') {
                    $buffer .= $next;
                    $index++;
                }
                continue;
            }

            if ($char === '/' && $next === '*') {
                $blockComment = true;
                $buffer .= $char . $next;
                $index++;
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }

            if ($char === ';') {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $statement = trim($buffer);
        if ($statement !== '') {
            $statements[] = $statement;
        }

        return array_values(array_filter($statements, static fn(string $statement): bool => trim($statement) !== ''));
    }

    private function shouldSkipRestoreStatement(string $statement): bool
    {
        $statement = $this->statementWithoutLeadingComments($statement);
        if ($statement === '') {
            return true;
        }

        return preg_match('/^SET\s+NAMES\b/i', $statement) === 1;
    }

    private function statementWithoutLeadingComments(string $statement): string
    {
        $statement = trim($statement);

        do {
            $previous = $statement;
            $statement = preg_replace('/^\s*--[^\r\n]*(?:\r\n|\r|\n|$)/', '', $statement) ?? $statement;
            $statement = preg_replace('/^\s*#[^\r\n]*(?:\r\n|\r|\n|$)/', '', $statement) ?? $statement;
            $statement = preg_replace('/^\s*\/\*.*?\*\//s', '', $statement) ?? $statement;
            $statement = trim($statement);
        } while ($statement !== $previous);

        return $statement;
    }

    private function write(mixed $handle, string $content): void
    {
        $length = strlen($content);
        $offset = 0;

        while ($offset < $length) {
            $written = @fwrite($handle, substr($content, $offset));
            if (!is_int($written) || $written <= 0) {
                throw new RuntimeException('Unable to write SQL dump content.');
            }

            $offset += $written;
        }
    }

    private function readExactly(mixed $handle, int $length, string $errorMessage): string
    {
        $content = '';

        while (strlen($content) < $length) {
            $chunk = fread($handle, $length - strlen($content));
            if (!is_string($chunk) || $chunk === '') {
                throw new RuntimeException($errorMessage);
            }
            $content .= $chunk;
        }

        return $content;
    }

    private function sqlLiteral(mixed $value, string $tableName = '', string $columnName = '', string $rowKey = ''): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return $this->mysqlStringLiteral((string)$value, $tableName, $columnName, $rowKey);
    }

    private function mysqlStringLiteral(string $value, string $tableName = '', string $columnName = '', string $rowKey = ''): string
    {
        $value = $this->normaliseDumpString($value, $tableName, $columnName, $rowKey);

        return "'" . strtr($value, [
            "\\" => "\\\\",
            "\0" => "\\0",
            "\n" => "\\n",
            "\r" => "\\r",
            "'" => "\\'",
            "\x1a" => "\\Z",
        ]) . "'";
    }

    private function normaliseDumpString(string $value, string $tableName = '', string $columnName = '', string $rowKey = ''): string
    {
        if ($this->isValidUtf8($value)) {
            return $value;
        }

        $converted = function_exists('mb_convert_encoding')
            ? mb_convert_encoding($value, 'UTF-8', 'Windows-1252')
            : false;

        if (is_string($converted) && $this->isValidUtf8($converted)) {
            return $converted;
        }

        $context = array_filter([
            $tableName !== '' ? 'table ' . $tableName : '',
            $columnName !== '' ? 'column ' . $columnName : '',
            $rowKey !== '' ? 'row id ' . $rowKey : '',
        ]);

        throw new RuntimeException(
            'Database backup cannot safely export a non-UTF-8 text value'
            . ($context !== [] ? ' from ' . implode(', ', $context) : '')
            . '.'
        );
    }

    private function isValidUtf8(string $value): bool
    {
        if (function_exists('mb_check_encoding')) {
            return mb_check_encoding($value, 'UTF-8');
        }

        return preg_match('//u', $value) === 1;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function safeFilename(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim($value)) ?? '';
        $safe = trim($safe, '_');

        return $safe !== '' ? $safe : 'database';
    }

    private function defaultBackupDirectory(): string
    {
        $root = defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 5) . DIRECTORY_SEPARATOR;

        return rtrim($root, '\\/') . DIRECTORY_SEPARATOR . 'sqldump';
    }
}
