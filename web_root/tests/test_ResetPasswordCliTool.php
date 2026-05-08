<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
$resetPasswordToolPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'reset_password.php';
$previousErrorHandler = set_error_handler(static function (int $severity, string $message): bool {
    return str_contains($message, 'already defined');
});
require_once $resetPasswordToolPath;
restore_error_handler();

$harness = new GeneratedServiceClassTestHarness();
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
                is_active
            ) VALUES (
                :display_name,
                :email_address,
                :password_hash,
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

$harness->check('reset_password.php', 'loads CLI helper functions', function () use ($harness): void {
    $harness->assertTrue(function_exists('eel_cli_find_user'));
    $harness->assertTrue(function_exists('eel_cli_reset_user_password'));
    $harness->assertTrue(function_exists('eel_cli_start_user_otp_reset'));
    $harness->assertTrue(function_exists('eel_cli_finish_user_otp_reset'));
});

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
