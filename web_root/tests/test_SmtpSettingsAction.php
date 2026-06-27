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
$harness->check(SmtpSettingsAction::class, 'explains SMTP connection and delivery states', function () use ($harness): void {
    $action = new SmtpSettingsAction();
    $method = new ReflectionMethod(SmtpSettingsAction::class, 'successFlashMessage');
    $method->setAccessible(true);

    $previous = [
        'enabled' => false,
        'transport' => 'smtp',
        'host' => 'old.example.test',
        'port' => 25,
        'username' => 'old',
        'encryption' => 'none',
        'auth_mode' => 'none',
        'from_address' => 'old@example.test',
        'from_name' => 'Old Sender',
        'development_mode' => false,
    ];
    $settings = [
        'enabled' => true,
        'transport' => 'smtp',
        'host' => 'mail.example.test',
        'port' => 587,
        'username' => 'user@example.test',
        'password' => '__unchanged__',
        'encryption' => 'starttls',
        'auth_mode' => 'login',
        'from_address' => 'from@example.test',
        'from_name' => 'New Sender',
        'development_mode' => false,
    ];

    $harness->assertSame(
        'SMTP connection settings updated: transport SMTP, encryption STARTTLS, authentication LOGIN, port 587. SMTP host or username updated. Email sender details updated. Email invitations are enabled. Test mode is disabled.',
        $method->invoke($action, $previous, $settings)
    );

    $settings = $previous;
    $settings['development_mode'] = true;
    $harness->assertSame(
        'Email invitations are disabled. Test mode is enabled.',
        $method->invoke($action, $previous, $settings)
    );
});

$harness->check(SmtpSettingsAction::class, 'limits SMTP port to the valid TCP port range', function () use ($harness): void {
    $action = new SmtpSettingsAction();
    $method = new ReflectionMethod(SmtpSettingsAction::class, 'port');
    $method->setAccessible(true);

    $harness->assertSame(1, $method->invoke($action, 0));
    $harness->assertSame(1, $method->invoke($action, -20));
    $harness->assertSame(587, $method->invoke($action, 587));
    $harness->assertSame(65535, $method->invoke($action, 70000));
});

$harness->check(SmtpSettingsAction::class, 'invalidates add user card after settings changes', function () use ($harness): void {
    $source = (string)file_get_contents(APP_ACTIONS . 'SmtpSettingsAction.php');

    $harness->assertTrue(str_contains($source, "ActionResultFramework::success(['smtp.settings', 'add.user']"));
});
