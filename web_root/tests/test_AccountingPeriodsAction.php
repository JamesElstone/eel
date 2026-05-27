<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'PageServiceTestFactory.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->run(AccountingPeriodsAction::class, function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof AccountingPeriodsAction) {
        throw new RuntimeException('Unexpected AccountingPeriodsAction instance.');
    }

    $harness->check('AccountingPeriodsAction', 'implements the action interface', function () use ($harness, $instance): void {
        $harness->assertSame(true, $instance instanceof ActionInterfaceFramework);
    });

    $harness->check('AccountingPeriodsAction', 'create_suggested_periods requires a selected company', function () use ($harness, $instance): void {
        $request = new RequestFramework(
            [],
            ['card_action' => 'AccountingPeriods', 'intent' => 'create_suggested_periods'],
            ['REQUEST_METHOD' => 'POST', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
            [],
            [],
            null
        );

        $result = $instance->handle($request, createTestPageServiceFramework());

        $harness->assertSame(false, $result->isSuccess());
        $harness->assertSame('Select a company before creating suggested accounting periods.', (string)($result->flashMessages()[0]['message'] ?? ''));
    });

    $harness->check('AccountingPeriodsAction', 'create_required_periods_for_upload requires a selected company', function () use ($harness, $instance): void {
        $request = new RequestFramework(
            [],
            ['card_action' => 'AccountingPeriods', 'intent' => 'create_required_periods_for_upload', 'required_period_end' => '2025-09-30'],
            ['REQUEST_METHOD' => 'POST', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
            [],
            [],
            null
        );

        $result = $instance->handle($request, createTestPageServiceFramework());

        $harness->assertSame(false, $result->isSuccess());
        $harness->assertSame('Select a company before creating required accounting periods.', (string)($result->flashMessages()[0]['message'] ?? ''));
    });
});
