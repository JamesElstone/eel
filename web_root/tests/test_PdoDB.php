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
$harness->check(PdoDB::class, 'loads static PDO helper', function () use ($harness): void {
    $harness->assertTrue(class_exists(PdoDB::class));
    $harness->assertTrue(method_exists(PdoDB::class, 'prepareExecuteOn'));
});

$harness->check(PdoDB::class, 'explains missing ODBC PDO driver failures', function (): void {
    $reflection = new ReflectionClass(PdoDB::class);
    $method = $reflection->getMethod('connectionExceptionMessage');
    $method->setAccessible(true);

    $message = $method->invoke(null, 'odbc:eelkit', new PDOException('could not find driver'));

    if (!is_string($message) || !str_contains($message, 'pdo_odbc')) {
        throw new RuntimeException('Missing ODBC driver failure did not include the pdo_odbc setup hint.');
    }
});
