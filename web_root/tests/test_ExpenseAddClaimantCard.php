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

$harness->run(_expense_add_claimantCard::class, function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof _expense_add_claimantCard) {
        $harness->skip('Expense add claimant card did not instantiate.');
    }

    $harness->check(_expense_add_claimantCard::class, 'renders add claimant form', function () use ($harness, $instance): void {
        $html = $instance->render(expenseAddClaimantCardContext(7));

        $harness->assertTrue(in_array('expense.claimants', $instance->invalidationFacts(), true));
        $harness->assertSame(false, str_contains($html, 'panel-soft'));
        $harness->assertSame(false, str_contains($html, 'New Claimants'));
        $harness->assertTrue(str_contains($html, 'class="expense-claimant-add-form"'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="add_claimant"'));
        $harness->assertTrue(str_contains($html, 'name="company_id" value="7"'));
        $harness->assertTrue(str_contains($html, '>Add Claimant</button>'));
        $harness->assertSame(false, str_contains($html, 'Select or add a company before configuring expense claimants.'));
    });

    $harness->check(_expense_add_claimantCard::class, 'disables add claimant form without company', function () use ($harness, $instance): void {
        $html = $instance->render(expenseAddClaimantCardContext(0));

        $harness->assertTrue(str_contains($html, 'Select or add a company before configuring expense claimants.'));
        $harness->assertTrue(str_contains($html, 'name="claimant_name" type="text" placeholder="Claimant\'s Name" disabled'));
        $harness->assertTrue(str_contains($html, '<button class="button primary" type="submit" disabled>Add Claimant</button>'));
    });
});

function expenseAddClaimantCardContext(int $companyId): array
{
    return [
        'page' => [
            'page_id' => 'expenses',
            'page_cards' => ['expense_add_claimant'],
        ],
        'company' => [
            'id' => $companyId,
        ],
    ];
}
