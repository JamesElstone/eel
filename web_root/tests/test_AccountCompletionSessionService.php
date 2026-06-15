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
$harness->run(AccountCompletionSessionService::class);

$harness->check(AccountCompletionSessionService::class, 'tracks validated and verified signup sessions', function () use ($harness): void {
    $service = new AccountCompletionSessionService();

    $service->begin(123, 456);
    $harness->assertSame(123, $service->inviteId());
    $harness->assertSame(456, $service->userId());
    $harness->assertTrue($service->isValidated());
    $harness->assertTrue(!$service->isVerified());

    $service->markVerified();
    $harness->assertTrue($service->isVerified());

    $service->clear();
    $harness->assertSame(0, $service->inviteId());
});
