<?php
declare(strict_types=1);

final class PdoDB
{
    private static ?self $instance = null;
    private ?PDO $connection = null;

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
        $config = AppConfigurationStore::config();
        $dbConfig = is_array($config['db'] ?? null) ? $config['db'] : [];
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
        if (self::driverNameFor($pdo) !== 'odbc' || array_key_exists(PDO::ATTR_STATEMENT_CLASS, $options)) {
            return [$sql, $options];
        }

        [$rewrittenSql, $namedOrder] = self::rewriteNamedPlaceholders($sql);
        if ($namedOrder === []) {
            return [$sql, $options];
        }

        $options[PDO::ATTR_STATEMENT_CLASS] = [PdoStatementDB::class, [$namedOrder, true]];

        return [$rewrittenSql, $options];
    }

    public static function prepareExecuteOn(PDO $pdo, string $sql, array $params = []): PDOStatement {
        $stmt = self::prepareOn($pdo, $sql);
        if ($stmt === false) {
            throw new RuntimeException('Failed to prepare SQL statement.');
        }
        $stmt->execute($params);

        return $stmt;
    }

    public static function prepareOn(PDO $pdo, string $sql, array $options = []): PDOStatement|false {
        [$preparedSql, $preparedOptions] = self::preparePlanOn($pdo, $sql, $options);

        return $pdo->prepare($preparedSql, $preparedOptions);
    }
}
