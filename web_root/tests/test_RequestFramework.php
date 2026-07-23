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

    $harness->check(RequestFramework::class, 'reads nested bracketed JSON values and repeated arrays', function () use ($harness): void {
        $request = new RequestFramework(
            [],
            [],
            [
                'REQUEST_METHOD' => 'POST',
                'CONTENT_TYPE' => 'application/json',
            ],
            [],
            [],
            '{"credential":{"provider":"COMPANIESHOUSE","api_key":"secret"},"cards":["settings","api_keys_editor"],"duplicate":["first","second"]}'
        );

        $credential = $request->input('credential');
        $harness->assertTrue(is_array($credential));
        $harness->assertSame('COMPANIESHOUSE', $credential['provider'] ?? null);
        $harness->assertSame('secret', $credential['api_key'] ?? null);
        $harness->assertSame(['settings', 'api_keys_editor'], $request->input('cards'));
        $harness->assertSame(['first', 'second'], $request->input('duplicate'));
    });

    $harness->check(RequestFramework::class, 'keeps post values ahead of JSON values when post values are merged', function () use ($harness): void {
        $request = new RequestFramework(
            ['shared' => 'query-value'],
            ['shared' => 'post-value'],
            [
                'REQUEST_METHOD' => 'POST',
                'CONTENT_TYPE' => 'application/json',
            ],
            [],
            [],
            '{"shared":"json-value","json_only":"json-only-value"}'
        );

        $harness->assertSame('post-value', $request->post('shared'));
        $harness->assertSame('post-value', $request->input('shared'));
        $harness->assertSame('post-value', $request->postValues()['shared'] ?? null);
        $harness->assertSame('json-only-value', $request->postValues()['json_only'] ?? null);
    });

    $harness->check(RequestFramework::class, 'reads injected raw body when creating a request from globals', function () use ($harness): void {
        $originalGet = $_GET;
        $originalPost = $_POST;
        $originalServer = $_SERVER;
        $originalFiles = $_FILES;
        $originalCookie = $_COOKIE;
        $originalRawBody = $GLOBALS['__request_framework_raw_body'] ?? null;
        $hadRawBody = array_key_exists('__request_framework_raw_body', $GLOBALS);

        try {
            $_GET = [];
            $_POST = [];
            $_FILES = [];
            $_COOKIE = [];
            $_SERVER = [
                'REQUEST_METHOD' => 'POST',
                'CONTENT_TYPE' => 'application/json',
            ];
            $GLOBALS['__request_framework_raw_body'] = '{"intent":"from-raw-hook"}';

            $request = RequestFramework::fromGlobals();

            $harness->assertSame('from-raw-hook', $request->post('intent'));
            $harness->assertSame('from-raw-hook', $request->input('intent'));
        } finally {
            $_GET = $originalGet;
            $_POST = $originalPost;
            $_SERVER = $originalServer;
            $_FILES = $originalFiles;
            $_COOKIE = $originalCookie;

            if ($hadRawBody) {
                $GLOBALS['__request_framework_raw_body'] = $originalRawBody;
            } else {
                unset($GLOBALS['__request_framework_raw_body']);
            }
        }
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
            [
                'upload' => [
                    'name' => 'example.txt',
                    'tmp_name' => '/tmp/example-upload',
                    'error' => UPLOAD_ERR_OK,
                    'size' => 123,
                ],
            ],
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
        $harness->assertSame('example.txt', $request->files()['upload']['name'] ?? null);
        $harness->assertSame('Europe/London', $request->cookie('af_client_timezone'));
        $harness->assertSame('10.0.0.5', $request->remoteAddress());
        $harness->assertSame('443', $request->remotePort());
        $harness->assertTrue($request->isSecure());
    });

    $harness->check(RequestFramework::class, 'maps CGI authorization server values to the authorization header', function () use ($harness): void {
        $request = new RequestFramework(
            [],
            [],
            [
                'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer upload-token-123',
            ],
            [],
            [],
            null,
            []
        );

        $harness->assertSame('Bearer upload-token-123', $request->header('Authorization'));
    });

    $harness->check(RequestFramework::class, 'keeps explicit authorization headers ahead of CGI fallbacks', function () use ($harness): void {
        $request = new RequestFramework(
            [],
            [],
            [
                'AUTHORIZATION' => 'Bearer cgi-token',
                'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer redirect-token',
            ],
            [],
            [
                'Authorization' => 'Bearer explicit-token',
            ],
            null,
            []
        );

        $harness->assertSame('Bearer explicit-token', $request->header('Authorization'));
    });

    $harness->check(RequestFramework::class, 'uses the submitted card action when duplicate card action fields are posted', function () use ($harness): void {
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
            '{"card_action":["SmsSettings","SmsTest"],"_ajax":"1"}'
        );

        $harness->assertSame('SmsTest', $request->cardAction());
    });
});
