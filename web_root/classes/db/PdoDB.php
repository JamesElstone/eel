<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class PdoDB
{
    private static ?self $instance = null;
    private ?PDO $connection = null;
    private ?array $dbConfig = null;
    private ?string $logFile = null;

    private function __construct() {
    }

    private static function connection(): PDO {
        $instance = self::getInstance();

        if ($instance->connection instanceof PDO) {
            return $instance->connection;
        }

        $instance->connection = self::connect();

        return $instance->connection;
    }

    public static function connectionForInterfaceDB(): PDO {
        $callerClass = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['class'] ?? null;

        if ($callerClass !== InterfaceDB::class) {
            throw new RuntimeException('PdoDB::connectionForInterfaceDB() may only be called by InterfaceDB.');
        }

        return self::connection();
    }

    private static function driverNameFor(PDO $pdo): string {
        return strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    private static function connectWithCredentials(string $dsn, ?string $username = null, ?string $password = null, array $options = []): PDO {
        $baseOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        return new PDO(
            $dsn,
            $username,
            $password,
            $options + $baseOptions
        );
    }

    private static function connect(): PDO {
        $dbConfig = self::dbConfig();
        $dsn = trim((string)($dbConfig['dsn'] ?? ''));

        if ($dsn === '') {
            throw new RuntimeException('Database DSN is not configured in config/app.php.');
        }

        $username = (string)($dbConfig['user'] ?? '');
        $password = (string)($dbConfig['pass'] ?? '');

        $pdo = self::connectWithCredentials(
            $dsn,
            $username !== '' ? $username : null,
            $password !== '' ? $password : null,
            []
        );

        self::initialiseConfiguredSqliteSchema($pdo, $dbConfig);

        return $pdo;
    }

    private static function initialiseConfiguredSqliteSchema(PDO $pdo, array $dbConfig): void
    {
        if (self::driverNameFor($pdo) !== 'sqlite') {
            return;
        }

        $schemaPath = trim((string)($dbConfig['sqlite_schema'] ?? ''));
        if ($schemaPath === '') {
            return;
        }

        $schemaPath = self::normaliseConfigPath($schemaPath);
        if (!is_file($schemaPath)) {
            throw new RuntimeException('SQLite schema fixture was not found: ' . $schemaPath);
        }

        $schema = file_get_contents($schemaPath);
        if (!is_string($schema) || trim($schema) === '') {
            throw new RuntimeException('SQLite schema fixture is empty: ' . $schemaPath);
        }

        $pdo->exec(self::sqliteSchemaSqlFor($schema));
    }

    private static function sqliteSchemaSqlFor(string $schema): string {
        if (stripos($schema, 'ENGINE=') === false && stripos($schema, 'AUTO_INCREMENT') === false) {
            return $schema;
        }

        preg_match_all(
            '/CREATE\s+TABLE\s+`([^`]+)`\s*\((.*?)\)\s*ENGINE\s*=\s*\w+.*?;/is',
            $schema,
            $matches,
            PREG_SET_ORDER
        );

        $statements = ['PRAGMA foreign_keys = OFF'];
        foreach ($matches as $match) {
            $table = $match[1];
            $body = $match[2];
            $definitions = self::splitSqlDefinitions($body);
            $autoIncrementPrimaryKey = self::autoIncrementPrimaryKeyFromDefinitions($definitions);
            $tableLines = [];
            $indexStatements = [];

            foreach ($definitions as $definition) {
                $converted = self::sqliteTableDefinitionFor($definition, $table, $autoIncrementPrimaryKey);
                if ($converted === '') {
                    continue;
                }

                if (str_starts_with($converted, 'CREATE ')) {
                    $indexStatements[] = $converted;
                    continue;
                }

                $tableLines[] = $converted;
            }

            $statements[] = 'DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`';
            $statements[] = "CREATE TABLE `" . str_replace('`', '``', $table) . "` (\n  " . implode(",\n  ", $tableLines) . "\n)";
            array_push($statements, ...$indexStatements);
        }

        if (count($statements) === 1) {
            throw new RuntimeException('No MariaDB CREATE TABLE statements were found in the configured SQLite schema fixture.');
        }

        $statements[] = 'PRAGMA foreign_keys = ON';

        return implode(";\n", $statements) . ";\n";
    }

    private static function splitSqlDefinitions(string $body): array {
        $definitions = [];
        $definition = '';
        $quote = null;
        $depth = 0;
        $length = strlen($body);

        for ($index = 0; $index < $length; $index++) {
            $character = $body[$index];
            $definition .= $character;

            if ($quote !== null) {
                if ($character === $quote) {
                    if ($quote === '\'' && $index + 1 < $length && $body[$index + 1] === '\'') {
                        $definition .= $body[++$index];
                        continue;
                    }

                    $quote = null;
                }

                continue;
            }

            if ($character === '\'' || $character === '"' || $character === '`') {
                $quote = $character;
                continue;
            }

            if ($character === '(') {
                $depth++;
                continue;
            }

            if ($character === ')' && $depth > 0) {
                $depth--;
                continue;
            }

            if ($character === ',' && $depth === 0) {
                $definitions[] = trim(substr($definition, 0, -1));
                $definition = '';
            }
        }

        $definition = trim($definition);
        if ($definition !== '') {
            $definitions[] = $definition;
        }

        return $definitions;
    }

    private static function autoIncrementPrimaryKeyFromDefinitions(array $definitions): ?string
    {
        $autoIncrementColumn = null;
        $primaryKeyColumn = null;

        foreach ($definitions as $definition) {
            if (
                preg_match('/^`([^`]+)`\s+.*\bAUTO_INCREMENT\b/i', $definition, $match) === 1
            ) {
                $autoIncrementColumn = $match[1];
                continue;
            }

            if (
                preg_match('/^PRIMARY\s+KEY\s+\(`([^`]+)`\)$/i', $definition, $match) === 1
            ) {
                $primaryKeyColumn = $match[1];
            }
        }

        return $autoIncrementColumn !== null && $autoIncrementColumn === $primaryKeyColumn
            ? $autoIncrementColumn
            : null;
    }

    private static function sqliteTableDefinitionFor(string $definition, string $table, ?string $autoIncrementPrimaryKey = null): string {
        $definition = trim($definition);
        if ($definition === '') {
            return '';
        }

        if (preg_match('/^UNIQUE\s+KEY\s+`([^`]+)`\s+\((.+)\)$/i', $definition, $match) === 1) {
            return 'CREATE UNIQUE INDEX `' . $match[1] . '` ON `' . $table . '` (' . $match[2] . ')';
        }

        if (preg_match('/^KEY\s+`([^`]+)`\s+\((.+)\)$/i', $definition, $match) === 1) {
            return 'CREATE INDEX `' . $match[1] . '` ON `' . $table . '` (' . $match[2] . ')';
        }

        if (
            $autoIncrementPrimaryKey !== null
            && preg_match('/^PRIMARY\s+KEY\s+\(`' . preg_quote($autoIncrementPrimaryKey, '/') . '`\)$/i', $definition) === 1
        ) {
            return '';
        }

        if (preg_match('/^PRIMARY\s+KEY\s+\((.+)\)$/i', $definition) === 1) {
            return $definition;
        }

        if (preg_match('/^CONSTRAINT\s+`[^`]+`\s+(FOREIGN\s+KEY.+)$/i', $definition, $match) === 1) {
            return $match[1];
        }

        if (preg_match('/^`[^`]+`/', $definition) !== 1) {
            return '';
        }

        if (
            $autoIncrementPrimaryKey !== null
            && preg_match('/^`' . preg_quote($autoIncrementPrimaryKey, '/') . '`\s+/i', $definition) === 1
        ) {
            return '`' . str_replace('`', '``', $autoIncrementPrimaryKey) . '` INTEGER PRIMARY KEY AUTOINCREMENT';
        }

        $definition = preg_replace('/\b(?:tinyint|smallint|mediumint|int|bigint)\s*(?:\(\d+\))?\s+unsigned\b/i', 'INTEGER', $definition);
        $definition = preg_replace('/\b(?:tinyint|smallint|mediumint|int|bigint)\s*(?:\(\d+\))?/i', 'INTEGER', $definition);
        $definition = preg_replace('/\b(?:var)?char\s*\(\d+\)(?:\s+CHARACTER\s+SET\s+\w+)?(?:\s+COLLATE\s+\w+)?/i', 'TEXT', $definition);
        $definition = preg_replace('/\blongtext\b/i', 'TEXT', $definition);
        $definition = preg_replace('/\bdatetime\b/i', 'TEXT', $definition);
        $definition = preg_replace('/\benum\s*\((?:[^()]|\'[^\']*\')*\)/i', 'TEXT', $definition);
        $definition = preg_replace('/\s+AUTO_INCREMENT\b/i', '', $definition);
        $definition = preg_replace('/\s+ON\s+UPDATE\s+current_timestamp\(\)/i', '', $definition);
        $definition = preg_replace('/\bDEFAULT\s+current_timestamp\(\)/i', 'DEFAULT CURRENT_TIMESTAMP', $definition);

        return trim((string)$definition);
    }

    private static function dbConfig(): array {
        $instance = self::getInstance();

        if (is_array($instance->dbConfig)) {
            return $instance->dbConfig;
        }

        $config = AppConfigurationStore::config();
        $instance->dbConfig = is_array($config['db'] ?? null) ? $config['db'] : [];

        return $instance->dbConfig;
    }

    private static function logFile(): string {
        $instance = self::getInstance();

        if ($instance->logFile !== null) {
            return $instance->logFile;
        }

        $configuredPath = trim((string)(self::dbConfig()['logfile'] ?? ''));
        if ($configuredPath === '') {
            $instance->logFile = '';
            return $instance->logFile;
        }

        $instance->logFile = self::normaliseLogPath($configuredPath);

        return $instance->logFile;
    }

    private static function normaliseLogPath(string $path): string {
        return self::normaliseConfigPath($path);
    }

    private static function normaliseConfigPath(string $path): string {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $normalised = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if (preg_match('/^(?:[A-Za-z]:[\\\\\\/]|[\\\\\\/]{2})/', $normalised) === 1) {
            return $normalised;
        }

        return APP_ROOT . ltrim($normalised, '\\/');
    }

    private static function rewriteNamedPlaceholders(string $sql): array {
        $rewrittenSql = '';
        $namedOrder = [];
        $length = strlen($sql);

        for ($index = 0; $index < $length; $index++) {
            $character = $sql[$index];

            if ($character === '\'' || $character === '"' || $character === '`') {
                $quote = $character;
                $rewrittenSql .= $character;

                while (++$index < $length) {
                    $quotedCharacter = $sql[$index];
                    $rewrittenSql .= $quotedCharacter;

                    if ($quotedCharacter === $quote) {
                        if ($quote === '\'' && $index + 1 < $length && $sql[$index + 1] === '\'') {
                            $rewrittenSql .= $sql[++$index];
                            continue;
                        }

                        break;
                    }
                }

                continue;
            }

            if (
                $character === '-'
                && $index + 1 < $length
                && $sql[$index + 1] === '-'
                && ($index + 2 >= $length || ctype_space($sql[$index + 2]))
            ) {
                $rewrittenSql .= '--';
                $index++;

                while (++$index < $length) {
                    $commentCharacter = $sql[$index];
                    $rewrittenSql .= $commentCharacter;

                    if ($commentCharacter === "\n" || $commentCharacter === "\r") {
                        break;
                    }
                }

                continue;
            }

            if ($character === '#') {
                $rewrittenSql .= $character;

                while (++$index < $length) {
                    $commentCharacter = $sql[$index];
                    $rewrittenSql .= $commentCharacter;

                    if ($commentCharacter === "\n" || $commentCharacter === "\r") {
                        break;
                    }
                }

                continue;
            }

            if (
                $character === '/'
                && $index + 1 < $length
                && $sql[$index + 1] === '*'
            ) {
                $rewrittenSql .= '/*';
                $index++;

                while (++$index < $length) {
                    $commentCharacter = $sql[$index];
                    $rewrittenSql .= $commentCharacter;

                    if (
                        $commentCharacter === '/'
                        && $index > 0
                        && $sql[$index - 1] === '*'
                    ) {
                        break;
                    }
                }

                continue;
            }

            if (
                $character === ':'
                && $index + 1 < $length
                && $sql[$index + 1] === ':'
            ) {
                $rewrittenSql .= '::';
                $index++;
                continue;
            }

            if (
                $character === ':'
                && $index + 1 < $length
                && preg_match('/[A-Za-z_]/', $sql[$index + 1]) === 1
            ) {
                $placeholder = '';
                $cursor = $index + 1;

                while ($cursor < $length && preg_match('/[A-Za-z0-9_]/', $sql[$cursor]) === 1) {
                    $placeholder .= $sql[$cursor];
                    $cursor++;
                }

                if ($placeholder !== '') {
                    $rewrittenSql .= '?';
                    $namedOrder[] = $placeholder;
                    $index = $cursor - 1;
                    continue;
                }
            }

            $rewrittenSql .= $character;
        }

        return [$rewrittenSql, $namedOrder];
    }

    private static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function preparePlanOn(PDO $pdo, string $sql, array $options = []): array {
        if (array_key_exists(PDO::ATTR_STATEMENT_CLASS, $options)) {
            return [$sql, $options];
        }

        $rewrittenSql = $sql;
        $namedOrder = [];
        $rewriteNamedParams = false;

        if (self::driverNameFor($pdo) === 'odbc') {
            [$rewrittenSql, $namedOrder] = self::rewriteNamedPlaceholders($sql);
            $rewriteNamedParams = $namedOrder !== [];
        }

        $options[PDO::ATTR_STATEMENT_CLASS] = [
            PdoStatementDB::class,
            [$namedOrder, $rewriteNamedParams, $sql, self::logFile()],
        ];

        return [$rewrittenSql, $options];
    }

    public static function prepareExecuteOn(PDO $pdo, string $sql, array $params = []): PDOStatement {
        $stmt = self::prepareOn($pdo, $sql);
        if ($stmt === false) {
            throw new RuntimeException('Failed to prepare SQL statement.');
        }
        $stmt->execute(self::filterParamsForSql($sql, $params));

        return $stmt;
    }

    public static function filterParamsForSql(string $sql, array $params = []): array {
        if ($params === []) {
            return [];
        }

        if (function_exists('array_is_list') ? array_is_list($params) : self::isListArray($params)) {
            return $params;
        }

        [, $namedOrder] = self::rewriteNamedPlaceholders($sql);
        if ($namedOrder === []) {
            return [];
        }

        $filtered = [];
        foreach (array_values(array_unique($namedOrder)) as $placeholder) {
            if (array_key_exists($placeholder, $params)) {
                $filtered[$placeholder] = $params[$placeholder];
            }
        }

        return $filtered;
    }

    public static function prepareOn(PDO $pdo, string $sql, array $options = []): PDOStatement|false {
        [$preparedSql, $preparedOptions] = self::preparePlanOn($pdo, $sql, $options);

        return $pdo->prepare($preparedSql, $preparedOptions);
    }

    public static function queryOn(PDO $pdo, string $sql, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        try {
            if ($fetchMode === null) {
                return $pdo->query($sql);
            }

            return $pdo->query($sql, $fetchMode, ...$fetchModeArgs);
        } finally {
            self::logSql($sql);
        }
    }

    public static function logSql(string $sql, ?array $params = null): void {
        $logFile = self::logFile();
        if ($logFile === '') {
            return;
        }

        try {
            (new LogStore())->appendLine($logFile, self::formatLogLine($sql, $params));
        } catch (Throwable) {
            return;
        }
    }

    private static function formatLogLine(string $sql, ?array $params = null): string {
        return self::toCsvLine([
            date('Y-m-d H:i:s'),
            $sql,
            self::stringifyParams($params),
        ]);
    }

    private static function stringifyParams(?array $params): string {
        if ($params === null || $params === []) {
            return '';
        }

        $json = json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '[unserializable params]' : $json;
    }

    private static function toCsvLine(array $fields): string {
        $escaped = array_map(
            static fn (mixed $field): string => '"' . str_replace('"', '""', (string)$field) . '"',
            $fields
        );

        return implode(',', $escaped);
    }

    private static function isListArray(array $value): bool {
        $expectedKey = 0;
        foreach ($value as $key => $_) {
            if ($key !== $expectedKey) {
                return false;
            }

            $expectedKey++;
        }

        return true;
    }
}
