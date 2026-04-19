<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->check(ResponseFramework::class, 'builds HTML responses through the factory', function () use ($harness): void {
    $response = ResponseFramework::html('<p>Hello</p>', 201);
    $reflection = new ReflectionClass($response);

    $harness->assertSame(201, $reflection->getProperty('statusCode')->getValue($response));
    $harness->assertSame('text/html; charset=utf-8', $reflection->getProperty('contentType')->getValue($response));
    $harness->assertSame('<p>Hello</p>', $reflection->getProperty('body')->getValue($response));
    $harness->assertSame([
        'X-Frame-Options' => 'SAMEORIGIN',
        'Content-Security-Policy' => "frame-ancestors 'self'",
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ], $reflection->getProperty('headers')->getValue($response));
});

$harness->check(ResponseFramework::class, 'builds JSON responses through the factory', function () use ($harness): void {
    $response = ResponseFramework::json(['ok' => true], 202);
    $reflection = new ReflectionClass($response);

    $harness->assertSame(202, $reflection->getProperty('statusCode')->getValue($response));
    $harness->assertSame('application/json; charset=utf-8', $reflection->getProperty('contentType')->getValue($response));
    $harness->assertSame('{"ok":true}', $reflection->getProperty('body')->getValue($response));
    $harness->assertSame([
        'X-Frame-Options' => 'SAMEORIGIN',
        'Content-Security-Policy' => "frame-ancestors 'self'",
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ], $reflection->getProperty('headers')->getValue($response));
});
