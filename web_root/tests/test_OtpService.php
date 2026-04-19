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
$harness->run(OtpService::class, function (GeneratedServiceClassTestHarness $harness): void {
    $withTemporaryUser = function (callable $callback) use ($harness): void {
        if (!InterfaceDB::tableExists('users') || !InterfaceDB::tableExists('user_totp')) {
            $harness->skip('users or user_totp table is not available on the default InterfaceDB connection.');
        }

        InterfaceDB::beginTransaction();

        try {
            $marker = 'otp-test-' . bin2hex(random_bytes(8));

            InterfaceDB::prepareExecute(
                'INSERT INTO users (
                    display_name,
                    email_address,
                    password_hash,
                    confirmed_director,
                    is_active
                ) VALUES (
                    :display_name,
                    :email_address,
                    :password_hash,
                    0,
                    1
                )',
                [
                    'display_name' => 'OTP Test User ' . $marker,
                    'email_address' => 'otp-' . $marker . '@example.test',
                    'password_hash' => 'hash-' . $marker,
                ]
            );

            $userId = (int)(InterfaceDB::fetchColumn(
                'SELECT id
                 FROM users
                 WHERE password_hash = :password_hash
                 ORDER BY id DESC
                 LIMIT 1',
                ['password_hash' => 'hash-' . $marker]
            ) ?: 0);

            if ($userId <= 0) {
                throw new RuntimeException('Temporary OTP test user could not be reloaded.');
            }

            $callback(new OtpService('Elstone'), new OtpVerificationService(), $userId);
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    };

    $harness->check(OtpService::class, 'generateOTPsecret stores a resettable OTP row for a temporary user', function () use ($harness, $withTemporaryUser): void {
        $withTemporaryUser(function (OtpService $service, OtpVerificationService $verificationService, int $userId) use ($harness): void {
            $firstSecret = $service->generateOTPsecret($userId);
            $secondSecret = $service->generateOTPsecret($userId);

            $row = InterfaceDB::fetchOne(
                'SELECT otp_secret, otp_enabled, otp_last_used_timestep
                 FROM user_totp
                 WHERE user_id = :user_id
                 LIMIT 1',
                ['user_id' => $userId]
            );

            $harness->assertTrue($verificationService instanceof OtpVerificationService);
            $harness->assertTrue(is_array($row));
            $harness->assertTrue($firstSecret !== '');
            $harness->assertTrue($secondSecret !== '');
            $harness->assertTrue($firstSecret !== $secondSecret);
            $harness->assertSame($secondSecret, (string)$row['otp_secret']);
            $harness->assertSame(0, (int)$row['otp_enabled']);
            $harness->assertSame(null, $row['otp_last_used_timestep']);
        });
    });

    $harness->check(OtpService::class, 'enable, replay protection, and disable all work via the default InterfaceDB connection', function () use ($harness, $withTemporaryUser): void {
        $withTemporaryUser(function (OtpService $service, OtpVerificationService $verificationService, int $userId) use ($harness): void {
            $secret = $service->generateOTPsecret($userId);
            $now = time();
            $code = $verificationService->generateCodeForTimestep(
                6,
                'SHA1',
                $secret,
                $verificationService->currentTimestep($now, 30)
            );

            $harness->assertTrue(!$service->checkOTP($userId, $code, true));
            $harness->assertTrue($service->enableOTP($userId, $code));
            $harness->assertTrue($service->isOTPenabled($userId));
            $harness->assertTrue(!$service->checkOTP($userId, $code, true));
            $harness->assertTrue($service->checkOTP($userId, $code, false));

            $row = InterfaceDB::fetchOne(
                'SELECT otp_enabled, otp_last_used_timestep
                 FROM user_totp
                 WHERE user_id = :user_id
                 LIMIT 1',
                ['user_id' => $userId]
            );

            $harness->assertTrue(is_array($row));
            $harness->assertSame(1, (int)$row['otp_enabled']);
            $harness->assertTrue((int)($row['otp_last_used_timestep'] ?? 0) > 0);

            $harness->assertTrue($service->disableOTP($userId));
            $harness->assertTrue(!$service->isOTPenabled($userId));

            try {
                $service->getManualEntrySecret($userId);
            } catch (RuntimeException $exception) {
                $harness->assertTrue(str_contains($exception->getMessage(), 'No OTP secret exists'));
                return;
            }

            throw new RuntimeException('Expected getManualEntrySecret() to fail after OTP is disabled.');
        });
    });

    $harness->check(OtpService::class, 'supports pending OTP enrollment before confirming a rotated secret', function () use ($harness, $withTemporaryUser): void {
        $withTemporaryUser(function (OtpService $service, OtpVerificationService $verificationService, int $userId) use ($harness): void {
            $pendingSecret = $service->beginPendingOtpEnrollment($userId);
            $harness->assertTrue($service->hasPendingOtpSecret($userId));
            $harness->assertSame($pendingSecret, $service->pendingManualEntrySecret($userId));
            $harness->assertTrue(str_contains($service->generatePendingOtpString($userId), 'otpauth://totp/'));

            $code = $verificationService->generateCodeForTimestep(
                6,
                'SHA1',
                $pendingSecret,
                $verificationService->currentTimestep(time(), 30)
            );

            $harness->assertTrue($service->completePendingOtpEnrollment($userId, $code));
            $harness->assertTrue($service->isOTPenabled($userId));
            $harness->assertTrue(!$service->hasPendingOtpSecret($userId));
        });
    });
});
