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
$harness->run(SignupResponseHeaderService::class);

$harness->check(SignupResponseHeaderService::class, 'exposes no-store signup response headers', function () use ($harness): void {
    $headers = (new SignupResponseHeaderService())->headers();

    $harness->assertSame('no-referrer', (string)($headers['Referrer-Policy'] ?? ''));
    $harness->assertSame('no-store, no-cache, must-revalidate, max-age=0', (string)($headers['Cache-Control'] ?? ''));
    $harness->assertSame('no-cache', (string)($headers['Pragma'] ?? ''));
    $harness->assertSame('0', (string)($headers['Expires'] ?? ''));
});
