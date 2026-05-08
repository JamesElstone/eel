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
$harness->run(RequestFramework::class, function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    $harness->check(RequestFramework::class, 'reads JSON body values from input and post accessors', function () use ($harness): void {
        $request = new RequestFramework(
            ['page' => 'settings'],
            [],
            [
                'REQUEST_METHOD' => 'POST',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            [],
            [],
            '{"card_action":"AppPaths","_ajax":"1","cards":["api_mode","check_file_paths"],"intent":"check"}'
        );

        $harness->assertTrue($request->isAjax());
        $harness->assertSame('AppPaths', $request->cardAction());
        $harness->assertSame('check', $request->post('intent'));
        $harness->assertSame(['api_mode', 'check_file_paths'], $request->cardKeys());
    });

    $harness->check(RequestFramework::class, 'centralises headers cookies and server values', function () use ($harness): void {
        $request = new RequestFramework(
            [],
            [],
            [
                'HTTP_X_FORWARDED_FOR' => '198.51.100.25',
                'REMOTE_ADDR' => '10.0.0.5',
                'REMOTE_PORT' => '443',
                'HTTPS' => 'on',
            ],
            [],
            [
                'X-AntiFraud-Client-Device-ID' => 'device-123',
            ],
            null,
            [
                'af_client_timezone' => 'Europe/London',
            ]
        );

        $harness->assertSame('device-123', $request->header('X-AntiFraud-Client-Device-ID'));
        $harness->assertSame('198.51.100.25', $request->header('X-Forwarded-For'));
        $harness->assertSame('Europe/London', $request->cookie('af_client_timezone'));
        $harness->assertSame('10.0.0.5', $request->remoteAddress());
        $harness->assertSame('443', $request->remotePort());
        $harness->assertTrue($request->isSecure());
    });
});
