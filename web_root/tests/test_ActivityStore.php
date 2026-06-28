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
$harness->run(ActivityStore::class, function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof ActivityStore) {
        $harness->skip('Activity store did not instantiate.');
    }

    $normaliseFlashMessages = new ReflectionMethod(ActivityStore::class, 'normaliseFlashMessages');
    $normaliseFlashMessages->setAccessible(true);
    $requestMetadata = new ReflectionMethod(ActivityStore::class, 'requestMetadata');
    $requestMetadata->setAccessible(true);

    $harness->check(ActivityStore::class, 'normalises scalar, plain, and HTML flash messages', function () use ($harness, $instance, $normaliseFlashMessages): void {
        $messages = $normaliseFlashMessages->invoke($instance, [
            'Saved successfully.',
            [
                'type' => 'error',
                'message' => 'Unable to save.',
            ],
            [
                'type' => 'success',
                'message_html' => '<strong>Created</strong> &amp; queued',
            ],
            [
                'type' => 'ignored',
                'message' => '',
            ],
        ]);

        $harness->assertSame([
            [
                'type' => 'success',
                'text' => 'Saved successfully.',
                'html_text' => null,
            ],
            [
                'type' => 'error',
                'text' => 'Unable to save.',
                'html_text' => null,
            ],
            [
                'type' => 'success',
                'text' => 'Created & queued',
                'html_text' => 'Created & queued',
            ],
        ], $messages);
    });

    $harness->check(ActivityStore::class, 'captures bounded request metadata', function () use ($harness, $instance, $requestMetadata): void {
        $configPath = AppConfigurationStore::configPath();
        $originalConfig = is_file($configPath) ? (string)file_get_contents($configPath) : '';
        $request = new RequestFramework(
            ['page' => 'dashboard'],
            [],
            [
                'REQUEST_METHOD' => 'POST',
                'REMOTE_ADDR' => '198.51.100.10',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.10, 198.51.100.10',
                'REQUEST_URI' => '/?page=dashboard&action=save',
                'HTTP_USER_AGENT' => str_repeat('a', 1200),
            ],
            [],
            [],
        );

        try {
            AppConfigurationStore::setWebEnvironmentSettings([
                'base_url_override' => '',
                'trusted_proxy_ips' => ['198.51.100.10'],
                'client_ip_headers' => ['X-Forwarded-For'],
            ]);

            $metadata = $requestMetadata->invoke($instance, $request);

            $harness->assertSame('203.0.113.10', $metadata['ip_address']);
            $harness->assertSame('/?page=dashboard&action=save', $metadata['request_uri']);
            $harness->assertSame(1000, mb_strlen((string)$metadata['user_agent']));
        } finally {
            if ($originalConfig !== '') {
                file_put_contents($configPath, $originalConfig);
                AppConfigurationStore::config(true);
            }
        }
    });

    $harness->check(ActivityStore::class, 'records API activity with normalized metadata', function () use ($harness, $instance): void {
        if (!InterfaceDB::tableExists('application_activity_flash_history')) {
            $harness->skip('activity history table is not available.');
        }

        InterfaceDB::beginTransaction();

        try {
            $instance->recordApiActivity(
                'api_uploads',
                'UploadFile',
                'error',
                " Upload\nfailed ",
                null,
                [
                    'device_id' => str_repeat('d', 80),
                    'ip_address' => '203.0.113.45',
                    'user_agent' => str_repeat('u', 1200),
                ],
                'UploadCard',
                'POSTED',
                '/api/upload?' . str_repeat('q', 2100)
            );

            $row = InterfaceDB::fetchOne(
                'SELECT page_id,
                        action_name,
                        card_action_name,
                        message_type,
                        message_text,
                        message_html_text,
                        request_method,
                        is_ajax,
                        device_id,
                        ip_address,
                        user_agent,
                        request_uri
                 FROM application_activity_flash_history
                 ORDER BY id DESC'
            );

            $harness->assertSame('api_uploads', (string)($row['page_id'] ?? ''));
            $harness->assertSame('UploadFile', (string)($row['action_name'] ?? ''));
            $harness->assertSame('UploadCard', (string)($row['card_action_name'] ?? ''));
            $harness->assertSame('error', (string)($row['message_type'] ?? ''));
            $harness->assertSame('Upload failed', (string)($row['message_text'] ?? ''));
            $harness->assertSame(null, $row['message_html_text'] ?? null);
            $harness->assertSame('POSTED', (string)($row['request_method'] ?? ''));
            $harness->assertSame(0, (int)($row['is_ajax'] ?? 1));
            $harness->assertSame(64, mb_strlen((string)($row['device_id'] ?? '')));
            $harness->assertSame('203.0.113.45', (string)($row['ip_address'] ?? ''));
            $harness->assertSame(1000, mb_strlen((string)($row['user_agent'] ?? '')));
            $harness->assertSame(2048, mb_strlen((string)($row['request_uri'] ?? '')));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});
