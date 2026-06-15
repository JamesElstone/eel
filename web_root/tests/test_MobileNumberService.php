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
$harness->run(MobileNumberService::class);

$harness->check(MobileNumberService::class, 'normalises local UK mobile numbers', function () use ($harness): void {
    $harness->assertSame('+447700900123', MobileNumberService::normaliseFromParts('+44', '07700 900123'));
});

$harness->check(MobileNumberService::class, 'splits normalised mobile numbers into country and local parts', function () use ($harness): void {
    $parts = MobileNumberService::parts('+447700900123');

    $harness->assertSame('+44', (string)($parts['country_code'] ?? ''));
    $harness->assertSame('7700900123', (string)($parts['local_number'] ?? ''));
});
