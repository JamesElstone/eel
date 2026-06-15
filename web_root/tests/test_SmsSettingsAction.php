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
$harness->check(SmsSettingsAction::class, 'explains SMS invitation and development mode states', function () use ($harness): void {
    $action = new SmsSettingsAction();
    $method = new ReflectionMethod(SmsSettingsAction::class, 'successFlashMessage');
    $method->setAccessible(true);

    $harness->assertSame(
        'SMS settings updated. SMS invitations are enabled. Development/test mode is disabled.',
        $method->invoke($action, true, false)
    );
    $harness->assertSame(
        'SMS settings updated. SMS invitations are disabled. Development/test mode is enabled.',
        $method->invoke($action, false, true)
    );
});
