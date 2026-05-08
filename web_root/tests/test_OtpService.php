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
$harness->run(OtpService::class);

$withTemporaryUser = function (callable $callback) use ($harness): void {
    if (!InterfaceDB::tableExists('users') || !InterfaceDB::tableExists('user_totp')) {
        $harness->skip('users or user_totp table is not available on the default InterfaceDB connection.');
    }

    InterfaceDB::beginTransaction();

    try {
        $marker = 'otp-service-' . bin2hex(random_bytes(8));

        InterfaceDB::prepareExecute(
            'INSERT INTO users (
                display_name,
                email_address,
                password_hash,
                is_active
            ) VALUES (
                :display_name,
                :email_address,
                :password_hash,
                1
            )',
            [
                'display_name' => 'OTP Service User ' . $marker,
                'email_address' => $marker . '@example.test',
                'password_hash' => 'hash-' . $marker,
            ]
        );

        $userId = (int)InterfaceDB::fetchColumn(
            'SELECT id
             FROM users
             WHERE email_address = :email_address
             LIMIT 1',
            ['email_address' => $marker . '@example.test']
        );

        if ($userId <= 0) {
            throw new RuntimeException('Temporary OTP test user could not be reloaded.');
        }

        $callback($userId);
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
};

$harness->check(OtpService::class, 'encrypts newly generated stored OTP secrets', function () use ($harness, $withTemporaryUser): void {
    $withTemporaryUser(function (int $userId) use ($harness): void {
        $service = new OtpService('EEL Accounts');
        $secret = $service->generateOTPsecret($userId);
        $stored = (string)InterfaceDB::fetchColumn(
            'SELECT otp_secret
             FROM user_totp
             WHERE user_id = :user_id
             LIMIT 1',
            ['user_id' => $userId]
        );

        $harness->assertTrue($secret !== '');
        $harness->assertTrue($stored !== $secret);
        $harness->assertTrue(str_starts_with($stored, 'eel:v1:gcm:'));
        $harness->assertSame($secret, $service->getManualEntrySecret($userId));
    });
});

$harness->check(OtpService::class, 'migrates legacy plaintext OTP secrets when read', function () use ($harness, $withTemporaryUser): void {
    $withTemporaryUser(function (int $userId) use ($harness): void {
        $legacySecret = 'JBSWY3DPEHPK3PXP';

        InterfaceDB::prepareExecute(
            'INSERT INTO user_totp (
                user_id,
                otp_secret,
                otp_algorithm,
                otp_digits,
                otp_period,
                otp_enabled,
                created_at,
                updated_at
            ) VALUES (
                :user_id,
                :otp_secret,
                :otp_algorithm,
                :otp_digits,
                :otp_period,
                1,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )',
            [
                'user_id' => $userId,
                'otp_secret' => $legacySecret,
                'otp_algorithm' => 'SHA1',
                'otp_digits' => 6,
                'otp_period' => 30,
            ]
        );

        $service = new OtpService('EEL Accounts');
        $harness->assertSame($legacySecret, $service->getManualEntrySecret($userId));

        $stored = (string)InterfaceDB::fetchColumn(
            'SELECT otp_secret
             FROM user_totp
             WHERE user_id = :user_id
             LIMIT 1',
            ['user_id' => $userId]
        );

        $harness->assertTrue($stored !== $legacySecret);
        $harness->assertTrue(str_starts_with($stored, 'eel:v1:gcm:'));
    });
});

$harness->check(OtpService::class, 'clears expired pending OTP secrets lazily', function () use ($harness, $withTemporaryUser): void {
    $withTemporaryUser(function (int $userId) use ($harness): void {
        $service = new OtpService('EEL Accounts');
        $secret = $service->beginPendingOtpEnrollment($userId);

        $harness->assertTrue($secret !== '');
        $harness->assertTrue($service->hasPendingOtpSecret($userId));

        InterfaceDB::prepareExecute(
            'UPDATE user_totp
             SET pending_otp_requested_at = :requested_at
             WHERE user_id = :user_id',
            [
                'user_id' => $userId,
                'requested_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-10 minutes')->format('Y-m-d H:i:s'),
            ]
        );

        $harness->assertTrue(!$service->hasPendingOtpSecret($userId));
        $stored = InterfaceDB::fetchColumn(
            'SELECT pending_otp_secret
             FROM user_totp
             WHERE user_id = :user_id
             LIMIT 1',
            ['user_id' => $userId]
        );

        $harness->assertSame(null, $stored);
    });
});
