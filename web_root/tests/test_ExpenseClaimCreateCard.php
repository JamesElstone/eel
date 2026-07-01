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

$harness->run(_expense_claim_createCard::class, function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof _expense_claim_createCard) {
        $harness->skip('Expense claim create card did not instantiate.');
    }

    $harness->check(_expense_claim_createCard::class, 'uses nested company context for expense service', function () use ($harness, $instance): void {
        $services = $instance->services();

        $harness->assertSame(':company.id', (string)($services[0]['params']['companyId'] ?? ''));
        $harness->assertSame(':expense_filters', (string)($services[0]['params']['filters'] ?? ''));
        $harness->assertTrue(in_array('expense.claimants', $instance->invalidationFacts(), true));
    });

    $harness->check(_expense_claim_createCard::class, 'renders claimant panel and create claim panel', function () use ($harness, $instance): void {
        $html = $instance->render(expenseClaimCreateCardContext(true));

        $harness->assertTrue(str_contains($html, 'class="expense-claims-stack"'));
        $harness->assertTrue(str_contains($html, '<div class="create-expense-claim">'));
        $harness->assertSame(false, str_contains($html, 'class="card-toolbar"'));
        $harness->assertSame(false, str_contains($html, 'class="actions-row"'));
        $harness->assertSame(2, substr_count($html, '<section class="panel-soft">'));
        $harness->assertTrue(str_contains($html, 'id="expense-create-claim-form"'));
        $harness->assertTrue(str_contains($html, 'id="expense-create-claimant"'));
        $harness->assertTrue(str_contains($html, '<label for="expense-create-claimant">Claimant</label>
                    <select class="select" id="expense-create-claimant" name="claimant_id" form="expense-create-claim-form"><option value="">Choose claimant...</option><option value="3">Alex Example</option></select>
                </div>
                <div class="mini-field">
                    <label for="expense-create-year">Year</label>'));
        $harness->assertTrue(str_contains($html, '<option value="3">Alex Example</option>'));
        $harness->assertTrue(str_contains($html, '<h3 class="card-title">Create Expense claim</h3>'));
        $harness->assertTrue(str_contains($html, 'id="expense-create-year"'));
        $harness->assertTrue(str_contains($html, 'id="expense-create-month"'));
        $harness->assertTrue(str_contains($html, 'data-show-card="expense_claim_editor"'));
        $harness->assertSame(false, str_contains($html, 'Create or open a monthly expense claim for an active claimant.'));
        $harness->assertSame(false, str_contains($html, 'Create Expense Claim is disabled because there are no active claimants.'));
    });

    $harness->check(_expense_claim_createCard::class, 'disables create claim controls without active claimants', function () use ($harness, $instance): void {
        $html = $instance->render(expenseClaimCreateCardContext(false));

        $harness->assertTrue(str_contains($html, 'Create Expense Claim is disabled because there are no active claimants.'));
        $harness->assertTrue(str_contains($html, 'id="expense-create-claimant" name="claimant_id" form="expense-create-claim-form" disabled'));
        $harness->assertTrue(str_contains($html, 'id="expense-create-year" name="claim_year" form="expense-create-claim-form" disabled'));
        $harness->assertTrue(str_contains($html, '<button class="button primary" type="submit" form="expense-create-claim-form" data-show-card="expense_claim_editor" disabled>Create Expense Claim</button>'));
    });
});

function expenseClaimCreateCardContext(bool $hasActiveClaimant): array
{
    return [
        'company' => [
            'id' => 7,
            'settings' => [
                'incorporation_date' => '2020-01-01',
            ],
        ],
        'expense_page_settings' => [
            'incorporation_date' => '2020-01-01',
        ],
        'services' => [
            'expensesPageData' => [
                'claimants' => $hasActiveClaimant ? [
                    [
                        'id' => 3,
                        'claimant_name' => 'Alex Example',
                        'is_active' => 1,
                    ],
                ] : [],
                'active_claimant_count' => $hasActiveClaimant ? 1 : 0,
            ],
        ],
    ];
}
