<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once APP_CARDS . 'current_users.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->check(_current_usersCard::class, 'shows cancelled invitation status for pending users with revoked invites', function () use ($harness): void {
    $card = new _current_usersCard();
    $statusLabel = new ReflectionMethod(_current_usersCard::class, 'statusLabel');
    $statusBadgeClass = new ReflectionMethod(_current_usersCard::class, 'statusBadgeClass');
    $statusLabel->setAccessible(true);
    $statusBadgeClass->setAccessible(true);

    $user = [
        'account_status' => 'pending_invitation',
        'is_active' => 0,
    ];
    $latestInvite = [
        'status' => 'revoked',
    ];

    $harness->assertSame('Invitation cancelled', $statusLabel->invoke($card, $user, $latestInvite));
    $harness->assertSame('danger', $statusBadgeClass->invoke($card, $user, $latestInvite));
});
