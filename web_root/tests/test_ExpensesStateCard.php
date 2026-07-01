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

    $harness->check(_expenses_stateCard::class, 'renders claim heatmap from page accounting period without period selector', function () use ($harness, $instance): void {
        $html = $instance->render(expensesStateCardContext());

        $harness->assertSame(false, str_contains($html, 'calendar-heatmap-range-select'));
        $harness->assertSame(false, str_contains($html, 'id="expense-claim-calendar-period-start"'));
        $harness->assertSame(false, str_contains($html, 'name="expense_heatmap_period_start"'));
        $harness->assertSame(false, str_contains($html, 'calendar-heatmap-year-select'));
        $harness->assertTrue(str_contains($html, 'name="expense_heatmap_date"'));
        $harness->assertTrue(str_contains($html, 'value="2026-05-01"'));
        $harness->assertTrue(str_contains($html, 'id="expense-create-claimant"'));
        $harness->assertSame(false, str_contains($html, 'id="expense-heatmap-claimant"'));
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

function expensesStateCardContext(): array
{
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
                        'id' => 3,
                        'claimant_name' => 'James Elstone',
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
                'filters' => [
                    'heatmap_claimant_id' => 3,
                    'heatmap_date' => '2026-05-01',
                    'status' => 'all',
                    'query' => '',
                ],
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
            'claimant_name' => 'James Elstone',
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
