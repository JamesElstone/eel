<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once APP_CARDS . 'invited_users.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->check(_invited_usersCard::class, 'requests current users refresh when cancelling invitations', function () use ($harness): void {
    $html = (new _invited_usersCard())->render([
        'page' => [
            'csrf_token' => 'test-csrf',
            'page_cards' => ['invited_users'],
        ],
        'services' => [
            'invited_users_dashboard' => [
                'invites' => [
                    [
                        'id' => 123,
                        'user_id' => 456,
                        'display_name' => 'Invite Target',
                        'delivery_summary' => 'Email: Sent | SMS: Sent',
                        'status' => 'sent',
                    ],
                ],
            ],
        ],
    ]);

    $harness->assertTrue(str_contains($html, 'name="action" value="users-revoke-invite"'));
    $harness->assertTrue(str_contains($html, 'name="cards[]" value="current_users"'));
    $harness->assertTrue(str_contains($html, 'name="cards[]" value="invited_users"'));
});
