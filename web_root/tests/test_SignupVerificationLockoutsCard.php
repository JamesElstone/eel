<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once APP_CARDS . 'signup_verification_lockouts.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->check(_signup_verification_lockoutsCard::class, 'renders signup verification block reset forms by scope', function () use ($harness): void {
    $scopeKey = hash('sha256', 'test-session');
    $html = (new _signup_verification_lockoutsCard())->render([
        'page' => [
            'csrf_token' => 'test-csrf',
            'page_cards' => ['signup_verification_lockouts'],
            'page_id' => 'logs',
        ],
        'services' => [
            'signup_verification_lockouts' => [
                [
                    'scope_type' => 'session',
                    'scope_key' => $scopeKey,
                    'scope_label' => 'session:' . substr($scopeKey, 0, 12),
                    'failed_attempts' => 5,
                    'window_started_at' => '2026-06-15 10:00:00',
                    'last_failed_at' => '2026-06-15 10:01:00',
                    'block_expires_at' => '2026-06-15 10:16:00',
                ],
            ],
        ],
    ]);

    $harness->assertTrue(str_contains($html, 'Session'));
    $harness->assertTrue(str_contains($html, 'session:' . substr($scopeKey, 0, 12)));
    $harness->assertTrue(str_contains($html, 'name="action" value="logs-reset-signup-verification-lockout"'));
    $harness->assertTrue(str_contains($html, 'name="scope_type" value="session"'));
    $harness->assertTrue(str_contains($html, 'name="scope_key" value="' . $scopeKey . '"'));
});

$harness->check(_signup_verification_lockoutsCard::class, 'suppresses missing verification lockout service errors', function () use ($harness): void {
    $html = (new _signup_verification_lockoutsCard())->handleError('signup_verification_lockouts', [
        'type' => 'error',
        'message' => 'No records available.',
    ], []);

    $harness->assertSame('', $html);
});
