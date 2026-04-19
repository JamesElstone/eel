<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tool' . DIRECTORY_SEPARATOR . 'reset_password.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run('reset_password.php', function (GeneratedServiceClassTestHarness $harness): void {
    $withTemporaryUser = function (callable $callback) use ($harness): void {
        if (!InterfaceDB::tableExists('users') || !InterfaceDB::tableExists('user_totp')) {
            $harness->skip('users or user_totp table is not available on the default InterfaceDB connection.');
        }

        InterfaceDB::beginTransaction();

        try {
            $marker = 'cli-reset-' . bin2hex(random_bytes(8));

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
                    'display_name' => 'CLI Reset User ' . $marker,
                    'email_address' => 'cli-reset-' . $marker . '@example.test',
                    'password_hash' => 'hash-' . $marker,
                ]
            );

            $user = InterfaceDB::fetchOne(
                'SELECT id, display_name, email_address
                 FROM users
                 WHERE email_address = :email_address
                 ORDER BY id DESC
                 LIMIT 1',
                ['email_address' => 'cli-reset-' . $marker . '@example.test']
            );

            if (!is_array($user) || (int)($user['id'] ?? 0) <= 0) {
                throw new RuntimeException('Temporary CLI reset test user could not be reloaded.');
            }

            $callback($user);
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    };

    $harness->check('reset_password.php', 'finds a user by email address and display name', function () use ($harness, $withTemporaryUser): void {
        $withTemporaryUser(function (array $user) use ($harness): void {
            $service = new UserAuthenticationService();

            $foundByEmail = eel_cli_find_user($service, (string)$user['email_address']);
            $foundByDisplayName = eel_cli_find_user($service, (string)$user['display_name']);

            $harness->assertTrue(is_array($foundByEmail));
            $harness->assertTrue(is_array($foundByDisplayName));
            $harness->assertSame((int)$user['id'], (int)($foundByEmail['id'] ?? 0));
            $harness->assertSame((int)$user['id'], (int)($foundByDisplayName['id'] ?? 0));
        });
    });

    $harness->check('reset_password.php', 'resets password and completes a fresh OTP setup for a temporary user', function () use ($harness, $withTemporaryUser): void {
        $withTemporaryUser(function (array $user) use ($harness): void {
            $userId = (int)$user['id'];
            $authService = new UserAuthenticationService();
            $otpService = new OtpService('EEL Accounts');
            $verificationService = new OtpVerificationService();

            $passwordResult = eel_cli_reset_user_password($authService, $userId, 'Cli Reset Password 1!');
            $harness->assertTrue(!empty($passwordResult['success']));

            $authenticated = $authService->authenticateByEmailAddress((string)$user['email_address'], 'Cli Reset Password 1!');
            $harness->assertTrue(is_array($authenticated));

            $secret = eel_cli_start_user_otp_reset($otpService, $userId);
            $harness->assertTrue($secret !== '');
            $harness->assertTrue(!$otpService->isOTPenabled($userId));

            $code = $verificationService->generateCodeForTimestep(
                6,
                'SHA1',
                $secret,
                $verificationService->currentTimestep(time(), 30)
            );

            $harness->assertTrue(eel_cli_finish_user_otp_reset($otpService, $userId, $code));
            $harness->assertTrue($otpService->isOTPenabled($userId));
        });
    });
});
