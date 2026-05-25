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
$harness->check(ApplicationSettingsAction::class, 'reads checkbox values from ajax duplicate fields', function () use ($harness): void {
    $action = new ApplicationSettingsAction();
    $method = new ReflectionMethod(ApplicationSettingsAction::class, 'checkboxValue');
    $method->setAccessible(true);

    $request = new RequestFramework(
        ['page' => 'settings'],
        [],
        [
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => 'application/json',
        ],
        [],
        [],
        '{"checked":["0","1"],"unchecked":"0"}'
    );

    $harness->assertSame(true, $method->invoke($action, $request, 'checked'));
    $harness->assertSame(false, $method->invoke($action, $request, 'unchecked'));
    $harness->assertSame(false, $method->invoke($action, $request, 'missing'));
});
