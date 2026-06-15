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
$harness->run(SmsService::class);

$withSmsSettings = static function (array $settings, callable $callback): void {
    $path = AppConfigurationStore::configPath();
    $original = is_file($path) ? (string)file_get_contents($path) : null;

    try {
        AppConfigurationStore::setSmsSettings($settings);
        $callback();
    } finally {
        if ($original !== null) {
            file_put_contents($path, $original);
        }
        AppConfigurationStore::config(true);
    }
};

$harness->check(SmsService::class, 'builds sms-gateway send URLs from telephone-number templates', function () use ($harness, $withSmsSettings): void {
    $withSmsSettings([
        'api_url' => 'http://hydrogen.int.elstone.net/sms-gateway/send/{telephone_number}',
    ], function () use ($harness): void {
        $service = new SmsService();
        $method = new ReflectionMethod(SmsService::class, 'sendUrl');
        $method->setAccessible(true);

        $harness->assertSame(
            'http://hydrogen.int.elstone.net/sms-gateway/send/%2B447700900000',
            $method->invoke($service, '+447700900000')
        );
    });
});

$harness->check(SmsService::class, 'rejects SMS API URLs without telephone-number templates', function () use ($harness, $withSmsSettings): void {
    $withSmsSettings([
        'api_url' => 'https://provider.example.test/send',
    ], function () use ($harness): void {
        $service = new SmsService();
        $method = new ReflectionMethod(SmsService::class, 'sendUrl');
        $method->setAccessible(true);

        $harness->assertSame('', $method->invoke($service, '+447700900000'));
    });
});

$harness->check(SmsService::class, 'preserves a blank SMS auth header', function () use ($harness, $withSmsSettings): void {
    $withSmsSettings([
        'auth_header' => '',
    ], function () use ($harness): void {
        $service = new SmsService();
        $method = new ReflectionMethod(SmsService::class, 'authHeader');
        $method->setAccessible(true);

        $harness->assertSame('', $method->invoke($service));
    });
});

$harness->check(SmsService::class, 'reads sms-gateway response status from JSON bodies', function () use ($harness): void {
    $service = new SmsService();
    $method = new ReflectionMethod(SmsService::class, 'responseStatus');
    $method->setAccessible(true);

    $harness->assertSame('sent', $method->invoke($service, '{"status":"sent","message":"SMS sent"}'));
    $harness->assertSame('no_service', $method->invoke($service, '{"status":"no_service"}'));
    $harness->assertSame('', $method->invoke($service, 'not json'));
});

$harness->check(SmsService::class, 'renders invite sender and recipient names in SMS templates', function () use ($harness, $withSmsSettings): void {
    $path = AppConfigurationStore::configPath();
    $original = is_file($path) ? (string)file_get_contents($path) : null;

    try {
        AppConfigurationStore::setInvitationSettings([
            'sms_template' => 'Hi {recipient_name}, {display_name} ({display_email}, {display_mobile}) invited {recipient} to finish {app_name}: {link} before {expires_at}',
        ]);
        $message = (new SmsService())->inviteMessage(
            'https://example.test/signup',
            '2026-06-20 10:00:00',
            'James Admin',
            'Invite Target',
            'JAMES@EXAMPLE.TEST',
            '+447700900123'
        );

        $harness->assertSame(
            'Hi Invite Target, James Admin (james@example.test, +447700900123) invited Invite Target to finish eelKit Framework Test: https://example.test/signup before 2026-06-20 10:00:00',
            $message
        );
    } finally {
        if ($original !== null) {
            file_put_contents($path, $original);
        }
        AppConfigurationStore::config(true);
    }
});
