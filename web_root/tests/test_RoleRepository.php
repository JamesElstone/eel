<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(RoleRepository::class, static function (GeneratedServiceClassTestHarness $harness, RoleRepository $repository): void {
    if (!InterfaceDB::tableExists('roles') || !InterfaceDB::tableExists('role_card_permissions') || !InterfaceDB::tableExists('users')) {
        $harness->skip('Role repository tables are not available on the default InterfaceDB connection.');
    }

    $harness->check(RoleRepository::class, 'lists roles as an array', static function () use ($harness, $repository): void {
        $roles = $repository->listRoles();

        $harness->assertTrue(is_array($roles));
    });

    $harness->check(RoleRepository::class, 'creates and reloads a role by id', static function () use ($harness, $repository): void {
        InterfaceDB::beginTransaction();

        try {
            $roleName = 'Repo Role ' . bin2hex(random_bytes(6));
            $roleId = $repository->createRole($roleName);

            $harness->assertTrue($roleId > 0);
            $harness->assertTrue($repository->roleExistsByName($roleName));

            $role = $repository->roleById($roleId);
            $harness->assertSame($roleName, (string)($role['role_name'] ?? ''));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(RoleRepository::class, 'stores and removes allowed card keys for a role', static function () use ($harness, $repository): void {
        InterfaceDB::beginTransaction();

        try {
            $roleId = $repository->createRole('Repo Cards ' . bin2hex(random_bytes(6)));

            $repository->allowCardForRole($roleId, 'current_users');
            $harness->assertTrue($repository->isCardAllowedForRole($roleId, 'current_users'));
            $harness->assertSame(['current_users'], $repository->allowedCardKeysForRole($roleId));

            $repository->denyCardForRole($roleId, 'current_users');
            $harness->assertTrue(!$repository->isCardAllowedForRole($roleId, 'current_users'));
            $harness->assertSame([], $repository->allowedCardKeysForRole($roleId));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(RoleRepository::class, 'assigns a role to a user', static function () use ($harness, $repository): void {
        InterfaceDB::beginTransaction();

        try {
            $authService = new UserAuthenticationService();
            $created = $authService->createUser(
                'Role Repo User',
                'role-repo-' . bin2hex(random_bytes(6)) . '@example.test',
                'RoleRepo Password 1!',
                false,
                true
            );
            $userId = (int)($created['user_id'] ?? 0);
            $roleId = $repository->createRole('Repo Assigned ' . bin2hex(random_bytes(6)));

            $repository->assignRoleToUser($userId, $roleId);

            $harness->assertSame($roleId, $repository->userRoleId($userId));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(RoleRepository::class, 'returns zero for users with no assigned role', static function () use ($harness, $repository): void {
        InterfaceDB::beginTransaction();

        try {
            $authService = new UserAuthenticationService();
            $created = $authService->createUser(
                'Null Role User',
                'null-role-' . bin2hex(random_bytes(6)) . '@example.test',
                'NullRole Password 1!',
                false,
                true
            );
            $userId = (int)($created['user_id'] ?? 0);

            InterfaceDB::prepareExecute(
                'UPDATE users
                 SET role_id = NULL
                 WHERE id = :id',
                ['id' => $userId]
            );

            $harness->assertSame(0, $repository->userRoleId($userId));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});
