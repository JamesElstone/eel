<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(RoleAssignmentService::class, static function (GeneratedServiceClassTestHarness $harness): void {
    if (!InterfaceDB::tableExists('roles') || !InterfaceDB::tableExists('role_card_permissions') || !InterfaceDB::tableExists('users')) {
        $harness->skip('Role tables are not available on the default InterfaceDB connection.');
    }

    $service = new RoleAssignmentService();
    $userAuthenticationService = new UserAuthenticationService();

    $harness->check(RoleAssignmentService::class, 'lists the synthetic admin role first', static function () use ($harness, $service): void {
        $roles = $service->listRolesForSelect();

        $harness->assertTrue($roles !== []);
        $harness->assertSame(RoleAssignmentService::ADMIN_ROLE_ID, (int)$roles[0]['id']);
        $harness->assertSame(RoleAssignmentService::ADMIN_ROLE_NAME, (string)$roles[0]['role_name']);
    });

    $harness->check(RoleAssignmentService::class, 'creates a new role and reports its identifier', static function () use ($harness, $service): void {
        InterfaceDB::beginTransaction();

        try {
            $adminUserId = createAdminRoleTestUser();
            $marker = 'Role ' . bin2hex(random_bytes(6));
            $result = $service->createRole($adminUserId, $marker);

            $harness->assertTrue($result['success'] === true);
            $harness->assertTrue((int)$result['role_id'] > 0);
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(RoleAssignmentService::class, 'stores and removes allowed cards for a real role', static function () use ($harness, $service): void {
        InterfaceDB::beginTransaction();

        try {
            $adminUserId = createAdminRoleTestUser();
            $marker = 'Role ' . bin2hex(random_bytes(6));
            $create = $service->createRole($adminUserId, $marker);
            $roleId = (int)($create['role_id'] ?? 0);

            $allow = $service->setCardAllowedForRole($adminUserId, $roleId, 'current_users', true);
            $harness->assertTrue($allow['success'] === true);

            $allowed = (int)InterfaceDB::fetchColumn(
                'SELECT COUNT(*)
                 FROM role_card_permissions
                 WHERE role_id = :role_id
                   AND card_key = :card_key',
                ['role_id' => $roleId, 'card_key' => 'current_users']
            );
            $harness->assertSame(1, $allowed);

            $deny = $service->setCardAllowedForRole($adminUserId, $roleId, 'current_users', false);
            $harness->assertTrue($deny['success'] === true);

            $remaining = (int)InterfaceDB::fetchColumn(
                'SELECT COUNT(*)
                 FROM role_card_permissions
                 WHERE role_id = :role_id
                   AND card_key = :card_key',
                ['role_id' => $roleId, 'card_key' => 'current_users']
            );
            $harness->assertSame(0, $remaining);
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(RoleAssignmentService::class, 'rejects non-admin role management attempts', static function () use ($harness, $service): void {
        InterfaceDB::beginTransaction();

        try {
            $userId = createRegularRoleTestUser();
            $create = $service->createRole($userId, 'Role ' . bin2hex(random_bytes(6)));

            $harness->assertTrue($create['success'] === false);
            $harness->assertSame(['Only administrators can manage roles.'], (array)$create['errors']);
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(RoleAssignmentService::class, 'blocks changing the role of the signed-in actor', static function () use ($harness, $service): void {
        InterfaceDB::beginTransaction();

        try {
            $adminUserId = createAdminRoleTestUser();
            $create = $service->createRole($adminUserId, 'Role ' . bin2hex(random_bytes(6)));
            $roleId = (int)($create['role_id'] ?? 0);

            $result = $service->assignRoleToUser($adminUserId, $adminUserId, $roleId);

            $harness->assertTrue($result['success'] === false);
            $harness->assertSame(
                ['You cannot change the role of the account you are currently signed in with.'],
                (array)$result['errors']
            );
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});

function createAdminRoleTestUser(): int
{
    $userId = createRegularRoleTestUser();

    InterfaceDB::prepareExecute(
        'UPDATE users
         SET role_id = :role_id
         WHERE id = :id',
        [
            'role_id' => RoleAssignmentService::ADMIN_ROLE_ID,
            'id' => $userId,
        ]
    );

    return $userId;
}

function createRegularRoleTestUser(): int
{
    $result = (new UserAuthenticationService())->createUser(
        'Role Test User',
        'role-test-' . bin2hex(random_bytes(6)) . '@example.com',
        'RoleTest!1234',
        false,
        true
    );

    if (empty($result['success']) || (int)($result['user_id'] ?? 0) <= 0) {
        throw new RuntimeException('Unable to create role-management test user.');
    }

    return (int)$result['user_id'];
}
