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

$harness->run(NominalsAction::class, function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof NominalsAction) {
        throw new RuntimeException('Unexpected NominalsAction instance.');
    }

    $harness->check('NominalsAction', 'implements the action interface', function () use ($harness, $instance): void {
        $harness->assertSame(true, $instance instanceof ActionInterfaceFramework);
    });

    $harness->check('NominalsAction', 'saved nominal defaults message lists each saved default', function () use ($harness, $instance): void {
        $method = new ReflectionMethod(NominalsAction::class, 'savedNominalsMessageHtml');
        $method->setAccessible(true);

        $message = $method->invoke($instance, [
            'default_bank_nominal_id' => '10',
            'default_expense_nominal_id' => '20',
            'director_loan_nominal_id' => '30',
            'vat_nominal_id' => '',
            'uncategorised_nominal_id' => '50',
        ], [
            ['id' => 10, 'code' => '1200', 'name' => 'Bank'],
            ['id' => 20, 'code' => '5000', 'name' => 'Expenses'],
            ['id' => 30, 'code' => '2100', 'name' => 'Director Loan'],
            ['id' => 50, 'code' => '9999', 'name' => 'Uncategorised'],
        ]);

        $harness->assertSame(true, str_contains($message, 'Default bank: 1200 - Bank'));
        $harness->assertSame(true, str_contains($message, '<br>Saved:<br>'));
        $harness->assertSame(true, str_contains($message, 'Default expense: 5000 - Expenses'));
        $harness->assertSame(true, str_contains($message, 'Director loan: 2100 - Director Loan'));
        $harness->assertSame(true, str_contains($message, 'VAT control: Unassigned'));
        $harness->assertSame(true, str_contains($message, 'Fallback uncategorised: 9999 - Uncategorised'));
    });

    $harness->check('NominalsAction', 'save_nominals requires a selected company', function () use ($harness, $instance): void {
        $request = new RequestFramework(
            [],
            ['card_action' => 'Nominals', 'intent' => 'save_nominals'],
            ['REQUEST_METHOD' => 'POST', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
            [],
            [],
            null
        );

        $result = $instance->handle($request, createTestPageServiceFramework());

        $harness->assertSame(false, $result->isSuccess());
        $harness->assertSame('Select a company before saving nominal defaults.', (string)($result->flashMessages()[0]['message'] ?? ''));
    });

    $harness->check('NominalsAction', 'apply_nominal_suggestions requires a selected company', function () use ($harness, $instance): void {
        $request = new RequestFramework(
            [],
            ['card_action' => 'Nominals', 'intent' => 'apply_nominal_suggestions'],
            ['REQUEST_METHOD' => 'POST', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
            [],
            [],
            null
        );

        $result = $instance->handle($request, createTestPageServiceFramework());

        $harness->assertSame(false, $result->isSuccess());
        $harness->assertSame('Select a company before applying suggested nominal defaults.', (string)($result->flashMessages()[0]['message'] ?? ''));
    });

    $harness->check('NominalsAction', 'returns a validation error when no complete suggestion set is available', function () use ($harness, $instance): void {
        $sessionAuthenticationService = new SessionAuthenticationService();
        $sessionAuthenticationService->completeAuthentication(1, 'test-device');
        (new AccountingContextService())->setPageContext(1, 'Test Company', '00000000', 0);

        $request = new RequestFramework(
            [],
            ['card_action' => 'Nominals', 'intent' => 'apply_nominal_suggestions'],
            ['REQUEST_METHOD' => 'POST', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
            [],
            [],
            null
        );

        $result = $instance->handle($request, createTestPageServiceFramework());

        $harness->assertSame(false, $result->isSuccess());
        $harness->assertSame('No complete nominal suggestion set is currently available.', (string)($result->flashMessages()[0]['message'] ?? ''));
    });
});
