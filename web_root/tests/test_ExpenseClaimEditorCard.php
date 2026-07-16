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

AppConfigurationStore::config(true);

$harness->run(_expense_claim_editorCard::class, function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof _expense_claim_editorCard) {
        $harness->skip('Expense claim editor card did not instantiate.');
    }

    $harness->check(_expense_claim_editorCard::class, 'renders claim summary, submit toolbar action, and paste helper', function () use ($harness, $instance): void {
        $html = $instance->render(expenseClaimEditorCardContext());

        $harness->assertTrue(str_contains($html, 'Claim Reference'));
        $harness->assertTrue(str_contains($html, 'Submit Claim'));
        $harness->assertTrue(str_contains($html, '<button class="button primary" type="submit" disabled>Submit Claim</button>'));
        $harness->assertSame(false, str_contains($html, 'data-chicken-title="Submit expense claim"'));
        $harness->assertSame(false, str_contains($html, '<h4 class="card-title">Submit Claim</h4>'));
        $harness->assertTrue(str_contains($html, 'Claim Lines can be pasted below'));
        $harness->assertTrue(str_contains($html, 'DATE[TAB]DESCRIPTION[TAB]AMOUNT CLAIMED'));
        $harness->assertTrue(str_contains($html, 'DATE[TAB]DESCRIPTION[TAB]Info[TAB]AMOUNT CLAIMED'));
        $harness->assertTrue(str_contains($html, 'Quoted CSV is also accepted.'));
        $harness->assertSame(false, str_contains($html, '&quot;DATE&quot;, &quot;DESCRIPTION&quot;, &quot;AMOUNT CLAIMED&quot;'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="bulk_save_lines"'));
        $harness->assertSame(false, str_contains($html, 'name="intent" value="preview_bulk_lines"'));
    });

    $harness->check(_expense_claim_editorCard::class, 'places submit claim in expense lines toolbar action row', function () use ($harness, $instance): void {
        $html = $instance->render(expenseClaimEditorCardContext());

        $expenseLinesPosition = strpos($html, '<h4 class="card-title">Expense Lines</h4>');
        $toolbarPosition = strpos($html, '<div class="card-toolbar">', (int)$expenseLinesPosition);
        $actionRowPosition = strpos($html, '<div class="actions-row">', (int)$toolbarPosition);
        $builtInActionRowPosition = strpos($html, '<div class="actions-row">', (int)$actionRowPosition + 1);
        $submitPosition = strpos($html, '>Submit Claim</button>', (int)$actionRowPosition);
        $condensedPosition = strpos($html, '>Condensed View</button>', (int)$builtInActionRowPosition);
        $tablePosition = strpos($html, '<table', (int)$toolbarPosition);

        $harness->assertTrue($expenseLinesPosition !== false);
        $harness->assertTrue($toolbarPosition !== false);
        $harness->assertTrue($actionRowPosition !== false);
        $harness->assertTrue($builtInActionRowPosition !== false);
        $harness->assertTrue($submitPosition !== false);
        $harness->assertTrue($condensedPosition !== false);
        $harness->assertTrue($tablePosition !== false);
        $harness->assertTrue($expenseLinesPosition < $toolbarPosition);
        $harness->assertTrue($toolbarPosition < $actionRowPosition);
        $harness->assertTrue($actionRowPosition < $submitPosition);
        $harness->assertTrue($submitPosition < $builtInActionRowPosition);
        $harness->assertTrue($builtInActionRowPosition < $condensedPosition);
        $harness->assertTrue($condensedPosition < $tablePosition);
        $expenseLinesToolbarHtml = substr($html, (int)$toolbarPosition, (int)$tablePosition - (int)$toolbarPosition);
        $harness->assertSame(0, preg_match('/<div class="actions-row">\s*<\/div>/', $expenseLinesToolbarHtml));
    });

    $harness->check(_expense_claim_editorCard::class, 'renders ready submit claim button with chicken confirmation', function () use ($harness, $instance): void {
        $context = expenseClaimEditorCardContext();
        $context['services']['expensesPageData']['selected_claim']['lines'][0]['nominal_account_id'] = 5000;

        $html = $instance->render($context);

        $harness->assertTrue(str_contains($html, 'name="intent" value="post_claim"'));
        $harness->assertTrue(str_contains($html, '>Submit Claim</button>'));
        $harness->assertTrue(str_contains($html, 'data-chicken-check="true"'));
        $harness->assertTrue(str_contains($html, 'data-chicken-title="Submit expense claim"'));
        $harness->assertTrue(str_contains($html, 'data-chicken-confirm-text="Submit Claim"'));
    });

    $harness->check(_expense_claim_editorCard::class, 'renders confirm no lines button with chicken confirmation for empty draft claims', function () use ($harness, $instance): void {
        $context = expenseClaimEditorCardContext();
        $context['services']['expensesPageData']['selected_claim']['lines'] = [];

        $html = $instance->render($context);

        $harness->assertTrue(str_contains($html, 'name="intent" value="confirm_no_lines"'));
        $harness->assertTrue(str_contains($html, '>Confirm No Lines</button>'));
        $harness->assertTrue(str_contains($html, 'data-chicken-check="true"'));
        $harness->assertTrue(str_contains($html, 'data-chicken-title="Confirm no claim lines"'));
        $harness->assertTrue(str_contains($html, 'data-chicken-confirm-text="Confirm No Lines"'));
        $harness->assertSame(false, str_contains($html, 'name="intent" value="post_claim"'));
    });

    $harness->check(_expense_claim_editorCard::class, 'renders no-lines confirmation state without active submit action', function () use ($harness, $instance): void {
        $context = expenseClaimEditorCardContext();
        $context['services']['expensesPageData']['selected_claim']['lines'] = [];
        $context['services']['expensesPageData']['selected_claim']['no_lines_confirmed'] = true;
        $context['services']['expensesPageData']['selected_claim']['no_lines_confirmed_at'] = '2026-07-01 20:00:00';

        $html = $instance->render($context);

        $harness->assertTrue(str_contains($html, 'No Lines Confirmed on 2026-07-01 20:00:00'));
        $harness->assertSame(false, str_contains($html, 'name="intent" value="confirm_no_lines"'));
        $harness->assertSame(false, str_contains($html, '>Confirm No Lines</button>'));
        $harness->assertSame(false, str_contains($html, '>Submit Claim</button>'));
    });

    $harness->check(_expense_claim_editorCard::class, 'shows draft line total in claim summary', function () use ($harness, $instance): void {
        $context = expenseClaimEditorCardContext();
        $context['services']['expensesPageData']['selected_claim']['control_totals'] = [
            'A' => 10.00,
            'B' => 0.00,
            'C' => 5.00,
            'D' => 5.00,
        ];
        $context['services']['expensesPageData']['selected_claim']['lines'][] = [
            'id' => 12,
            'expense_date' => '2022-10-06',
            'description' => 'Fuel',
            'line_type' => 'expense',
            'nominal_account_id' => null,
            'amount' => 20.00,
        ];

        $html = $instance->render($context);
        $inClaimPosition = strpos($html, '<div class="summary-label">In this claim (B)</div><div class="summary-value">$ 103.20</div>');
        $carriedForwardPosition = strpos($html, '<div class="summary-label">Carried Forward (D=A+B-C)</div><div class="summary-value">$ 108.20</div>');

        $harness->assertTrue($inClaimPosition !== false);
        $harness->assertTrue($carriedForwardPosition !== false);
    });

    $harness->check(_expense_claim_editorCard::class, 'prefixes claim summary totals with default currency symbol', function () use ($harness, $instance): void {
        $context = expenseClaimEditorCardContext();
        $context['company']['settings']['default_currency_symbol'] = '&#8364;';
        $context['expense_page_settings']['default_currency_symbol'] = '&#8364;';

        $html = $instance->render($context);

        $harness->assertTrue(str_contains($html, '<div class="summary-label">Brought Forwards (A)</div><div class="summary-value">€ 0.00</div>'));
        $harness->assertTrue(str_contains($html, '<div class="summary-label">In this claim (B)</div><div class="summary-value">€ 83.20</div>'));
        $harness->assertTrue(str_contains($html, '<div class="summary-label">Paid in this period (C)</div><div class="summary-value">€ 60.00</div>'));
        $harness->assertTrue(str_contains($html, '<div class="summary-label">Carried Forward (D=A+B-C)</div><div class="summary-value">€ 23.20</div>'));
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
        $harness->assertSame(3, count($tables));
        foreach ($tables as $table) {
            $harness->assertTrue($table instanceof TableFramework);
        }

        $html = $instance->render($context);
        $harness->assertTrue(str_contains($html, '_table_export_prepare'));
        $harness->assertTrue(str_contains($html, 'CSV'));
        $harness->assertTrue(str_contains($html, 'XLSX'));
        $harness->assertTrue(str_contains($html, 'TSV'));
        $harness->assertTrue(str_contains($html, 'ASCII'));
        $harness->assertTrue(str_contains($html, 'Expense Lines 1-20 of 25'));
        $harness->assertTrue(str_contains($html, 'name="expense_claim_editor_expense_claim_editor_lines"'));
    });

    $harness->check(_expense_claim_editorCard::class, 'preserves expense lines page in row ajax forms', function () use ($harness, $instance): void {
        $context = expenseClaimEditorCardContext();
        $context['page']['expense_claim_editor_expense_claim_editor_lines'] = 2;

        for ($i = 2; $i <= 25; $i++) {
            $line = [
                'id' => 10 + $i,
                'expense_date' => '2022-10-' . str_pad((string)min($i, 28), 2, '0', STR_PAD_LEFT),
                'description' => 'Line ' . $i,
                'line_type' => 'expense',
                'nominal_account_id' => null,
                'amount' => (float)$i,
            ];

            if ($i === 21) {
                $line['line_type'] = 'asset';
                $line['asset_category'] = 'tools_equipment';
                $line['asset_category_label'] = 'Tools & Equipment';
                $line['asset_useful_life_years'] = 3;
                $line['asset_depreciation_method'] = 'straight_line';
                $line['asset_residual_value'] = 0.0;
            }

            $context['services']['expensesPageData']['selected_claim']['lines'][] = $line;
        }

        $html = $instance->render($context);
        $hiddenPage = '<input type="hidden" name="expense_claim_editor_expense_claim_editor_lines" value="2">';

        $harness->assertTrue(str_contains($html, 'Expense Lines 21-25 of 25'));
        $harness->assertTrue(str_contains($html, 'id="expense-line-asset-form-31"'));
        $harness->assertTrue(str_contains($html, 'id="expense-line-nominal-form-32"'));
        $harness->assertTrue(str_contains($html, $hiddenPage));
        $harness->assertSame(1, preg_match('/id="expense-line-type-form-31"[\s\S]*?name="expense_claim_editor_expense_claim_editor_lines" value="2"[\s\S]*?<\/form>/', $html));
        $harness->assertSame(1, preg_match('/id="expense-line-asset-form-31"[\s\S]*?name="expense_claim_editor_expense_claim_editor_lines" value="2"[\s\S]*?<\/form>/', $html));
        $harness->assertSame(1, preg_match('/id="expense-line-nominal-form-32"[\s\S]*?name="expense_claim_editor_expense_claim_editor_lines" value="2"[\s\S]*?<\/form>/', $html));
        $harness->assertSame(1, preg_match('/name="intent" value="delete_line"[\s\S]*?name="line_id" value="32"[\s\S]*?name="expense_claim_editor_expense_claim_editor_lines" value="2"[\s\S]*?>Remove<\/button>/', $html));
    });

    $harness->check(_expense_claim_editorCard::class, 'hides receipt reference from editor UI', function () use ($harness, $instance): void {
        $html = $instance->render(expenseClaimEditorCardContext());

        $harness->assertSame(false, str_contains($html, 'Receipt reference'));
        $harness->assertSame(false, str_contains($html, 'name="receipt_reference"'));
        $harness->assertSame(false, str_contains($html, '<th>Receipt</th>'));
    });

    $harness->check(_expense_claim_editorCard::class, 'renders type and charge controls for draft lines', function () use ($harness, $instance): void {
        $html = $instance->render(expenseClaimEditorCardContext());

        $harness->assertTrue(str_contains($html, '>Type</th>'));
        $harness->assertTrue(str_contains($html, '>Charge To</th>'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="update_line_type"'));
        $harness->assertTrue(str_contains($html, 'name="line_type" value="asset"'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="update_line_nominal"'));
        $harness->assertTrue(str_contains($html, 'data-autosave-submit-target=".js-expense-line-nominal-submit"'));
        $harness->assertTrue(str_contains($html, '<option value="">Unassigned</option>'));
        $harness->assertTrue(str_contains($html, '<label for="expense-line-amount">Amount ($)</label>'));
        $harness->assertSame(false, str_contains($html, 'for="expense-line-notes"'));
        $harness->assertSame(false, str_contains($html, 'name="notes"'));
        $harness->assertTrue(str_contains($html, '$ 83.20'));
    });

    $harness->check(_expense_claim_editorCard::class, 'renders asset charge details for asset lines', function () use ($harness, $instance): void {
        $context = expenseClaimEditorCardContext();
        $context['services']['expensesPageData']['selected_claim']['lines'][0]['line_type'] = 'asset';
        $context['services']['expensesPageData']['selected_claim']['lines'][0]['asset_category'] = 'tools_equipment';
        $context['services']['expensesPageData']['selected_claim']['lines'][0]['asset_category_label'] = 'Tools & Equipment';
        $context['services']['expensesPageData']['selected_claim']['lines'][0]['asset_useful_life_years'] = 3;
        $context['services']['expensesPageData']['selected_claim']['lines'][0]['asset_depreciation_method'] = 'straight_line';
        $context['services']['expensesPageData']['selected_claim']['lines'][0]['asset_residual_value'] = 0.0;

        $html = $instance->render($context);

        $harness->assertTrue(str_contains($html, 'name="intent" value="save_line_asset_details"'));
        $harness->assertTrue(str_contains($html, '<button class="js-expense-line-asset-submit" type="submit" hidden>Autosave</button>'));
        $harness->assertTrue(str_contains($html, 'Asset category'));
        $harness->assertTrue(str_contains($html, 'Tools &amp; Equipment'));
        $harness->assertSame(false, str_contains($html, 'Asset description'));
        $harness->assertSame(false, str_contains($html, 'name="asset_description"'));
        $harness->assertTrue(str_contains($html, '<select class="select" id="expense-line-asset-life-11" name="asset_useful_life_years" data-autosave-submit-target=".js-expense-line-asset-submit">'));
        $harness->assertTrue(str_contains($html, 'name="asset_category" data-autosave-submit-target=".js-expense-line-asset-submit"'));
        $harness->assertTrue(str_contains($html, 'title="None: no depreciation is posted. Straight Line: spreads cost less EOL Value evenly over the useful life. Reducing Balance: depreciates by the same rate each period, using the asset&apos;s remaining value after previous depreciation."'));
        $harness->assertTrue(str_contains($html, 'name="asset_depreciation_method" data-autosave-submit-target=".js-expense-line-asset-submit"'));
        $harness->assertTrue(str_contains($html, 'EOL Value'));
        $harness->assertTrue(str_contains($html, 'title="End of Life Value, also known as the Residual Value, is the value the item has after the useful life period has expired."'));
        $harness->assertSame(false, str_contains($html, '>Residual</label>'));
        $harness->assertTrue(str_contains($html, 'name="asset_residual_value" inputmode="decimal" value="0.00" data-autosave-submit-target=".js-expense-line-asset-submit"'));
        $harness->assertTrue(str_contains($html, '<option value="1">1 Year</option>'));
        $harness->assertTrue(str_contains($html, '<option value="2">2 Years</option>'));
        $harness->assertTrue(str_contains($html, '<option value="3" selected>3 Years</option>'));
        $harness->assertTrue(str_contains($html, '<option value="5">5 Years</option>'));
        $harness->assertTrue(str_contains($html, '<option value="10">10 Years</option>'));
        $harness->assertSame(false, str_contains($html, 'Save Asset Details'));

        $context['services']['expensesPageData']['selected_claim']['lines'][0]['asset_useful_life_years'] = 4;
        $fallbackHtml = $instance->render($context);
        $harness->assertTrue(str_contains($fallbackHtml, '<option value="3" selected>3 Years</option>'));
    });

    $harness->check(_expense_claim_editorCard::class, 'renders direct bulk import without preview panel', function () use ($harness, $instance): void {
        $context = expenseClaimEditorCardContext();
        $context['expense_bulk_preview'] = [
            'claim_id' => 42,
            'source_text' => "5/10/2022\tSynthetic Preview Tool\t£83.20",
            'rows' => [[
                'expense_date' => '2022-10-05',
                'expense_date_display' => '05-10-2022',
                'description' => 'Synthetic Preview Tool',
                'amount' => 83.20,
            ]],
            'total' => 83.20,
        ];

        $html = $instance->render($context);

        $harness->assertTrue(str_contains($html, 'name="intent" value="bulk_save_lines"'));
        $harness->assertTrue(str_contains($html, 'Import Lines'));
        $harness->assertSame(false, str_contains($html, 'Synthetic Preview Tool'));
        $harness->assertSame(false, str_contains($html, 'Preview total'));
        $harness->assertSame(false, str_contains($html, 'Import previewed lines'));
    });

    $harness->check(_expense_claim_editorCard::class, 'renders repayment link and unlink forms', function () use ($harness, $instance): void {
        $html = $instance->render(expenseClaimEditorCardContext());

        $harness->assertTrue(str_contains($html, 'name="intent" value="link_payment"'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="unlink_payment"'));
        $harness->assertTrue(str_contains($html, 'name="payment_query"'));
        $harness->assertTrue(strpos($html, '<div class="card-toolbar">') < strpos($html, 'id="expense-payment-query"'));
        $harness->assertSame(false, str_contains($html, '<div class="mini-field">
                <label for="expense-payment-query">Search repayments</label>'));
        $repaymentsPosition = strpos($html, '<h4 class="card-title">Repayments</h4>');
        $candidateRepaymentsPosition = strpos($html, '<h4 class="card-title">Candidate Repayments</h4>');
        $repaymentsHtml = substr($html, (int)$repaymentsPosition, (int)$candidateRepaymentsPosition - (int)$repaymentsPosition);
        $candidateRepaymentsHtml = substr($html, (int)$candidateRepaymentsPosition);
        $harness->assertSame(0, preg_match('/<div class="actions-row">\s*<\/div>/', $repaymentsHtml));
        $harness->assertTrue(str_contains($repaymentsHtml, '$ 60.00'));
        $harness->assertSame(2, substr_count($candidateRepaymentsHtml, '$ 60.00'));
        $harness->assertTrue(str_contains($candidateRepaymentsHtml, 'name="payment_link_id" value="22"'));
        $harness->assertTrue(str_contains($candidateRepaymentsHtml, '>Unlink</button>'));
        $harness->assertTrue(str_contains($candidateRepaymentsHtml, '>Link</button>'));
        $harness->assertSame(false, str_contains($candidateRepaymentsHtml, '>Update</button>'));
        $harness->assertTrue(str_contains($html, 'name="default_expense_nominal_id" value="5000"'));
        $harness->assertSame(false, str_contains($html, 'name="director_loan_nominal_id"'));
        $harness->assertSame(false, str_contains($html, 'name="linked_amount" inputmode="decimal"'));
        $harness->assertTrue(str_contains($html, 'The selected claim determines the claimant.'));
    });

    $harness->check(_expense_claim_editorCard::class, 'renders repayment linking for posted claims while keeping claim lines locked', function () use ($harness, $instance): void {
        $context = expenseClaimEditorCardContext();
        $context['services']['expensesPageData']['selected_claim']['status_label'] = 'Posted';
        $context['services']['expensesPageData']['selected_claim']['is_posted'] = true;

        $html = $instance->render($context);
        $repaymentsPosition = strpos($html, '<h4 class="card-title">Repayments</h4>');
        $candidateRepaymentsPosition = strpos($html, '<h4 class="card-title">Candidate Repayments</h4>');
        $repaymentsHtml = substr($html, (int)$repaymentsPosition, (int)$candidateRepaymentsPosition - (int)$repaymentsPosition);
        $candidateRepaymentsHtml = substr($html, (int)$candidateRepaymentsPosition);

        $harness->assertTrue(str_contains($html, 'Posted claim lines are locked. Repayments can still be linked from bank transactions.'));
        $harness->assertTrue(str_contains($html, '<h4 class="card-title">Candidate Repayments</h4>'));
        $harness->assertTrue(str_contains($html, 'name="payment_query"'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="link_payment"'));
        $harness->assertSame(false, str_contains($repaymentsHtml, 'name="intent" value="unlink_payment"'));
        $harness->assertTrue(str_contains($candidateRepaymentsHtml, 'name="intent" value="unlink_payment"'));
        $harness->assertSame(false, str_contains($candidateRepaymentsHtml, '>Update</button>'));
        $harness->assertSame(false, str_contains($html, 'name="intent" value="bulk_save_lines"'));
        $harness->assertSame(false, str_contains($html, 'Claim Lines can be pasted below'));
        $harness->assertSame(false, str_contains($html, '>Submit Claim</button>'));
        $harness->assertSame(false, str_contains($html, '>Add Line</button>'));
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
                'default_currency_symbol' => '&#36;',
            ],
        ],
        'expense_page_settings' => [
            'date_format' => 'd-m-Y',
            'default_expense_nominal_id' => 5000,
            'director_loan_nominal_id' => 2300,
            'default_bank_nominal_id' => 1000,
            'default_currency_symbol' => '&#36;',
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
                'asset_categories' => [
                    'tools_equipment' => 'Tools & Equipment',
                    'plant_machinery' => 'Plant & Machinery',
                    'van' => 'Van',
                    'car' => 'Car',
                ],
                'payment_candidates' => [
                    [
                        'id' => 91,
                        'txn_date' => '2022-10-31',
                        'description' => 'Expense repayment',
                        'reference' => 'EXP PAY',
                        'amount' => 60.00,
                        'available_amount' => 60.00,
                        'current_link_id' => 22,
                        'current_link_amount' => 60.00,
                    ],
                    [
                        'id' => 92,
                        'txn_date' => '2022-10-30',
                        'description' => 'Expense repayment available',
                        'reference' => 'EXP PAY 2',
                        'amount' => 25.00,
                        'available_amount' => 25.00,
                        'current_link_amount' => 0.0,
                    ],
                ],
                'selected_claim' => [
                    'id' => 42,
                    'claim_reference_code' => 'EXP-2210-TEST',
                    'claimant_name' => 'Taylor Fixture',
                    'claim_month' => 10,
                    'claim_year' => 2022,
                    'period_end' => '2022-10-31',
                    'status_label' => 'Draft',
                    'is_posted' => false,
                    'control_totals' => [
                        'A' => 0,
                        'B' => 83.20,
                        'C' => 60,
                        'D' => 23.20,
                    ],
                    'lines' => [
                        [
                            'id' => 11,
                            'expense_date' => '2022-10-05',
                            'description' => 'Northbridge Test Supplies, Cable Tracer',
                            'line_type' => 'expense',
                            'nominal_account_id' => null,
                            'amount' => 83.20,
                        ],
                    ],
                    'payment_links' => [
                        [
                            'id' => 22,
                            'txn_date' => '2022-10-31',
                            'description' => 'Expense repayment',
                            'reference' => 'EXP PAY',
                            'linked_amount' => 60.00,
                        ],
                    ],
                ],
            ],
        ],
    ];
}
