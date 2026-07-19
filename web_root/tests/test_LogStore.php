<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(LogStore::class);

$logPath = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'log-store-buffered' . DIRECTORY_SEPARATOR . 'buffered.log';
$logDirectory = dirname($logPath);
if (!is_dir($logDirectory) && !mkdir($logDirectory, 0777, true) && !is_dir($logDirectory)) {
    throw new RuntimeException('Unable to create LogStore test directory.');
}

@unlink($logPath);

$harness->check(LogStore::class, 'buffers lines until an explicit flush', function () use ($harness, $logPath): void {
    $store = new LogStore();
    $store->appendBufferedLine($logPath, 'first');
    $store->appendBufferedLine($logPath, 'second');

    $harness->assertSame('', (string)file_get_contents($logPath));

    $store->flush($logPath);
    $harness->assertSame('first' . PHP_EOL . 'second' . PHP_EOL, (string)file_get_contents($logPath));
});

$harness->check(LogStore::class, 'keeps synchronous appendLine behavior', function () use ($harness, $logPath): void {
    $store = new LogStore();
    $store->appendLine($logPath, 'third');

    $harness->assertSame('first' . PHP_EOL . 'second' . PHP_EOL . 'third' . PHP_EOL, (string)file_get_contents($logPath));
});

$harness->check(PdoDB::class, 'formats SQL logs with microsecond timestamps', function () use ($harness): void {
    $reflection = new ReflectionClass(PdoDB::class);
    $method = $reflection->getMethod('formatLogLine');
    $method->setAccessible(true);

    $line = (string)$method->invoke(null, 'SELECT :id', ['id' => 7]);
    $fields = str_getcsv($line, ',', '"', '');

    $harness->assertSame(3, count($fields));
    $harness->assertSame('SELECT :id', $fields[1]);
    $harness->assertSame('{"id":7}', $fields[2]);
    $harness->assertSame(1, preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', $fields[0]));
});

@unlink($logPath);
