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
$harness->run(CardAccessFramework::class, static function (GeneratedServiceClassTestHarness $harness): void {
    if (!InterfaceDB::tableExists('roles') || !InterfaceDB::tableExists('role_card_permissions') || !InterfaceDB::tableExists('users')) {
        $harness->skip('Role tables are not available on the default InterfaceDB connection.');
    }

    $framework = new CardAccessFramework();
    $service = new RoleAssignmentService();

    $harness->check(CardAccessFramework::class, 'returns all cards for the synthetic admin role', static function () use ($harness, $framework): void {
        $cards = ['dashboard_notes', 'role_assignment', 'user_logon_history_log'];
        $harness->assertSame($cards, $framework->allowedCardsForRole(RoleAssignmentService::ADMIN_ROLE_ID, $cards));
    });

    $harness->check(CardAccessFramework::class, 'returns no cards for an unassigned role', static function () use ($harness, $framework): void {
        $allowed = $framework->allowedCardsForRole(0, ['dashboard_notes', 'role_assignment']);

        $harness->assertSame([], $allowed);
    });

    $harness->check(CardAccessFramework::class, 'filters cards by allowed rows for a real role', static function () use ($harness, $framework, $service): void {
        InterfaceDB::beginTransaction();

        try {
            $adminUserId = createCardAccessAdminTestUser();
            $marker = 'Role ' . bin2hex(random_bytes(6));
            $create = $service->createRole($adminUserId, $marker);
            $roleId = (int)($create['role_id'] ?? 0);
            $service->setCardAllowedForRole($adminUserId, $roleId, 'role_assignment', true);

            $allowed = $framework->allowedCardsForRole($roleId, ['dashboard_notes', 'role_assignment', 'user_logon_history_log']);

            $harness->assertSame(['role_assignment'], $allowed);
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(CardAccessFramework::class, 'expands user-management card access to related management cards', static function () use ($harness, $framework, $service): void {
        InterfaceDB::beginTransaction();

        try {
            $adminUserId = createCardAccessAdminTestUser();
            $marker = 'Role ' . bin2hex(random_bytes(6));
            $create = $service->createRole($adminUserId, $marker);
            $roleId = (int)($create['role_id'] ?? 0);
            $service->setCardAllowedForRole($adminUserId, $roleId, 'current_users', true);

            $allowed = $framework->allowedCardsForRole($roleId, ['current_users', 'add_user', 'user_login_lockouts', 'invited_users', 'role_assignment']);

            $harness->assertSame(['current_users', 'add_user', 'user_login_lockouts', 'invited_users'], $allowed);
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});

function createCardAccessAdminTestUser(): int
{
    $result = (new UserAuthenticationService())->createUser(
        'Card Access Admin',
        'card-access-' . bin2hex(random_bytes(6)) . '@example.com',
        'CardAccess!1234',
        true
    );

    if (empty($result['success']) || (int)($result['user_id'] ?? 0) <= 0) {
        throw new RuntimeException('Unable to create card-access admin test user.');
    }

    $userId = (int)$result['user_id'];

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
