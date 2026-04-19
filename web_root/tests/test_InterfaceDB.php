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
    $sqlitePdo->exec('CREATE TABLE sample_rows (id INTEGER PRIMARY KEY, name TEXT NOT NULL, tag TEXT NULL)');
    $sqlitePdo->exec("INSERT INTO sample_rows (id, name, tag) VALUES (1, 'Alpha', 'x'), (2, 'Beta', NULL)");

    if (InterfaceDB::_driverNameOn($sqlitePdo) !== 'sqlite') {
        throw new RuntimeException('Expected driverNameOn() to detect sqlite for a supplied PDO connection.');
    }
    test_output_line('InterfaceDB: driverNameOn() detected the supplied PDO driver.');

    if (InterfaceDB::_isOdbcDriverOn($sqlitePdo)) {
        throw new RuntimeException('Expected isOdbcDriverOn() to return false for sqlite.');
    }
    test_output_line('InterfaceDB: isOdbcDriverOn() returned false for a non-ODBC supplied PDO connection.');

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
    test_output_line('InterfaceDB: fetchAllOn() returned rows from a supplied PDO connection.');

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
    test_output_line('InterfaceDB: fetchColumnOn() queried a supplied PDO connection.');

    $sqliteIgnoredParamValue = InterfaceDB::_fetchColumnOn(
        $sqlitePdo,
        'SELECT name
         FROM sample_rows
         WHERE id = :id',
        [
            'id' => 1,
            'unused_param' => 'ignored',
        ]
    );
    if ($sqliteIgnoredParamValue !== 'Alpha') {
        throw new RuntimeException('Expected prepareExecuteOn() to ignore unused named parameters on the supplied PDO connection.');
    }
    test_output_line('InterfaceDB: prepareExecuteOn() ignored unused named parameters on a supplied PDO connection.');

    $sqlitePreparedStmt = InterfaceDB::_prepareOn($sqlitePdo, 'SELECT name FROM sample_rows WHERE id = :id');
    if (!$sqlitePreparedStmt instanceof PDOStatement) {
        throw new RuntimeException('Expected prepareOn() to return a PDOStatement for a supplied PDO connection.');
    }
    $sqlitePreparedStmt->execute(['id' => 1]);
    if ($sqlitePreparedStmt->fetchColumn() !== 'Alpha') {
        throw new RuntimeException('Expected prepareOn() to execute against the supplied PDO connection.');
    }
    test_output_line('InterfaceDB: prepareOn() returned a statement for a supplied PDO connection.');

    $sqliteQueryStmt = InterfaceDB::_queryOn($sqlitePdo, 'SELECT name FROM sample_rows ORDER BY id', PDO::FETCH_NUM);
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
        InterfaceDB::_prepareExecuteOn($sqlitePdo, 'SELECT * FROM missing_table');
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

    if (!InterfaceDB::_tableExistsOn($sqlitePdo, 'sample_rows')) {
        throw new RuntimeException('Expected tableExistsOn() to return true for an existing sqlite table.');
    }
    test_output_line('InterfaceDB: tableExistsOn() returned true for an existing supplied-PDO table.');

    if (InterfaceDB::_tableRowCountOn($sqlitePdo, 'sample_rows') !== 2) {
        throw new RuntimeException('Expected tableRowCountOn() to return the sqlite table row count.');
    }
    test_output_line('InterfaceDB: tableRowCountOn() returned the row count for an existing supplied-PDO table.');

    if (InterfaceDB::_tableRowCountOn($sqlitePdo, 'missing_rows') !== InterfaceDB::TABLE_ROW_COUNT_TABLE_MISSING) {
        throw new RuntimeException('Expected tableRowCountOn() to return the missing-table code for an absent sqlite table.');
    }
    test_output_line('InterfaceDB: tableRowCountOn() returned the missing-table code for an absent supplied-PDO table.');

    if (InterfaceDB::_countWhereOn($sqlitePdo, 'sample_rows', 'name', 'Alpha') !== 1) {
        throw new RuntimeException('Expected countWhereOn() to support the field/value overload on a supplied PDO connection.');
    }
    test_output_line('InterfaceDB: countWhereOn() supported the field/value overload on a supplied PDO connection.');

    if (InterfaceDB::_countWhereOn($sqlitePdo, 'sample_rows', ['name' => 'Beta']) !== 1) {
        throw new RuntimeException('Expected countWhereOn() to support associative condition arrays on a supplied PDO connection.');
    }
    test_output_line('InterfaceDB: countWhereOn() supported associative condition arrays on a supplied PDO connection.');

    if (InterfaceDB::_countWhereOn($sqlitePdo, 'sample_rows', ['id' => 2, 'name' => 'Beta']) !== 1) {
        throw new RuntimeException('Expected countWhereOn() to support multiple AND conditions on a supplied PDO connection.');
    }
    test_output_line('InterfaceDB: countWhereOn() supported multiple AND conditions on a supplied PDO connection.');

    if (InterfaceDB::_countWhereOn($sqlitePdo, 'missing_rows', 'name', 'Alpha') !== InterfaceDB::TABLE_ROW_COUNT_TABLE_MISSING) {
        throw new RuntimeException('Expected countWhereOn() to return the missing-table code for an absent supplied-PDO table.');
    }
    test_output_line('InterfaceDB: countWhereOn() returned the missing-table code for an absent supplied-PDO table.');

    if (InterfaceDB::_countWhereOn($sqlitePdo, 'sample_rows', 'missing_column', 'Alpha') !== InterfaceDB::TABLE_ROW_COUNT_ERROR) {
        throw new RuntimeException('Expected countWhereOn() to return the error code for a missing supplied-PDO column.');
    }
    test_output_line('InterfaceDB: countWhereOn() returned the error code for a missing supplied-PDO column.');

    if (InterfaceDB::_countWhereOn($sqlitePdo, 'sample_rows', ['tag' => null]) !== 1) {
        throw new RuntimeException('Expected countWhereOn() to treat null conditions as IS NULL on a supplied PDO connection.');
    }
    test_output_line('InterfaceDB: countWhereOn() treated null conditions as IS NULL on a supplied PDO connection.');

    if (InterfaceDB::_countWhereNotNullOn($sqlitePdo, 'sample_rows', 'tag') !== 1) {
        throw new RuntimeException('Expected countWhereNotNullOn() to count non-null values on a supplied PDO connection.');
    }
    test_output_line('InterfaceDB: countWhereNotNullOn() counted non-null values on a supplied PDO connection.');

    if (InterfaceDB::_countWhereNotNullOn($sqlitePdo, 'sample_rows', 'tag', ['name' => 'Alpha']) !== 1) {
        throw new RuntimeException('Expected countWhereNotNullOn() to support extra equality conditions on a supplied PDO connection.');
    }
    test_output_line('InterfaceDB: countWhereNotNullOn() supported extra equality conditions on a supplied PDO connection.');

    if (InterfaceDB::_countInOn($sqlitePdo, 'sample_rows', 'id', [1, 2]) !== 2) {
        throw new RuntimeException('Expected countInOn() to support IN lists on a supplied PDO connection.');
    }
    test_output_line('InterfaceDB: countInOn() supported IN lists on a supplied PDO connection.');

    if (InterfaceDB::_countInOn($sqlitePdo, 'sample_rows', 'id', [1, 2], ['name' => 'Beta']) !== 1) {
        throw new RuntimeException('Expected countInOn() to support extra equality conditions on a supplied PDO connection.');
    }
    test_output_line('InterfaceDB: countInOn() supported extra equality conditions on a supplied PDO connection.');

    if (InterfaceDB::_countWhereCompareOn($sqlitePdo, 'sample_rows', 'id', '>', 1) !== 1) {
        throw new RuntimeException('Expected countWhereCompareOn() to support comparison operators on a supplied PDO connection.');
    }
    test_output_line('InterfaceDB: countWhereCompareOn() supported comparison operators on a supplied PDO connection.');

    if (InterfaceDB::_countWhereCompareOn($sqlitePdo, 'sample_rows', 'id', '<>', 1, ['name' => 'Beta']) !== 1) {
        throw new RuntimeException('Expected countWhereCompareOn() to support extra equality conditions on a supplied PDO connection.');
    }
    test_output_line('InterfaceDB: countWhereCompareOn() supported extra equality conditions on a supplied PDO connection.');

    if (!InterfaceDB::_columnExistsOn($sqlitePdo, 'sample_rows', 'name')) {
        throw new RuntimeException('Expected columnExistsOn() to return true for an existing sqlite column.');
    }
    test_output_line('InterfaceDB: columnExistsOn() returned true for an existing supplied-PDO column.');

    if (InterfaceDB::_columnExistsOn($sqlitePdo, 'sample_rows', 'missing_column')) {
        throw new RuntimeException('Expected columnExistsOn() to return false for a missing sqlite column.');
    }
    test_output_line('InterfaceDB: columnExistsOn() returned false for a missing supplied-PDO column.');

    if (!InterfaceDB::_columnExistsOn($sqlitePdo, ' sample_rows ', ' name ')) {
        throw new RuntimeException('Expected columnExistsOn() to trim table and column names before testing sqlite columns.');
    }
    test_output_line('InterfaceDB: columnExistsOn() trims supplied table and column names.');

    if (!InterfaceDB::_columnsExistsOn($sqlitePdo, 'sample_rows', ['id', 'name'])) {
        throw new RuntimeException('Expected columnsExistsOn() to return true when all requested columns exist.');
    }
    test_output_line('InterfaceDB: columnsExistsOn() returned true when all requested columns exist.');

    if (InterfaceDB::_columnsExistsOn($sqlitePdo, 'sample_rows', ['id', 'missing_column'])) {
        throw new RuntimeException('Expected columnsExistsOn() to return false when a requested column is missing.');
    }
    test_output_line('InterfaceDB: columnsExistsOn() returned false when a requested column is missing.');
} catch (Throwable $exception) {
    if (!headers_sent()) {
        http_response_code(500);
    }

    test_output_failure_line('InterfaceDB test failed: ' . $exception->getMessage());

    if (PHP_SAPI === 'cli') {
        exit(1);
    }
}
