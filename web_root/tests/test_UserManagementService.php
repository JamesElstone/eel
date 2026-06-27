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
$harness->run(UserManagementService::class);

$userManagementTempDirectory = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp';
if (!is_dir($userManagementTempDirectory)) {
    mkdir($userManagementTempDirectory, 0777, true);
}

$withTemporaryManagedUsers = function (callable $callback) use ($harness, $userManagementTempDirectory): void {
    if (!InterfaceDB::tableExists('users') || !InterfaceDB::tableExists('user_account_audit')) {
        $harness->skip('users or user_account_audit table is not available on the default InterfaceDB connection.');
    }

    InterfaceDB::beginTransaction();
    $securityPath = $userManagementTempDirectory . DIRECTORY_SEPARATOR . 'user-management-' . bin2hex(random_bytes(8)) . '.keys';

    try {
        $authService = new UserAuthenticationService($securityPath, [
            'memory_cost' => 8192,
            'time_cost' => 1,
            'threads' => 1,
        ]);
        $marker = bin2hex(random_bytes(4));

        $admin = $authService->createUser('Admin User', 'admin-' . $marker . '@example.test', 'Strong Password 1!');
        $target = $authService->createUser('Target User', 'target-' . $marker . '@example.test', 'Strong Password 1!');
        $ordinary = $authService->createUser('Ordinary User', 'ordinary-' . $marker . '@example.test', 'Strong Password 1!');

        $adminId = (int)($admin['user_id'] ?? 0);
        $targetId = (int)($target['user_id'] ?? 0);
        $ordinaryId = (int)($ordinary['user_id'] ?? 0);

        InterfaceDB::prepareExecute('INSERT INTO roles (role_name) VALUES (:role_name)', ['role_name' => 'Managed Users ' . $marker]);
        $standardRoleId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM roles WHERE role_name = :role_name ORDER BY id DESC LIMIT 1',
            ['role_name' => 'Managed Users ' . $marker]
        );

        InterfaceDB::prepareExecute('UPDATE users SET role_id = :role_id WHERE id = :id', ['role_id' => RoleAssignmentService::ADMIN_ROLE_ID, 'id' => $adminId]);
        InterfaceDB::prepareExecute('UPDATE users SET role_id = :role_id WHERE id IN (:target_id, :ordinary_id)', ['role_id' => $standardRoleId, 'target_id' => $targetId, 'ordinary_id' => $ordinaryId]);
        UserAuthenticationService::forgetUserByIdCache($adminId);
        UserAuthenticationService::forgetUserByIdCache($targetId);
        UserAuthenticationService::forgetUserByIdCache($ordinaryId);

        $roleService = new RoleAssignmentService(userAuthenticationService: $authService);
        $managementService = new UserManagementService(
            userAuthenticationService: $authService,
            roleAssignmentService: $roleService
        );

        $callback($managementService, $authService, $adminId, $targetId, $ordinaryId);
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
        if (is_file($securityPath)) {
            unlink($securityPath);
        }
    }
};

$harness->check(UserManagementService::class, 'requires management permission for admin actions', function () use ($harness, $withTemporaryManagedUsers): void {
    $withTemporaryManagedUsers(function (UserManagementService $service, UserAuthenticationService $authService, int $adminId, int $targetId, int $ordinaryId) use ($harness): void {
        $unauthorisedCreate = $service->createUser($ordinaryId, 'Blocked User', 'blocked@example.test', 'Strong Password 1!');
        $authorisedCreate = $service->createUser($adminId, 'Created User', 'created@example.test', 'Strong Password 1!', '+44', '07123 456789');

        $harness->assertTrue(empty($unauthorisedCreate['success']));
        $harness->assertTrue(!empty($authorisedCreate['success']));
        $harness->assertTrue((int)($authorisedCreate['user_id'] ?? 0) > 0);
        $harness->assertSame('+447123456789', (string)(($authorisedCreate['user'] ?? [])['mobile_number'] ?? ''));

        $auditCount = InterfaceDB::countWhere('user_account_audit', [
            'affected_user_id' => (int)$authorisedCreate['user_id'],
            'actor_user_id' => $adminId,
            'action_type' => 'user_created',
        ]);
        $harness->assertSame(1, $auditCount);
    });
});

$harness->check(UserManagementService::class, 'creates pending users and sends invites to every supplied contact method', function () use ($harness, $withTemporaryManagedUsers): void {
    if (
        !InterfaceDB::tableExists('user_account_invites')
        || !InterfaceDB::tableExists('user_account_invite_deliveries')
    ) {
        $harness->skip('invitation tables are not available.');
    }

    $property = new ReflectionProperty(AppConfigurationStore::class, 'config');
    $property->setAccessible(true);
    $baseConfig = AppConfigurationStore::config(true);

    try {
        $config = $baseConfig;
        $config['smtp']['enabled'] = true;
        $config['smtp']['development_mode'] = true;
        $config['sms']['enabled'] = true;
        $config['sms']['development_mode'] = true;
        $property->setValue(null, $config);

        $withTemporaryManagedUsers(function (UserManagementService $service, UserAuthenticationService $authService, int $adminId): void {
            $harness = new GeneratedServiceClassTestHarness();
            $marker = bin2hex(random_bytes(4));
            $result = $service->createInvitedUserAndSendInvites(
                $adminId,
                'Invited User',
                'invited-' . $marker . '@example.test',
                '+44',
                '07123 456789',
                RoleAssignmentService::ADMIN_ROLE_ID,
                'https://example.test'
            );

            $userId = (int)($result['user_id'] ?? 0);
            $harness->assertTrue(!empty($result['success']));
            $harness->assertSame(2, (int)($result['sent_invite_count'] ?? 0));
            $harness->assertTrue($userId > 0);

            $inviteId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM user_account_invites WHERE user_id = :user_id ORDER BY id DESC LIMIT 1',
                ['user_id' => $userId]
            );
            $deliveries = InterfaceDB::fetchAll(
                'SELECT contact_method, status
                 FROM user_account_invite_deliveries
                 WHERE invite_id = :invite_id
                 ORDER BY id ASC',
                ['invite_id' => $inviteId]
            );

            $harness->assertCount(2, $deliveries);
            $harness->assertSame('email', (string)($deliveries[0]['contact_method'] ?? ''));
            $harness->assertSame('sent', (string)($deliveries[0]['status'] ?? ''));
            $harness->assertSame('sms', (string)($deliveries[1]['contact_method'] ?? ''));
            $harness->assertSame('sent', (string)($deliveries[1]['status'] ?? ''));
        });
    } finally {
        $property->setValue(null, $baseConfig);
    }
});

$harness->check(UserManagementService::class, 'lists archived users with valid restore contact methods', function () use ($harness, $withTemporaryManagedUsers): void {
    $withTemporaryManagedUsers(function (UserManagementService $service, UserAuthenticationService $authService, int $adminId, int $targetId, int $ordinaryId) use ($harness): void {
        InterfaceDB::prepareExecute(
            'UPDATE users
             SET account_status = :account_status,
                 is_active = 0,
                 mobile_number = :mobile_number
             WHERE id = :id',
            [
                'account_status' => 'archived',
                'mobile_number' => '+447123456789',
                'id' => $targetId,
            ]
        );
        InterfaceDB::prepareExecute(
            'UPDATE users
             SET account_status = :account_status,
                 is_active = 0,
                 email_address = NULL,
                 mobile_number = NULL
             WHERE id = :id',
            [
                'account_status' => 'archived',
                'id' => $ordinaryId,
            ]
        );
        UserAuthenticationService::forgetUserByIdCache($targetId);
        UserAuthenticationService::forgetUserByIdCache($ordinaryId);

        $dashboard = $service->restorableArchivedUsersDashboard($adminId);
        $users = (array)($dashboard['users'] ?? []);

        $harness->assertCount(1, $users);
        $harness->assertSame($targetId, (int)($users[0]['id'] ?? 0));
        $harness->assertSame('email + mobile', (string)($users[0]['contact_label'] ?? ''));
    });
});

$harness->check(UserManagementService::class, 'restores archived users to pending invitation and sends every stored invite method', function () use ($harness, $withTemporaryManagedUsers): void {
    if (
        !InterfaceDB::tableExists('user_account_invites')
        || !InterfaceDB::tableExists('user_account_invite_deliveries')
    ) {
        $harness->skip('invitation tables are not available.');
    }

    $property = new ReflectionProperty(AppConfigurationStore::class, 'config');
    $property->setAccessible(true);
    $baseConfig = AppConfigurationStore::config(true);

    try {
        $config = $baseConfig;
        $config['smtp']['enabled'] = true;
        $config['smtp']['development_mode'] = true;
        $config['sms']['enabled'] = true;
        $config['sms']['development_mode'] = true;
        $property->setValue(null, $config);

        $withTemporaryManagedUsers(function (UserManagementService $service, UserAuthenticationService $authService, int $adminId, int $targetId) use ($harness): void {
            InterfaceDB::prepareExecute(
                'UPDATE users
                 SET account_status = :account_status,
                     is_active = 0,
                     mobile_number = :mobile_number,
                     must_change_password = 1,
                     account_completed_at = CURRENT_TIMESTAMP,
                     current_session_token_hash = :session_hash,
                     current_session_started_at = CURRENT_TIMESTAMP,
                     current_session_last_seen_at = CURRENT_TIMESTAMP
                 WHERE id = :id',
                [
                    'account_status' => 'archived',
                    'mobile_number' => '+447123456789',
                    'session_hash' => 'restore-test-session',
                    'id' => $targetId,
                ]
            );
            UserAuthenticationService::forgetUserByIdCache($targetId);

            $result = $service->restoreArchivedUserAndSendInvites($adminId, $targetId, 'https://example.test');
            $user = InterfaceDB::fetchOne(
                'SELECT account_status,
                        is_active,
                        password_hash,
                        must_change_password,
                        account_completed_at,
                        current_session_token_hash
                 FROM users
                 WHERE id = :id',
                ['id' => $targetId]
            );

            $harness->assertTrue(!empty($result['success']));
            $harness->assertSame(2, (int)($result['sent_invite_count'] ?? 0));
            $harness->assertSame('pending_invitation', (string)($user['account_status'] ?? ''));
            $harness->assertSame(0, (int)($user['is_active'] ?? 1));
            $harness->assertSame(null, $user['password_hash'] ?? null);
            $harness->assertSame(0, (int)($user['must_change_password'] ?? 1));
            $harness->assertSame(null, $user['account_completed_at'] ?? null);
            $harness->assertSame(null, $user['current_session_token_hash'] ?? null);

            $inviteId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM user_account_invites WHERE user_id = :user_id ORDER BY id DESC LIMIT 1',
                ['user_id' => $targetId]
            );
            $deliveries = InterfaceDB::fetchAll(
                'SELECT contact_method, status
                 FROM user_account_invite_deliveries
                 WHERE invite_id = :invite_id
                 ORDER BY id ASC',
                ['invite_id' => $inviteId]
            );

            $harness->assertCount(2, $deliveries);
            $harness->assertSame('email', (string)($deliveries[0]['contact_method'] ?? ''));
            $harness->assertSame('sms', (string)($deliveries[1]['contact_method'] ?? ''));
            $harness->assertSame(1, InterfaceDB::countWhere('user_account_audit', [
                'affected_user_id' => $targetId,
                'actor_user_id' => $adminId,
                'action_type' => 'user_restored',
            ]));
        });
    } finally {
        $property->setValue(null, $baseConfig);
    }
});

$harness->check(UserManagementService::class, 'sends only the available contact method when restoring archived users', function () use ($harness, $withTemporaryManagedUsers): void {
    if (
        !InterfaceDB::tableExists('user_account_invites')
        || !InterfaceDB::tableExists('user_account_invite_deliveries')
    ) {
        $harness->skip('invitation tables are not available.');
    }

    $property = new ReflectionProperty(AppConfigurationStore::class, 'config');
    $property->setAccessible(true);
    $baseConfig = AppConfigurationStore::config(true);

    try {
        $config = $baseConfig;
        $config['smtp']['enabled'] = false;
        $config['sms']['enabled'] = true;
        $config['sms']['development_mode'] = true;
        $property->setValue(null, $config);

        $withTemporaryManagedUsers(function (UserManagementService $service, UserAuthenticationService $authService, int $adminId, int $targetId) use ($harness): void {
            InterfaceDB::prepareExecute(
                'UPDATE users
                 SET account_status = :account_status,
                     is_active = 0,
                     email_address = NULL,
                     mobile_number = :mobile_number
                 WHERE id = :id',
                [
                    'account_status' => 'archived',
                    'mobile_number' => '+447123456789',
                    'id' => $targetId,
                ]
            );
            UserAuthenticationService::forgetUserByIdCache($targetId);

            $result = $service->restoreArchivedUserAndSendInvites($adminId, $targetId, 'https://example.test');
            $inviteId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM user_account_invites WHERE user_id = :user_id ORDER BY id DESC LIMIT 1',
                ['user_id' => $targetId]
            );
            $deliveries = InterfaceDB::fetchAll(
                'SELECT contact_method, status
                 FROM user_account_invite_deliveries
                 WHERE invite_id = :invite_id
                 ORDER BY id ASC',
                ['invite_id' => $inviteId]
            );

            $harness->assertTrue(!empty($result['success']));
            $harness->assertSame(1, (int)($result['sent_invite_count'] ?? 0));
            $harness->assertCount(1, $deliveries);
            $harness->assertSame('sms', (string)($deliveries[0]['contact_method'] ?? ''));
        });
    } finally {
        $property->setValue(null, $baseConfig);
    }
});

$harness->check(UserManagementService::class, 'rejects non archived users during restore', function () use ($harness, $withTemporaryManagedUsers): void {
    $withTemporaryManagedUsers(function (UserManagementService $service, UserAuthenticationService $authService, int $adminId, int $targetId) use ($harness): void {
        $result = $service->restoreArchivedUserAndSendInvites($adminId, $targetId, 'https://example.test');
        $user = $authService->userById($targetId);

        $harness->assertTrue(empty($result['success']));
        $harness->assertSame('active', (string)($user['account_status'] ?? ''));
    });
});

$harness->check(UserManagementService::class, 'keeps restored users pending when invite delivery fails', function () use ($harness, $withTemporaryManagedUsers): void {
    if (
        !InterfaceDB::tableExists('user_account_invites')
        || !InterfaceDB::tableExists('user_account_invite_deliveries')
    ) {
        $harness->skip('invitation tables are not available.');
    }

    $property = new ReflectionProperty(AppConfigurationStore::class, 'config');
    $property->setAccessible(true);
    $baseConfig = AppConfigurationStore::config(true);

    try {
        $config = $baseConfig;
        $config['smtp']['enabled'] = false;
        $config['sms']['enabled'] = false;
        $property->setValue(null, $config);

        $withTemporaryManagedUsers(function (UserManagementService $service, UserAuthenticationService $authService, int $adminId, int $targetId) use ($harness): void {
            InterfaceDB::prepareExecute(
                'UPDATE users
                 SET account_status = :account_status,
                     is_active = 0,
                     mobile_number = NULL
                 WHERE id = :id',
                [
                    'account_status' => 'archived',
                    'id' => $targetId,
                ]
            );
            UserAuthenticationService::forgetUserByIdCache($targetId);

            $result = $service->restoreArchivedUserAndSendInvites($adminId, $targetId, 'https://example.test');
            $user = $authService->userById($targetId);

            $harness->assertTrue(empty($result['success']));
            $harness->assertTrue((array)($result['errors'] ?? []) !== []);
            $harness->assertSame(['email'], (array)($result['failed_channels'] ?? []));
            $harness->assertSame('pending_invitation', (string)($user['account_status'] ?? ''));
            $harness->assertSame(null, InterfaceDB::fetchColumn('SELECT password_hash FROM users WHERE id = :id', ['id' => $targetId]));
        });
    } finally {
        $property->setValue(null, $baseConfig);
    }
});

$harness->check(UserManagementService::class, 'rejects pending invited users without a contact method', function () use ($harness, $withTemporaryManagedUsers): void {
    $withTemporaryManagedUsers(function (UserManagementService $service, UserAuthenticationService $authService, int $adminId): void {
        $harness = new GeneratedServiceClassTestHarness();
        $result = $service->createInvitedUserAndSendInvites(
            $adminId,
            'No Contact User',
            '',
            '+44',
            '',
            RoleAssignmentService::ADMIN_ROLE_ID,
            'https://example.test'
        );

        $harness->assertTrue(empty($result['success']));
        $harness->assertSame('At least one contact method is required.', (string)(($result['errors'] ?? [])[0] ?? ''));
    });
});

$harness->check(UserManagementService::class, 'prevents self destructive admin account changes', function () use ($harness, $withTemporaryManagedUsers): void {
    $withTemporaryManagedUsers(function (UserManagementService $service, UserAuthenticationService $authService, int $adminId): void {
        $harness = new GeneratedServiceClassTestHarness();

        $disableSelf = $service->setUserEnabled($adminId, $adminId, false);
        $setOwnPassword = $service->setPasswordForUser($adminId, $adminId, 'New Strong Password 1!');

        $harness->assertTrue(empty($disableSelf['success']));
        $harness->assertTrue(empty($setOwnPassword['success']));
    });
});

$harness->check(UserManagementService::class, 'normalises mobile numbers by stripping local leading zeroes', function () use ($harness): void {
    $harness->assertSame('+447123456789', UserManagementService::normaliseMobileNumberFromParts('+44', '07123 456789'));
    $harness->assertSame('+447775633330', UserManagementService::normaliseMobileNumberFromParts('+44', '7775633330'));
    $harness->assertSame('+447123456789', UserManagementService::normaliseMobileNumberFromParts('+44', '+44 07123 456789'));
    $harness->assertSame('+447123456789', UserManagementService::normaliseMobileNumberFromParts('+44', '0044 07123 456789'));
});

$harness->check(UserManagementService::class, 'splits stored mobile numbers with or without a leading plus', function () use ($harness): void {
    $withPlus = UserManagementService::mobileNumberParts('+447775633330');
    $withoutPlus = UserManagementService::mobileNumberParts('447775633330');

    $harness->assertSame('+44', (string)($withPlus['country_code'] ?? ''));
    $harness->assertSame('7775633330', (string)($withPlus['local_number'] ?? ''));
    $harness->assertSame('+44', (string)($withoutPlus['country_code'] ?? ''));
    $harness->assertSame('7775633330', (string)($withoutPlus['local_number'] ?? ''));
});

$harness->check(UserManagementService::class, 'updates current user only with a valid current password', function () use ($harness, $withTemporaryManagedUsers): void {
    $withTemporaryManagedUsers(function (UserManagementService $service, UserAuthenticationService $authService, int $adminId, int $targetId) use ($harness): void {
        $withoutPassword = $service->updateCurrentUser($targetId, 'Target Renamed', 'target-renamed@example.test', '', '');
        $wrongPassword = $service->updateCurrentUser($targetId, 'Target Renamed', 'target-renamed@example.test', 'wrong', '');
        $success = $service->updateCurrentUser($targetId, 'Target Renamed', 'target-renamed@example.test', 'Strong Password 1!', 'New Strong Password 1!', '+44', '07123 456789');

        $harness->assertTrue(empty($withoutPassword['success']));
        $harness->assertTrue(empty($wrongPassword['success']));
        $harness->assertTrue(!empty($success['success']));
        $harness->assertSame('+447123456789', (string)(($success['user'] ?? [])['mobile_number'] ?? ''));
        $harness->assertTrue(is_array($authService->authenticateByEmailAddress('target-renamed@example.test', 'New Strong Password 1!')));
    });
});

$harness->check(UserManagementService::class, 'updates current user mobile number without changing password', function () use ($harness, $withTemporaryManagedUsers): void {
    $withTemporaryManagedUsers(function (UserManagementService $service, UserAuthenticationService $authService, int $adminId, int $targetId): void {
        $target = $authService->userById($targetId);
        $success = $service->updateCurrentUser(
            $targetId,
            (string)($target['display_name'] ?? ''),
            (string)($target['email_address'] ?? ''),
            'Strong Password 1!',
            '',
            '+44',
            '7775633330'
        );

        $harness = new GeneratedServiceClassTestHarness();
        $harness->assertTrue(!empty($success['success']));
        $harness->assertSame('+447775633330', (string)(($success['user'] ?? [])['mobile_number'] ?? ''));
    });
});

$harness->check(UserManagementService::class, 'does not lose mobile number when incomplete user cache rows are offered', function () use ($harness, $withTemporaryManagedUsers): void {
    $withTemporaryManagedUsers(function (UserManagementService $service, UserAuthenticationService $authService, int $adminId, int $targetId): void {
        $updatedMobile = $authService->updateUser($targetId, 'Target User', 'target@example.test', null, null, '+447775633330');
        UserAuthenticationService::forgetUserByIdCache($targetId);
        UserAuthenticationService::primeUserByIdCache([
            'id' => $targetId,
            'display_name' => 'Target User',
            'email_address' => 'target@example.test',
        ]);

        $currentUser = $service->currentUserDetails($targetId);

        $harness = new GeneratedServiceClassTestHarness();
        $harness->assertTrue(!empty($updatedMobile['success']));
        $harness->assertSame('+447775633330', (string)($currentUser['mobile_number'] ?? ''));
    });
});

$harness->check(UserManagementService::class, 'clears login lockouts when an administrator sets a password', function () use ($harness, $withTemporaryManagedUsers): void {
    $withTemporaryManagedUsers(function (UserManagementService $service, UserAuthenticationService $authService, int $adminId, int $targetId): void {
        $harness = new GeneratedServiceClassTestHarness();
        $target = $authService->userById($targetId);
        $emailAddress = (string)($target['email_address'] ?? '');

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $authService->recordFailedPasswordAttempt($emailAddress, 'locked-device');
        }

        $reset = $service->setPasswordForUser($adminId, $targetId, 'New Strong Password 1!');
        $lockouts = $service->loginLockoutsDashboard($adminId);

        $harness->assertTrue(!empty($reset['success']));
        $harness->assertTrue((int)($reset['cleared_rate_limit_rows'] ?? 0) > 0);
        $harness->assertTrue(!empty($reset['must_change_password']));
        $harness->assertSame([], (array)($lockouts['locked_users'] ?? []));
        $harness->assertTrue(is_array($authService->authenticateByEmailAddress($emailAddress, 'New Strong Password 1!')));
        $targetAfterReset = $authService->userById($targetId);
        $harness->assertSame(1, (int)($targetAfterReset['must_change_password'] ?? 0));
    });
});

$harness->check(UserManagementService::class, 'resets another user OTP as an administrator', function () use ($harness, $withTemporaryManagedUsers): void {
    $withTemporaryManagedUsers(function (UserManagementService $service, UserAuthenticationService $authService, int $adminId, int $targetId, int $ordinaryId) use ($harness): void {
        $otpService = new OtpService('eelKit Framework');
        $secret = $otpService->generateOTPsecret($targetId);
        $code = (new OtpVerificationService())->generateCodeForTimestep(
            6,
            'SHA1',
            $secret,
            (new OtpVerificationService())->currentTimestep(time(), 30)
        );
        $harness->assertTrue($otpService->enableOTP($targetId, $code));

        $blocked = $service->resetUserOtp($ordinaryId, $targetId);
        $reset = $service->resetUserOtp($adminId, $targetId);

        $harness->assertTrue(empty($blocked['success']));
        $harness->assertTrue(!empty($reset['success']));
        $harness->assertTrue(!$otpService->isOTPenabled($targetId));
    });
});

$harness->check(UserManagementService::class, 'shows and resets locked out users as an administrator', function () use ($harness, $withTemporaryManagedUsers): void {
    $withTemporaryManagedUsers(function (UserManagementService $service, UserAuthenticationService $authService, int $adminId, int $targetId, int $ordinaryId) use ($harness): void {
        $target = $authService->userById($targetId);
        $emailAddress = (string)($target['email_address'] ?? '');

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $authService->recordFailedPasswordAttempt($emailAddress, 'locked-device');
        }

        $blocked = $service->resetUserLoginLockout($ordinaryId, $targetId);
        $dashboardBeforeReset = $service->loginLockoutsDashboard($adminId);
        $reset = $service->resetUserLoginLockout($adminId, $targetId);
        $dashboardAfterReset = $service->loginLockoutsDashboard($adminId);

        $harness->assertTrue(empty($blocked['success']));
        $harness->assertSame($targetId, (int)(($dashboardBeforeReset['locked_users'][0] ?? [])['user_id'] ?? 0));
        $harness->assertTrue(!empty($reset['success']));
        $harness->assertTrue((int)($reset['cleared_rate_limit_rows'] ?? 0) > 0);
        $harness->assertSame([], (array)($dashboardAfterReset['locked_users'] ?? []));
        $harness->assertSame(1, InterfaceDB::countWhere('user_account_audit', [
            'affected_user_id' => $targetId,
            'actor_user_id' => $adminId,
            'action_type' => 'login_lockout_reset_admin',
        ]));
    });
});

$harness->check(UserManagementService::class, 'requires another user to change password as an administrator', function () use ($harness, $withTemporaryManagedUsers): void {
    $withTemporaryManagedUsers(function (UserManagementService $service, UserAuthenticationService $authService, int $adminId, int $targetId, int $ordinaryId) use ($harness): void {
        $blocked = $service->requirePasswordChangeForUser($ordinaryId, $targetId);
        $required = $service->requirePasswordChangeForUser($adminId, $targetId);
        $target = $authService->userById($targetId);

        $harness->assertTrue(empty($blocked['success']));
        $harness->assertTrue(!empty($required['success']));
        $harness->assertSame(1, (int)($target['must_change_password'] ?? 0));
        $harness->assertSame(1, InterfaceDB::countWhere('user_account_audit', [
            'affected_user_id' => $targetId,
            'actor_user_id' => $adminId,
            'action_type' => 'password_change_required_admin',
        ]));
    });
});

$harness->check(UserManagementService::class, 'allows administrators to make OTP setup optional for a user', function () use ($harness, $withTemporaryManagedUsers): void {
    $withTemporaryManagedUsers(function (UserManagementService $service, UserAuthenticationService $authService, int $adminId, int $targetId, int $ordinaryId) use ($harness): void {
        $blocked = $service->setUserOtpRequired($ordinaryId, $targetId, false);
        $updated = $service->setUserOtpRequired($adminId, $targetId, false);
        $target = $authService->userById($targetId);

        $harness->assertTrue(empty($blocked['success']));
        $harness->assertTrue(!empty($updated['success']));
        $harness->assertSame(0, (int)($target['otp_required'] ?? 1));
        $harness->assertSame(1, InterfaceDB::countWhere('user_account_audit', [
            'affected_user_id' => $targetId,
            'actor_user_id' => $adminId,
            'action_type' => 'otp_requirement_changed',
        ]));
    });
});
