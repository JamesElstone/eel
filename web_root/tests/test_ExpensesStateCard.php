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

$harness->run(_expenses_stateCard::class, function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof _expenses_stateCard) {
        $harness->skip('Expenses state card did not instantiate.');
    }

    $harness->check(_expenses_stateCard::class, 'renders empty claim heatmap until claimant is selected', function () use ($harness, $instance): void {
        $html = $instance->render(expensesStateCardContext([
            'heatmap_claimant_id' => 0,
            'heatmap_date' => '',
        ]));

        $harness->assertTrue(str_contains($html, '<select class="select" id="expense-claimant" name="claimant_id"><option value="" selected>Choose Claimant...</option><option value="4">Bob</option><option value="3">Alex Example</option></select>'));
        $harness->assertTrue(str_contains($html, 'class="calendar-heatmap"'));
        $harness->assertTrue(str_contains($html, 'calendar-heatmap-day-level-0'));
        $harness->assertSame(false, str_contains($html, 'EXP-2605-001'));
        $harness->assertSame(false, str_contains($html, '<button class="button" type="submit">Apply</button>'));
    });

    $harness->check(_expenses_stateCard::class, 'renders claim heatmap from page accounting period without period selector', function () use ($harness, $instance): void {
        $html = $instance->render(expensesStateCardContext());

        $harness->assertSame(false, str_contains($html, 'calendar-heatmap-range-select'));
        $harness->assertSame(false, str_contains($html, 'id="expense-claim-calendar-period-start"'));
        $harness->assertSame(false, str_contains($html, 'name="expense_heatmap_period_start"'));
        $harness->assertSame(false, str_contains($html, 'calendar-heatmap-year-select'));
        $harness->assertTrue(str_contains($html, 'name="expense_heatmap_date"'));
        $harness->assertTrue(str_contains($html, 'value="2026-05-01"'));
        $harness->assertSame(false, str_contains($html, 'Create or open a monthly expense claim for an active claimant.'));
        $harness->assertSame(false, str_contains($html, 'id="expense-create-claimant"'));
        $harness->assertTrue(str_contains($html, '<form method="get" action="?page=expenses" data-ajax="true" class="toolbar">
                <input type="hidden" name="page" value="expenses">
                <input type="hidden" name="card_action" value="Expense">
                <input type="hidden" name="company_id" value="7">
                <input type="hidden" name="intent" value="filter_claims">
                <input type="hidden" name="expense_query" value="">
                <input type="hidden" name="expense_status" value="all">
                <label for="expense-claimant">Claimant</label>
                <select class="select" id="expense-claimant" name="claimant_id"><option value="">Choose Claimant...</option><option value="4">Bob</option><option value="3" selected>Alex Example</option></select>
            </form>'));
        $harness->assertTrue(str_contains($html, '<form method="post" data-ajax="true" class="toolbar">
                <input type="hidden" name="page" value="expenses">
                <input type="hidden" name="card_action" value="Expense">
                <input type="hidden" name="company_id" value="7">
                <input type="hidden" name="intent" value="filter_claims">
                <input type="hidden" name="expense_query" value="">
                <input type="hidden" name="expense_heatmap_claimant_id" value="3">
                <input type="hidden" name="expense_heatmap_date" value="2026-05-01">
                <div class="form-row table-filter-row">
                    <label for="table-filter-expenses_state-expense_status">Show</label>
                    <select class="selector-input" id="table-filter-expenses_state-expense_status" name="expense_status"><option value="all" selected>All</option><option value="draft">Draft</option><option value="posted">Posted</option></select>
                </div>
            </form>'));
        $harness->assertSame(false, str_contains($html, '<div class="mini-field">
                    <label for="expense-claimant">Claimant</label>'));
        $harness->assertSame(false, str_contains($html, '<h3 class="card-title">Create Expense claim</h3>'));
        $harness->assertSame(false, str_contains($html, 'id="expense-heatmap-claimant"'));
        $harness->assertTrue(str_contains($html, 'class="expense-claims-stack"'));
        $harness->assertSame(1, substr_count($html, '<section class="panel-soft">'));
        $harness->assertSame(false, str_contains($html, 'class="expense-claim-heatmap-controls create-expense-claim"'));
        $harness->assertSame(false, str_contains($html, '<button class="button" type="submit">Apply</button>'));
        $harness->assertTrue(strpos($html, 'id="expense-claimant"') < strpos($html, 'class="calendar-heatmap"'));
        $harness->assertTrue(strpos($html, '<div class="card-toolbar">') < strpos($html, 'id="expense-claimant"'));
        $harness->assertTrue(strpos($html, '<div class="card-toolbar">') < strpos($html, 'class="expense-claim-heatmap"'));
        $harness->assertTrue(strpos($html, 'class="expense-claim-heatmap"') < strpos($html, '<table'));
        $harness->assertSame(0, substr_count($html, 'class="card-toolbar expenses-toolbar"'));
        $harness->assertSame(0, substr_count($html, 'class="toolbar expenses-toolbar"'));
        $harness->assertSame(false, str_contains($html, 'id="expense-search-status"'));
        $harness->assertTrue(str_contains($html, '<label for="table-filter-expenses_state-expense_status">Show</label>'));
        $harness->assertTrue(str_contains($html, 'id="table-filter-expenses_state-expense_status" name="expense_status"'));
        $harness->assertTrue(str_contains($html, '<option value="all" selected>All</option>'));
        $harness->assertTrue(str_contains($html, '<form id="expense-search-form" method="get" action="?page=expenses" data-ajax="true" class="toolbar">
                <input type="hidden" name="page" value="expenses">
                <input type="hidden" name="card_action" value="Expense">
                <input type="hidden" name="company_id" value="7">
                <input type="hidden" name="intent" value="filter_claims">
                <input type="hidden" name="expense_status" value="all">
                <input type="hidden" name="expense_heatmap_claimant_id" value="3">
                <input type="hidden" name="expense_heatmap_date" value="2026-05-01">
                <div class="mini-field">
                    <input class="input" id="expense-search-query" name="expense_query" type="search" value="" placeholder="EXP-...">
                </div>
                <button class="button primary" type="submit">Search</button>
            </form>'));
        $harness->assertSame(false, str_contains($html, 'name="expense_query" form="expense-search-form"'));
        $harness->assertSame(false, str_contains($html, 'type="submit" form="expense-search-form"'));
        $harness->assertTrue(str_contains($html, '<div class="actions-row"><button class="button'));
        $statusFilterPosition = strpos($html, 'id="table-filter-expenses_state-expense_status"');
        $claimantPosition = strpos($html, 'id="expense-claimant"');
        $searchPosition = strpos($html, 'id="expense-search-query"');
        $condensedPosition = strpos($html, 'table-condensed-toggle');
        $exportPosition = strpos($html, 'name="_table_export_prepare" value="csv"');
        $harness->assertTrue($statusFilterPosition !== false);
        $harness->assertTrue($claimantPosition !== false);
        $harness->assertTrue($searchPosition !== false);
        $harness->assertTrue($condensedPosition !== false);
        $harness->assertTrue($exportPosition !== false);
        $harness->assertTrue($claimantPosition < $statusFilterPosition);
        $harness->assertTrue($statusFilterPosition < $searchPosition);
        $harness->assertTrue($searchPosition < $exportPosition);
        $harness->assertTrue($searchPosition < $condensedPosition);
        $harness->assertTrue($condensedPosition < $exportPosition);
    });

    $harness->check(_expenses_stateCard::class, 'renders exportable paginated claims table with thirteen rows', function () use ($harness, $instance): void {
        $context = expensesStateCardContext();
        $html = $instance->render($context);

        $harness->assertTrue(str_contains($html, 'name="_table_export_prepare" value="csv"'));
        $harness->assertTrue(str_contains($html, 'name="_table_export_prepare" value="xlsx"'));
        $harness->assertTrue(str_contains($html, 'Expense claims 1-13 of 14'));
        $harness->assertTrue(str_contains($html, 'EXP-2605-013'));
        $harness->assertSame(false, str_contains($html, 'EXP-2605-014'));

        $table = $instance->tables($context)[0] ?? null;
        $harness->assertTrue($table instanceof TableFramework);

        $csv = $table->exportCsv();
        $harness->assertTrue(str_starts_with($csv, "Reference,Claimant,Month,A,B,C,D,Status,Updated\n"));
        $harness->assertTrue(str_contains($csv, 'EXP-2605-014'));
        $harness->assertSame(false, str_contains($csv, 'Open'));
    });
});

function expensesStateCardContext(array $filterOverrides = []): array
{
    $filters = array_merge([
        'heatmap_claimant_id' => 3,
        'heatmap_date' => '2026-05-01',
        'status' => 'all',
        'query' => '',
    ], $filterOverrides);

    return [
        'company' => [
            'id' => 7,
            'accounting_period_id' => 102,
            'settings' => [
                'incorporation_date' => '2020-01-01',
            ],
        ],
        'services' => [
            'expensesPageData' => [
                'claimants' => [
                    [
                        'id' => 4,
                        'claimant_name' => 'Bob',
                        'is_active' => 1,
                    ],
                    [
                        'id' => 3,
                        'claimant_name' => 'Alex Example',
                        'is_active' => 1,
                    ],
                ],
                'active_claimant_count' => 1,
                'accounting_periods' => [
                    [
                        'id' => 102,
                        'label' => '2026/27',
                        'period_start' => '2026-04-01',
                        'period_end' => '2027-03-31',
                    ],
                    [
                        'id' => 101,
                        'label' => '2025/26',
                        'period_start' => '2025-04-01',
                        'period_end' => '2026-03-31',
                    ],
                ],
                'claims' => expensesStateCardClaims(),
                'claim_heatmap_claims' => expensesStateCardClaims(),
                'filters' => $filters,
            ],
        ],
    ];
}

function expensesStateCardClaims(): array
{
    $claims = [];

    foreach (range(1, 14) as $index) {
        $claims[] = [
            'id' => 40 + $index,
            'claimant_id' => 3,
            'claimant_name' => 'Alex Example',
            'claim_year' => 2026,
            'claim_month' => 5,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'claim_reference_code' => 'EXP-2605-' . str_pad((string)$index, 3, '0', STR_PAD_LEFT),
            'A' => 0,
            'B' => 94.99 + $index,
            'C' => 0,
            'D' => 94.99 + $index,
            'status' => $index % 2 === 0 ? 'posted' : 'draft',
            'last_updated' => '2026-05-' . str_pad((string)min($index + 1, 28), 2, '0', STR_PAD_LEFT) . ' 10:00:00',
        ];
    }

    return $claims;
}
