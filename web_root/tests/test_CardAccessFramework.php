<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(CardAccessFramework::class, static function (GeneratedServiceClassTestHarness $harness): void {
    if (!InterfaceDB::tableExists('roles') || !InterfaceDB::tableExists('role_card_permissions') || !InterfaceDB::tableExists('users')) {
        $harness->skip('Role tables are not available on the default InterfaceDB connection.');
    }

    $framework = new CardAccessFramework();
    $service = new RoleAssignmentService();

    $harness->check(CardAccessFramework::class, 'returns all cards for the synthetic admin role', static function () use ($harness, $framework): void {
        $cards = ['current_users', 'role_assignment', 'account_logon_history'];
        $harness->assertSame($cards, $framework->allowedCardsForRole(RoleAssignmentService::ADMIN_ROLE_ID, $cards));
    });

    $harness->check(CardAccessFramework::class, 'returns no cards for an unassigned role', static function () use ($harness, $framework): void {
        $allowed = $framework->allowedCardsForRole(0, ['current_users', 'role_assignment', 'add_user']);

        $harness->assertSame([], $allowed);
    });

    $harness->check(CardAccessFramework::class, 'filters cards by allowed rows for a real role', static function () use ($harness, $framework, $service): void {
        InterfaceDB::beginTransaction();

        try {
            $adminUserId = createCardAccessAdminTestUser();
            $marker = 'Role ' . bin2hex(random_bytes(6));
            $create = $service->createRole($adminUserId, $marker);
            $roleId = (int)($create['role_id'] ?? 0);
            $service->setCardAllowedForRole($adminUserId, $roleId, 'current_users', true);

            $allowed = $framework->allowedCardsForRole($roleId, ['current_users', 'role_assignment', 'add_user']);

            $harness->assertSame(['current_users', 'add_user'], $allowed);
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
        false,
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
