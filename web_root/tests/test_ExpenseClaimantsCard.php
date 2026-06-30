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

$harness->run(_expense_claimantsCard::class, function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof _expense_claimantsCard) {
        $harness->skip('Expense claimants card did not instantiate.');
    }

    $harness->check(_expense_claimantsCard::class, 'uses nested company context for expense service', function () use ($harness, $instance): void {
        $services = $instance->services();

        $harness->assertSame(':company.id', (string)($services[0]['params']['companyId'] ?? ''));
        $harness->assertSame(':expense_filters', (string)($services[0]['params']['filters'] ?? ''));
    });

    $harness->check(_expense_claimantsCard::class, 'renders paginated exportable claimant table', function () use ($harness, $instance): void {
        $context = expenseClaimantsCardContext();
        $html = $instance->render($context);

        $harness->assertTrue(str_contains($html, 'Alex Example'));
        $harness->assertTrue(str_contains($html, '>Add Claimant</button>'));
        $harness->assertTrue(str_contains($html, 'name="_table_export_prepare" value="csv"'));
        $harness->assertTrue(str_contains($html, 'name="_table_export_prepare" value="xlsx"'));
        $harness->assertTrue(str_contains($html, 'Expense claimants 1-10 of 12'));
        $harness->assertTrue(str_contains($html, 'Claimant 10'));
        $harness->assertSame(false, str_contains($html, 'Claimant 11'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="deactivate_claimant"'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="activate_claimant"'));
    });

    $harness->check(_expense_claimantsCard::class, 'keeps action column screen-only in exports', function () use ($harness, $instance): void {
        $table = $instance->tables(expenseClaimantsCardContext())[0] ?? null;
        $harness->assertTrue($table instanceof TableFramework);

        $csv = $table->exportCsv();

        $harness->assertTrue(str_starts_with($csv, "Claimant,Status\n"));
        $harness->assertTrue(str_contains($csv, 'Alex Example,Active'));
        $harness->assertTrue(str_contains($csv, 'Claimant 12,Inactive'));
        $harness->assertSame(false, str_contains($csv, 'Action'));
        $harness->assertSame(false, str_contains($csv, 'deactivate_claimant'));
        $harness->assertSame(false, str_contains($csv, 'activate_claimant'));
    });
});

function expenseClaimantsCardContext(): array
{
    return [
        'page' => [
            'page_id' => 'expenses',
            'page_cards' => ['expense_claimants'],
            'expense_claimants_page' => 1,
        ],
        'company' => [
            'id' => 7,
        ],
        'services' => [
            'expensesPageData' => [
                'claimants' => expenseClaimantsCardRows(),
            ],
        ],
    ];
}

function expenseClaimantsCardRows(): array
{
    $rows = [
        [
            'id' => 1,
            'company_id' => 7,
            'claimant_name' => 'Alex Example',
            'is_active' => 1,
        ],
        [
            'id' => 2,
            'company_id' => 7,
            'claimant_name' => 'Inactive Claimant',
            'is_active' => 0,
        ],
    ];

    foreach (range(3, 12) as $id) {
        $rows[] = [
            'id' => $id,
            'company_id' => 7,
            'claimant_name' => 'Claimant ' . $id,
            'is_active' => $id % 2,
        ];
    }

    return $rows;
}
