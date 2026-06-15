<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once APP_CARDS . 'signup_token_lockouts.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->check(_signup_token_lockoutsCard::class, 'renders signup token block reset forms by IP', function () use ($harness): void {
    $html = (new _signup_token_lockoutsCard())->render([
        'page' => [
            'csrf_token' => 'test-csrf',
            'page_cards' => ['signup_token_lockouts'],
            'page_id' => 'logs',
        ],
        'services' => [
            'signup_token_lockouts' => [
                [
                    'client_ip' => '203.0.113.12',
                    'failed_attempts' => 5,
                    'window_started_at' => '2026-06-15 10:00:00',
                    'last_failed_at' => '2026-06-15 10:01:00',
                    'block_expires_at' => '2026-06-15 10:16:00',
                ],
            ],
        ],
    ]);

    $harness->assertTrue(str_contains($html, 'Client IP'));
    $harness->assertTrue(str_contains($html, '203.0.113.12'));
    $harness->assertTrue(str_contains($html, 'name="action" value="logs-reset-signup-token-lockout"'));
    $harness->assertTrue(str_contains($html, 'name="client_ip" value="203.0.113.12"'));
});

$harness->check(_signup_token_lockoutsCard::class, 'suppresses missing lockout service errors', function () use ($harness): void {
    $html = (new _signup_token_lockoutsCard())->handleError('signup_token_lockouts', [
        'type' => 'error',
        'message' => 'No records available.',
    ], []);

    $harness->assertSame('', $html);
});
