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
        $harness->assertSame(false, str_contains($html, '>Add Claimant</button>'));
        $harness->assertSame(false, str_contains($html, 'New Claimants'));
        $harness->assertTrue(str_contains($html, '<div class="card-toolbar">'));
        $harness->assertTrue(str_contains($html, 'name="expense_claimants_query"'));
        $harness->assertTrue(strpos($html, 'id="expense-claimants-query"') < strpos($html, '<table'));
        $harness->assertTrue(str_contains($html, 'name="_table_export_prepare" value="csv"'));
        $harness->assertTrue(str_contains($html, 'name="_table_export_prepare" value="xlsx"'));
        $harness->assertTrue(strpos($html, 'id="expense-claimants-query"') < strpos($html, 'name="_table_export_prepare" value="csv"'));
        $harness->assertTrue(str_contains($html, 'Expense Claimants 1-10 of 12'));
        $harness->assertTrue(str_contains($html, 'Claimant 10'));
        $harness->assertSame(false, str_contains($html, 'Claimant 11'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="deactivate_claimant"'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="activate_claimant"'));
        $harness->assertTrue(str_contains($html, 'class="button button-inline danger" type="submit" name="intent" value="delete_claimant"'));
        $harness->assertSame(1, substr_count($html, 'name="intent" value="delete_claimant"'));
        $harness->assertSame(10, substr_count($html, 'name="intent" value="filter_claims" data-page-card-switch-tab="Claims">Claims</button>'));
        $harness->assertTrue(
            strpos($html, 'name="intent" value="deactivate_claimant"') < strpos($html, 'name="intent" value="filter_claims" data-page-card-switch-tab="Claims">Claims</button>')
        );
        $harness->assertTrue(
            strpos($html, 'name="intent" value="activate_claimant"') < strpos($html, 'name="intent" value="filter_claims" data-page-card-switch-tab="Claims">Claims</button>', strpos($html, 'name="intent" value="activate_claimant"'))
        );
    });

    $harness->check(_expense_claimantsCard::class, 'keeps action column screen-only in exports', function () use ($harness, $instance): void {
        $table = $instance->tables(expenseClaimantsCardContext())[0] ?? null;
        $harness->assertTrue($table instanceof TableFramework);

        $csv = $table->exportCsv();

        $harness->assertTrue(str_starts_with($csv, "Claimant,Status\n"));
        $harness->assertTrue(str_contains($csv, '"Alex Example",Active'));
        $harness->assertTrue(str_contains($csv, '"Claimant 12",Inactive'));
        $harness->assertSame(false, str_contains($csv, 'Action'));
        $harness->assertSame(false, str_contains($csv, 'deactivate_claimant'));
        $harness->assertSame(false, str_contains($csv, 'activate_claimant'));
        $harness->assertSame(false, str_contains($csv, 'filter_claims'));
        $harness->assertSame(false, str_contains($csv, 'data-page-card-switch-tab'));
        $harness->assertSame(false, str_contains($csv, 'Claims'));
    });

    $harness->check(_expense_claimantsCard::class, 'filters claimant table by active status', function () use ($harness, $instance): void {
        $context = expenseClaimantsCardContext('inactive');
        $html = $instance->render($context);

        $harness->assertTrue(str_contains($html, 'name="expense_claimants_status"'));
        $harness->assertTrue(str_contains($html, '<option value="all">All</option>'));
        $harness->assertTrue(str_contains($html, '<option value="active">Active</option>'));
        $harness->assertTrue(str_contains($html, '<option value="inactive" selected>Inactive</option>'));
        $harness->assertTrue(str_contains($html, 'Inactive Claimant'));
        $harness->assertSame(false, str_contains($html, 'Alex Example'));
        $harness->assertTrue(str_contains($html, 'Expense Claimants 1-6 of 6'));

        $table = $instance->tables($context)[0] ?? null;
        $harness->assertTrue($table instanceof TableFramework);

        $csv = $table->exportCsv();
        $harness->assertTrue(str_contains($csv, '"Inactive Claimant",Inactive'));
        $harness->assertSame(false, str_contains($csv, '"Alex Example",Active'));
    });

    $harness->check(_expense_claimantsCard::class, 'searches claimant table by claimant name', function () use ($harness, $instance): void {
        $context = expenseClaimantsCardContext('all', 'Alex');
        $html = $instance->render($context);

        $harness->assertTrue(str_contains($html, 'value="Alex"'));
        $harness->assertTrue(str_contains($html, '>Clear</button>'));
        $harness->assertTrue(str_contains($html, 'Alex Example'));
        $harness->assertSame(false, str_contains($html, 'Inactive Claimant'));
        $harness->assertSame(false, str_contains($html, 'Claimant 3'));

        $table = $instance->tables($context)[0] ?? null;
        $harness->assertTrue($table instanceof TableFramework);

        $csv = $table->exportCsv();
        $harness->assertTrue(str_contains($csv, '"Alex Example",Active'));
        $harness->assertSame(false, str_contains($csv, '"Inactive Claimant",Inactive'));
        $harness->assertSame(false, str_contains($csv, 'Action'));
    });

    $harness->check(_expense_claimantsCard::class, 'combines claimant search with active status filter', function () use ($harness, $instance): void {
        $context = expenseClaimantsCardContext('active', 'Claimant 3');
        $html = $instance->render($context);

        $harness->assertTrue(str_contains($html, '>Claimant 3</td>'));
        $harness->assertSame(false, str_contains($html, '>Claimant 4</td>'));
        $harness->assertSame(false, str_contains($html, 'Inactive Claimant'));

        $inactiveContext = expenseClaimantsCardContext('inactive', 'Claimant 3');
        $inactiveHtml = $instance->render($inactiveContext);
        $harness->assertSame(false, str_contains($inactiveHtml, '>Claimant 3</td>'));
    });
});

function expenseClaimantsCardContext(string $statusFilter = 'all', string $query = ''): array
{
    return [
        'page' => [
            'page_id' => 'expenses',
            'page_cards' => ['expense_claimants'],
            'expense_claimants_page' => 1,
        ],
        'expense_claimants' => [
            'status_filter' => $statusFilter,
            'query' => $query,
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
            'claim_count' => 1,
        ],
        [
            'id' => 2,
            'company_id' => 7,
            'claimant_name' => 'Inactive Claimant',
            'is_active' => 0,
            'claim_count' => 0,
        ],
    ];

    foreach (range(3, 12) as $id) {
        $rows[] = [
            'id' => $id,
            'company_id' => 7,
            'claimant_name' => 'Claimant ' . $id,
            'is_active' => $id % 2,
            'claim_count' => 1,
        ];
    }

    return $rows;
}
