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
$harness->run(UserSessionService::class);

$withTemporaryUserSessionUser = function (callable $callback) use ($harness): void {
    if (!InterfaceDB::tableExists('users')) {
        $harness->skip('users table is not available on the default InterfaceDB connection.');
    }

    InterfaceDB::beginTransaction();

    try {
        $marker = 'user-session-' . bin2hex(random_bytes(8));

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
                'display_name' => 'User Session ' . $marker,
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
            throw new RuntimeException('Temporary session test user could not be reloaded.');
        }

        $callback($userId);
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
};

$harness->check(UserSessionService::class, 'builds request metadata with browser and IP labels', function () use ($harness): void {
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.10, 10.0.0.1';
    $_SERVER['REMOTE_ADDR'] = '10.0.0.2';
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Chrome/124.0 Safari/537.36';

    $metadata = (new UserSessionService())->buildRequestMetadata('device-a');

    $harness->assertSame('device-a', $metadata['device_id']);
    $harness->assertSame('198.51.100.10', $metadata['ip_address']);
    $harness->assertSame('Chrome', $metadata['browser_label']);
});

$harness->check(UserSessionService::class, 'starts and validates authenticated sessions', function () use ($harness, $withTemporaryUserSessionUser): void {
    $withTemporaryUserSessionUser(function (int $userId) use ($harness): void {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.44';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Firefox/124.0';

        $service = new UserSessionService();
        $session = $service->startAuthenticatedSession($userId, 'device-a', 'session@example.test');
        $hash = (string)$session['session_token_hash'];

        $harness->assertTrue($hash !== '');
        $harness->assertSame('Firefox', (string)($session['metadata']['browser_label'] ?? ''));

        $valid = $service->validateAuthenticatedSession($userId, $hash, 'device-a');
        $invalidDevice = $service->validateAuthenticatedSession($userId, $hash, 'device-b');

        $harness->assertTrue((bool)($valid['valid'] ?? false));
        $harness->assertTrue(!((bool)($invalidDevice['valid'] ?? true)));
    });
});

$harness->check(UserSessionService::class, 'replaces existing authenticated sessions', function () use ($harness, $withTemporaryUserSessionUser): void {
    $withTemporaryUserSessionUser(function (int $userId) use ($harness): void {
        $service = new UserSessionService();
        $first = $service->startAuthenticatedSession($userId, 'device-a', 'first@example.test');
        $second = $service->startAuthenticatedSession($userId, 'device-b', 'second@example.test');

        $harness->assertTrue((string)$first['session_token_hash'] !== (string)$second['session_token_hash']);
        $harness->assertTrue(is_array($second['replaced_session']));
        $harness->assertSame('device-a', (string)($second['replaced_session']['device_id'] ?? ''));
    });
});
