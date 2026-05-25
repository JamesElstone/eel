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
$expectedHeaders = [
    'X-Frame-Options' => 'SAMEORIGIN',
    'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'",
    'X-Content-Type-Options' => 'nosniff',
    'Referrer-Policy' => 'strict-origin-when-cross-origin',
    'Permissions-Policy' => 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()',
    'X-Permitted-Cross-Domain-Policies' => 'none',
    'Cross-Origin-Opener-Policy' => 'same-origin',
    'Cross-Origin-Resource-Policy' => 'same-origin',
];

$harness->check(ResponseFramework::class, 'builds HTML responses through the factory', function () use ($harness): void {
    global $expectedHeaders;

    $response = ResponseFramework::html('<p>Hello</p>', 201);
    $reflection = new ReflectionClass($response);

    $harness->assertSame(201, $reflection->getProperty('statusCode')->getValue($response));
    $harness->assertSame('text/html; charset=utf-8', $reflection->getProperty('contentType')->getValue($response));
    $harness->assertSame('<p>Hello</p>', $reflection->getProperty('body')->getValue($response));
    $harness->assertSame($expectedHeaders, $reflection->getProperty('headers')->getValue($response));
});

$harness->check(ResponseFramework::class, 'builds JSON responses through the factory', function () use ($harness): void {
    global $expectedHeaders;

    $response = ResponseFramework::json(['ok' => true], 202);
    $reflection = new ReflectionClass($response);

    $harness->assertSame(202, $reflection->getProperty('statusCode')->getValue($response));
    $harness->assertSame('application/json; charset=utf-8', $reflection->getProperty('contentType')->getValue($response));
    $harness->assertSame('{"ok":true}', $reflection->getProperty('body')->getValue($response));
    $harness->assertSame($expectedHeaders, $reflection->getProperty('headers')->getValue($response));
});
