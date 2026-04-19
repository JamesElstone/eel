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
$harness->run(SessionAuthenticationService::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $service = new SessionAuthenticationService(300, 3);
    $service->startSession();

    $resetSession = static function () use ($service): void {
        $service->logout();
        $_SESSION = [];
    };

    $harness->check(SessionAuthenticationService::class, 'creates and validates a CSRF token', static function () use ($harness, $service, $resetSession): void {
        $resetSession();

        $token = $service->csrfToken();

        $harness->assertTrue($token !== '');
        $harness->assertTrue($service->isValidCsrfToken($token));
        $harness->assertTrue(!$service->isValidCsrfToken('invalid-token'));
    });

    $harness->check(SessionAuthenticationService::class, 'binds authenticated sessions to the device id', static function () use ($harness, $service, $resetSession): void {
        $resetSession();
        $service->completeAuthentication(42, 'device-a');

        $harness->assertTrue($service->isAuthenticated('device-a'));
        $harness->assertSame(42, $service->authenticatedUserId('device-a'));
        $harness->assertTrue(!$service->isAuthenticated('device-b'));
    });

    $harness->check(SessionAuthenticationService::class, 'stores authenticated anti-fraud context on the session', static function () use ($harness, $service, $resetSession): void {
        $resetSession();
        $service->completeAuthentication(
            42,
            'device-a',
            'session-hash',
            'user@example.test',
            [
                'type' => 'TOTP',
                'timestamp' => '2026-04-17T18:00:00.000Z',
                'unique_reference' => 'abc123',
            ]
        );

        $context = $service->authenticatedAntiFraudContext('device-a');

        $harness->assertSame(42, $context['user_id'] ?? null);
        $harness->assertSame('user@example.test', $context['email_address'] ?? null);
        $harness->assertSame('TOTP', $context['mfa']['type'] ?? null);
        $harness->assertSame('2026-04-17T18:00:00.000Z', $context['mfa']['timestamp'] ?? null);
        $harness->assertSame('abc123', $context['mfa']['unique_reference'] ?? null);
    });

    $harness->check(SessionAuthenticationService::class, 'clears pending otp when the device changes', static function () use ($harness, $service, $resetSession): void {
        $resetSession();
        $service->beginPendingOtp(9, 'device-a');

        $harness->assertTrue($service->hasPendingOtp('device-a'));
        $service->invalidateForDeviceMismatch('device-b');
        $harness->assertTrue(!$service->hasPendingOtp('device-b'));
    });

    $harness->check(SessionAuthenticationService::class, 'counts pending otp failures', static function () use ($harness, $service, $resetSession): void {
        $resetSession();
        $service->beginPendingOtp(15, 'device-a');

        $harness->assertSame(1, $service->recordPendingOtpFailure());
        $harness->assertSame(2, $service->recordPendingOtpFailure());
    });

    $harness->check(SessionAuthenticationService::class, 'clears pending otp setup when the device changes', static function () use ($harness, $service, $resetSession): void {
        $resetSession();
        $service->beginPendingOtpSetup(21, 'device-a');

        $harness->assertTrue($service->hasPendingOtpSetup('device-a'));
        $service->invalidateForDeviceMismatch('device-b');
        $harness->assertTrue(!$service->hasPendingOtpSetup('device-b'));
    });
});
