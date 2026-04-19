<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'TestOutput.php';

test_output_bootstrap();

try {
    $config = AppConfigurationStore::config();
    $dbConfig = is_array($config['db'] ?? null) ? $config['db'] : [];

    $dsn = trim((string)($dbConfig['dsn'] ?? ''));
    $username = (string)($dbConfig['user'] ?? '');
    $password = (string)($dbConfig['pass'] ?? '');

    if ($dsn === '') {
        throw new RuntimeException('Database DSN is not configured.');
    }

    test_output_line('PdoDb: db config found.');
    test_output_line('PdoDb: DSN = ' . $dsn);
    test_output_line('PdoDb: username configured = ' . ($username !== '' ? 'yes' : 'no'));
    test_output_line('PdoDb: password configured = ' . ($password !== '' ? 'yes' : 'no'));

    $driver = InterfaceDB::driverName();
    if ($driver === '') {
        throw new RuntimeException('Connected but driver name was empty.');
    }

    test_output_line('PdoDb: connected successfully.');
    test_output_line('PdoDb: driver = ' . $driver);

    $version = InterfaceDB::getServerVersion();
    if ($version === '') {
        throw new RuntimeException('Connected but could not determine server version.');
    }

    test_output_line('PdoDb: server version = ' . $version);
} catch (Throwable $exception) {
    if (!headers_sent()) {
        http_response_code(500);
    }

    test_output_failure_line('PdoDb test failed: ' . $exception->getMessage());

    if (PHP_SAPI === 'cli') {
        exit(1);
    }
}

