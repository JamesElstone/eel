<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once APP_CARDS . 'restore_deleted_user.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->check(_restore_deleted_userCard::class, 'renders a hidden disabled placeholder before restorable users', function () use ($harness): void {
    $html = (new _restore_deleted_userCard())->render([
        'page' => [
            'csrf_token' => 'test-csrf',
            'page_cards' => ['current_users', 'invited_users', 'restore_deleted_user'],
        ],
        'services' => [
            'restore_deleted_users_dashboard' => [
                'users' => [
                    [
                        'id' => 42,
                        'option_label' => 'Archived User (email + mobile)',
                    ],
                ],
            ],
        ],
    ]);

    $placeholder = '<option value="" disabled selected hidden>Select User to restore</option>';
    $userOption = '<option value="42">Archived User (email + mobile)</option>';

    $harness->assertTrue(str_contains($html, 'name="action" value="users-restore-deleted-user"'));
    $harness->assertTrue(str_contains($html, 'name="target_user_id" required'));
    $harness->assertTrue(str_contains($html, $placeholder));
    $harness->assertTrue(str_contains($html, $userOption));
    $harness->assertTrue(strpos($html, $placeholder) < strpos($html, $userOption));
});

$harness->check(_restore_deleted_userCard::class, 'renders friendly empty state when no archived users can be restored', function () use ($harness): void {
    $html = (new _restore_deleted_userCard())->render([
        'page' => [
            'csrf_token' => 'test-csrf',
            'page_cards' => ['restore_deleted_user'],
        ],
        'services' => [
            'restore_deleted_users_dashboard' => [
                'users' => [],
            ],
        ],
    ]);

    $harness->assertTrue(str_contains($html, 'No deleted users are ready to restore.'));
    $harness->assertTrue(!str_contains($html, '<select'));
});

$harness->check(_users::class, 'registers restore action and invalidates affected user cards', function () use ($harness): void {
    $page = new _users();
    $cards = $page->cards();
    $layout = $page->cardLayout();
    $source = (string)file_get_contents(APP_PAGES . 'users.php');

    $harness->assertSame('restore_deleted_user', (string)end($cards));
    $harness->assertTrue(in_array('restore_deleted_user', (array)($layout[1]['cards'] ?? []), true));
    $harness->assertTrue(str_contains($source, "'users-restore-deleted-user' =>"));
    $harness->assertTrue(str_contains($source, 'restoreArchivedUserAndSendInvites('));
    $harness->assertTrue(str_contains($source, "['current.users', 'invited.users', 'restore.deleted.user']"));
});
