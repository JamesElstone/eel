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

$harness->check(GeneratedServiceClassTestHarness::class, 'accepts an expected throwable type and its subclasses', function () use ($harness): void {
    $harness->assertThrows(
        static function (): void {
            throw new InvalidArgumentException('invalid value');
        },
        Exception::class
    );
});

$harness->check(GeneratedServiceClassTestHarness::class, 'reports when an expected throwable is not thrown', function () use ($harness): void {
    try {
        $harness->assertThrows(static function (): void {
        }, InvalidArgumentException::class);
        throw new RuntimeException('assertThrows did not fail when its callback returned normally.');
    } catch (RuntimeException $exception) {
        $harness->assertTrue(str_contains($exception->getMessage(), 'no exception was thrown'));
        $harness->assertTrue(str_contains($exception->getMessage(), InvalidArgumentException::class));
    }
});

$harness->check(GeneratedServiceClassTestHarness::class, 'reports a mismatched throwable type', function () use ($harness): void {
    try {
        $harness->assertThrows(
            static function (): void {
                throw new LogicException('wrong type');
            },
            InvalidArgumentException::class
        );
        throw new RuntimeException('assertThrows did not fail for a mismatched throwable type.');
    } catch (RuntimeException $exception) {
        $harness->assertTrue(str_contains($exception->getMessage(), InvalidArgumentException::class));
        $harness->assertTrue(str_contains($exception->getMessage(), LogicException::class));
    }
});
