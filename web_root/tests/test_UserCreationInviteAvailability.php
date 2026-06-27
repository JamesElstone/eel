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

$withRestoredConfig = static function (callable $callback): void {
    $path = AppConfigurationStore::configPath();
    $previous = is_file($path) ? (string)file_get_contents($path) : null;

    try {
        $callback();
    } finally {
        if ($previous === null) {
            if (is_file($path)) {
                unlink($path);
            }
        } else {
            file_put_contents($path, $previous);
        }

        AppConfigurationStore::config(true);
    }
};

$harness->check(UserManagementService::class, 'hides invite creation without enabled invitation settings', function () use ($harness, $withRestoredConfig): void {
    $withRestoredConfig(function () use ($harness): void {
        AppConfigurationStore::setInvitationSettings(['enabled' => false]);
        AppConfigurationStore::setSmtpSettings([
            'enabled' => true,
            'development_mode' => false,
            'transport' => 'mail',
            'from_address' => 'from@example.test',
        ]);

        $availability = (new UserManagementService())->userCreationInviteAvailability();

        $harness->assertSame(false, $availability['available']);
        $harness->assertSame(true, $availability['smtp_ready']);
    });
});

$harness->check(UserManagementService::class, 'allows invite creation with live SMTP mail transport', function () use ($harness, $withRestoredConfig): void {
    $withRestoredConfig(function () use ($harness): void {
        AppConfigurationStore::setInvitationSettings(['enabled' => true]);
        AppConfigurationStore::setSmtpSettings([
            'enabled' => true,
            'development_mode' => false,
            'transport' => 'mail',
            'from_address' => 'from@example.test',
        ]);
        AppConfigurationStore::setSmsSettings([
            'enabled' => false,
            'development_mode' => true,
            'api_url' => '',
        ]);

        $availability = (new UserManagementService())->userCreationInviteAvailability();

        $harness->assertSame(true, $availability['available']);
        $harness->assertSame(true, $availability['smtp_ready']);
        $harness->assertSame(false, $availability['sms_ready']);
    });
});

$harness->check(UserManagementService::class, 'hides invite creation when SMTP is in test mode or incomplete', function () use ($harness, $withRestoredConfig): void {
    $withRestoredConfig(function () use ($harness): void {
        AppConfigurationStore::setInvitationSettings(['enabled' => true]);
        AppConfigurationStore::setSmtpSettings([
            'enabled' => true,
            'development_mode' => true,
            'transport' => 'smtp',
            'host' => 'mail.example.test',
            'port' => 587,
            'auth_mode' => 'none',
            'from_address' => 'from@example.test',
        ]);
        AppConfigurationStore::setSmsSettings([
            'enabled' => false,
            'development_mode' => true,
            'api_url' => '',
        ]);

        $harness->assertSame(false, (new UserManagementService())->userCreationInviteAvailability()['available']);

        AppConfigurationStore::setSmtpSettings([
            'enabled' => true,
            'development_mode' => false,
            'transport' => 'smtp',
            'host' => '',
            'port' => 587,
            'auth_mode' => 'none',
            'from_address' => 'from@example.test',
        ]);

        $harness->assertSame(false, (new UserManagementService())->userCreationInviteAvailability()['available']);
    });
});

$harness->check(UserManagementService::class, 'allows invite creation with live SMS URL template', function () use ($harness, $withRestoredConfig): void {
    $withRestoredConfig(function () use ($harness): void {
        AppConfigurationStore::setInvitationSettings(['enabled' => true]);
        AppConfigurationStore::setSmtpSettings([
            'enabled' => false,
            'development_mode' => true,
            'from_address' => '',
        ]);
        AppConfigurationStore::setSmsSettings([
            'enabled' => true,
            'development_mode' => false,
            'api_url' => 'https://sms.example.test/send/{telephone_number}',
        ]);

        $availability = (new UserManagementService())->userCreationInviteAvailability();

        $harness->assertSame(true, $availability['available']);
        $harness->assertSame(false, $availability['smtp_ready']);
        $harness->assertSame(true, $availability['sms_ready']);
    });
});

$harness->check(UserManagementService::class, 'hides invite creation when SMS is in test mode or lacks telephone template', function () use ($harness, $withRestoredConfig): void {
    $withRestoredConfig(function () use ($harness): void {
        AppConfigurationStore::setInvitationSettings(['enabled' => true]);
        AppConfigurationStore::setSmsSettings([
            'enabled' => true,
            'development_mode' => true,
            'api_url' => 'https://sms.example.test/send/{telephone_number}',
        ]);
        AppConfigurationStore::setSmtpSettings([
            'enabled' => false,
            'development_mode' => true,
            'from_address' => '',
        ]);

        $harness->assertSame(false, (new UserManagementService())->userCreationInviteAvailability()['available']);

        AppConfigurationStore::setSmsSettings([
            'enabled' => true,
            'development_mode' => false,
            'api_url' => 'https://sms.example.test/send',
        ]);

        $harness->assertSame(false, (new UserManagementService())->userCreationInviteAvailability()['available']);
    });
});
