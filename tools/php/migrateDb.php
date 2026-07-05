<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

function eel_migration_log(string $message): void
{
    global $eelMigrationDetails;

    if (PHP_SAPI !== 'cli') {
        return;
    }

    if (($eelMigrationDetails ?? false) !== true) {
        return;
    }

    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

function eel_migration_notice(string $message): void
{
    if (PHP_SAPI !== 'cli') {
        return;
    }

    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

function eel_migration_details_requested(array $argv): bool
{
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--details' || $argument === '/details') {
            return true;
        }
    }

    return false;
}

function eel_migration_execution_timeout_description(): string
{
    $configured = ini_get('max_execution_time');
    if ($configured === false || trim((string)$configured) === '') {
        return 'unknown';
    }

    $seconds = (int)$configured;
    if ($seconds <= 0) {
        return 'unlimited (max_execution_time=' . (string)$configured . ')';
    }

    return $seconds . ' second(s) (max_execution_time=' . (string)$configured . ')';
}

$eelMigrationDetails = eel_migration_details_requested($argv ?? []);

eel_migration_notice('PHP SAPI: ' . PHP_SAPI . '; execution timeout: ' . eel_migration_execution_timeout_description() . '.');

if (!defined('APP_ROOT')) {
    eel_migration_log('Loading application bootstrap...');
    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'web_root' . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
    eel_migration_log('Application bootstrap loaded.');
}

$schemaFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'eel_accounts.schema.sql';
$migrationsDirectory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations';

function eel_run_migration_tool(string $schemaFile, string $migrationsDirectory, ?bool $details = null): int
{
    global $eelMigrationDetails;

    if ($details !== null) {
        $eelMigrationDetails = $details;
    }

    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, "This migration runner must be run from the command line.\n");
        return 1;
    }

    $currentMigration = '';

    try {
        eel_migration_log('Starting database migration runner.');
        eel_migration_log('Schema file: ' . $schemaFile);
        eel_migration_log('Migrations directory: ' . $migrationsDirectory);
        eel_migration_confirm_database_connection();

        eel_migration_log('Checking whether the database needs the baseline schema.');
        eel_migration_hydrate_empty_database($schemaFile);
        eel_migration_log('Ensuring schema_migrations table exists.');
        ensureSchemaMigrationsTable();
        eel_migration_log('Reading applied migration list.');
        $applied = appliedMigrations();
        eel_migration_log('Found ' . count($applied) . ' applied migration(s).');
        eel_migration_log('Scanning migration files.');
        $files = migrationFiles($migrationsDirectory);
        eel_migration_log('Found ' . count($files) . ' migration file(s).');
        $pending = array_values(array_filter(
            $files,
            static fn(string $file): bool => !isset($applied[basename($file)])
        ));
        eel_migration_log('Found ' . count($pending) . ' pending migration(s).');

        if ($pending === []) {
            echo "No pending migrations.\n";
            return 0;
        }

        foreach ($pending as $file) {
            $currentMigration = basename($file);
            echo 'Applying ' . $currentMigration . "\n";
            applyMigration($file);
            echo 'Applied ' . $currentMigration . "\n";
            $currentMigration = '';
        }

        echo 'Applied ' . count($pending) . " migration(s).\n";
        return 0;
    } catch (Throwable $exception) {
        fwrite(STDERR, eel_migration_failure_message($exception, $currentMigration) . "\n");
        return 1;
    }
}

function eel_migration_confirm_database_connection(): void
{
    eel_migration_log('Checking database connection...');
    $started = microtime(true);
    $driver = InterfaceDB::driverName();
    $probe = InterfaceDB::fetchColumn('SELECT 1');
    $elapsed = eel_migration_elapsed_ms($started);

    eel_migration_log('Database connection OK. Driver: ' . $driver . '; SELECT 1 returned ' . (string)$probe . ' in ' . $elapsed . 'ms.');
}

function eel_migration_elapsed_ms(float $started): string
{
    return number_format((microtime(true) - $started) * 1000, 1, '.', '');
}

function eel_migration_failure_message(Throwable $exception, string $migration): string
{
    $migration = trim($migration);
    if ($migration === '') {
        return 'Migration failed: ' . $exception->getMessage();
    }

    return 'Migration failed while applying ' . $migration . ': ' . $exception->getMessage();
}

function eel_migration_hydrate_empty_database(string $schemaFile): void
{
    if (!eel_migration_database_has_no_application_tables()) {
        eel_migration_log('Application tables were found; baseline schema load is not needed.');
        return;
    }

    eel_migration_log('No application tables found; loading baseline schema.');
    eel_migration_apply_sql_file($schemaFile, 'baseline schema');
    echo 'Loaded baseline schema from ' . $schemaFile . "\n";
}

function eel_migration_database_has_no_application_tables(): bool
{
    foreach (eel_migration_application_tables() as $table) {
        eel_migration_log('Checking for application table: ' . $table);
        if (InterfaceDB::tableExists($table)) {
            eel_migration_log('Found application table: ' . $table);
            return false;
        }
    }

    eel_migration_log('No known application tables found.');
    return true;
}

function eel_migration_application_tables(): array
{
    return [
        'roles',
        'mobile_country_codes',
        'users',
        'user_account_invites',
        'user_account_invite_deliveries',
        'role_card_permissions',
        'signup_token_rate_limits',
        'signup_verification_rate_limits',
        'user_login_rate_limits',
        'application_activity_flash_history',
        'user_account_audit',
        'user_logon_history',
        'user_totp',
    ];
}

function ensureSchemaMigrationsTable(): void
{
    $started = microtime(true);
    InterfaceDB::execute(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            migration varchar(255) NOT NULL PRIMARY KEY,
            applied_at datetime NOT NULL DEFAULT current_timestamp()
        )'
    );
    eel_migration_log('schema_migrations table is ready in ' . eel_migration_elapsed_ms($started) . 'ms.');
}

function appliedMigrations(): array
{
    $rows = InterfaceDB::fetchAll(
        'SELECT migration
         FROM schema_migrations'
    );
    $applied = [];

    foreach ($rows as $row) {
        $migration = trim((string)($row['migration'] ?? ''));
        if ($migration !== '') {
            $applied[$migration] = true;
        }
    }

    return $applied;
}

function migrationFiles(string $directory): array
{
    eel_migration_log('Checking migration directory exists.');
    if (!is_dir($directory)) {
        throw new RuntimeException('Migration directory was not found: ' . $directory);
    }

    eel_migration_log('Globbing SQL migration files.');
    $files = glob($directory . DIRECTORY_SEPARATOR . '*.sql');
    if ($files === false) {
        eel_migration_log('No migration files could be read from the directory.');
        return [];
    }

    sort($files, SORT_STRING);

    return array_values(array_filter($files, 'is_file'));
}

function applyMigration(string $file): void
{
    $name = basename($file);

    eel_migration_log('Checking transaction state before migration: ' . $name);
    if (InterfaceDB::inTransaction()) {
        throw new RuntimeException('A database transaction is already open before migration: ' . $name);
    }

    eel_migration_log('Beginning transaction for migration: ' . $name);
    InterfaceDB::beginTransaction();

    try {
        eel_migration_execute_sql_file($file, 'Migration could not be read: ' . $name);

        eel_migration_log('Recording migration as applied: ' . $name);
        InterfaceDB::prepareExecute(
            'INSERT INTO schema_migrations (
                migration
            ) VALUES (
                :migration
            )',
            ['migration' => $name]
        );

        if (InterfaceDB::inTransaction()) {
            eel_migration_log('Committing transaction for migration: ' . $name);
            InterfaceDB::commit();
        }
    } catch (Throwable $throwable) {
        if (InterfaceDB::inTransaction()) {
            eel_migration_log('Rolling back transaction for migration: ' . $name);
            InterfaceDB::rollBack();
        }
        throw $throwable;
    }
}

function eel_migration_apply_sql_file(string $file, string $label): void
{
    eel_migration_log('Checking transaction state before loading ' . $label . '.');
    if (InterfaceDB::inTransaction()) {
        throw new RuntimeException('A database transaction is already open before loading ' . $label . '.');
    }

    eel_migration_execute_sql_file($file, ucfirst($label) . ' could not be read: ' . basename($file));
}

function eel_migration_execute_sql_file(string $file, string $readErrorMessage): void
{
    eel_migration_log('Reading SQL file: ' . $file);
    $started = microtime(true);
    $sql = file_get_contents($file);

    if (!is_string($sql)) {
        throw new RuntimeException($readErrorMessage);
    }

    eel_migration_log('Read SQL file in ' . eel_migration_elapsed_ms($started) . 'ms; splitting statements.');
    $started = microtime(true);
    $statements = splitMigrationSql($sql);
    eel_migration_log('Split SQL file into ' . count($statements) . ' statement(s) in ' . eel_migration_elapsed_ms($started) . 'ms.');

    foreach ($statements as $index => $statement) {
        $statementNumber = $index + 1;
        eel_migration_log('Executing statement ' . $statementNumber . ' of ' . count($statements) . ' from ' . basename($file) . ': ' . eel_migration_statement_summary($statement));
        $started = microtime(true);
        InterfaceDB::execute($statement);
        eel_migration_log('Executed statement ' . $statementNumber . ' of ' . count($statements) . ' in ' . eel_migration_elapsed_ms($started) . 'ms.');
    }
}

function eel_migration_statement_summary(string $statement): string
{
    $summary = preg_replace('/\s+/', ' ', trim($statement));
    if (!is_string($summary)) {
        return '';
    }

    if (strlen($summary) > 140) {
        return substr($summary, 0, 137) . '...';
    }

    return $summary;
}

function splitMigrationSql(string $sql): array
{
    $statements = [];
    $statement = '';
    $quote = null;
    $length = strlen($sql);

    for ($index = 0; $index < $length; $index++) {
        $character = $sql[$index];

        if ($quote !== null) {
            $statement .= $character;

            if ($character === $quote) {
                if ($quote === '\'' && $index + 1 < $length && $sql[$index + 1] === '\'') {
                    $statement .= $sql[++$index];
                    continue;
                }

                $quote = null;
            }

            continue;
        }

        if ($character === '\'' || $character === '"' || $character === '`') {
            $quote = $character;
            $statement .= $character;
            continue;
        }

        if ($character === '-' && $index + 1 < $length && $sql[$index + 1] === '-') {
            $index++;
            while (++$index < $length && !in_array($sql[$index], ["\n", "\r"], true)) {
            }
            continue;
        }

        if ($character === '#') {
            while (++$index < $length && !in_array($sql[$index], ["\n", "\r"], true)) {
            }
            continue;
        }

        if ($character === '/' && $index + 1 < $length && $sql[$index + 1] === '*') {
            $index++;
            while (++$index < $length) {
                if ($sql[$index] === '/' && $index > 0 && $sql[$index - 1] === '*') {
                    break;
                }
            }
            continue;
        }

        if ($character === ';') {
            $trimmed = trim($statement);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $statement = '';
            continue;
        }

        $statement .= $character;
    }

    $trimmed = trim($statement);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(eel_run_migration_tool($schemaFile, $migrationsDirectory, eel_migration_details_requested($argv ?? [])));
}
