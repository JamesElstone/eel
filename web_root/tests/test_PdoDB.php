<?php
/**
 * EEL Accounts
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
