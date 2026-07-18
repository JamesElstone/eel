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
$harness->run(AccountCompletionService::class);

$completionTempDirectory = test_tmp_directory();
if (!is_dir($completionTempDirectory)) {
    mkdir($completionTempDirectory, 0777, true);
}

$withCompletionInvite = function (callable $callback) use ($harness, $completionTempDirectory): void {
    if (!InterfaceDB::tableExists('users') || !InterfaceDB::tableExists('user_account_invites')) {
        $harness->skip('invitation tables are not available.');
    }

    InterfaceDB::beginTransaction();
    $securityPath = $completionTempDirectory . DIRECTORY_SEPARATOR . 'completion-' . bin2hex(random_bytes(8)) . '.keys';

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
                'display_name' => 'Test User',
                'email_address' => 'pending-' . $marker . '@example.test',
                'mobile_number' => '+447700900123',
                'account_status' => 'pending_invitation',
                'role_id' => RoleAssignmentService::ADMIN_ROLE_ID,
            ]
        );
        $userId = (int)InterfaceDB::fetchColumn('SELECT COALESCE(MAX(id), 0) FROM users');
        UserAuthenticationService::forgetUserByIdCache($userId);
        $authService = new UserAuthenticationService($securityPath, [
            'memory_cost' => 8192,
            'time_cost' => 1,
            'threads' => 1,
        ]);
        $inviteService = new AccountInviteService($authService);
        $invite = $inviteService->createInviteLink(0, $userId, 'email', 'https://example.test');
        preg_match('/token=([^&]+)/', (string)$invite['link'], $matches);

        $callback($userId, rawurldecode((string)($matches[1] ?? '')), $authService, $inviteService);
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
        if (is_file($securityPath)) {
            unlink($securityPath);
        }
    }
};

$harness->check(AccountCompletionService::class, 'requires token-linked contact before completing account', function () use ($harness, $withCompletionInvite): void {
    $withCompletionInvite(function (int $userId, string $token, UserAuthenticationService $authService, AccountInviteService $inviteService) use ($harness): void {
        $session = new AccountCompletionSessionService();
        $service = new AccountCompletionService($inviteService, $authService);

        $harness->assertTrue(!empty($service->beginFromToken($token, $session)['success']));
        $failed = $service->verifyIdentity($session, '', '+44', '07123 456789');
        $harness->assertTrue(empty($failed['success']));

        $passed = $service->verifyIdentity($session, '', '+44', '07700 900123');
        $harness->assertTrue(!empty($passed['success']));

        $completed = $service->completeAccount(
            $session,
            'Test User',
            'completed-' . bin2hex(random_bytes(4)) . '@example.test',
            '+44',
            '07700 900123',
            'New Strong Password 1!',
            'New Strong Password 1!'
        );
        $harness->assertTrue(!empty($completed['success']));

        $user = $authService->userById($userId);
        $harness->assertSame('active', (string)($user['account_status'] ?? ''));
        $harness->assertSame(1, (int)($user['is_active'] ?? 0));
        $harness->assertTrue(is_array($authService->authenticateByEmailAddress((string)$user['email_address'], 'New Strong Password 1!')));
    });
});

$harness->check(AccountCompletionService::class, 'does not authenticate pending users through normal login', function () use ($harness, $withCompletionInvite): void {
    $withCompletionInvite(function (int $userId, string $token, UserAuthenticationService $authService): void {
        $user = $authService->userById($userId);

        (new GeneratedServiceClassTestHarness())->assertTrue($authService->authenticateByEmailAddress((string)$user['email_address'], 'anything') === false);
    });
});
