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

$harness->run(_expense_claim_editorCard::class, function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof _expense_claim_editorCard) {
        $harness->skip('Expense claim editor card did not instantiate.');
    }

    $harness->check(_expense_claim_editorCard::class, 'renders four-column control totals and paste helper', function () use ($harness, $instance): void {
        $html = $instance->render(expenseClaimEditorCardContext());

        $harness->assertTrue(str_contains($html, '<div class="summary-grid four">'));
        $harness->assertTrue(str_contains($html, 'Paste claim lines'));
        $harness->assertTrue(str_contains($html, 'Paste tab-delimited rows in this column order: DATE, DESCRIPTION, AMOUNT CLAIMED.'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="preview_bulk_lines"'));
    });

    $harness->check(_expense_claim_editorCard::class, 'uses exportable builder tables with 20 row pagination', function () use ($harness, $instance): void {
        $context = expenseClaimEditorCardContext();
        for ($i = 2; $i <= 25; $i++) {
            $context['services']['expensesPageData']['selected_claim']['lines'][] = [
                'id' => 10 + $i,
                'expense_date' => '2022-10-' . str_pad((string)min($i, 28), 2, '0', STR_PAD_LEFT),
                'description' => 'Line ' . $i,
                'nominal_account_id' => null,
                'amount' => (float)$i,
            ];
        }

        $tables = $instance->tables($context);
        $harness->assertSame(4, count($tables));
        foreach ($tables as $table) {
            $harness->assertTrue($table instanceof TableFramework);
        }

        $html = $instance->render($context);
        $harness->assertTrue(str_contains($html, '_table_export_prepare'));
        $harness->assertTrue(str_contains($html, 'CSV'));
        $harness->assertTrue(str_contains($html, 'XLSX'));
        $harness->assertTrue(str_contains($html, 'TSV'));
        $harness->assertTrue(str_contains($html, 'ASCII'));
        $harness->assertTrue(str_contains($html, 'Expense lines 1-20 of 25'));
        $harness->assertTrue(str_contains($html, 'name="expense_claim_editor_expense_claim_editor_lines"'));
    });

    $harness->check(_expense_claim_editorCard::class, 'hides receipt reference from editor UI', function () use ($harness, $instance): void {
        $html = $instance->render(expenseClaimEditorCardContext());

        $harness->assertSame(false, str_contains($html, 'Receipt reference'));
        $harness->assertSame(false, str_contains($html, 'name="receipt_reference"'));
        $harness->assertSame(false, str_contains($html, '<th>Receipt</th>'));
    });

    $harness->check(_expense_claim_editorCard::class, 'renders inline nominal update controls for draft lines', function () use ($harness, $instance): void {
        $html = $instance->render(expenseClaimEditorCardContext());

        $harness->assertTrue(str_contains($html, 'name="intent" value="update_line_nominal"'));
        $harness->assertTrue(str_contains($html, 'data-autosave-submit-target=".js-expense-line-nominal-submit"'));
        $harness->assertTrue(str_contains($html, '<option value="">Unassigned</option>'));
    });

    $harness->check(_expense_claim_editorCard::class, 'renders bulk preview and import action with display date', function () use ($harness, $instance): void {
        $context = expenseClaimEditorCardContext();
        $context['expense_bulk_preview'] = [
            'claim_id' => 42,
            'source_text' => "5/10/2022\tElectricFix, Wall Chaser\t£94.99",
            'rows' => [[
                'expense_date' => '2022-10-05',
                'expense_date_display' => '05-10-2022',
                'description' => 'ElectricFix, Wall Chaser',
                'amount' => 94.99,
            ]],
            'total' => 94.99,
        ];

        $html = $instance->render($context);

        $harness->assertTrue(str_contains($html, '05-10-2022'));
        $harness->assertTrue(str_contains($html, 'Preview total'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="bulk_save_lines"'));
        $harness->assertTrue(str_contains($html, 'Import previewed lines'));
    });

    $harness->check(_expense_claim_editorCard::class, 'renders repayment link and unlink forms', function () use ($harness, $instance): void {
        $html = $instance->render(expenseClaimEditorCardContext());

        $harness->assertTrue(str_contains($html, 'name="intent" value="link_payment"'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="unlink_payment"'));
        $harness->assertTrue(str_contains($html, 'name="payment_query"'));
        $harness->assertTrue(strpos($html, '<div class="card-toolbar">') < strpos($html, 'id="expense-payment-query"'));
        $harness->assertSame(false, str_contains($html, '<div class="mini-field">
                <label for="expense-payment-query">Search repayments</label>'));
        $harness->assertSame(0, preg_match('/<div class="actions-row">\s*<\/div>/', $html));
        $harness->assertTrue(str_contains($html, 'name="default_expense_nominal_id" value="5000"'));
        $harness->assertSame(false, str_contains($html, 'name="director_loan_nominal_id"'));
        $harness->assertSame(false, str_contains($html, 'name="linked_amount" inputmode="decimal"'));
        $harness->assertTrue(str_contains($html, 'The selected claim determines the claimant.'));
    });
});

function expenseClaimEditorCardContext(): array
{
    return [
        'company' => [
            'id' => 7,
            'settings' => [
                'date_format' => 'd-m-Y',
                'default_expense_nominal_id' => 5000,
                'director_loan_nominal_id' => 2300,
                'default_bank_nominal_id' => 1000,
            ],
        ],
        'expense_page_settings' => [
            'date_format' => 'd-m-Y',
            'default_expense_nominal_id' => 5000,
            'director_loan_nominal_id' => 2300,
            'default_bank_nominal_id' => 1000,
        ],
        'services' => [
            'expensesPageData' => [
                'filters' => [
                    'payment_query' => 'repayment',
                ],
                'nominal_accounts' => [
                    [
                        'id' => 5000,
                        'code' => '5000',
                        'name' => 'Purchases',
                    ],
                ],
                'payment_candidates' => [
                    [
                        'id' => 91,
                        'txn_date' => '2022-10-31',
                        'description' => 'Expense repayment',
                        'reference' => 'EXP PAY',
                        'amount' => 75.00,
                        'available_amount' => 75.00,
                        'current_link_amount' => 0.0,
                    ],
                ],
                'selected_claim' => [
                    'id' => 42,
                    'claim_reference_code' => 'EXP-2210-TEST',
                    'claimant_name' => 'Alex Example',
                    'claim_month' => 10,
                    'claim_year' => 2022,
                    'period_end' => '2022-10-31',
                    'status_label' => 'Draft',
                    'is_posted' => false,
                    'control_totals' => [
                        'A' => 0,
                        'B' => 94.99,
                        'C' => 75,
                        'D' => 19.99,
                    ],
                    'lines' => [
                        [
                            'id' => 11,
                            'expense_date' => '2022-10-05',
                            'description' => 'ElectricFix, Wall Chaser',
                            'nominal_account_id' => null,
                            'amount' => 94.99,
                        ],
                    ],
                    'payment_links' => [
                        [
                            'id' => 22,
                            'txn_date' => '2022-10-31',
                            'description' => 'Expense repayment',
                            'reference' => 'EXP PAY',
                            'linked_amount' => 75.00,
                        ],
                    ],
                ],
            ],
        ],
    ];
}
