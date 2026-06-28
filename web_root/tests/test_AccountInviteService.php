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
$harness->run(AccountInviteService::class);

$setInMemoryConfig = static function (array $config): void {
    $property = new ReflectionProperty(AppConfigurationStore::class, 'config');
    $property->setAccessible(true);
    $property->setValue(null, $config);
};

$tokenFromLink = static function (string $link): string {
    $query = parse_url($link, PHP_URL_QUERY);
    if (!is_string($query)) {
        return '';
    }

    parse_str($query, $values);

    return trim((string)($values['token'] ?? ''));
};

$withInviteUser = function (callable $callback) use ($harness): void {
    if (
        !InterfaceDB::tableExists('users')
        || !InterfaceDB::tableExists('user_account_invites')
        || !InterfaceDB::tableExists('user_account_invite_deliveries')
    ) {
        $harness->skip('invitation tables are not available.');
    }

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
                'display_name' => 'Invite Target',
                'email_address' => 'invite-' . $marker . '@example.test',
                'mobile_number' => '+447123456789',
                'account_status' => 'pending_invitation',
                'role_id' => RoleAssignmentService::ADMIN_ROLE_ID,
            ]
        );
        $userId = (int)InterfaceDB::fetchColumn('SELECT COALESCE(MAX(id), 0) FROM users');
        UserAuthenticationService::forgetUserByIdCache($userId);

        $callback($userId);
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
};

$harness->check(AccountInviteService::class, 'reuses an active invite token for repeated link generation', function () use ($harness, $withInviteUser, $tokenFromLink): void {
    $withInviteUser(function (int $userId) use ($harness, $tokenFromLink): void {
        $service = new AccountInviteService();
        $first = $service->createInviteLink(0, $userId, 'email', 'https://example.test');
        $second = $service->createInviteLink(0, $userId, 'email', 'https://example.test');

        $harness->assertTrue(!empty($first['success']));
        $harness->assertTrue(!empty($second['success']));
        $harness->assertTrue(str_contains((string)$second['link'], '/signup/?token='));
        $harness->assertSame((int)$first['invite_id'], (int)$second['invite_id']);
        $harness->assertSame($tokenFromLink((string)$first['link']), $tokenFromLink((string)$second['link']));
        $harness->assertSame(0, InterfaceDB::countWhere('user_account_invites', 'token_hash', (string)($first['link'] ?? '')));
        $harness->assertSame(1, InterfaceDB::countWhere('user_account_invites', [
            'user_id' => $userId,
        ]));
        $harness->assertSame(0, InterfaceDB::countWhere('user_account_invites', [
            'user_id' => $userId,
            'status' => AccountInviteService::STATUS_REVOKED,
        ]));
    });
});

$harness->check(AccountInviteService::class, 'records email and SMS deliveries against the same invite token', function () use ($harness, $withInviteUser, $setInMemoryConfig, $tokenFromLink): void {
    $baseConfig = AppConfigurationStore::config(true);

    try {
        $config = $baseConfig;
        $config['smtp']['enabled'] = true;
        $config['smtp']['development_mode'] = true;
        $config['sms']['enabled'] = true;
        $config['sms']['development_mode'] = true;
        $setInMemoryConfig($config);

        $withInviteUser(function (int $userId) use ($harness, $tokenFromLink): void {
            $service = new AccountInviteService();
            $emailInvite = $service->sendEmailInvite($userId, $userId, 'https://example.test');
            $smsInvite = $service->sendSmsInvite($userId, $userId, 'https://example.test');

            $harness->assertTrue(!empty($emailInvite['success']));
            $harness->assertTrue(!empty($smsInvite['success']));
            $harness->assertSame((int)$emailInvite['invite_id'], (int)$smsInvite['invite_id']);
            $harness->assertSame($tokenFromLink((string)$emailInvite['link']), $tokenFromLink((string)$smsInvite['link']));
            $harness->assertSame(1, InterfaceDB::countWhere('user_account_invites', [
                'user_id' => $userId,
            ]));

            $deliveries = InterfaceDB::fetchAll(
                'SELECT contact_method, status, created_by_user_id
                 FROM user_account_invite_deliveries
                 WHERE invite_id = :invite_id
                 ORDER BY id ASC',
                ['invite_id' => (int)$emailInvite['invite_id']]
            );
            $harness->assertCount(2, $deliveries);
            $harness->assertSame('email', (string)($deliveries[0]['contact_method'] ?? ''));
            $harness->assertSame('sent', (string)($deliveries[0]['status'] ?? ''));
            $harness->assertSame($userId, (int)($deliveries[0]['created_by_user_id'] ?? 0));
            $harness->assertSame('sms', (string)($deliveries[1]['contact_method'] ?? ''));
            $harness->assertSame('sent', (string)($deliveries[1]['status'] ?? ''));
            $harness->assertSame($userId, (int)($deliveries[1]['created_by_user_id'] ?? 0));
        });
    } finally {
        $setInMemoryConfig($baseConfig);
    }
});

$harness->check(AccountInviteService::class, 'creates a new invite token after revocation', function () use ($harness, $withInviteUser, $tokenFromLink): void {
    $withInviteUser(function (int $userId) use ($harness, $tokenFromLink): void {
        $service = new AccountInviteService();
        $first = $service->createInviteLink(0, $userId, 'email', 'https://example.test');
        $revoke = $service->revokeInvite(0, (int)$first['invite_id']);
        $second = $service->createInviteLink(0, $userId, 'email', 'https://example.test');

        $harness->assertTrue(!empty($first['success']));
        $harness->assertTrue(!empty($revoke['success']));
        $harness->assertTrue(!empty($second['success']));
        $harness->assertTrue((int)$first['invite_id'] !== (int)$second['invite_id']);
        $harness->assertTrue($tokenFromLink((string)$first['link']) !== $tokenFromLink((string)$second['link']));
        $harness->assertSame(1, InterfaceDB::countWhere('user_account_invites', [
            'user_id' => $userId,
            'status' => AccountInviteService::STATUS_REVOKED,
        ]));
    });
});

$harness->check(AccountInviteService::class, 'resolves base URL from trusted forwarded request headers', function () use ($harness, $setInMemoryConfig): void {
    $baseConfig = AppConfigurationStore::config(true);
    $config = $baseConfig;
    $config['invitation']['base_url_override'] = '';
    $config['reverse_proxy']['trusted_proxy_ips'] = ['198.51.100.10'];
    $config['reverse_proxy']['client_ip_headers'] = ['X-Forwarded-For'];
    $setInMemoryConfig($config);

    $request = new RequestFramework(
        [],
        [],
        [
            'REQUEST_METHOD' => 'POST',
            'REMOTE_ADDR' => '198.51.100.10',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'eel.example.test',
        ],
        [],
        []
    );

    try {
        $harness->assertSame('https://eel.example.test', (new AccountInviteService())->buildBaseUrl($request));
    } finally {
        $setInMemoryConfig($baseConfig);
    }
});

$harness->check(AccountInviteService::class, 'ignores untrusted forwarded base URL headers', function () use ($harness, $setInMemoryConfig): void {
    $baseConfig = AppConfigurationStore::config(true);
    $config = $baseConfig;
    $config['invitation']['base_url_override'] = '';
    $config['reverse_proxy']['trusted_proxy_ips'] = [];
    $config['reverse_proxy']['client_ip_headers'] = ['X-Forwarded-For'];
    $setInMemoryConfig($config);

    $request = new RequestFramework(
        [],
        [],
        [
            'REQUEST_METHOD' => 'POST',
            'REMOTE_ADDR' => '198.51.100.20',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'evil.example.test',
            'HTTP_HOST' => 'eel.example.test',
        ],
        [],
        []
    );

    try {
        $harness->assertSame('http://eel.example.test', (new AccountInviteService())->buildBaseUrl($request));
    } finally {
        $setInMemoryConfig($baseConfig);
    }
});

$harness->check(AccountInviteService::class, 'resolves actor display names for invite templates', function () use ($harness, $withInviteUser): void {
    $withInviteUser(function (int $userId) use ($harness): void {
        $service = new AccountInviteService();
        $method = new ReflectionMethod(AccountInviteService::class, 'actorDisplayName');
        $method->setAccessible(true);

        $harness->assertSame('Invite Target', $method->invoke($service, $userId));
        $harness->assertSame('', $method->invoke($service, 0));
    });
});
