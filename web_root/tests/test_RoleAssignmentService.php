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
$harness->run(RoleAssignmentService::class);

$roleAssignmentTempDirectory = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp';
if (!is_dir($roleAssignmentTempDirectory)) {
    mkdir($roleAssignmentTempDirectory, 0777, true);
}

$withTemporaryRoleUsers = function (callable $callback) use ($harness, $roleAssignmentTempDirectory): void {
    if (
        !InterfaceDB::tableExists('users')
        || !InterfaceDB::tableExists('roles')
        || !InterfaceDB::tableExists('role_card_permissions')
    ) {
        $harness->skip('users, roles or role_card_permissions table is not available on the default InterfaceDB connection.');
    }

    InterfaceDB::beginTransaction();
    $securityPath = $roleAssignmentTempDirectory . DIRECTORY_SEPARATOR . 'role-assignment-' . bin2hex(random_bytes(8)) . '.keys';

    try {
        $authService = new UserAuthenticationService($securityPath, [
            'memory_cost' => 8192,
            'time_cost' => 1,
            'threads' => 1,
        ]);
        $marker = bin2hex(random_bytes(4));

        $admin = $authService->createUser('Role Admin', 'role-admin-' . $marker . '@example.test', 'Strong Password 1!');
        $target = $authService->createUser('Role Target', 'role-target-' . $marker . '@example.test', 'Strong Password 1!');
        $ordinary = $authService->createUser('Role Ordinary', 'role-ordinary-' . $marker . '@example.test', 'Strong Password 1!');

        $adminId = (int)($admin['user_id'] ?? 0);
        $targetId = (int)($target['user_id'] ?? 0);
        $ordinaryId = (int)($ordinary['user_id'] ?? 0);

        InterfaceDB::prepareExecute('INSERT INTO roles (role_name) VALUES (:role_name)', ['role_name' => 'Role Users ' . $marker]);
        $standardRoleId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM roles WHERE role_name = :role_name ORDER BY id DESC LIMIT 1',
            ['role_name' => 'Role Users ' . $marker]
        );

        InterfaceDB::prepareExecute('UPDATE users SET role_id = :role_id WHERE id = :id', ['role_id' => RoleAssignmentService::ADMIN_ROLE_ID, 'id' => $adminId]);
        InterfaceDB::prepareExecute('UPDATE users SET role_id = :role_id WHERE id IN (:target_id, :ordinary_id)', ['role_id' => $standardRoleId, 'target_id' => $targetId, 'ordinary_id' => $ordinaryId]);
        UserAuthenticationService::forgetUserByIdCache($adminId);
        UserAuthenticationService::forgetUserByIdCache($targetId);
        UserAuthenticationService::forgetUserByIdCache($ordinaryId);

        $service = new RoleAssignmentService(userAuthenticationService: $authService);

        $callback($service, $adminId, $targetId, $ordinaryId);
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
        if (is_file($securityPath)) {
            unlink($securityPath);
        }
    }
};

$harness->check(RoleAssignmentService::class, 'detects admin users and exposes admin role options', function () use ($harness, $withTemporaryRoleUsers): void {
    $withTemporaryRoleUsers(function (RoleAssignmentService $service, int $adminId, int $targetId) use ($harness): void {
        $roles = $service->listRolesForSelect();

        $harness->assertTrue($service->isAdminUser($adminId));
        $harness->assertTrue(!$service->isAdminUser($targetId));
        $harness->assertSame(RoleAssignmentService::ADMIN_ROLE_ID, (int)($roles[0]['id'] ?? 0));
        $harness->assertSame(RoleAssignmentService::ADMIN_ROLE_NAME, (string)($roles[0]['role_name'] ?? ''));
        $harness->assertTrue((int)($roles[0]['allowed_card_count'] ?? 0) > 0);
    });
});

$harness->check(RoleAssignmentService::class, 'assigns valid roles and rejects invalid actors or targets', function () use ($harness, $withTemporaryRoleUsers): void {
    $withTemporaryRoleUsers(function (RoleAssignmentService $service, int $adminId, int $targetId, int $ordinaryId) use ($harness): void {
        $role = $service->createRole($adminId, 'Support ' . bin2hex(random_bytes(4)));
        $roleId = (int)($role['role_id'] ?? 0);

        $blocked = $service->assignRoleToUser($ordinaryId, $targetId, $roleId);
        $missingTarget = $service->assignRoleToUser($adminId, 99999999, $roleId);
        $missingRole = $service->assignRoleToUser($adminId, $targetId, 99999999);
        $assigned = $service->assignRoleToUser($adminId, $targetId, $roleId);

        $harness->assertTrue(!empty($role['success']));
        $harness->assertTrue(empty($blocked['success']));
        $harness->assertTrue(empty($missingTarget['success']));
        $harness->assertTrue(empty($missingRole['success']));
        $harness->assertTrue(!empty($assigned['success']));
        $harness->assertSame($roleId, (int)InterfaceDB::fetchColumn('SELECT role_id FROM users WHERE id = :id LIMIT 1', ['id' => $targetId]));
    });
});

$harness->check(RoleAssignmentService::class, 'protects built-in admin role permissions', function () use ($harness, $withTemporaryRoleUsers): void {
    $withTemporaryRoleUsers(function (RoleAssignmentService $service, int $adminId): void {
        $harness = new GeneratedServiceClassTestHarness();

        $createAdmin = $service->createRole($adminId, 'Admin');
        $changeAdminCard = $service->setCardAllowedForRole($adminId, RoleAssignmentService::ADMIN_ROLE_ID, 'current_users', false);
        $matrix = $service->permissionMatrixForRole(RoleAssignmentService::ADMIN_ROLE_ID);

        $harness->assertTrue(empty($createAdmin['success']));
        $harness->assertTrue(empty($changeAdminCard['success']));
        $harness->assertTrue($matrix !== []);
        $harness->assertTrue((bool)($matrix[0]['is_forced'] ?? false));
    });
});
