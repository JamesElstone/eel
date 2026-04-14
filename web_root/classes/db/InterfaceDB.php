<?php
declare(strict_types=1);

final class InterfaceDB
{
    public const TABLE_ROW_COUNT_ERROR = -1;
    public const TABLE_ROW_COUNT_TABLE_MISSING = -2;

    private static ?PDO $connection = null;

    private static function connection(): PDO
    {
        if (!self::$connection instanceof PDO) {
            self::$connection = PdoDB::connectionForInterfaceDB();
        }

        return self::$connection;
    }

    public static function prepare(string $sql, array $options = []): PDOStatement|false
    {
        return self::prepareOn(self::connection(), $sql, $options);
    }

    public static function prepareOn(PDO $pdo, string $sql, array $options = []): PDOStatement|false
    {
        return PdoDB::prepareOn($pdo, $sql, $options);
    }

    public static function query(string $sql, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($fetchMode === null) {
            return self::connection()->query($sql);
        }

        return self::connection()->query($sql, $fetchMode, ...$fetchModeArgs);
    }

    public static function queryOn(PDO $pdo, string $sql, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($fetchMode === null) {
            return $pdo->query($sql);
        }

        return $pdo->query($sql, $fetchMode, ...$fetchModeArgs);
    }

    public static function beginTransaction(): bool
    {
        return self::connection()->beginTransaction();
    }

    public static function commit(): bool
    {
        return self::connection()->commit();
    }

    public static function rollBack(): bool
    {
        if (!self::connection()->inTransaction()) {
            return false;
        }

        return self::connection()->rollBack();
    }

    public static function inTransaction(): bool
    {
        return self::connection()->inTransaction();
    }

    public static function transaction(callable $callback): mixed
    {
        $ownsTransaction = !self::inTransaction();

        if ($ownsTransaction) {
            self::beginTransaction();
        }

        try {
            $result = $callback();

            if ($ownsTransaction) {
                self::commit();
            }

            return $result;
        } catch (Throwable $throwable) {
            if ($ownsTransaction) {
                self::rollBack();
            }

            throw $throwable;
        }
    }

    public static function driverName(): string
    {
        return self::driverNameOn(self::connection());
    }

    public static function driverNameOn(PDO $pdo): string
    {
        return strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    public static function isOdbcDriver(): bool
    {
        return self::driverName() === 'odbc';
    }

    public static function isOdbcDriverOn(PDO $pdo): bool
    {
        return self::driverNameOn($pdo) === 'odbc';
    }

    public static function getServerVersion(): string
    {
        try {
            $stmt = self::query('SELECT VERSION()');
            if ($stmt instanceof PDOStatement) {
                $version = trim((string)$stmt->fetchColumn());
                if ($version !== '') {
                    return $version;
                }
            }
        } catch (Throwable) {
        }

        try {
            return trim((string)self::fetchColumn('SELECT VERSION()'));
        } catch (Throwable) {
            return '';
        }
    }

    public static function prepareExecute(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException('Failed to prepare SQL statement.');
        }

        $stmt->execute($params);

        return $stmt;
    }

    public static function prepareExecuteOn(PDO $pdo, string $sql, array $params = []): PDOStatement
    {
        $stmt = self::prepareOn($pdo, $sql);
        if ($stmt === false) {
            throw new RuntimeException('Failed to prepare SQL statement.');
        }

        $stmt->execute($params);

        return $stmt;
    }

    public static function execute(string $sql, array $params = []): int
    {
        return self::prepareExecute($sql, $params)->rowCount();
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        try {
            return self::prepareExecute($sql, $params)->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    public static function fetchAllOn(PDO $pdo, string $sql, array $params = []): array
    {
        try {
            return self::prepareExecuteOn($pdo, $sql, $params)->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    public static function fetchOne(string $sql, array $params = []): array|false
    {
        return self::prepareExecute($sql, $params)->fetch();
    }

    public static function fetchOneOn(PDO $pdo, string $sql, array $params = []): array|false
    {
        return self::prepareExecuteOn($pdo, $sql, $params)->fetch();
    }

    public static function fetchColumn(string $sql, array $params = [], int $column = 0): mixed
    {
        return self::prepareExecute($sql, $params)->fetchColumn($column);
    }

    public static function fetchColumnOn(PDO $pdo, string $sql, array $params = [], int $column = 0): mixed
    {
        return self::prepareExecuteOn($pdo, $sql, $params)->fetchColumn($column);
    }

    public static function tableExists(string $table): bool
    {
        return self::tableExistsOn(self::connection(), $table);
    }

    public static function tableRowCount(string $table): int
    {
        return self::tableRowCountOn(self::connection(), $table);
    }

    public static function tableExistsOn(PDO $pdo, string $table): bool
    {
        $table = trim($table);
        if ($table === '') {
            return false;
        }

        try {
            $tableParts = explode('.', $table, 2);
            $schemaName = count($tableParts) === 2 ? trim($tableParts[0]) : null;
            $tableName = trim($tableParts[count($tableParts) - 1]);

            if ($tableName === '') {
                return false;
            }

            if (self::driverNameOn($pdo) === 'sqlite') {
                return (bool)self::fetchColumnOn(
                    $pdo,
                    "SELECT 1
                     FROM sqlite_master
                     WHERE type = 'table'
                       AND name = :table_name
                     LIMIT 1",
                    ['table_name' => $tableName]
                );
            }

            $sql = "SELECT 1
                    FROM INFORMATION_SCHEMA.TABLES
                    WHERE TABLE_NAME = :table_name";
            $params = ['table_name' => $tableName];

            if ($schemaName !== null && $schemaName !== '') {
                $sql .= '
                      AND TABLE_SCHEMA = :schema_name';
                $params['schema_name'] = $schemaName;
            } else {
                $sql .= '
                      AND TABLE_SCHEMA = DATABASE()';
            }

            $sql .= '
                    LIMIT 1';

            return (bool)self::fetchColumnOn($pdo, $sql, $params);
        } catch (Throwable) {
            return false;
        }
    }

    public static function tableRowCountOn(PDO $pdo, string $table): int
    {
        try {
            [$schemaName, $tableName] = self::splitTableReference($table);
        } catch (InvalidArgumentException) {
            return self::TABLE_ROW_COUNT_ERROR;
        }

        try {
            if (!self::tableExistsByMetadataOn($pdo, $schemaName, $tableName)) {
                return self::TABLE_ROW_COUNT_TABLE_MISSING;
            }
        } catch (Throwable) {
            return self::TABLE_ROW_COUNT_ERROR;
        }

        try {
            $qualifiedTable = self::qualifiedTableIdentifier($schemaName, $tableName, self::driverNameOn($pdo));
            $count = self::fetchColumnOn($pdo, 'SELECT COUNT(*) FROM ' . $qualifiedTable);

            return $count === false ? self::TABLE_ROW_COUNT_ERROR : (int)$count;
        } catch (Throwable) {
            return self::TABLE_ROW_COUNT_ERROR;
        }
    }

    public static function columnExists(string $table, string $column): bool
    {
        return self::columnExistsOn(self::connection(), $table, $column);
    }

    public static function columnExistsOn(PDO $pdo, string $table, string $column): bool
    {
        $table = trim($table);
        $column = trim($column);

        if ($table === '' || $column === '') {
            return false;
        }

        try {
            $tableParts = explode('.', $table, 2);
            $schemaName = count($tableParts) === 2 ? trim($tableParts[0]) : null;
            $tableName = trim($tableParts[count($tableParts) - 1]);

            if ($tableName === '') {
                return false;
            }

            if (self::driverNameOn($pdo) === 'sqlite') {
                $stmt = self::queryOn($pdo, 'PRAGMA table_info(' . $tableName . ')');
                $columns = $stmt instanceof PDOStatement ? $stmt->fetchAll() : [];

                foreach ($columns as $columnMeta) {
                    if (strcasecmp((string)($columnMeta['name'] ?? ''), $column) === 0) {
                        return true;
                    }
                }

                return false;
            }

            $sql = "SELECT 1
                    FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_NAME = :table_name
                      AND COLUMN_NAME = :column_name";
            $params = [
                'table_name' => $tableName,
                'column_name' => $column,
            ];

            if ($schemaName !== null && $schemaName !== '') {
                $sql .= '
                      AND TABLE_SCHEMA = :schema_name';
                $params['schema_name'] = $schemaName;
            } else {
                $sql .= '
                      AND TABLE_SCHEMA = DATABASE()';
            }

            $sql .= '
                    LIMIT 1';

            return (bool)self::fetchColumnOn($pdo, $sql, $params);
        } catch (Throwable) {
            return false;
        }
    }

    private static function splitTableReference(string $table): array
    {
        $table = trim($table);
        if ($table === '') {
            throw new InvalidArgumentException('Table name cannot be blank.');
        }

        $parts = explode('.', $table, 2);
        $schemaName = count($parts) === 2 ? trim($parts[0]) : null;
        $tableName = trim($parts[count($parts) - 1]);

        if ($tableName === '' || !self::isSafeIdentifier($tableName)) {
            throw new InvalidArgumentException('Invalid table name.');
        }

        if ($schemaName !== null && $schemaName !== '' && !self::isSafeIdentifier($schemaName)) {
            throw new InvalidArgumentException('Invalid schema name.');
        }

        return [$schemaName !== '' ? $schemaName : null, $tableName];
    }

    private static function tableExistsByMetadataOn(PDO $pdo, ?string $schemaName, string $tableName): bool
    {
        if (self::driverNameOn($pdo) === 'sqlite') {
            return (bool)self::fetchColumnOn(
                $pdo,
                "SELECT 1
                 FROM sqlite_master
                 WHERE type = 'table'
                   AND name = :table_name
                 LIMIT 1",
                ['table_name' => $tableName]
            );
        }

        $sql = "SELECT 1
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_NAME = :table_name";
        $params = ['table_name' => $tableName];

        if ($schemaName !== null && $schemaName !== '') {
            $sql .= '
                  AND TABLE_SCHEMA = :schema_name';
            $params['schema_name'] = $schemaName;
        } else {
            $sql .= '
                  AND TABLE_SCHEMA = DATABASE()';
        }

        $sql .= '
                LIMIT 1';

        return (bool)self::fetchColumnOn($pdo, $sql, $params);
    }

    private static function qualifiedTableIdentifier(?string $schemaName, string $tableName, string $driverName): string
    {
        $identifier = self::quotedIdentifier($tableName, $driverName);

        if ($schemaName === null || $schemaName === '') {
            return $identifier;
        }

        return self::quotedIdentifier($schemaName, $driverName) . '.' . $identifier;
    }

    private static function quotedIdentifier(string $identifier, string $driverName): string
    {
        return match ($driverName) {
            'sqlite' => '"' . str_replace('"', '""', $identifier) . '"',
            default => '`' . str_replace('`', '``', $identifier) . '`',
        };
    }

    private static function isSafeIdentifier(string $identifier): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) === 1;
    }
}

