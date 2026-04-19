<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
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
        return self::_prepareOn(self::connection(), $sql, $options);
    }

    public static function query(string $sql, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return self::_queryOn(self::connection(), $sql, $fetchMode, ...$fetchModeArgs);
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
        return self::_driverNameOn(self::connection());
    }

    public static function isOdbcDriver(): bool
    {
        return self::driverName() === 'odbc';
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

        $stmt->execute(PdoDB::filterParamsForSql($sql, $params));

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

    public static function fetchOne(string $sql, array $params = []): array|false
    {
        return self::prepareExecute($sql, $params)->fetch();
    }

    public static function fetchColumn(string $sql, array $params = [], int $column = 0): mixed
    {
        return self::prepareExecute($sql, $params)->fetchColumn($column);
    }


    public static function tableExists(string $table): bool
    {
        return self::_tableExistsOn(self::connection(), $table);
    }

    public static function tableRowCount(string $table): int
    {
        return self::_tableRowCountOn(self::connection(), $table);
    }

    public static function countWhere(string $table, array|string $conditionsOrField, mixed $value = null): int
    {
        return self::_countWhereOn(self::connection(), $table, $conditionsOrField, $value);
    }

    public static function countWhereNotNull(string $table, string $field, array|string $conditionsOrField = []): int
    {
        return self::_countWhereNotNullOn(self::connection(), $table, $field, $conditionsOrField);
    }

    public static function countIn(string $table, string $field, array $values, array|string $conditionsOrField = []): int
    {
        return self::_countInOn(self::connection(), $table, $field, $values, $conditionsOrField);
    }

    public static function countWhereCompare(string $table, string $field, string $operator, mixed $value, array|string $conditionsOrField = []): int
    {
        return self::_countWhereCompareOn(self::connection(), $table, $field, $operator, $value, $conditionsOrField);
    }

    public static function columnExists(string $table, string $column): bool
    {
        return self::_columnExistsOn(self::connection(), $table, $column);
    }

    public static function columnsExists(string $table, array $columns): bool
    {
        return self::_columnsExistsOn(self::connection(), $table, $columns);
    }

    public static function _columnsExistsOn(PDO $pdo, string $table, array $columns): bool
    {
        if ($columns === []) {
            return false;
        }

        foreach ($columns as $column) {
            if (!is_string($column) || !self::_columnExistsOn($pdo, $table, $column)) {
                return false;
            }
        }

        return true;
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

    private static function normaliseCountWhereConditions(array|string $conditionsOrField, mixed $value): array
    {
        if (is_string($conditionsOrField)) {
            $fieldName = self::normaliseCountIdentifier($conditionsOrField, 'Invalid field name.');

            return [$fieldName => $value];
        }

        $conditions = [];
        foreach ($conditionsOrField as $fieldName => $fieldValue) {
            if (!is_string($fieldName)) {
                throw new InvalidArgumentException('Condition field names must be strings.');
            }

            $fieldName = self::normaliseCountIdentifier($fieldName, 'Invalid field name.');

            $conditions[$fieldName] = $fieldValue;
        }

        if ($conditions === []) {
            throw new InvalidArgumentException('At least one condition is required.');
        }

        return $conditions;
    }

    private static function normaliseOptionalCountWhereConditions(array|string $conditionsOrField): array
    {
        if ($conditionsOrField === []) {
            return [];
        }

        if (is_string($conditionsOrField) && trim($conditionsOrField) === '') {
            return [];
        }

        return self::normaliseCountWhereConditions($conditionsOrField, null);
    }

    private static function normaliseCountIdentifier(string $identifier, string $message): string
    {
        $identifier = trim($identifier);
        if ($identifier === '' || !self::isSafeIdentifier($identifier)) {
            throw new InvalidArgumentException($message);
        }

        return $identifier;
    }

    private static function normaliseComparisonOperator(string $operator): string
    {
        $operator = trim($operator);
        $allowedOperators = ['=', '!=', '<>', '>', '>=', '<', '<='];
        if (!in_array($operator, $allowedOperators, true)) {
            throw new InvalidArgumentException('Invalid comparison operator.');
        }

        return $operator;
    }

    private static function tableExistsByMetadataOn(PDO $pdo, ?string $schemaName, string $tableName): bool
    {
        if (self::_driverNameOn($pdo) === 'sqlite') {
            return (bool)self::_fetchColumnOn(
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

        return (bool)self::_fetchColumnOn($pdo, $sql, $params);
    }

    private static function columnExistsByMetadataOn(PDO $pdo, ?string $schemaName, string $tableName, string $columnName): bool
    {
        if (self::_driverNameOn($pdo) === 'sqlite') {
            $stmt = self::_queryOn($pdo, 'PRAGMA table_info(' . $tableName . ')');
            $columns = $stmt instanceof PDOStatement ? $stmt->fetchAll() : [];

            foreach ($columns as $columnMeta) {
                if (strcasecmp((string)($columnMeta['name'] ?? ''), $columnName) === 0) {
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
            'column_name' => $columnName,
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

        return (bool)self::_fetchColumnOn($pdo, $sql, $params);
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

    public static function _prepareOn(PDO $pdo, string $sql, array $options = []): PDOStatement|false
    {
        return PdoDB::prepareOn($pdo, $sql, $options);
    }

    public static function _queryOn(PDO $pdo, string $sql, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return PdoDB::queryOn($pdo, $sql, $fetchMode, ...$fetchModeArgs);
    }

    public static function _driverNameOn(PDO $pdo): string
    {
        return strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    public static function _isOdbcDriverOn(PDO $pdo): bool
    {
        return self::_driverNameOn($pdo) === 'odbc';
    }

    public static function _prepareExecuteOn(PDO $pdo, string $sql, array $params = []): PDOStatement
    {
        return PdoDB::prepareExecuteOn($pdo, $sql, $params);
    }

    public static function _fetchAllOn(PDO $pdo, string $sql, array $params = []): array
    {
        try {
            return self::_prepareExecuteOn($pdo, $sql, $params)->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    public static function _fetchOneOn(PDO $pdo, string $sql, array $params = []): array|false
    {
        return self::_prepareExecuteOn($pdo, $sql, $params)->fetch();
    }

    public static function _fetchColumnOn(PDO $pdo, string $sql, array $params = [], int $column = 0): mixed
    {
        return self::_prepareExecuteOn($pdo, $sql, $params)->fetchColumn($column);
    }

    public static function _tableExistsOn(PDO $pdo, string $table): bool
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

            if (self::_driverNameOn($pdo) === 'sqlite') {
                return (bool)self::_fetchColumnOn(
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

            return (bool)self::_fetchColumnOn($pdo, $sql, $params);
        } catch (Throwable) {
            return false;
        }
    }

    public static function _tableRowCountOn(PDO $pdo, string $table): int
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
            $qualifiedTable = self::qualifiedTableIdentifier($schemaName, $tableName, self::_driverNameOn($pdo));
            $count = self::_fetchColumnOn($pdo, 'SELECT COUNT(*) FROM ' . $qualifiedTable);

            return $count === false ? self::TABLE_ROW_COUNT_ERROR : (int)$count;
        } catch (Throwable) {
            return self::TABLE_ROW_COUNT_ERROR;
        }
    }

    public static function _countWhereOn(PDO $pdo, string $table, array|string $conditionsOrField, mixed $value = null): int
    {
        try {
            [$schemaName, $tableName] = self::splitTableReference($table);
            $conditions = self::normaliseCountWhereConditions($conditionsOrField, $value);

            if (!self::tableExistsByMetadataOn($pdo, $schemaName, $tableName)) {
                return self::TABLE_ROW_COUNT_TABLE_MISSING;
            }

            $qualifiedTable = self::qualifiedTableIdentifier($schemaName, $tableName, self::_driverNameOn($pdo));
            $whereParts = [];
            $params = [];
            $index = 0;

            foreach ($conditions as $columnName => $columnValue) {
                if (!self::columnExistsByMetadataOn($pdo, $schemaName, $tableName, $columnName)) {
                    return self::TABLE_ROW_COUNT_ERROR;
                }

                $quotedColumn = self::quotedIdentifier($columnName, self::_driverNameOn($pdo));

                if ($columnValue === null) {
                    $whereParts[] = $quotedColumn . ' IS NULL';
                    continue;
                }

                $paramName = 'where_value_' . $index++;
                $whereParts[] = $quotedColumn . ' = :' . $paramName;
                $params[$paramName] = $columnValue;
            }

            if ($whereParts === []) {
                return self::TABLE_ROW_COUNT_ERROR;
            }

            $count = self::_fetchColumnOn(
                $pdo,
                'SELECT COUNT(*) FROM ' . $qualifiedTable . ' WHERE ' . implode(' AND ', $whereParts),
                $params
            );

            return $count === false ? self::TABLE_ROW_COUNT_ERROR : (int)$count;
        } catch (Throwable) {
            return self::TABLE_ROW_COUNT_ERROR;
        }
    }

    public static function _countWhereNotNullOn(PDO $pdo, string $table, string $field, array|string $conditionsOrField = []): int
    {
        try {
            [$schemaName, $tableName] = self::splitTableReference($table);
            $field = self::normaliseCountIdentifier($field, 'Invalid field name.');
            $conditions = self::normaliseOptionalCountWhereConditions($conditionsOrField);

            if (!self::tableExistsByMetadataOn($pdo, $schemaName, $tableName)) {
                return self::TABLE_ROW_COUNT_TABLE_MISSING;
            }

            if (!self::columnExistsByMetadataOn($pdo, $schemaName, $tableName, $field)) {
                return self::TABLE_ROW_COUNT_ERROR;
            }

            $driverName = self::_driverNameOn($pdo);
            $qualifiedTable = self::qualifiedTableIdentifier($schemaName, $tableName, $driverName);
            $whereParts = [self::quotedIdentifier($field, $driverName) . ' IS NOT NULL'];
            $params = [];
            $index = 0;

            foreach ($conditions as $columnName => $columnValue) {
                if (!self::columnExistsByMetadataOn($pdo, $schemaName, $tableName, $columnName)) {
                    return self::TABLE_ROW_COUNT_ERROR;
                }

                $quotedColumn = self::quotedIdentifier($columnName, $driverName);
                if ($columnValue === null) {
                    $whereParts[] = $quotedColumn . ' IS NULL';
                    continue;
                }

                $paramName = 'where_value_' . $index++;
                $whereParts[] = $quotedColumn . ' = :' . $paramName;
                $params[$paramName] = $columnValue;
            }

            $count = self::_fetchColumnOn(
                $pdo,
                'SELECT COUNT(*) FROM ' . $qualifiedTable . ' WHERE ' . implode(' AND ', $whereParts),
                $params
            );

            return $count === false ? self::TABLE_ROW_COUNT_ERROR : (int)$count;
        } catch (Throwable) {
            return self::TABLE_ROW_COUNT_ERROR;
        }
    }

    public static function _countInOn(PDO $pdo, string $table, string $field, array $values, array|string $conditionsOrField = []): int
    {
        try {
            [$schemaName, $tableName] = self::splitTableReference($table);
            $field = self::normaliseCountIdentifier($field, 'Invalid field name.');
            $conditions = self::normaliseOptionalCountWhereConditions($conditionsOrField);

            if ($values === []) {
                return 0;
            }

            if (!self::tableExistsByMetadataOn($pdo, $schemaName, $tableName)) {
                return self::TABLE_ROW_COUNT_TABLE_MISSING;
            }

            if (!self::columnExistsByMetadataOn($pdo, $schemaName, $tableName, $field)) {
                return self::TABLE_ROW_COUNT_ERROR;
            }

            $driverName = self::_driverNameOn($pdo);
            $qualifiedTable = self::qualifiedTableIdentifier($schemaName, $tableName, $driverName);
            $params = [];
            $inParams = [];
            foreach (array_values($values) as $index => $fieldValue) {
                $paramName = 'in_value_' . $index;
                $params[$paramName] = $fieldValue;
                $inParams[] = ':' . $paramName;
            }

            $whereParts = [
                self::quotedIdentifier($field, $driverName) . ' IN (' . implode(', ', $inParams) . ')',
            ];

            $whereIndex = 0;
            foreach ($conditions as $columnName => $columnValue) {
                if (!self::columnExistsByMetadataOn($pdo, $schemaName, $tableName, $columnName)) {
                    return self::TABLE_ROW_COUNT_ERROR;
                }

                $quotedColumn = self::quotedIdentifier($columnName, $driverName);
                if ($columnValue === null) {
                    $whereParts[] = $quotedColumn . ' IS NULL';
                    continue;
                }

                $paramName = 'where_value_' . $whereIndex++;
                $whereParts[] = $quotedColumn . ' = :' . $paramName;
                $params[$paramName] = $columnValue;
            }

            $count = self::_fetchColumnOn(
                $pdo,
                'SELECT COUNT(*) FROM ' . $qualifiedTable . ' WHERE ' . implode(' AND ', $whereParts),
                $params
            );

            return $count === false ? self::TABLE_ROW_COUNT_ERROR : (int)$count;
        } catch (Throwable) {
            return self::TABLE_ROW_COUNT_ERROR;
        }
    }

    public static function _countWhereCompareOn(PDO $pdo, string $table, string $field, string $operator, mixed $value, array|string $conditionsOrField = []): int
    {
        try {
            [$schemaName, $tableName] = self::splitTableReference($table);
            $field = self::normaliseCountIdentifier($field, 'Invalid field name.');
            $operator = self::normaliseComparisonOperator($operator);
            $conditions = self::normaliseOptionalCountWhereConditions($conditionsOrField);

            if (!self::tableExistsByMetadataOn($pdo, $schemaName, $tableName)) {
                return self::TABLE_ROW_COUNT_TABLE_MISSING;
            }

            if (!self::columnExistsByMetadataOn($pdo, $schemaName, $tableName, $field)) {
                return self::TABLE_ROW_COUNT_ERROR;
            }

            $driverName = self::_driverNameOn($pdo);
            $qualifiedTable = self::qualifiedTableIdentifier($schemaName, $tableName, $driverName);
            $params = ['compare_value' => $value];
            $whereParts = [
                self::quotedIdentifier($field, $driverName) . ' ' . $operator . ' :compare_value',
            ];

            $whereIndex = 0;
            foreach ($conditions as $columnName => $columnValue) {
                if (!self::columnExistsByMetadataOn($pdo, $schemaName, $tableName, $columnName)) {
                    return self::TABLE_ROW_COUNT_ERROR;
                }

                $quotedColumn = self::quotedIdentifier($columnName, $driverName);
                if ($columnValue === null) {
                    $whereParts[] = $quotedColumn . ' IS NULL';
                    continue;
                }

                $paramName = 'where_value_' . $whereIndex++;
                $whereParts[] = $quotedColumn . ' = :' . $paramName;
                $params[$paramName] = $columnValue;
            }

            $count = self::_fetchColumnOn(
                $pdo,
                'SELECT COUNT(*) FROM ' . $qualifiedTable . ' WHERE ' . implode(' AND ', $whereParts),
                $params
            );

            return $count === false ? self::TABLE_ROW_COUNT_ERROR : (int)$count;
        } catch (Throwable) {
            return self::TABLE_ROW_COUNT_ERROR;
        }
    }

    public static function _columnExistsOn(PDO $pdo, string $table, string $column): bool
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

            if (self::_driverNameOn($pdo) === 'sqlite') {
                $stmt = self::_queryOn($pdo, 'PRAGMA table_info(' . $tableName . ')');
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

            return (bool)self::_fetchColumnOn($pdo, $sql, $params);
        } catch (Throwable) {
            return false;
        }
    }
}

