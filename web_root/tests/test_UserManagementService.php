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
$harness->run(UserManagementService::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $tempDirectory = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp';
    $securityPath = $tempDirectory . DIRECTORY_SEPARATOR . 'user-management-security.keys';

    if (!is_dir($tempDirectory)) {
        mkdir($tempDirectory, 0777, true);
    }

    if (is_file($securityPath)) {
        unlink($securityPath);
    }

    if (
        !InterfaceDB::tableExists('users')
        || !InterfaceDB::tableExists('user_totp')
        || !InterfaceDB::tableExists('user_logon_history')
        || !InterfaceDB::tableExists('user_account_audit')
    ) {
        $harness->skip('Required user-management tables are not available on the default InterfaceDB connection.');
    }

    $harness->check(UserManagementService::class, 'creates a managed user and supports OTP rotation data', static function () use ($harness, $securityPath): void {
        InterfaceDB::beginTransaction();

        try {
            $authService = new UserAuthenticationService($securityPath);
            $otpService = new OtpService('EEL Accounts');
            $service = new UserManagementService(
                $authService,
                new RoleAssignmentService(),
                $otpService,
                new QrCodeService(),
                new UserHistoryStore(),
                new UserSessionService()
            );

            $marker = 'user-mgmt-' . bin2hex(random_bytes(8));
            $actor = $authService->createUser('Actor ' . $marker, 'actor-' . $marker . '@example.test', 'Actor Password 1!');
            $actorId = (int)($actor['user_id'] ?? 0);
            promoteUserToAdmin($actorId);

            $created = $service->createUser($actorId, 'Managed User', 'managed-' . $marker . '@example.test', 'Managed Password 1!');
            $harness->assertTrue(!empty($created['success']));
            $managedUserId = (int)($created['user_id'] ?? 0);

            $service->beginOtpRotation($managedUserId);
            $managedDashboard = $service->dashboardData($managedUserId);
            $actorDashboard = $service->dashboardData($actorId);

            $harness->assertTrue(!empty($managedDashboard['otp_setup']['has_pending']));
            $harness->assertTrue(str_contains((string)$managedDashboard['otp_setup']['otpauth_uri'], 'otpauth://totp/'));
            $harness->assertTrue(str_contains((string)$managedDashboard['otp_setup']['qr_svg'], '<svg '));
            $harness->assertTrue(count((array)$actorDashboard['current_users']) >= 2);
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(UserManagementService::class, 'requires the real current password before self-updating account details', static function () use ($harness, $securityPath): void {
        InterfaceDB::beginTransaction();

        try {
            $authService = new UserAuthenticationService($securityPath);
            $service = new UserManagementService(
                $authService,
                new RoleAssignmentService(),
                new OtpService('EEL Accounts'),
                new QrCodeService(),
                new UserHistoryStore(),
                new UserSessionService()
            );

            $marker = 'self-update-' . bin2hex(random_bytes(8));
            $actor = $authService->createUser('Actor ' . $marker, 'actor-update-' . $marker . '@example.test', 'Actor Password 1!');
            $actorId = (int)($actor['user_id'] ?? 0);

            $failed = $service->updateCurrentUser(
                $actorId,
                'Updated Name',
                'updated-' . $marker . '@example.test',
                'wrong-password',
                ''
            );

            $harness->assertTrue(empty($failed['success']));
            $harness->assertSame('The current password entered is not correct.', (string)($failed['errors'][0] ?? ''));

            $successful = $service->updateCurrentUser(
                $actorId,
                'Updated Name',
                'updated-' . $marker . '@example.test',
                'Actor Password 1!',
                ''
            );

            $harness->assertTrue(!empty($successful['success']));
            $reloadedUser = $authService->userById($actorId);
            $harness->assertSame('Updated Name', (string)($reloadedUser['display_name'] ?? ''));
            $harness->assertSame('updated-' . $marker . '@example.test', (string)($reloadedUser['email_address'] ?? ''));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(UserManagementService::class, 'logs OTP rotation completion into account logon history', static function () use ($harness, $securityPath): void {
        InterfaceDB::beginTransaction();

        try {
            $authService = new UserAuthenticationService($securityPath);
            $otpService = new OtpService('EEL Accounts');
            $service = new UserManagementService(
                $authService,
                new RoleAssignmentService(),
                $otpService,
                new QrCodeService(),
                new UserHistoryStore(),
                new UserSessionService()
            );

            $marker = 'otp-rotation-log-' . bin2hex(random_bytes(8));
            $actor = $authService->createUser('Actor ' . $marker, 'actor-otp-' . $marker . '@example.test', 'Actor Password 1!');
            $actorId = (int)($actor['user_id'] ?? 0);

            $setup = $service->beginOtpRotation($actorId);
            $verificationService = new OtpVerificationService();
            $code = $verificationService->generateCodeForTimestep(
                6,
                'SHA1',
                (string)($setup['manual_secret'] ?? ''),
                $verificationService->currentTimestep(time(), 30)
            );

            $result = $service->completeOtpRotation($actorId, $code);
            $history = InterfaceDB::fetchAll(
                'SELECT event_type, reason
                 FROM user_logon_history
                 WHERE user_id = :user_id
                 ORDER BY id ASC',
                ['user_id' => $actorId]
            );

            $eventTypes = array_map(
                static fn(array $row): string => (string)($row['event_type'] ?? ''),
                $history
            );

            $harness->assertTrue(!empty($result['success']));
            $harness->assertTrue(in_array('otp_setup_completed', $eventTypes, true));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(UserManagementService::class, 'blocks disabling the account currently signed in', static function () use ($harness, $securityPath): void {
        InterfaceDB::beginTransaction();

        try {
            $authService = new UserAuthenticationService($securityPath);
            $service = new UserManagementService(
                $authService,
                new RoleAssignmentService(),
                new OtpService('EEL Accounts'),
                new QrCodeService(),
                new UserHistoryStore(),
                new UserSessionService()
            );

            $marker = 'self-disable-' . bin2hex(random_bytes(8));
            $actor = $authService->createUser('Actor ' . $marker, 'actor-disable-' . $marker . '@example.test', 'Actor Password 1!');
            $actorId = (int)($actor['user_id'] ?? 0);
            promoteUserToAdmin($actorId);

            $result = $service->setUserEnabled($actorId, $actorId, false);

            $harness->assertTrue(empty($result['success']));
            $harness->assertSame(
                'You cannot disable the account you are currently signed in with.',
                (string)($result['errors'][0] ?? '')
            );
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(UserManagementService::class, 'blocks changing the password of the account currently signed in via user management', static function () use ($harness, $securityPath): void {
        InterfaceDB::beginTransaction();

        try {
            $authService = new UserAuthenticationService($securityPath);
            $service = new UserManagementService(
                $authService,
                new RoleAssignmentService(),
                new OtpService('EEL Accounts'),
                new QrCodeService(),
                new UserHistoryStore(),
                new UserSessionService()
            );

            $marker = 'self-password-' . bin2hex(random_bytes(8));
            $actor = $authService->createUser('Actor ' . $marker, 'actor-password-' . $marker . '@example.test', 'Actor Password 1!');
            $actorId = (int)($actor['user_id'] ?? 0);
            promoteUserToAdmin($actorId);

            $result = $service->setPasswordForUser($actorId, $actorId, 'Changed Password 1!');

            $harness->assertTrue(empty($result['success']));
            $harness->assertSame(
                'Use your own account settings to change your password.',
                (string)($result['errors'][0] ?? '')
            );
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(UserManagementService::class, 'allows custom roles with current_users access to manage users', static function () use ($harness, $securityPath): void {
        InterfaceDB::beginTransaction();

        try {
            $authService = new UserAuthenticationService($securityPath);
            $service = new UserManagementService(
                $authService,
                new RoleAssignmentService(),
                new OtpService('EEL Accounts'),
                new QrCodeService(),
                new UserHistoryStore(),
                new UserSessionService()
            );

            $marker = 'role-manage-' . bin2hex(random_bytes(8));
            $admin = $authService->createUser('Admin ' . $marker, 'admin-' . $marker . '@example.test', 'Admin Password 1!');
            $adminId = (int)($admin['user_id'] ?? 0);
            promoteUserToAdmin($adminId);

            $manager = $authService->createUser('Manager ' . $marker, 'manager-' . $marker . '@example.test', 'Manager Password 1!');
            $managerId = (int)($manager['user_id'] ?? 0);

            $roleService = new RoleAssignmentService();
            $roleCreate = $roleService->createRole($adminId, 'Managers ' . $marker);
            $roleId = (int)($roleCreate['role_id'] ?? 0);
            $roleService->setCardAllowedForRole($adminId, $roleId, 'current_users', true);
            $roleService->assignRoleToUser($adminId, $managerId, $roleId);

            $harness->assertTrue($service->canManageUsers($managerId));

            $created = $service->createUser($managerId, 'Managed User', 'managed-role-' . $marker . '@example.test', 'Managed Password 1!');
            $harness->assertTrue(!empty($created['success']));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(UserManagementService::class, 'denies user management when the actor lacks current_users access', static function () use ($harness, $securityPath): void {
        InterfaceDB::beginTransaction();

        try {
            $authService = new UserAuthenticationService($securityPath);
            $service = new UserManagementService(
                $authService,
                new RoleAssignmentService(),
                new OtpService('EEL Accounts'),
                new QrCodeService(),
                new UserHistoryStore(),
                new UserSessionService()
            );

            $marker = 'role-deny-' . bin2hex(random_bytes(8));
            $actor = $authService->createUser('Actor ' . $marker, 'actor-deny-' . $marker . '@example.test', 'Actor Password 1!');
            $actorId = (int)($actor['user_id'] ?? 0);

            $harness->assertTrue(!$service->canManageUsers($actorId));

            $result = $service->createUser($actorId, 'Managed User', 'managed-deny-' . $marker . '@example.test', 'Managed Password 1!');
            $harness->assertTrue(empty($result['success']));
            $harness->assertSame('You do not have permission to manage users.', (string)($result['errors'][0] ?? ''));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});

function promoteUserToAdmin(int $userId): void
{
    InterfaceDB::prepareExecute(
        'UPDATE users
         SET role_id = :role_id
         WHERE id = :id',
        [
            'role_id' => RoleAssignmentService::ADMIN_ROLE_ID,
            'id' => $userId,
        ]
    );
}
