<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'TestOutput.php';

test_output_bootstrap();

try {
    $directAccessBlocked = false;
    try {
        PdoDB::connectionForInterfaceDB();
    } catch (RuntimeException) {
        $directAccessBlocked = true;
    }

    if (!$directAccessBlocked) {
        throw new RuntimeException('Expected PdoDB::connectionForInterfaceDB() to reject non-InterfaceDB callers.');
    }
    test_output_line('PdoDB: blocks direct default PDO access outside InterfaceDB.');

    $serverVersion = InterfaceDB::getServerVersion();
    if ($serverVersion === '') {
        throw new RuntimeException('Expected getServerVersion() to return a non-empty string.');
    }
    test_output_line('PdoDB: getServerVersion() returned a value.');

    $stmt = InterfaceDB::prepareExecute('SELECT 1 AS result');
    $row = $stmt->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('Expected prepareExecute() to return a row for SELECT 1.');
    }
    test_output_line('PdoDB: prepareExecute() executed a schema-free select.');

    $oneRow = InterfaceDB::fetchOne('SELECT 1 AS result');
    if (!is_array($oneRow)) {
        throw new RuntimeException('Expected fetchOne() to return an array for SELECT 1.');
    }
    test_output_line('PdoDB: fetchOne() returned a row.');

    $allRows = InterfaceDB::fetchAll('SELECT 1 AS result');
    if (!is_array($allRows) || count($allRows) < 1) {
        throw new RuntimeException('Expected fetchAll() to return at least one row for SELECT 1.');
    }
    test_output_line('PdoDB: fetchAll() returned rows.');

    $sqlitePdo = new PDO('sqlite::memory:');
    $sqlitePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sqlitePdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $sqlitePdo->exec('CREATE TABLE sample_rows (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
    $sqlitePdo->exec("INSERT INTO sample_rows (id, name) VALUES (1, 'Alpha'), (2, 'Beta')");

    $sqliteRows = InterfaceDB::_fetchAllOn(
        $sqlitePdo,
        'SELECT id, name
         FROM sample_rows
         WHERE id >= :minimum_id
         ORDER BY id',
        ['minimum_id' => 1]
    );
    if (count($sqliteRows) !== 2) {
        throw new RuntimeException('Expected fetchAllOn() to return rows from the supplied PDO connection.');
    }
    test_output_line('PdoDB: fetchAllOn() returned rows from a supplied PDO connection.');

    $sqliteValue = InterfaceDB::_fetchColumnOn(
        $sqlitePdo,
        'SELECT name
         FROM sample_rows
         WHERE id = :id',
        ['id' => 2]
    );
    if ($sqliteValue !== 'Beta') {
        throw new RuntimeException('Expected fetchColumnOn() to query the supplied PDO connection.');
    }
    test_output_line('PdoDB: fetchColumnOn() queried a supplied PDO connection.');

    $columnValue = InterfaceDB::fetchColumn('SELECT 123 AS result');
    if ((string)$columnValue !== '123') {
        throw new RuntimeException('Expected fetchColumn() to return the first column value.');
    }
    test_output_line('PdoDB: fetchColumn() returned the first column.');

    $rowCount = InterfaceDB::execute('SELECT 1');
    if (!is_int($rowCount)) {
        throw new RuntimeException('Expected execute() to return an integer row count.');
    }
    test_output_line('PdoDB: execute() returned a row count.');

    if (!is_bool(InterfaceDB::tableExists('definitely_not_a_real_table_name_12345'))) {
        throw new RuntimeException('Expected tableExists() to return a boolean.');
    }
    test_output_line('PdoDB: tableExists() returned a boolean for a missing table.');

} catch (Throwable $exception) {
    if (!headers_sent()) {
        http_response_code(500);
    }

    test_output_failure_line('PdoDB test failed: ' . $exception->getMessage());

    if (PHP_SAPI === 'cli') {
        exit(1);
    }
}


