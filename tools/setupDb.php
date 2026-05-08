<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'migrateDb.php';

function eel_run_database_setup_tool(string $schemaFile, string $migrationsDirectory): int
{
    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, "This database setup tool must be run from the command line.\n");
        return 1;
    }

    try {
        $configPath = AppConfigurationStore::ensureStoredConfig();
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Configuration setup failed: ' . $exception->getMessage() . "\n");
        return 1;
    }

    echo 'Application config ready at ' . $configPath . "\n";

    return eel_run_migration_tool($schemaFile, $migrationsDirectory);
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(eel_run_database_setup_tool($schemaFile, $migrationsDirectory));
}
