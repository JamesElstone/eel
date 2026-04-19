<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(LogStore::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(LogStore::class, 'normalises line endings and appends log entries', static function () use ($harness): void {
        $store = new LogStore();
        $directory = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'logs';
        $path = $directory . DIRECTORY_SEPARATOR . 'log-store-' . bin2hex(random_bytes(6)) . '.log';

        $store->appendLine($path, "first line\r\n");
        $store->appendLine($path, "second\r\nthird");

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Expected log file to be readable.');
        }

        $harness->assertSame("first line" . PHP_EOL . "second third" . PHP_EOL, $contents);

        unlink($path);
        @rmdir($directory);
    });

    $harness->check(LogStore::class, 'rejects blank log paths', static function () use ($harness): void {
        $store = new LogStore();

        try {
            $store->appendLine('   ', 'message');
            throw new RuntimeException('Expected blank log path to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $harness->assertSame('Log path cannot be blank.', $exception->getMessage());
        }
    });

    $harness->check(LogStore::class, 'requires a directory in the log path', static function () use ($harness): void {
        $store = new LogStore();

        try {
            $store->appendLine('plain.log', 'message');
            throw new RuntimeException('Expected directory-less log path to be rejected.');
        } catch (RuntimeException $exception) {
            $harness->assertSame('Log path must include a directory.', $exception->getMessage());
        }
    });
});
