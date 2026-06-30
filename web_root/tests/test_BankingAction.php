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
$harness->run(BankingAction::class, static function (GeneratedServiceClassTestHarness $harness, BankingAction $action): void {
    $harness->check(BankingAction::class, 'preserves warning flash message arrays', static function () use ($harness, $action): void {
        $method = new ReflectionMethod(BankingAction::class, 'result');
        $method->setAccessible(true);

        $result = $method->invoke($action, true, [[
            'type' => 'warning',
            'message' => 'Transactions have been posted for this account, so the internal transfer marker was not changed.',
        ]], [], [], []);

        $flashMessages = $result instanceof ActionResultFramework ? $result->flashMessages() : [];

        $harness->assertSame('warning', (string)($flashMessages[0]['type'] ?? ''));
        $harness->assertSame(
            'Transactions have been posted for this account, so the internal transfer marker was not changed.',
            (string)($flashMessages[0]['message'] ?? '')
        );
    });
});
