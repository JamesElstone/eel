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
$harness->run(SignupVerificationRateLimitService::class);

$harness->check(SignupVerificationRateLimitService::class, 'blocks client IP and session after repeated failed verification attempts', function () use ($harness): void {
    if (!InterfaceDB::tableExists('signup_verification_rate_limits')) {
        $harness->skip('signup verification rate limit table is not available.');
    }

    $clientIp = '203.0.113.' . random_int(1, 250);
    $request = new RequestFramework([], [], ['REMOTE_ADDR' => $clientIp], [], []);
    $session = new SessionAuthenticationService(request: $request);
    $service = new SignupVerificationRateLimitService();

    $session->startSession();
    InterfaceDB::beginTransaction();
    try {
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $statuses = $service->recordFailedVerification($request, $session);
            $harness->assertSame(2, count($statuses));
            $harness->assertTrue(!$service->isBlocked($request, $session));
        }

        $statuses = $service->recordFailedVerification($request, $session);
        $harness->assertSame(2, count($statuses));
        $harness->assertTrue($service->isBlocked($request, $session));

        $activeBlocks = $service->activeBlocks();
        $scopeTypes = array_map(static fn(array $row): string => (string)($row['scope_type'] ?? ''), $activeBlocks);
        $harness->assertTrue(in_array('ip', $scopeTypes, true));
        $harness->assertTrue(in_array('session', $scopeTypes, true));

        $sessionScopeKey = $service->sessionScopeKey($session);
        $harness->assertSame(64, strlen($sessionScopeKey));
        $harness->assertTrue(!str_contains(json_encode($activeBlocks) ?: '', session_id()));

        $harness->assertSame(1, $service->clearBlock('ip', $clientIp));
        $harness->assertSame(1, $service->clearBlock('session', $sessionScopeKey));
        $harness->assertTrue(!$service->isBlocked($request, $session));
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
});

$harness->check(SignupVerificationRateLimitService::class, 'clears current verification throttle rows after success', function () use ($harness): void {
    if (!InterfaceDB::tableExists('signup_verification_rate_limits')) {
        $harness->skip('signup verification rate limit table is not available.');
    }

    $clientIp = '203.0.113.' . random_int(1, 250);
    $request = new RequestFramework([], [], ['REMOTE_ADDR' => $clientIp], [], []);
    $session = new SessionAuthenticationService(request: $request);
    $service = new SignupVerificationRateLimitService();

    $session->startSession();
    InterfaceDB::beginTransaction();
    try {
        $service->recordFailedVerification($request, $session);
        $harness->assertSame(2, $service->clearCurrent($request, $session));
        $service->recordFailedVerification($request, $session);
        $statuses = $service->recordFailedVerification($request, $session);
        foreach ($statuses as $status) {
            $harness->assertSame(2, (int)($status['failed_attempts'] ?? 0));
        }
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
});

$harness->check(SignupVerificationRateLimitService::class, 'uses trusted reverse proxy client IP for verification throttles', function () use ($harness): void {
    if (!InterfaceDB::tableExists('signup_verification_rate_limits')) {
        $harness->skip('signup verification rate limit table is not available.');
    }

    $proxyIp = '198.51.100.10';
    $clientIp = '203.0.113.88';
    AppConfigurationStore::set('reverse_proxy.trusted_proxy_ips', [$proxyIp]);
    AppConfigurationStore::set('reverse_proxy.client_ip_headers', ['X-Forwarded-For']);

    $request = new RequestFramework([], [], [
        'REMOTE_ADDR' => $proxyIp,
        'HTTP_X_FORWARDED_FOR' => $clientIp,
    ], [], []);
    $session = new SessionAuthenticationService(request: $request);
    $service = new SignupVerificationRateLimitService();

    $session->startSession();
    InterfaceDB::beginTransaction();
    try {
        $service->recordFailedVerification($request, $session);
        $activeRows = InterfaceDB::fetchAll(
            'SELECT scope_type, scope_key
             FROM signup_verification_rate_limits
             WHERE scope_type = :scope_type
               AND scope_key = :scope_key',
            [
                'scope_type' => 'ip',
                'scope_key' => $clientIp,
            ]
        );

        $harness->assertSame(1, count($activeRows));
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
        AppConfigurationStore::set('reverse_proxy.trusted_proxy_ips', []);
        AppConfigurationStore::set('reverse_proxy.client_ip_headers', ['X-Forwarded-For', 'X-Real-IP']);
    }
});

$harness->check(SignupVerificationRateLimitService::class, 'supports pre-verification blocking without invite failure increments', function () use ($harness): void {
    if (!InterfaceDB::tableExists('signup_verification_rate_limits') || !InterfaceDB::tableExists('user_account_invites')) {
        $harness->skip('signup verification tables are not available.');
    }

    $clientIp = '203.0.113.' . random_int(1, 250);
    $request = new RequestFramework([], [], ['REMOTE_ADDR' => $clientIp], [], []);
    $session = new SessionAuthenticationService(request: $request);
    $service = new SignupVerificationRateLimitService();

    $session->startSession();
    InterfaceDB::beginTransaction();
    try {
        $marker = bin2hex(random_bytes(4));
        InterfaceDB::prepareExecute(
            'INSERT INTO users (
                display_name,
                email_address,
                mobile_number,
                password_hash,
                is_active,
                account_status,
                role_id
            ) VALUES (
                :display_name,
                :email_address,
                :mobile_number,
                NULL,
                0,
                :account_status,
                :role_id
            )',
            [
                'display_name' => 'Verification Block Test',
                'email_address' => 'verification-block-' . $marker . '@example.test',
                'mobile_number' => '+447700900123',
                'account_status' => 'pending_invitation',
                'role_id' => RoleAssignmentService::ADMIN_ROLE_ID,
            ]
        );
        $userId = (int)InterfaceDB::fetchColumn('SELECT COALESCE(MAX(id), 0) FROM users');

        InterfaceDB::prepareExecute(
            'INSERT INTO user_account_invites (
                user_id,
                token_hash,
                token_value,
                purpose,
                status,
                expires_at,
                failed_attempts
            ) VALUES (
                :user_id,
                :token_hash,
                :token_value,
                :purpose,
                :status,
                :expires_at,
                0
            )',
            [
                'user_id' => $userId,
                'token_hash' => hash('sha256', 'test-token-' . random_int(1, 999999)),
                'token_value' => 'test-token',
                'purpose' => AccountInviteService::PURPOSE_ACCOUNT_COMPLETION,
                'status' => AccountInviteService::STATUS_OPENED,
                'expires_at' => (new DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s'),
            ]
        );
        $inviteId = (int)InterfaceDB::fetchColumn('SELECT COALESCE(MAX(id), 0) FROM user_account_invites');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $service->recordFailedVerification($request, $session);
        }

        $harness->assertTrue($service->isBlocked($request, $session));
        $failedAttempts = (int)InterfaceDB::fetchColumn(
            'SELECT failed_attempts
             FROM user_account_invites
             WHERE id = :id',
            ['id' => $inviteId]
        );
        $harness->assertSame(0, $failedAttempts);
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
});

$harness->check(SignupVerificationRateLimitService::class, 'logs page reset action clears selected verification lockout', function () use ($harness): void {
    if (!InterfaceDB::tableExists('signup_verification_rate_limits')) {
        $harness->skip('signup verification rate limit table is not available.');
    }

    require_once APP_PAGES . 'logs.php';

    $clientIp = '203.0.113.' . random_int(1, 250);
    $request = new RequestFramework([], [
        'scope_type' => 'ip',
        'scope_key' => $clientIp,
    ], ['REMOTE_ADDR' => $clientIp], [], []);
    $session = new SessionAuthenticationService(request: $request);
    $service = new SignupVerificationRateLimitService();

    $session->startSession();
    InterfaceDB::beginTransaction();
    try {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $service->recordFailedVerification($request, $session);
        }

        $harness->assertTrue($service->isBlocked($request, $session));

        $page = new _logs();
        $method = new ReflectionMethod($page, 'resetSignupVerificationLockout');
        $method->setAccessible(true);
        $result = $method->invoke($page, $request, true);

        $harness->assertTrue($result instanceof ActionResultFramework);
        $harness->assertTrue($result->isSuccess());
        $ipRows = InterfaceDB::fetchAll(
            'SELECT scope_type, scope_key
             FROM signup_verification_rate_limits
             WHERE scope_type = :scope_type
               AND scope_key = :scope_key',
            [
                'scope_type' => 'ip',
                'scope_key' => $clientIp,
            ]
        );
        $sessionRows = InterfaceDB::fetchAll(
            'SELECT scope_type
             FROM signup_verification_rate_limits
             WHERE scope_type = :scope_type',
            ['scope_type' => 'session']
        );
        $harness->assertSame(0, count($ipRows));
        $harness->assertTrue(count($sessionRows) > 0);
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
});
