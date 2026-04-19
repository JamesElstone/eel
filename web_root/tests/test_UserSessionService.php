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
$harness->run(UserSessionService::class, static function (GeneratedServiceClassTestHarness $harness): void {
    if (!InterfaceDB::tableExists('users') || !InterfaceDB::tableExists('user_logon_history')) {
        $harness->skip('users or user_logon_history table is not available on the default InterfaceDB connection.');
    }

    $harness->check(UserSessionService::class, 'starts and validates a single active session for a user', static function () use ($harness): void {
        $service = new UserSessionService();
        InterfaceDB::beginTransaction();

        try {
            $marker = 'session-test-' . bin2hex(random_bytes(8));

            InterfaceDB::prepareExecute(
                'INSERT INTO users (
                    display_name,
                    email_address,
                    password_hash,
                    confirmed_director,
                    is_active
                ) VALUES (
                    :display_name,
                    :email_address,
                    :password_hash,
                    0,
                    1
                )',
                [
                    'display_name' => 'Session Test User ' . $marker,
                    'email_address' => 'session-' . $marker . '@example.test',
                    'password_hash' => 'hash-' . $marker,
                ]
            );

            $userId = (int)(InterfaceDB::fetchColumn(
                'SELECT id
                 FROM users
                 WHERE password_hash = :password_hash
                 ORDER BY id DESC
                 LIMIT 1',
                ['password_hash' => 'hash-' . $marker]
            ) ?: 0);

            $session = $service->startAuthenticatedSession($userId, 'device-a', 'session@example.test');
            $valid = $service->validateAuthenticatedSession($userId, (string)$session['session_token_hash'], 'device-a');

            $harness->assertTrue(!empty($valid['valid']));

            $replacement = $service->startAuthenticatedSession($userId, 'device-b', 'session@example.test');
            $invalid = $service->validateAuthenticatedSession($userId, (string)$session['session_token_hash'], 'device-a');

            $harness->assertTrue(empty($invalid['valid']));
            $harness->assertTrue(str_contains((string)(($invalid['logout_notice'] ?? [])['message'] ?? ''), 'logged out'));

            $service->clearAuthenticatedSession($userId, (string)$replacement['session_token_hash']);

            $storedHash = InterfaceDB::fetchColumn(
                'SELECT current_session_token_hash
                 FROM users
                 WHERE id = :id',
                ['id' => $userId]
            );

            $harness->assertSame(null, $storedHash);
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});
