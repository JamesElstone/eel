<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

final class InterfaceDBCountingPdoTestDouble extends PDO
{
    public int $prepareCount = 0;
    public int $queryCount = 0;

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->prepareCount++;

        return parent::prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->queryCount++;

        if ($fetchMode === null) {
            return parent::query($query);
        }

        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

$harness = new GeneratedServiceClassTestHarness();

$harness->check(InterfaceDB::class, 'caches table metadata per PDO connection', function () use ($harness): void {
    $pdo = new InterfaceDBCountingPdoTestDouble();
    $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');
    InterfaceDB::clearMetadataCache();

    $firstPrepareCount = $pdo->prepareCount;
    $harness->assertTrue(InterfaceDB::_tableExistsOn($pdo, 'items'));
    $harness->assertSame($firstPrepareCount + 1, $pdo->prepareCount);

    $harness->assertTrue(InterfaceDB::_tableExistsOn($pdo, 'items'));
    $harness->assertSame($firstPrepareCount + 1, $pdo->prepareCount);

    $harness->assertSame(false, InterfaceDB::_tableExistsOn($pdo, 'missing_items'));
    $missingPrepareCount = $pdo->prepareCount;
    $harness->assertSame(false, InterfaceDB::_tableExistsOn($pdo, 'missing_items'));
    $harness->assertSame($missingPrepareCount, $pdo->prepareCount);
});

$harness->check(InterfaceDB::class, 'caches column metadata and supports schema-qualified SQLite names', function () use ($harness): void {
    $pdo = new InterfaceDBCountingPdoTestDouble();
    $pdo->exec("ATTACH DATABASE 'file:interface_db_aux?mode=memory&cache=shared' AS aux");
    $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');
    $pdo->exec('CREATE TABLE aux.items (id INTEGER PRIMARY KEY, description TEXT)');
    InterfaceDB::clearMetadataCache();

    $firstQueryCount = $pdo->queryCount;
    $harness->assertTrue(InterfaceDB::_columnExistsOn($pdo, 'items', 'id'));
    $harness->assertSame($firstQueryCount + 1, $pdo->queryCount);
    $harness->assertTrue(InterfaceDB::_columnExistsOn($pdo, 'items', 'id'));
    $harness->assertSame($firstQueryCount + 1, $pdo->queryCount);

    $harness->assertTrue(InterfaceDB::_columnExistsOn($pdo, 'aux.items', 'description'));
    $harness->assertTrue(InterfaceDB::_columnsExistsOn($pdo, 'aux.items', ['id', 'description']));
    $harness->assertSame(false, InterfaceDB::_columnExistsOn($pdo, 'aux.items', 'missing_column'));
    $missingQueryCount = $pdo->queryCount;
    $harness->assertSame(false, InterfaceDB::_columnExistsOn($pdo, 'aux.items', 'missing_column'));
    $harness->assertSame($missingQueryCount, $pdo->queryCount);

    $harness->assertTrue(InterfaceDB::_tableExistsOn($pdo, 'aux.items'));
    $harness->assertSame(0, InterfaceDB::_tableRowCountOn($pdo, 'aux.items'));
});

$harness->check(InterfaceDB::class, 'does not share metadata cache entries between PDO connections', function () use ($harness): void {
    $firstPdo = new InterfaceDBCountingPdoTestDouble();
    $secondPdo = new InterfaceDBCountingPdoTestDouble();
    $firstPdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY)');
    $secondPdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY)');
    InterfaceDB::clearMetadataCache();

    InterfaceDB::_tableExistsOn($firstPdo, 'items');
    InterfaceDB::_tableExistsOn($firstPdo, 'items');
    InterfaceDB::_tableExistsOn($secondPdo, 'items');

    $harness->assertSame(1, $firstPdo->prepareCount);
    $harness->assertSame(1, $secondPdo->prepareCount);
});

$harness->check(InterfaceDB::class, 'clears metadata after an explicit schema cache reset', function () use ($harness): void {
    $pdo = new InterfaceDBCountingPdoTestDouble();
    InterfaceDB::clearMetadataCache();

    $harness->assertSame(false, InterfaceDB::_tableExistsOn($pdo, 'created_later'));
    $pdo->exec('CREATE TABLE created_later (id INTEGER PRIMARY KEY)');
    InterfaceDB::clearMetadataCache();

    $harness->assertTrue(InterfaceDB::_tableExistsOn($pdo, 'created_later'));
});

$harness->check(InterfaceDB::class, 'invalidates metadata after successful schema mutations', function () use ($harness): void {
    $pdo = new InterfaceDBCountingPdoTestDouble();
    $connection = new ReflectionProperty(InterfaceDB::class, 'connection');
    $originalConnection = $connection->getValue();
    $connection->setValue(null, $pdo);

    try {
        InterfaceDB::clearMetadataCache();
        $harness->assertSame(false, InterfaceDB::tableExists('created_by_interface'));
        InterfaceDB::execute('CREATE TABLE created_by_interface (id INTEGER PRIMARY KEY)');
        $harness->assertTrue(InterfaceDB::tableExists('created_by_interface'));
    } finally {
        $connection->setValue(null, $originalConnection);
        InterfaceDB::clearMetadataCache();
    }
});

$harness->check(InterfaceDB::class, 'reuses cached table metadata in row-count helpers', function () use ($harness): void {
    $pdo = new InterfaceDBCountingPdoTestDouble();
    $pdo->exec('CREATE TABLE count_items (id INTEGER PRIMARY KEY)');
    $pdo->exec('INSERT INTO count_items (id) VALUES (1), (2)');
    InterfaceDB::clearMetadataCache();

    $harness->assertSame(2, InterfaceDB::_tableRowCountOn($pdo, 'count_items'));
    $firstCountQuery = $pdo->prepareCount;
    $harness->assertSame(2, InterfaceDB::_tableRowCountOn($pdo, 'count_items'));
    $harness->assertSame($firstCountQuery + 1, $pdo->prepareCount);
});
