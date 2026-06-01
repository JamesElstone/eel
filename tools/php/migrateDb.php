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

$schemaFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'eelKit.schema.sql';
$migrationsDirectory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations';

function eel_run_migration_tool(string $schemaFile, string $migrationsDirectory): int
{
    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, "This migration runner must be run from the command line.\n");
        return 1;
    }

    try {
        eel_migration_hydrate_empty_database($schemaFile);
        ensureSchemaMigrationsTable();
        $applied = appliedMigrations();
        $files = migrationFiles($migrationsDirectory);
        $pending = array_values(array_filter(
            $files,
            static fn(string $file): bool => !isset($applied[basename($file)])
        ));

        if ($pending === []) {
            echo "No pending migrations.\n";
            return 0;
        }

        foreach ($pending as $file) {
            applyMigration($file);
            echo 'Applied ' . basename($file) . "\n";
        }

        echo 'Applied ' . count($pending) . " migration(s).\n";
        return 0;
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Migration failed: ' . $exception->getMessage() . "\n");
        return 1;
    }
}

function eel_migration_hydrate_empty_database(string $schemaFile): void
{
    if (!eel_migration_database_has_no_application_tables()) {
        return;
    }

    eel_migration_apply_sql_file($schemaFile, 'baseline schema');
    echo 'Loaded baseline schema from ' . $schemaFile . "\n";
}

function eel_migration_database_has_no_application_tables(): bool
{
    foreach (eel_migration_application_tables() as $table) {
        if (InterfaceDB::tableExists($table)) {
            return false;
        }
    }

    return true;
}

function eel_migration_application_tables(): array
{
    return [
        'roles',
        'mobile_country_codes',
        'users',
        'role_card_permissions',
        'user_login_rate_limits',
        'application_activity_flash_history',
        'user_account_audit',
        'user_logon_history',
        'user_totp',
    ];
}

function ensureSchemaMigrationsTable(): void
{
    InterfaceDB::execute(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            migration varchar(255) NOT NULL PRIMARY KEY,
            applied_at datetime NOT NULL DEFAULT current_timestamp()
        )'
    );
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
    if (!is_dir($directory)) {
        throw new RuntimeException('Migration directory was not found: ' . $directory);
    }

    $files = glob($directory . DIRECTORY_SEPARATOR . '*.sql');
    if ($files === false) {
        return [];
    }

    sort($files, SORT_STRING);

    return array_values(array_filter($files, 'is_file'));
}

function applyMigration(string $file): void
{
    $name = basename($file);

    if (InterfaceDB::inTransaction()) {
        throw new RuntimeException('A database transaction is already open before migration: ' . $name);
    }

    InterfaceDB::beginTransaction();

    try {
        eel_migration_execute_sql_file($file, 'Migration could not be read: ' . $name);

        InterfaceDB::prepareExecute(
            'INSERT INTO schema_migrations (
                migration
            ) VALUES (
                :migration
            )',
            ['migration' => $name]
        );

        if (InterfaceDB::inTransaction()) {
            InterfaceDB::commit();
        }
    } catch (Throwable $throwable) {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
        throw $throwable;
    }
}

function eel_migration_apply_sql_file(string $file, string $label): void
{
    if (InterfaceDB::inTransaction()) {
        throw new RuntimeException('A database transaction is already open before loading ' . $label . '.');
    }

    eel_migration_execute_sql_file($file, ucfirst($label) . ' could not be read: ' . basename($file));
}

function eel_migration_execute_sql_file(string $file, string $readErrorMessage): void
{
    $sql = file_get_contents($file);

    if (!is_string($sql)) {
        throw new RuntimeException($readErrorMessage);
    }

    foreach (splitMigrationSql($sql) as $statement) {
        InterfaceDB::execute($statement);
    }
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
    exit(eel_run_migration_tool($schemaFile, $migrationsDirectory));
}
