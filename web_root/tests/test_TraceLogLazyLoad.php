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

$harness->check('logDetails', 'is available without eagerly loading the trace logger class', function () use ($harness): void {
    $harness->assertTrue(function_exists('logDetails'));
    $harness->assertTrue(!class_exists('TraceLogFramework', false));
});
