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
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare(
        'SELECT ? AS first_value, ? AS second_value',
        [
            PDO::ATTR_STATEMENT_CLASS => [
                PdoStatementDB::class,
                [['alpha', 'beta'], true],
            ],
        ]
    );

    if (!$stmt instanceof PdoStatementDB) {
        throw new RuntimeException('Expected prepare() to return a PdoStatementDB instance.');
    }

    $stmt->execute([
        'alpha' => 'A',
        'beta' => 'B',
    ]);

    $row = $stmt->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('Expected a result row for associative execute params.');
    }

    if (($row['first_value'] ?? null) !== 'A' || ($row['second_value'] ?? null) !== 'B') {
        throw new RuntimeException('Associative parameter rewrite did not preserve placeholder order.');
    }

    test_output_line('PdoStatementDB: rewrites associative execute params in named placeholder order.');

    $listStmt = $pdo->prepare(
        'SELECT ? AS first_value, ? AS second_value',
        [
            PDO::ATTR_STATEMENT_CLASS => [
                PdoStatementDB::class,
                [['alpha', 'beta'], true],
            ],
        ]
    );

    if (!$listStmt instanceof PdoStatementDB) {
        throw new RuntimeException('Expected prepare() to return a PdoStatementDB instance for list param test.');
    }

    $listStmt->execute(['X', 'Y']);
    $listRow = $listStmt->fetch();
    if (!is_array($listRow)) {
        throw new RuntimeException('Expected a result row for list execute params.');
    }

    if (($listRow['first_value'] ?? null) !== 'X' || ($listRow['second_value'] ?? null) !== 'Y') {
        throw new RuntimeException('List execute params were not passed through unchanged.');
    }

    test_output_line('PdoStatementDB: leaves list execute params unchanged.');
} catch (Throwable $exception) {
    if (!headers_sent()) {
        http_response_code(500);
    }

    test_output_failure_line('PdoStatementDB test failed: ' . $exception->getMessage());

    if (PHP_SAPI === 'cli') {
        exit(1);
    }
}
