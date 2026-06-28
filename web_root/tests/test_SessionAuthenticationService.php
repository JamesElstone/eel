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
$harness->run(SessionAuthenticationService::class);

$withRestoredConfig = static function (callable $callback): void {
    $configPath = AppConfigurationStore::configPath();
    $originalConfig = is_file($configPath) ? (string)file_get_contents($configPath) : '';

    try {
        $callback();
    } finally {
        if ($originalConfig !== '') {
            file_put_contents($configPath, $originalConfig);
            AppConfigurationStore::config(true);
        }
    }
};

$harness->check(SessionAuthenticationService::class, 'defaults session cookies to SameSite Strict', function () use ($harness): void {
    $service = new SessionAuthenticationService();

    $harness->assertSame('Strict', $service->cookieSameSite());
});

$harness->check(SessionAuthenticationService::class, 'uses request scheme when secure cookie config is auto', function () use ($harness): void {
    $secureRequest = new RequestFramework(
        [],
        [],
        [
            'REQUEST_METHOD' => 'GET',
            'HTTPS' => 'on',
        ],
        [],
        []
    );
    $plainRequest = new RequestFramework(
        [],
        [],
        [
            'REQUEST_METHOD' => 'GET',
            'HTTPS' => 'off',
            'SERVER_PORT' => 80,
        ],
        [],
        []
    );

    $harness->assertTrue((new SessionAuthenticationService(request: $secureRequest))->cookieSecure());
    $harness->assertTrue(!(new SessionAuthenticationService(request: $plainRequest))->cookieSecure());
});

$harness->check(SessionAuthenticationService::class, 'trusts forwarded HTTPS only from configured reverse proxies', function () use ($harness, $withRestoredConfig): void {
    $withRestoredConfig(function () use ($harness): void {
        AppConfigurationStore::setWebEnvironmentSettings([
            'base_url_override' => '',
            'trusted_proxy_ips' => ['198.51.100.10'],
            'client_ip_headers' => ['X-Forwarded-For', 'X-Real-IP'],
        ]);

        $trustedRequest = new RequestFramework(
            [],
            [],
            [
                'REQUEST_METHOD' => 'GET',
                'HTTPS' => 'off',
                'SERVER_PORT' => 80,
                'REMOTE_ADDR' => '198.51.100.10',
                'HTTP_X_FORWARDED_PROTO' => 'https',
            ],
            [],
            []
        );
        $untrustedRequest = new RequestFramework(
            [],
            [],
            [
                'REQUEST_METHOD' => 'GET',
                'HTTPS' => 'off',
                'SERVER_PORT' => 80,
                'REMOTE_ADDR' => '198.51.100.20',
                'HTTP_X_FORWARDED_PROTO' => 'https',
            ],
            [],
            []
        );

        $harness->assertTrue((new SessionAuthenticationService(request: $trustedRequest))->cookieSecure());
        $harness->assertTrue(!(new SessionAuthenticationService(request: $untrustedRequest))->cookieSecure());
    });
});

$harness->check(SessionAuthenticationService::class, 'uses standard Forwarded proto from configured reverse proxies', function () use ($harness, $withRestoredConfig): void {
    $withRestoredConfig(function () use ($harness): void {
        AppConfigurationStore::setWebEnvironmentSettings([
            'base_url_override' => '',
            'trusted_proxy_ips' => ['198.51.100.10'],
            'client_ip_headers' => ['X-Forwarded-For', 'X-Real-IP'],
        ]);

        $request = new RequestFramework(
            [],
            [],
            [
                'REQUEST_METHOD' => 'GET',
                'HTTPS' => 'off',
                'SERVER_PORT' => 80,
                'REMOTE_ADDR' => '198.51.100.10',
                'HTTP_FORWARDED' => 'for=203.0.113.40;proto=https;host=app.example.test',
            ],
            [],
            []
        );

        $harness->assertTrue((new SessionAuthenticationService(request: $request))->cookieSecure());
    });
});

$harness->check(SessionAuthenticationService::class, 'ignores invalid forwarded proto from configured reverse proxies', function () use ($harness, $withRestoredConfig): void {
    $withRestoredConfig(function () use ($harness): void {
        AppConfigurationStore::setWebEnvironmentSettings([
            'base_url_override' => '',
            'trusted_proxy_ips' => ['198.51.100.10'],
            'client_ip_headers' => ['X-Forwarded-For', 'X-Real-IP'],
        ]);

        $request = new RequestFramework(
            [],
            [],
            [
                'REQUEST_METHOD' => 'GET',
                'HTTPS' => 'off',
                'SERVER_PORT' => 80,
                'REMOTE_ADDR' => '198.51.100.10',
                'HTTP_X_FORWARDED_PROTO' => 'ftp',
            ],
            [],
            []
        );

        $harness->assertTrue(!(new SessionAuthenticationService(request: $request))->cookieSecure());
    });
});

$harness->check(SessionAuthenticationService::class, 'uses HTTPS external base URL when secure cookie config is auto', function () use ($harness, $withRestoredConfig): void {
    $withRestoredConfig(function () use ($harness): void {
        AppConfigurationStore::setWebEnvironmentSettings([
            'base_url_override' => 'https://app.example.test',
            'trusted_proxy_ips' => [],
            'client_ip_headers' => ['X-Forwarded-For', 'X-Real-IP'],
        ]);

        $request = new RequestFramework(
            [],
            [],
            [
                'REQUEST_METHOD' => 'GET',
                'HTTPS' => 'off',
                'SERVER_PORT' => 80,
            ],
            [],
            []
        );

        $harness->assertTrue((new SessionAuthenticationService(request: $request))->cookieSecure());

        AppConfigurationStore::setWebEnvironmentSettings([
            'base_url_override' => 'http://app.example.test',
            'trusted_proxy_ips' => [],
            'client_ip_headers' => ['X-Forwarded-For', 'X-Real-IP'],
        ]);

        $harness->assertTrue(!(new SessionAuthenticationService(request: $request))->cookieSecure());
    });
});

$harness->check(SessionAuthenticationService::class, 'generates and validates CSRF tokens', function () use ($harness): void {
    $_SESSION = [];
    $service = new SessionAuthenticationService();
    $token = $service->csrfToken();

    $harness->assertTrue($token !== '');
    $harness->assertSame($token, $service->csrfToken());
    $harness->assertTrue($service->isValidCsrfToken($token));
    $harness->assertTrue(!$service->isValidCsrfToken('wrong-token'));
});

$harness->check(SessionAuthenticationService::class, 'expires pending OTP sessions and tracks failures', function () use ($harness): void {
    $_SESSION = [];
    $service = new SessionAuthenticationService(pendingOtpLifetimeSeconds: 1, maxPendingOtpAttempts: 2);
    $service->beginPendingOtp(42, 'device-a');

    $harness->assertSame(42, $service->pendingOtpUserId('device-a'));
    $harness->assertSame(1, $service->recordPendingOtpFailure());
    $harness->assertSame(2, $service->maxPendingOtpAttempts());

    $_SESSION['auth.pending_otp.started_at'] = time() - 5;

    $harness->assertSame(0, $service->pendingOtpUserId('device-a'));
    $harness->assertTrue(!isset($_SESSION['auth.pending_otp.user_id']));
});

$harness->check(SessionAuthenticationService::class, 'clears authentication and pending state on device mismatch', function () use ($harness): void {
    $_SESSION = [];
    $service = new SessionAuthenticationService();

    $service->completeAuthentication(7, 'device-a', 'session-hash', 'user@example.test', [
        'type' => 'TOTP',
        'timestamp' => '2026-05-08T12:00:00.000Z',
        'unique_reference' => 'mfa-reference',
    ]);
    $service->invalidateForDeviceMismatch('device-b');

    $harness->assertSame(0, $service->authenticatedUserId('device-a'));
    $notice = $service->consumeLogoutNotice();
    $harness->assertTrue(is_array($notice));
    $harness->assertSame('error', (string)($notice['type'] ?? ''));

    $service->beginPendingOtpSetup(8, 'device-a');
    $service->invalidateForDeviceMismatch('device-b');
    $harness->assertSame(0, $service->pendingOtpSetupUserId('device-a'));
});

$harness->check(SessionAuthenticationService::class, 'rotates AJAX nonces for authenticated sessions', function () use ($harness): void {
    $_SESSION = [];
    $service = new SessionAuthenticationService();
    $service->completeAuthentication(9, 'device-a', 'hash-a', 'ajax@example.test');

    $pool = $service->ensureAjaxNoncePool('device-a');
    $harness->assertCount(3, $pool);

    $result = $service->consumeAjaxNonce((string)$pool[0], 'device-a');
    $harness->assertTrue((bool)($result['valid'] ?? false));
    $harness->assertCount(3, (array)($result['pool'] ?? []));
    $harness->assertSame($result['replacement_nonce'], $service->consumeAjaxNonceRefresh());
    $harness->assertSame(null, $service->consumeAjaxNonceRefresh());

    $invalid = $service->consumeAjaxNonce('not-in-pool', 'device-a');
    $harness->assertTrue(!((bool)($invalid['valid'] ?? true)));
});

$harness->check(SessionAuthenticationService::class, 'exposes authenticated anti-fraud context', function () use ($harness): void {
    $_SESSION = [];
    $service = new SessionAuthenticationService();
    $service->completeAuthentication(12, 'device-a', 'hash-a', 'mfa@example.test', [
        'type' => 'TOTP',
        'timestamp' => '2026-05-08T12:00:00.000Z',
        'unique_reference' => 'mfa-reference',
    ]);

    $context = $service->authenticatedAntiFraudContext('device-a');

    $harness->assertSame(12, (int)($context['user_id'] ?? 0));
    $harness->assertSame('mfa@example.test', (string)($context['email_address'] ?? ''));
    $harness->assertSame('TOTP', (string)($context['mfa']['type'] ?? ''));
    $harness->assertSame([], $service->authenticatedAntiFraudContext('device-b'));
});
