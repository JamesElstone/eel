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
    private array $dbConfig;
    private string $backupDirectory;

    public function __construct(?array $dbConfig = null, ?string $backupDirectory = null)
    {
        $this->dbConfig = $dbConfig ?? (array)\AppConfigurationStore::get('db', []);
        $this->backupDirectory = $backupDirectory ?? $this->defaultBackupDirectory();
    }

    public function fetchBackupStatus(): array
    {
        $directoryExists = is_dir($this->backupDirectory);

        return [
            'directory' => $this->backupDirectory,
            'directory_exists' => $directoryExists,
            'directory_writable' => $directoryExists && is_writable($this->backupDirectory),
            'zip_available' => true,
            'recent_backups' => array_slice($this->fetchAvailableBackups(), 0, 5),
        ];
    }

    public function fetchAvailableBackups(): array
    {
        if (!is_dir($this->backupDirectory)) {
            return [];
        }

        $files = glob($this->backupDirectory . DIRECTORY_SEPARATOR . '*.sql.zip');
        if ($files === false) {
            return [];
        }

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
            $backups[] = [
                'filename' => $filename,
                'path' => $file,
                'restore_key' => hash('sha256', $filename),
                'size_bytes' => (int)(filesize($file) ?: 0),
                'created_at' => date('Y-m-d H:i:s', (int)(filemtime($file) ?: time())),
            ];
        }

        return $backups;
    }

    public function createBackup(): array
    {
        $this->ensureBackupDirectory();
        $pdo = $this->connect();
        $databaseName = $this->databaseName($pdo);
        $timestamp = (new DateTimeImmutable())->format('Ymd_His');
        $baseName = $this->safeFilename($databaseName !== '' ? $databaseName : 'database') . '_' . $timestamp;
        $sqlPath = $this->backupDirectory . DIRECTORY_SEPARATOR . $baseName . '.sql';
        $zipPath = $this->backupDirectory . DIRECTORY_SEPARATOR . $baseName . '.sql.zip';

        try {
            $tableCount = $this->writeSqlDump($pdo, $sqlPath, $databaseName);
            $this->zipSqlDump($sqlPath, $zipPath, basename($sqlPath));
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
        ];
    }

    public function restoreBackup(string $filename): array
    {
        $backupPath = $this->resolveBackupPath($filename);
        $sql = $this->extractSqlFromBackup($backupPath);
        $statements = $this->splitSqlStatements($sql);
        if ($statements === []) {
            throw new RuntimeException('The selected backup does not contain SQL statements to restore.');
        }

        $pdo = $this->connect();
        $executed = 0;

        foreach ($statements as $statement) {
            if ($this->shouldSkipRestoreStatement($statement)) {
                continue;
            }

            $pdo->exec($statement);
            $executed++;
        }

        clearstatcache(true, $backupPath);

        return [
            'file' => $backupPath,
            'filename' => basename($backupPath),
            'directory' => $this->backupDirectory,
            'size_bytes' => is_file($backupPath) ? (int)filesize($backupPath) : 0,
            'statement_count' => $executed,
            'restored_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];
    }

    public function backupFileForDownload(string $filename): array
    {
        $backupPath = $this->resolveBackupPath($filename);

        return [
            'file' => $backupPath,
            'filename' => basename($backupPath),
            'size_bytes' => is_file($backupPath) ? (int)filesize($backupPath) : 0,
        ];
    }

    private function writeSqlDump(PDO $pdo, string $sqlPath, string $databaseName): int
    {
        $handle = @fopen($sqlPath, 'wb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Unable to create SQL dump file: ' . $sqlPath);
        }

        $tables = $this->tableNames($pdo, $databaseName);

        try {
            $this->write($handle, "-- EEL Accounts database backup\n");
            $this->write($handle, '-- Created: ' . (new DateTimeImmutable())->format('Y-m-d H:i:s') . "\n");
            $this->write($handle, '-- Database: ' . ($databaseName !== '' ? $databaseName : 'unknown') . "\n\n");
            $this->write($handle, "SET NAMES utf8mb4;\n");
            $this->write($handle, "SET FOREIGN_KEY_CHECKS=0;\n");
            $this->write($handle, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

            foreach ($tables as $tableName) {
                $this->writeTable($pdo, $handle, $tableName);
            }

            $this->write($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($handle);
        }

        return count($tables);
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

    private function connect(): PDO
    {
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
        try {
            $stmt = $pdo->query('SELECT DATABASE()');
            if ($stmt instanceof PDOStatement) {
                return $this->safeIdentifierText((string)$stmt->fetchColumn());
            }
        } catch (Throwable) {
        }

        if (preg_match('/(?:^|;)dbname=([^;]+)/i', (string)($this->dbConfig['dsn'] ?? ''), $matches) === 1) {
            return $this->safeIdentifierText($matches[1]);
        }

        return '';
    }

    private function ensureBackupDirectory(): void
    {
        if (!is_dir($this->backupDirectory) && !@mkdir($this->backupDirectory, 0755, true) && !is_dir($this->backupDirectory)) {
            throw new RuntimeException('Unable to create SQL dump directory: ' . $this->backupDirectory);
        }

        if (!is_writable($this->backupDirectory)) {
            throw new RuntimeException('SQL dump directory is not writable: ' . $this->backupDirectory);
        }
    }

    private function resolveBackupPath(string $filename): string
    {
        $filename = trim(str_replace('\\', '/', $filename));
        if ($filename === '' || basename($filename) !== $filename || !str_ends_with(strtolower($filename), '.sql.zip')) {
            throw new RuntimeException('Select a valid SQL ZIP backup file to restore.');
        }

        $directory = realpath($this->backupDirectory);
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

    private function extractSqlFromBackup(string $zipPath): string
    {
        $content = @file_get_contents($zipPath);
        if (!is_string($content) || $content === '') {
            throw new RuntimeException('The selected backup file is empty or unreadable.');
        }

        $offset = 0;
        $entries = [];
        while (($offset + 30) <= strlen($content) && substr($content, $offset, 4) === "PK\x03\x04") {
            $header = unpack('Vsignature/vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed_size/Vuncompressed_size/vname_length/vextra_length', substr($content, $offset, 30));
            if (!is_array($header)) {
                throw new RuntimeException('The selected backup ZIP header is malformed.');
            }

            $method = (int)$header['method'];
            $compressedSize = (int)$header['compressed_size'];
            $uncompressedSize = (int)$header['uncompressed_size'];
            $nameLength = (int)$header['name_length'];
            $extraLength = (int)$header['extra_length'];
            $dataOffset = $offset + 30 + $nameLength + $extraLength;
            $dataEnd = $dataOffset + $compressedSize;

            if ($nameLength <= 0 || $compressedSize < 0 || $dataEnd > strlen($content)) {
                throw new RuntimeException('The selected backup ZIP entry is malformed.');
            }

            $entryName = substr($content, $offset + 30, $nameLength);
            $entryData = substr($content, $dataOffset, $compressedSize);
            if ($method !== 0 || strlen($entryData) !== $uncompressedSize) {
                throw new RuntimeException('The selected backup ZIP uses an unsupported compression method.');
            }

            $entries[] = [
                'name' => $entryName,
                'data' => $entryData,
            ];

            $offset = $dataEnd;
        }

        if (count($entries) !== 1) {
            throw new RuntimeException('The selected backup ZIP must contain exactly one SQL file.');
        }

        $entry = $entries[0];
        if (!str_ends_with(strtolower((string)$entry['name']), '.sql')) {
            throw new RuntimeException('The selected backup ZIP does not contain a SQL dump.');
        }

        $sql = (string)$entry['data'];
        if (trim($sql) === '') {
            throw new RuntimeException('The selected backup SQL dump is empty.');
        }

        return $sql;
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
        if (@fwrite($handle, $content) === false) {
            throw new RuntimeException('Unable to write SQL dump content.');
        }
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

    private function safeIdentifierText(string $value): string
    {
        return trim(preg_replace('/[^A-Za-z0-9_ -]+/', '', $value) ?? '');
    }

    private function defaultBackupDirectory(): string
    {
        $root = defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 5) . DIRECTORY_SEPARATOR;

        return rtrim($root, '\\/') . DIRECTORY_SEPARATOR . 'sqldump';
    }
}
