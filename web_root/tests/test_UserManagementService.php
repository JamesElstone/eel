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
        $authorisedCreate = $service->createUser($adminId, 'Created User', 'created@example.test', 'Strong Password 1!');

        $harness->assertTrue(empty($unauthorisedCreate['success']));
        $harness->assertTrue(!empty($authorisedCreate['success']));
        $harness->assertTrue((int)($authorisedCreate['user_id'] ?? 0) > 0);

        $auditCount = InterfaceDB::countWhere('user_account_audit', [
            'affected_user_id' => (int)$authorisedCreate['user_id'],
            'actor_user_id' => $adminId,
            'action_type' => 'user_created',
        ]);
        $harness->assertSame(1, $auditCount);
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

$harness->check(UserManagementService::class, 'updates current user only with a valid current password', function () use ($harness, $withTemporaryManagedUsers): void {
    $withTemporaryManagedUsers(function (UserManagementService $service, UserAuthenticationService $authService, int $adminId, int $targetId) use ($harness): void {
        $withoutPassword = $service->updateCurrentUser($targetId, 'Target Renamed', 'target-renamed@example.test', '', '');
        $wrongPassword = $service->updateCurrentUser($targetId, 'Target Renamed', 'target-renamed@example.test', 'wrong', '');
        $success = $service->updateCurrentUser($targetId, 'Target Renamed', 'target-renamed@example.test', 'Strong Password 1!', 'New Strong Password 1!');

        $harness->assertTrue(empty($withoutPassword['success']));
        $harness->assertTrue(empty($wrongPassword['success']));
        $harness->assertTrue(!empty($success['success']));
        $harness->assertTrue(is_array($authService->authenticateByEmailAddress('target-renamed@example.test', 'New Strong Password 1!')));
    });
});

$harness->check(UserManagementService::class, 'resets another user OTP as an administrator', function () use ($harness, $withTemporaryManagedUsers): void {
    $withTemporaryManagedUsers(function (UserManagementService $service, UserAuthenticationService $authService, int $adminId, int $targetId, int $ordinaryId) use ($harness): void {
        $otpService = new OtpService('EEL Accounts');
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
