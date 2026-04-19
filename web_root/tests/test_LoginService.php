<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(LoginService::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $tempDirectory = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp';
    $securityPath = $tempDirectory . DIRECTORY_SEPARATOR . 'login-service-security.keys';

    if (!is_dir($tempDirectory)) {
        mkdir($tempDirectory, 0777, true);
    }

    if (is_file($securityPath)) {
        unlink($securityPath);
    }

    $sessionService = new SessionAuthenticationService(300, 3);
    $sessionService->startSession();

    if (
        !InterfaceDB::tableExists('users')
        || !InterfaceDB::tableExists('user_totp')
        || !InterfaceDB::tableExists('user_logon_history')
        || !InterfaceDB::tableExists('user_login_rate_limits')
    ) {
        $harness->check(LoginService::class, 'requires login tables on the default InterfaceDB connection', static function () use ($harness): void {
            $harness->skip('users, user_totp, user_logon_history, or user_login_rate_limits table is not available on the default InterfaceDB connection.');
        });

        return;
    }

    if (
        !InterfaceDB::columnsExists('user_login_rate_limits', [
            'scope_type',
            'scope_key',
            'lock_expires_at',
        ])
    ) {
        $harness->check(LoginService::class, 'requires scoped login rate-limit columns on the default InterfaceDB connection', static function () use ($harness): void {
            $harness->skip('user_login_rate_limits scoped rate-limit columns are not available on the default InterfaceDB connection.');
        });

        return;
    }

    $withTemporaryUser = static function (
        string $password,
        callable $callback
    ) use ($securityPath, $sessionService): void {
        $userAuthenticationService = new UserAuthenticationService($securityPath);
        $otpService = new OtpService('Elstone');
        $loginService = new LoginService($userAuthenticationService, $otpService, new QrCodeService(), $sessionService);

        InterfaceDB::beginTransaction();

        try {
            $marker = 'login-test-' . bin2hex(random_bytes(8));
            $hash = $userAuthenticationService->hashPassword($password);

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
                    'display_name' => 'Login Test User ' . $marker,
                    'email_address' => 'login-' . $marker . '@example.test',
                    'password_hash' => $hash,
                ]
            );

            $user = InterfaceDB::fetchOne(
                'SELECT id, email_address
                 FROM users
                 WHERE password_hash = :password_hash
                 ORDER BY id DESC
                 LIMIT 1',
                ['password_hash' => $hash]
            );

            if (!is_array($user)) {
                throw new RuntimeException('Temporary login test user could not be reloaded.');
            }

            $_SESSION = [];

            $callback(
                $loginService,
                $userAuthenticationService,
                $otpService,
                (int)$user['id'],
                (string)$user['email_address']
            );
        } finally {
            $_SESSION = [];
            InterfaceDB::prepareExecute('DELETE FROM user_login_rate_limits WHERE email_address LIKE :email', ['email' => 'login-%@example.test']);

            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    };

    $harness->check(LoginService::class, 'starts otp setup when otp is not enabled', static function () use ($harness, $withTemporaryUser, $sessionService): void {
        $withTemporaryUser('Login Password 1!', static function (
            LoginService $loginService,
            UserAuthenticationService $_authService,
            OtpService $_otpService,
            int $_userId,
            string $emailAddress
        ) use ($harness, $sessionService): void {
            $result = $loginService->startLogin($emailAddress, 'Login Password 1!', 'device-1');

            $harness->assertTrue($result['success'] === true);
            $harness->assertTrue($result['authenticated'] === false);
            $harness->assertTrue($result['requires_otp'] === false);
            $harness->assertTrue($result['requires_otp_setup'] === true);
            $harness->assertTrue($sessionService->hasPendingOtpSetup('device-1'));
            $harness->assertSame(0, $sessionService->authenticatedUserId('device-1'));
        });
    });

    $harness->check(LoginService::class, 'requires otp and completes login on the same device', static function () use ($harness, $withTemporaryUser, $sessionService): void {
        $withTemporaryUser('Login Password 2!', static function (
            LoginService $loginService,
            UserAuthenticationService $_authService,
            OtpService $otpService,
            int $userId,
            string $emailAddress
        ) use ($harness, $sessionService): void {
            $verificationService = new OtpVerificationService();
            $secret = $otpService->generateOTPsecret($userId);
            $setupCode = $verificationService->generateCodeForTimestep(
                6,
                'SHA1',
                $secret,
                $verificationService->currentTimestep(time(), 30)
            );

            $harness->assertTrue($otpService->enableOTP($userId, $setupCode));

            $result = $loginService->startLogin($emailAddress, 'Login Password 2!', 'device-2');

            $harness->assertTrue($result['success'] === true);
            $harness->assertTrue($result['requires_otp'] === true);
            $harness->assertTrue($sessionService->hasPendingOtp('device-2'));

            $otpCode = $verificationService->generateCodeForTimestep(
                6,
                'SHA1',
                $secret,
                $verificationService->currentTimestep(time(), 30) + 1
            );
            $otpResult = $loginService->completeOtpLogin($otpCode, 'device-2');

            $harness->assertTrue($otpResult['success'] === true);
            $harness->assertTrue($otpResult['authenticated'] === true);
            $harness->assertSame($userId, $sessionService->authenticatedUserId('device-2'));
            $antiFraudContext = $sessionService->authenticatedAntiFraudContext('device-2');
            $harness->assertSame((string)$emailAddress, $antiFraudContext['email_address'] ?? null);
            $harness->assertSame('TOTP', $antiFraudContext['mfa']['type'] ?? null);
            $harness->assertTrue((string)($antiFraudContext['mfa']['timestamp'] ?? '') !== '');
            $harness->assertTrue((string)($antiFraudContext['mfa']['unique_reference'] ?? '') !== '');
            $harness->assertSame(
                hash_hmac('sha256', 'eel-accounts:totp:' . $userId, SecurityStore::ensureFact('pepper')),
                $antiFraudContext['mfa']['unique_reference'] ?? null
            );

            $history = InterfaceDB::fetchAll(
                'SELECT event_type, session_token_hash
                 FROM user_logon_history
                 WHERE user_id = :user_id
                 ORDER BY id ASC',
                ['user_id' => $userId]
            );
            $eventTypes = array_map(
                static fn(array $row): string => (string)($row['event_type'] ?? ''),
                $history
            );

            $harness->assertSame(['login_succeeded', 'otp_challenge_passed'], $eventTypes);
            $harness->assertSame(
                (string)$history[1]['session_token_hash'],
                (string)$history[0]['session_token_hash']
            );
            $audit = InterfaceDB::fetchOne(
                'SELECT action_type, details_json
                 FROM user_account_audit
                 WHERE affected_user_id = :user_id
                 ORDER BY id DESC
                 LIMIT 1',
                ['user_id' => $userId]
            );
            $auditDetails = json_decode((string)($audit['details_json'] ?? ''), true);
            $harness->assertSame('mfa_authenticated', $audit['action_type'] ?? null);
            $harness->assertSame('TOTP', $auditDetails['type'] ?? null);
            $harness->assertSame($antiFraudContext['mfa']['unique_reference'] ?? null, $auditDetails['unique_reference'] ?? null);
        });
    });

    $harness->check(LoginService::class, 'rejects otp completion when the device id changes mid-flow', static function () use ($harness, $withTemporaryUser, $sessionService): void {
        $withTemporaryUser('Login Password 3!', static function (
            LoginService $loginService,
            UserAuthenticationService $_authService,
            OtpService $otpService,
            int $userId,
            string $emailAddress
        ) use ($harness, $sessionService): void {
            $verificationService = new OtpVerificationService();
            $secret = $otpService->generateOTPsecret($userId);
            $setupCode = $verificationService->generateCodeForTimestep(
                6,
                'SHA1',
                $secret,
                $verificationService->currentTimestep(time(), 30)
            );

            $harness->assertTrue($otpService->enableOTP($userId, $setupCode));
            $loginService->startLogin($emailAddress, 'Login Password 3!', 'device-3');

            $otpCode = $verificationService->generateCodeForTimestep(
                6,
                'SHA1',
                $secret,
                $verificationService->currentTimestep(time(), 30)
            );
            $otpResult = $loginService->completeOtpLogin($otpCode, 'device-4');

            $harness->assertTrue($otpResult['success'] === false);
            $harness->assertTrue($otpResult['requires_otp'] === false);
            $harness->assertTrue(!$sessionService->hasPendingOtp('device-4'));
            $harness->assertTrue(!$sessionService->isAuthenticated('device-4'));
        });
    });

    $harness->check(LoginService::class, 'begins otp setup and authenticates after confirming the setup code', static function () use ($harness, $withTemporaryUser, $sessionService): void {
        $withTemporaryUser('Login Password 4!', static function (
            LoginService $loginService,
            UserAuthenticationService $_authService,
            OtpService $otpService,
            int $userId,
            string $_emailAddress
        ) use ($harness, $sessionService): void {
            $setup = $loginService->beginOtpSetup($userId, 'device-5');

            $harness->assertTrue($sessionService->hasPendingOtpSetup('device-5'));
            $harness->assertTrue(str_contains((string)$setup['qr_svg'], '<svg '));
            $harness->assertTrue((string)$setup['manual_secret'] !== '');

            $verificationService = new OtpVerificationService();
            $code = $verificationService->generateCodeForTimestep(
                6,
                'SHA1',
                (string)$setup['manual_secret'],
                $verificationService->currentTimestep(time(), 30)
            );

            $result = $loginService->completeOtpSetup($code, 'device-5');

            $harness->assertTrue($result['success'] === true);
            $harness->assertTrue($result['authenticated'] === true);
            $harness->assertSame($userId, $sessionService->authenticatedUserId('device-5'));
            $antiFraudContext = $sessionService->authenticatedAntiFraudContext('device-5');
            $harness->assertSame('TOTP', $antiFraudContext['mfa']['type'] ?? null);
            $harness->assertTrue((string)($antiFraudContext['mfa']['timestamp'] ?? '') !== '');
            $history = InterfaceDB::fetchAll(
                'SELECT event_type, session_token_hash
                 FROM user_logon_history
                 WHERE user_id = :user_id
                 ORDER BY id ASC',
                ['user_id' => $userId]
            );
            $harness->assertSame('otp_setup_started', (string)($history[0]['event_type'] ?? ''));
            $harness->assertSame('otp_setup_completed', (string)($history[1]['event_type'] ?? ''));
            $harness->assertSame('', (string)($history[0]['session_token_hash'] ?? ''));
            $harness->assertSame(
                (string)($history[1]['session_token_hash'] ?? ''),
                $sessionService->authenticatedSessionTokenHash()
            );
            $audit = InterfaceDB::fetchOne(
                'SELECT action_type
                 FROM user_account_audit
                 WHERE affected_user_id = :user_id
                 ORDER BY id DESC
                 LIMIT 1',
                ['user_id' => $userId]
            );
            $harness->assertSame('mfa_authenticated', $audit['action_type'] ?? null);
        });
    });

    $harness->check(LoginService::class, 'records login_succeeded before otp setup when bootstrap-style setup begins', static function () use ($harness, $withTemporaryUser): void {
        $withTemporaryUser('Login Password 4A!', static function (
            LoginService $loginService,
            UserAuthenticationService $_authService,
            OtpService $_otpService,
            int $userId,
            string $_emailAddress
        ) use ($harness): void {
            $loginService->beginOtpSetup($userId, 'device-bootstrap-1', true);

            $history = InterfaceDB::fetchAll(
                'SELECT event_type
                 FROM user_logon_history
                 WHERE user_id = :user_id
                 ORDER BY id ASC',
                ['user_id' => $userId]
            );
            $eventTypes = array_map(
                static fn(array $row): string => (string)($row['event_type'] ?? ''),
                $history
            );

            $harness->assertSame(['login_succeeded', 'otp_setup_started'], $eventTypes);
        });
    });

    $harness->check(LoginService::class, 'applies a 30 second cooldown after three wrong password attempts', static function () use ($harness, $withTemporaryUser): void {
        $withTemporaryUser('Login Password 5!', static function (
            LoginService $loginService,
            UserAuthenticationService $authService,
            OtpService $_otpService,
            int $_userId,
            string $emailAddress
        ) use ($harness): void {
            $first = $loginService->startLogin($emailAddress, 'wrong-password', 'device-rate-1');
            $second = $loginService->startLogin($emailAddress, 'wrong-password', 'device-rate-1');
            $third = $loginService->startLogin($emailAddress, 'wrong-password', 'device-rate-1');
            $blocked = $loginService->startLogin($emailAddress, 'Login Password 5!', 'device-rate-1');

            $harness->assertTrue(empty($first['throttled']));
            $harness->assertTrue(empty($second['throttled']));
            $harness->assertTrue(!empty($third['throttled']));
            $harness->assertTrue((int)($third['retry_after_seconds'] ?? 0) > 0);
            $harness->assertTrue(!empty($blocked['throttled']));
            $harness->assertSame('Please wait before trying again.', (string)($blocked['errors'][0] ?? ''));

            $status = $authService->loginRateLimitStatus($emailAddress, 'device-rate-1');
            $harness->assertTrue(!empty($status['is_throttled']));
            $harness->assertSame(4, (int)($status['consecutive_failed_password_attempts'] ?? 0));
        });
    });

    $harness->check(LoginService::class, 'locks the account after repeated wrong password attempts and exposes lock expiry details', static function () use ($harness, $withTemporaryUser): void {
        $withTemporaryUser('Login Password 6!', static function (
            LoginService $loginService,
            UserAuthenticationService $authService,
            OtpService $_otpService,
            int $_userId,
            string $emailAddress
        ) use ($harness): void {
            for ($attempt = 0; $attempt < 20; $attempt += 1) {
                $result = $loginService->startLogin($emailAddress, 'wrong-password', 'device-rate-2');
            }

            $status = $authService->loginRateLimitStatus($emailAddress, 'device-rate-2');

            $harness->assertTrue(!empty($result['account_locked']));
            $harness->assertTrue(!empty($status['is_locked']));
            $harness->assertTrue((int)($status['consecutive_failed_password_attempts'] ?? 0) >= 10);
            $harness->assertTrue((string)($status['lock_reason'] ?? '') !== '');
            $harness->assertTrue(
                (string)($status['lock_expires_at'] ?? '') !== ''
                || (string)($status['locked_at'] ?? '') !== ''
            );
        });
    });
});

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    test_output_render();

    if (($GLOBALS['test_output_state']['summary']['status'] ?? 'healthy') !== 'healthy' && PHP_SAPI === 'cli') {
        exit(1);
    }
}
