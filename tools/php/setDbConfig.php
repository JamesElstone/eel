<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'web_root' . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
}

function eel_set_db_config_writeln(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function eel_set_db_config_error(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
}

function eel_set_db_config_prompt(string $message): string
{
    fwrite(STDOUT, $message);

    $input = fgets(STDIN);

    return $input === false ? '' : trim($input);
}

function eel_set_db_config_prompt_hidden_windows(string $message): string
{
    fwrite(STDOUT, $message);

    $command = 'powershell -Command "$p = Read-Host -AsSecureString; ' .
        '[Runtime.InteropServices.Marshal]::PtrToStringAuto(' .
        '[Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))"';

    $input = shell_exec($command);

    return $input === null ? '' : trim($input);
}

function eel_set_db_config_prompt_hidden(string $message): string
{
    if (stripos(PHP_OS, 'WIN') === 0) {
        return eel_set_db_config_prompt_hidden_windows($message);
    }

    fwrite(STDOUT, $message);
    system('stty -echo');
    $input = fgets(STDIN);
    system('stty echo');
    fwrite(STDOUT, PHP_EOL);

    return $input === false ? '' : trim($input);
}

function eel_set_db_config_usage(): string
{
    return implode(PHP_EOL, [
        'Usage:',
        '  php tools/php/setDbConfig.php --dsn=<dsn> [--user=<user>] [--password=<password>]',
        '  php tools/php/setDbConfig.php --driver=odbc --odbc-name=<name> [--user=<user>] [--password=<password>]',
        '  php tools/php/setDbConfig.php --driver=mysql --host=<host> --database=<database> [--port=<port>] [--user=<user>] [--password=<password>]',
        '  php tools/php/setDbConfig.php --driver=sqlite --sqlite-path=<path>',
        '  php tools/php/setDbConfig.php <dsn> [user] [password]',
        '',
        'If no DSN or driver options are supplied, the tool asks questions and builds the DSN.',
    ]);
}

function eel_set_db_config_arguments(array $argv): array
{
    $options = [
        'dsn' => null,
        'driver' => null,
        'odbc_name' => null,
        'host' => null,
        'port' => null,
        'database' => null,
        'sqlite_path' => null,
        'user' => null,
        'password' => null,
        'help' => false,
    ];
    $positionals = [];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
            continue;
        }

        if (str_starts_with($argument, '--dsn=')) {
            $options['dsn'] = substr($argument, 6);
            continue;
        }

        if (str_starts_with($argument, '--driver=')) {
            $options['driver'] = substr($argument, 9);
            continue;
        }

        if (str_starts_with($argument, '--odbc-name=')) {
            $options['odbc_name'] = substr($argument, 12);
            continue;
        }

        if (str_starts_with($argument, '--host=')) {
            $options['host'] = substr($argument, 7);
            continue;
        }

        if (str_starts_with($argument, '--port=')) {
            $options['port'] = substr($argument, 7);
            continue;
        }

        if (str_starts_with($argument, '--database=')) {
            $options['database'] = substr($argument, 11);
            continue;
        }

        if (str_starts_with($argument, '--sqlite-path=')) {
            $options['sqlite_path'] = substr($argument, 14);
            continue;
        }

        if (str_starts_with($argument, '--user=')) {
            $options['user'] = substr($argument, 7);
            continue;
        }

        if (str_starts_with($argument, '--password=')) {
            $options['password'] = substr($argument, 11);
            continue;
        }

        if (str_starts_with($argument, '--')) {
            throw new RuntimeException('Unknown option: ' . $argument);
        }

        $positionals[] = $argument;
    }

    if ($options['dsn'] === null && array_key_exists(0, $positionals)) {
        $options['dsn'] = $positionals[0];
    }

    if ($options['user'] === null && array_key_exists(1, $positionals)) {
        $options['user'] = $positionals[1];
    }

    if ($options['password'] === null && array_key_exists(2, $positionals)) {
        $options['password'] = $positionals[2];
    }

    if (count($positionals) > 3) {
        throw new RuntimeException('Too many positional arguments.');
    }

    return $options;
}

function eel_set_db_config_normalise_driver(string $driver): string
{
    $driver = strtolower(trim($driver));

    return match ($driver) {
        '1', 'odbc' => 'odbc',
        '2', 'mysql', 'mariadb' => 'mysql',
        '3', 'sqlite', 'sqlite3' => 'sqlite',
        '4', 'custom', 'dsn' => 'custom',
        default => throw new RuntimeException('Unknown database driver: ' . $driver),
    };
}

function eel_set_db_config_build_dsn(array $settings): string
{
    $driver = eel_set_db_config_normalise_driver((string)($settings['driver'] ?? ''));

    if ($driver === 'odbc') {
        $name = trim((string)($settings['odbc_name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('ODBC DSN name cannot be empty.');
        }

        return 'odbc:' . $name;
    }

    if ($driver === 'mysql') {
        $host = trim((string)($settings['host'] ?? ''));
        $database = trim((string)($settings['database'] ?? ''));
        $port = trim((string)($settings['port'] ?? ''));

        if ($host === '') {
            throw new RuntimeException('Database host cannot be empty.');
        }

        if ($database === '') {
            throw new RuntimeException('Database name cannot be empty.');
        }

        $parts = [
            'host=' . $host,
        ];

        if ($port !== '') {
            $parts[] = 'port=' . $port;
        }

        $parts[] = 'dbname=' . $database;
        $parts[] = 'charset=utf8mb4';

        return 'mysql:' . implode(';', $parts);
    }

    if ($driver === 'sqlite') {
        $path = trim((string)($settings['sqlite_path'] ?? ''));
        if ($path === '') {
            throw new RuntimeException('SQLite path cannot be empty.');
        }

        return 'sqlite:' . $path;
    }

    $dsn = trim((string)($settings['dsn'] ?? ''));
    if ($dsn === '') {
        throw new RuntimeException('Database DSN cannot be empty.');
    }

    return $dsn;
}

function eel_set_db_config_prompt_with_default(string $message, string $default = ''): string
{
    $suffix = $default === '' ? ': ' : ' [' . $default . ']: ';
    $value = eel_set_db_config_prompt($message . $suffix);

    return $value === '' ? $default : $value;
}

function eel_set_db_config_default_mysql_host(): string
{
    return '127.0.0.1';
}

function eel_set_db_config_prompt_driver(): string
{
    eel_set_db_config_writeln('Database type:');
    eel_set_db_config_writeln('  1. ODBC named DSN');
    eel_set_db_config_writeln('  2. MariaDB/MySQL');
    eel_set_db_config_writeln('  3. SQLite');
    eel_set_db_config_writeln('  4. Custom PDO DSN');

    return eel_set_db_config_normalise_driver(eel_set_db_config_prompt_with_default('Select database type', '1'));
}

function eel_set_db_config_prompt_dsn_builder(?string $driver = null): array
{
    $driver = $driver === null || trim($driver) === ''
        ? eel_set_db_config_prompt_driver()
        : eel_set_db_config_normalise_driver($driver);

    if ($driver === 'odbc') {
        return [
            'driver' => $driver,
            'odbc_name' => eel_set_db_config_prompt('ODBC DSN name: '),
        ];
    }

    if ($driver === 'mysql') {
        return [
            'driver' => $driver,
            'host' => eel_set_db_config_prompt_with_default('Database host', eel_set_db_config_default_mysql_host()),
            'port' => eel_set_db_config_prompt_with_default('Database port', '3306'),
            'database' => eel_set_db_config_prompt('Database name: '),
        ];
    }

    if ($driver === 'sqlite') {
        return [
            'driver' => $driver,
            'sqlite_path' => eel_set_db_config_prompt('SQLite database path: '),
        ];
    }

    return [
        'driver' => $driver,
        'dsn' => eel_set_db_config_prompt('Database DSN: '),
    ];
}

function eel_set_db_config_complete_builder_settings(array $settings): array
{
    $driver = eel_set_db_config_normalise_driver((string)($settings['driver'] ?? ''));
    $settings['driver'] = $driver;

    if ($driver === 'odbc' && trim((string)($settings['odbc_name'] ?? '')) === '') {
        $settings['odbc_name'] = eel_set_db_config_prompt('ODBC DSN name: ');
    }

    if ($driver === 'mysql') {
        if (trim((string)($settings['host'] ?? '')) === '') {
            $settings['host'] = eel_set_db_config_prompt_with_default('Database host', eel_set_db_config_default_mysql_host());
        }

        if (trim((string)($settings['port'] ?? '')) === '') {
            $settings['port'] = eel_set_db_config_prompt_with_default('Database port', '3306');
        }

        if (trim((string)($settings['database'] ?? '')) === '') {
            $settings['database'] = eel_set_db_config_prompt('Database name: ');
        }
    }

    if ($driver === 'sqlite' && trim((string)($settings['sqlite_path'] ?? '')) === '') {
        $settings['sqlite_path'] = eel_set_db_config_prompt('SQLite database path: ');
    }

    if ($driver === 'custom' && trim((string)($settings['dsn'] ?? '')) === '') {
        $settings['dsn'] = eel_set_db_config_prompt('Database DSN: ');
    }

    return $settings;
}

function eel_set_db_config_should_prompt_for_credentials(string $driver): bool
{
    return $driver !== 'sqlite';
}

function eel_set_db_config_run_tool(array $argv): int
{
    if (PHP_SAPI !== 'cli') {
        eel_set_db_config_error('This tool can only be run from the command line.');
        return 1;
    }

    try {
        $arguments = eel_set_db_config_arguments($argv);

        if ($arguments['help'] === true) {
            eel_set_db_config_writeln(eel_set_db_config_usage());
            return 0;
        }

        $driver = null;
        if ($arguments['dsn'] !== null && trim((string)$arguments['dsn']) !== '') {
            $dsn = (string)$arguments['dsn'];
            $driver = 'custom';
        } else {
            $settings = $arguments['driver'] === null
                ? eel_set_db_config_prompt_dsn_builder()
                : eel_set_db_config_complete_builder_settings($arguments);

            $dsn = eel_set_db_config_build_dsn($settings);
            $driver = eel_set_db_config_normalise_driver((string)($settings['driver'] ?? 'custom'));
        }

        $user = $arguments['user'];
        if ($user === null && eel_set_db_config_should_prompt_for_credentials($driver)) {
            $user = eel_set_db_config_prompt('Database user: ');
        }

        $password = $arguments['password'];
        if ($password === null && eel_set_db_config_should_prompt_for_credentials($driver)) {
            $password = eel_set_db_config_prompt_hidden('Database password: ');
        }

        AppConfigurationStore::setDatabaseConfig((string)$dsn, (string)($user ?? ''), (string)($password ?? ''));
    } catch (Throwable $exception) {
        eel_set_db_config_error($exception->getMessage());
        return 1;
    }

    eel_set_db_config_writeln('Updated db.dsn, db.user, and db.pass in secure/app.php');
    eel_set_db_config_writeln('-EOL-');

    return 0;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(eel_set_db_config_run_tool($argv));
}
