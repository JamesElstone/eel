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
$harness->run(UserHistoryStore::class, static function (GeneratedServiceClassTestHarness $harness): void {
    if (!InterfaceDB::tableExists('users') || !InterfaceDB::tableExists('user_logon_history') || !InterfaceDB::tableExists('user_account_audit')) {
        $harness->skip('users, user_logon_history, or user_account_audit table is not available on the default InterfaceDB connection.');
    }

    $harness->check(UserHistoryStore::class, 'records logon events and account audits for a temporary user', static function () use ($harness): void {
        $store = new UserHistoryStore();
        InterfaceDB::beginTransaction();

        try {
            $marker = 'history-test-' . bin2hex(random_bytes(8));

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
                    'display_name' => 'History Test User ' . $marker,
                    'email_address' => 'history-' . $marker . '@example.test',
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

            $store->recordLogonEvent($userId, 'history@example.test', 'login_succeeded', true, 'ok', 'token-hash', [
                'device_id' => 'device-1',
                'ip_address' => '127.0.0.1',
                'user_agent' => 'HistoryAgent/1.0',
                'browser_label' => 'History Browser',
            ]);
            $store->recordAccountAudit($userId, $userId, 'display_name_changed', 'changed', ['field' => 'display_name'], [
                'device_id' => 'device-1',
                'ip_address' => '127.0.0.1',
                'user_agent' => 'HistoryAgent/1.0',
            ]);
            $store->recordLogonEvent($userId, 'history@example.test', 'login_succeeded', true, 'ok-second', null, [
                'device_id' => 'device-1',
                'ip_address' => '127.0.0.1',
                'user_agent' => 'HistoryAgent/1.0',
                'browser_label' => 'History Browser',
            ]);
            $store->attachSessionTokenHashToLatestLogonEvent($userId, 'login_succeeded', 'new-token-hash');

            $logonRows = $store->fetchLogonHistoryForUser($userId, 10);
            $auditRows = $store->fetchAuditHistoryForUser($userId, 10);

            $harness->assertTrue($logonRows !== []);
            $harness->assertTrue($auditRows !== []);
            $harness->assertSame('login_succeeded', (string)$logonRows[0]['event_type']);
            $harness->assertSame('new-token-hash', (string)$logonRows[0]['session_token_hash']);
            $harness->assertSame('token-hash', (string)$logonRows[1]['session_token_hash']);
            $harness->assertSame('display_name_changed', (string)$auditRows[0]['action_type']);
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});
