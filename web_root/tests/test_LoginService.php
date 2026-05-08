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
$harness->run(LoginService::class);

$loginTempDirectory = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp';
if (!is_dir($loginTempDirectory)) {
    mkdir($loginTempDirectory, 0777, true);
}

$withTemporaryLoginUser = function (callable $callback) use ($harness, $loginTempDirectory): void {
    if (!InterfaceDB::tableExists('users') || !InterfaceDB::tableExists('user_totp')) {
        $harness->skip('users or user_totp table is not available on the default InterfaceDB connection.');
    }

    InterfaceDB::beginTransaction();
    $securityPath = $loginTempDirectory . DIRECTORY_SEPARATOR . 'login-service-' . bin2hex(random_bytes(8)) . '.keys';

    try {
        $authService = new UserAuthenticationService($securityPath, [
            'memory_cost' => 8192,
            'time_cost' => 1,
            'threads' => 1,
        ]);
        $created = $authService->createUser('Login User', 'login-user-' . bin2hex(random_bytes(4)) . '@example.test', 'Strong Password 1!');

        if (empty($created['success'])) {
            throw new RuntimeException('Temporary login user could not be created: ' . implode(', ', (array)($created['errors'] ?? [])));
        }

        $callback($authService, (int)$created['user_id'], (string)($created['user']['email_address'] ?? ''), $securityPath);
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
        if (is_file($securityPath)) {
            unlink($securityPath);
        }
    }
};

$harness->check(LoginService::class, 'starts OTP setup after valid primary credentials', function () use ($harness, $withTemporaryLoginUser): void {
    $withTemporaryLoginUser(function (UserAuthenticationService $authService, int $userId, string $emailAddress): void {
        $_SESSION = [];
        $loginService = new LoginService(
            $authService,
            new OtpService('EEL Accounts'),
            new QrCodeService(),
            new SessionAuthenticationService()
        );

        $result = $loginService->startLogin(strtoupper($emailAddress), 'Strong Password 1!', 'device-a');

        $harness = new GeneratedServiceClassTestHarness();
        $harness->assertTrue(!empty($result['success']));
        $harness->assertTrue(!empty($result['requires_otp_setup']));
        $harness->assertSame($userId, (new SessionAuthenticationService())->pendingOtpSetupUserId('device-a'));
    });
});

$harness->check(LoginService::class, 'records failed primary credential attempts', function () use ($harness, $withTemporaryLoginUser): void {
    $withTemporaryLoginUser(function (UserAuthenticationService $authService, int $userId, string $emailAddress) use ($harness): void {
        $_SESSION = [];
        $loginService = new LoginService(
            $authService,
            new OtpService('EEL Accounts'),
            new QrCodeService(),
            new SessionAuthenticationService()
        );

        $result = $loginService->startLogin($emailAddress, 'Wrong Password 1!', 'device-a');

        $harness->assertTrue(empty($result['success']));
        $harness->assertTrue(empty($result['requires_otp']));
        $harness->assertSame(1, (int)($result['rate_limit']['consecutive_failed_password_attempts'] ?? 0));
        $harness->assertSame(0, (new SessionAuthenticationService())->pendingOtpSetupUserId('device-a'));
    });
});

$harness->check(LoginService::class, 'requires forced password change before OTP challenge', function () use ($harness, $withTemporaryLoginUser): void {
    $withTemporaryLoginUser(function (UserAuthenticationService $authService, int $userId, string $emailAddress) use ($harness): void {
        $_SESSION = [];
        $otpService = new OtpService('EEL Accounts');
        $secret = $otpService->generateOTPsecret($userId);
        $code = (new OtpVerificationService())->generateCodeForTimestep(
            6,
            'SHA1',
            $secret,
            (new OtpVerificationService())->currentTimestep(time(), 30)
        );
        $harness->assertTrue($otpService->enableOTP($userId, $code));
        $harness->assertTrue(!empty($authService->requirePasswordChange($userId)['success']));

        $sessionService = new SessionAuthenticationService();
        $loginService = new LoginService(
            $authService,
            $otpService,
            new QrCodeService(),
            $sessionService
        );

        $start = $loginService->startLogin($emailAddress, 'Strong Password 1!', 'device-a');

        $harness->assertTrue(!empty($start['requires_password_change']));
        $harness->assertSame($userId, $sessionService->pendingPasswordChangeUserId('device-a'));
        $changed = $loginService->completeRequiredPasswordChange('Changed Password 1!', 'Changed Password 1!', 'device-a');
        $user = $authService->userById($userId);
        $harness->assertTrue(!empty($changed['requires_otp']));
        $harness->assertSame(0, $sessionService->pendingPasswordChangeUserId('device-a'));
        $harness->assertSame(0, (int)($user['must_change_password'] ?? 1));
    });
});

$harness->check(LoginService::class, 'completes OTP challenge and clears excessive failures', function () use ($harness, $withTemporaryLoginUser): void {
    $withTemporaryLoginUser(function (UserAuthenticationService $authService, int $userId, string $emailAddress) use ($harness): void {
        $_SESSION = [];
        $otpService = new OtpService('EEL Accounts');
        $secret = $otpService->generateOTPsecret($userId);
        $code = (new OtpVerificationService())->generateCodeForTimestep(
            6,
            'SHA1',
            $secret,
            (new OtpVerificationService())->currentTimestep(time(), 30)
        );
        $harness->assertTrue($otpService->enableOTP($userId, $code));
        InterfaceDB::prepareExecute(
            'UPDATE user_totp
             SET otp_last_used_timestep = NULL
             WHERE user_id = :user_id',
            ['user_id' => $userId]
        );

        $sessionService = new SessionAuthenticationService(maxPendingOtpAttempts: 2);
        $loginService = new LoginService(
            $authService,
            $otpService,
            new QrCodeService(),
            $sessionService
        );

        $start = $loginService->startLogin($emailAddress, 'Strong Password 1!', 'device-a');
        $firstFailure = $loginService->completeOtpLogin('000000', 'device-a');
        $secondFailure = $loginService->completeOtpLogin('000000', 'device-a');

        $harness->assertTrue(!empty($start['requires_otp']));
        $harness->assertTrue(!empty($firstFailure['requires_otp']));
        $harness->assertTrue(empty($secondFailure['requires_otp']));
        $harness->assertSame(0, $sessionService->pendingOtpUserId('device-a'));

        $loginService->startLogin($emailAddress, 'Strong Password 1!', 'device-a');
        $success = $loginService->completeOtpLogin($code, 'device-a');

        $harness->assertTrue(!empty($success['authenticated']));
        $harness->assertSame($userId, $sessionService->authenticatedUserId('device-a'));
    });
});
