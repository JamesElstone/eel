<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'TestOutput.php';

test_output_bootstrap();

try {
    $driverName = InterfaceDB::driverName();
    if ($driverName === '') {
        throw new RuntimeException('Expected driverName() to return a non-empty string.');
    }
    test_output_line('InterfaceDB: driverName() returned a value.');

    if (!is_bool(InterfaceDB::isOdbcDriver())) {
        throw new RuntimeException('Expected isOdbcDriver() to return a boolean.');
    }
    test_output_line('InterfaceDB: isOdbcDriver() returned a boolean.');

    $serverVersion = InterfaceDB::getServerVersion();
    if ($serverVersion === '') {
        throw new RuntimeException('Expected getServerVersion() to return a non-empty string.');
    }
    test_output_line('InterfaceDB: getServerVersion() returned a value.');

    $stmt = InterfaceDB::prepareExecute('SELECT 1 AS result');
    $row = $stmt->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('Expected prepareExecute() to return a row for SELECT 1.');
    }
    test_output_line('InterfaceDB: prepareExecute() executed a schema-free select.');

    $preparedStmt = InterfaceDB::prepare('SELECT 2 AS result');
    if (!$preparedStmt instanceof PDOStatement) {
        throw new RuntimeException('Expected prepare() to return a PDOStatement.');
    }
    $preparedStmt->execute();
    if ((string)$preparedStmt->fetchColumn() !== '2') {
        throw new RuntimeException('Expected prepare() to support iterative fetching on the default connection.');
    }
    test_output_line('InterfaceDB: prepare() returned a statement on the default connection.');

    $queryStmt = InterfaceDB::query('SELECT 3 AS result', PDO::FETCH_NUM);
    if (!$queryStmt instanceof PDOStatement) {
        throw new RuntimeException('Expected query() to return a PDOStatement.');
    }
    $queryRow = $queryStmt->fetch();
    if (!is_array($queryRow) || (string)($queryRow[0] ?? '') !== '3') {
        throw new RuntimeException('Expected query() to support explicit fetch modes on the default connection.');
    }
    test_output_line('InterfaceDB: query() supported explicit fetch modes on the default connection.');

    $oneRow = InterfaceDB::fetchOne('SELECT 1 AS result');
    if (!is_array($oneRow)) {
        throw new RuntimeException('Expected fetchOne() to return an array for SELECT 1.');
    }
    test_output_line('InterfaceDB: fetchOne() returned a row.');

    $allRows = InterfaceDB::fetchAll('SELECT 1 AS result');
    if (!is_array($allRows) || count($allRows) < 1) {
        throw new RuntimeException('Expected fetchAll() to return at least one row for SELECT 1.');
    }
    test_output_line('InterfaceDB: fetchAll() returned rows.');

    $sqlitePdo = new PDO('sqlite::memory:');
    $sqlitePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sqlitePdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $sqlitePdo->exec('CREATE TABLE sample_rows (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
    $sqlitePdo->exec("INSERT INTO sample_rows (id, name) VALUES (1, 'Alpha'), (2, 'Beta')");

    if (InterfaceDB::driverNameOn($sqlitePdo) !== 'sqlite') {
        throw new RuntimeException('Expected driverNameOn() to detect sqlite for a supplied PDO connection.');
    }
    test_output_line('InterfaceDB: driverNameOn() detected the supplied PDO driver.');

    if (InterfaceDB::isOdbcDriverOn($sqlitePdo)) {
        throw new RuntimeException('Expected isOdbcDriverOn() to return false for sqlite.');
    }
    test_output_line('InterfaceDB: isOdbcDriverOn() returned false for a non-ODBC supplied PDO connection.');

    $sqliteRows = InterfaceDB::fetchAllOn(
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
    test_output_line('InterfaceDB: fetchAllOn() returned rows from a supplied PDO connection.');

    $sqliteValue = InterfaceDB::fetchColumnOn(
        $sqlitePdo,
        'SELECT name
         FROM sample_rows
         WHERE id = :id',
        ['id' => 2]
    );
    if ($sqliteValue !== 'Beta') {
        throw new RuntimeException('Expected fetchColumnOn() to query the supplied PDO connection.');
    }
    test_output_line('InterfaceDB: fetchColumnOn() queried a supplied PDO connection.');

    $sqlitePreparedStmt = InterfaceDB::prepareOn($sqlitePdo, 'SELECT name FROM sample_rows WHERE id = :id');
    if (!$sqlitePreparedStmt instanceof PDOStatement) {
        throw new RuntimeException('Expected prepareOn() to return a PDOStatement for a supplied PDO connection.');
    }
    $sqlitePreparedStmt->execute(['id' => 1]);
    if ($sqlitePreparedStmt->fetchColumn() !== 'Alpha') {
        throw new RuntimeException('Expected prepareOn() to execute against the supplied PDO connection.');
    }
    test_output_line('InterfaceDB: prepareOn() returned a statement for a supplied PDO connection.');

    $sqliteQueryStmt = InterfaceDB::queryOn($sqlitePdo, 'SELECT name FROM sample_rows ORDER BY id', PDO::FETCH_NUM);
    if (!$sqliteQueryStmt instanceof PDOStatement) {
        throw new RuntimeException('Expected queryOn() to return a PDOStatement for a supplied PDO connection.');
    }
    $sqliteQueryRows = $sqliteQueryStmt->fetchAll();
    if ($sqliteQueryRows !== [[0 => 'Alpha'], [0 => 'Beta']]) {
        throw new RuntimeException('Expected queryOn() to support explicit fetch modes on a supplied PDO connection.');
    }
    test_output_line('InterfaceDB: queryOn() supported explicit fetch modes on a supplied PDO connection.');

    $malformedSqlThrew = false;
    try {
        InterfaceDB::prepareExecuteOn($sqlitePdo, 'SELECT * FROM missing_table');
    } catch (Throwable) {
        $malformedSqlThrew = true;
    }
    if (!$malformedSqlThrew) {
        throw new RuntimeException('Expected prepareExecuteOn() to surface malformed SQL on the supplied PDO connection.');
    }
    test_output_line('InterfaceDB: prepareExecuteOn() surfaces malformed SQL on a supplied PDO connection.');

    $columnValue = InterfaceDB::fetchColumn('SELECT 123 AS result');
    if ((string)$columnValue !== '123') {
        throw new RuntimeException('Expected fetchColumn() to return the first column value.');
    }
    test_output_line('InterfaceDB: fetchColumn() returned the first column.');

    $rowCount = InterfaceDB::execute('SELECT 1');
    if (!is_int($rowCount)) {
        throw new RuntimeException('Expected execute() to return an integer row count.');
    }
    test_output_line('InterfaceDB: execute() returned a row count.');

    $transactionResult = InterfaceDB::transaction(static function (): string {
        if (!InterfaceDB::inTransaction()) {
            throw new RuntimeException('Expected inTransaction() to be true inside transaction().');
        }

        return 'ok';
    });

    if ($transactionResult !== 'ok') {
        throw new RuntimeException('Expected transaction() to return callback result.');
    }

    if (InterfaceDB::inTransaction()) {
        throw new RuntimeException('Expected transaction() to leave no active transaction.');
    }
    test_output_line('InterfaceDB: transaction() managed transaction lifecycle.');

    $nestedTransactionResult = InterfaceDB::transaction(static function (): string {
        if (!InterfaceDB::inTransaction()) {
            throw new RuntimeException('Expected outer transaction() to be active.');
        }

        $innerResult = InterfaceDB::transaction(static function (): string {
            if (!InterfaceDB::inTransaction()) {
                throw new RuntimeException('Expected nested transaction() to reuse the active transaction.');
            }

            return 'inner-ok';
        });

        if ($innerResult !== 'inner-ok') {
            throw new RuntimeException('Expected nested transaction() to return the inner callback result.');
        }

        if (!InterfaceDB::inTransaction()) {
            throw new RuntimeException('Expected outer transaction() to remain active after nested transaction().');
        }

        return 'outer-ok';
    });

    if ($nestedTransactionResult !== 'outer-ok') {
        throw new RuntimeException('Expected nested transaction() to return the outer callback result.');
    }

    if (InterfaceDB::inTransaction()) {
        throw new RuntimeException('Expected nested transaction() to leave no active transaction.');
    }
    test_output_line('InterfaceDB: transaction() reuses an existing transaction when nested.');

    if (!is_bool(InterfaceDB::tableExists('definitely_not_a_real_table_name_12345'))) {
        throw new RuntimeException('Expected tableExists() to return a boolean.');
    }
    test_output_line('InterfaceDB: tableExists() returned a boolean for a missing table.');

    if (!InterfaceDB::tableExistsOn($sqlitePdo, 'sample_rows')) {
        throw new RuntimeException('Expected tableExistsOn() to return true for an existing sqlite table.');
    }
    test_output_line('InterfaceDB: tableExistsOn() returned true for an existing supplied-PDO table.');

    if (InterfaceDB::tableRowCountOn($sqlitePdo, 'sample_rows') !== 2) {
        throw new RuntimeException('Expected tableRowCountOn() to return the sqlite table row count.');
    }
    test_output_line('InterfaceDB: tableRowCountOn() returned the row count for an existing supplied-PDO table.');

    if (InterfaceDB::tableRowCountOn($sqlitePdo, 'missing_rows') !== InterfaceDB::TABLE_ROW_COUNT_TABLE_MISSING) {
        throw new RuntimeException('Expected tableRowCountOn() to return the missing-table code for an absent sqlite table.');
    }
    test_output_line('InterfaceDB: tableRowCountOn() returned the missing-table code for an absent supplied-PDO table.');

    if (!InterfaceDB::columnExistsOn($sqlitePdo, 'sample_rows', 'name')) {
        throw new RuntimeException('Expected columnExistsOn() to return true for an existing sqlite column.');
    }
    test_output_line('InterfaceDB: columnExistsOn() returned true for an existing supplied-PDO column.');

    if (InterfaceDB::columnExistsOn($sqlitePdo, 'sample_rows', 'missing_column')) {
        throw new RuntimeException('Expected columnExistsOn() to return false for a missing sqlite column.');
    }
    test_output_line('InterfaceDB: columnExistsOn() returned false for a missing supplied-PDO column.');
} catch (Throwable $exception) {
    if (!headers_sent()) {
        http_response_code(500);
    }

    test_output_failure_line('InterfaceDB test failed: ' . $exception->getMessage());

    if (PHP_SAPI === 'cli') {
        exit(1);
    }
}
