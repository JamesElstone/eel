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
$harness->check(CardBaseFramework::class, 'loads as an abstract base class', function () use ($harness): void {
    $reflection = new ReflectionClass(CardBaseFramework::class);
    $harness->assertTrue($reflection->isAbstract());
});

$harness->check(CardBaseFramework::class, 'array pagination controls do not require shared card actions', function () use ($harness): void {
    $card = new _user_logon_history_logCard();
    $rows = [];

    for ($i = 1; $i <= 6; $i++) {
        $rows[] = [
            'occurred_at' => '2026-05-10 10:0' . $i . ':00',
            'user_display_name' => 'Test User',
            'attempted_email_address' => '',
            'event_type' => 'login_success',
            'success' => 1,
            'ip_address' => '127.0.0.1',
            'browser_label' => 'Test Browser',
            'user_agent' => '',
            'reason' => '',
        ];
    }

    $html = $card->render([
        'page' => [
            'page_id' => 'users',
            'page_cards' => ['user_logon_history_log'],
            'user_logon_history_log_page' => 1,
        ],
        'services' => [
            'logon_rows' => $rows,
            'users' => [],
        ],
        'user_logon_history_log' => [
            'selected_user_id' => 0,
        ],
    ]);

    $harness->assertSame(false, str_contains($html, 'name="card_action" value="UserLogonHistory"'));
    $harness->assertTrue(str_contains($html, 'name="_pagination" value="1"'));
    $harness->assertTrue(str_contains($html, 'name="user_logon_history_log_page" value="2"'));
});
