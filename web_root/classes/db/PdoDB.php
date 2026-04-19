<?php
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

        return self::connectWithCredentials(
            $dsn,
            $username !== '' ? $username : null,
            $password !== '' ? $password : null,
            []
        );
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

        (new LogStore())->appendLine($logFile, self::formatLogLine($sql, $params));
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
