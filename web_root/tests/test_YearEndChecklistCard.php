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

$harness->run(_year_end_checklistCard::class, static function (GeneratedServiceClassTestHarness $harness, _year_end_checklistCard $card): void {
    $harness->check(_year_end_checklistCard::class, 'renders workflow links but not inline cut-off review approval', static function () use ($harness, $card): void {
        $html = $card->render([
            'year_end' => [
                'checklist' => [
                    'company_id' => 12,
                    'accounting_period' => ['id' => 34],
                    'overall_status' => 'in_progress',
                    'sections' => [
                        'year_end_accounts_review' => [
                            [
                                'check_code' => 'cut_off_journals_review',
                                'title' => 'Cut-off journals review',
                                'status' => 'warning',
                                'detail_text' => 'Review whether any cut-off journals are required.',
                                'metric_value' => 'Pending',
                                'action_url' => '?page=journal&company_id=12&accounting_period_id=34&show_card=journal_cut_off_confirmation',
                                'review_clearable' => true,
                            ],
                            [
                                'check_code' => 'fixed_asset_review_placeholder',
                                'title' => 'Fixed asset review',
                                'status' => 'pass',
                                'detail_text' => 'Review acknowledged for this period.',
                                'metric_value' => 'Reviewed',
                                'action_url' => '?page=assets&company_id=12&accounting_period_id=34&show_card=not_an_asset',
                                'review_clearable' => true,
                                'review_acknowledgement' => [
                                    'acknowledged_at' => '2026-07-03 12:00:00',
                                    'acknowledged_by' => 'test',
                                ],
                            ],
                            [
                                'check_code' => 'prepayment_approvals',
                                'title' => 'Prepayment approvals',
                                'status' => 'warning',
                                'detail_text' => 'Approve the prepayment review before closing this accounting period.',
                                'metric_value' => 'Pending',
                                'action_url' => '?page=prepayments&company_id=12&accounting_period_id=34&show_card=year_end_prepayment_approvals',
                                'review_clearable' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'Open Related Workflow'));
        $harness->assertSame(true, str_contains($html, '<form method="post" action="?page=journal" data-ajax="true"'));
        $harness->assertSame(true, str_contains($html, '<form method="post" action="?page=prepayments" data-ajax="true"'));
        $harness->assertSame(true, str_contains($html, '<form method="post" action="?page=year_end" data-ajax="true"'));
        $harness->assertSame(true, str_contains($html, '<input type="hidden" name="show_card" value="journal_cut_off_confirmation">'));
        $harness->assertSame(true, str_contains($html, '<input type="hidden" name="show_card" value="year_end_prepayment_approvals">'));
        $harness->assertSame(true, str_contains($html, '<form method="post" action="?page=assets" data-ajax="true"'));
        $harness->assertSame(true, str_contains($html, '<input type="hidden" name="show_card" value="not_an_asset">'));
        $harness->assertSame(true, str_contains($html, '<input type="hidden" name="company_id" value="12">'));
        $harness->assertSame(true, str_contains($html, '<input type="hidden" name="accounting_period_id" value="34">'));
        $harness->assertSame(false, str_contains($html, 'company_id=12'));
        $harness->assertSame(false, str_contains($html, 'accounting_period_id=34'));
        $harness->assertSame(false, str_contains($html, 'name="check_code" value="cut_off_journals_review"'));
        $harness->assertSame(false, str_contains($html, 'name="check_code" value="prepayment_approvals"'));
        $harness->assertSame(false, str_contains($html, 'name="intent" value="acknowledge_review_check"'));
        $harness->assertSame(false, str_contains($html, 'Mark reviewed'));
        $harness->assertSame(true, str_contains($html, 'name="intent" value="reopen_review_check"'));
        $harness->assertSame(true, str_contains($html, 'Reopen review'));
    });

    $harness->check(_year_end_checklistCard::class, 'renders filing basis reminder as information without review actions', static function () use ($harness, $card): void {
        $html = $card->render([
            'year_end' => [
                'checklist' => [
                    'company_id' => 12,
                    'accounting_period' => ['id' => 34],
                    'overall_status' => 'ready_for_review',
                    'sections' => [
                        'corporation_tax_readiness' => [
                            [
                                'check_code' => 'filing_basis_reminder',
                                'title' => 'Filing basis reminder',
                                'status' => 'info',
                                'detail_text' => 'Year-end lock finalises the app ledger. Statutory accounts, iXBRL, and tax filing outputs should still be reviewed separately before submission.',
                                'metric_value' => '',
                                'action_url' => '?page=tax&company_id=12&accounting_period_id=34&show_card=year_end_tax_readiness',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'Filing basis reminder'));
        $harness->assertSame(true, str_contains($html, 'Ready to Close and Lock'));
        $harness->assertSame(true, str_contains($html, 'year-end-check-panel-info'));
        $harness->assertSame(true, str_contains($html, 'Year-end lock finalises the app ledger.'));
        $harness->assertSame(true, str_contains($html, '<form method="post" action="?page=tax" data-ajax="true"'));
        $harness->assertSame(true, str_contains($html, '<input type="hidden" name="show_card" value="year_end_tax_readiness">'));
        $harness->assertSame(false, str_contains($html, 'name="intent" value="acknowledge_review_check"'));
        $harness->assertSame(false, str_contains($html, 'name="intent" value="reopen_review_check"'));
        $harness->assertSame(false, str_contains($html, 'Mark reviewed'));
        $harness->assertSame(false, str_contains($html, 'Reopen review'));
    });

    $harness->check(_year_end_checklistCard::class, 'keeps Companies House approval in its dedicated workflow while retaining the two generic review actions', static function () use ($harness, $card): void {
        $html = $card->render([
            'year_end' => [
                'checklist' => [
                    'company_id' => 12,
                    'accounting_period' => ['id' => 34],
                    'overall_status' => 'in_progress',
                    'sections' => [
                        'director_loan_expenses' => [[
                            'check_code' => 'director_loan_tax_review',
                            'title' => 'Director loan tax review',
                            'status' => 'warning',
                            'detail_text' => 'Review the director loan tax exposure.',
                            'metric_value' => '£1,000.00',
                            'action_url' => '?page=director_loans&show_card=year_end_director_loan_offset',
                            'review_clearable' => true,
                        ]],
                        'year_end_accounts_review' => [[
                            'check_code' => 'fixed_asset_review_placeholder',
                            'title' => 'Fixed asset review',
                            'status' => 'warning',
                            'detail_text' => 'Review potential fixed assets.',
                            'metric_value' => '1',
                            'action_url' => '?page=assets&show_card=not_an_asset',
                            'review_clearable' => true,
                        ]],
                        'companies_house_comparison' => [[
                            'check_code' => 'companies_house_mismatch_acknowledgement',
                            'title' => 'Accounts comparison metrics',
                            'status' => 'warning',
                            'detail_text' => 'Stored filing values differ from the app figures.',
                            'metric_value' => '2',
                            'action_url' => '?page=companies_house&show_card=year_end_companies_house_comparison',
                            'review_clearable' => true,
                        ]],
                    ],
                ],
            ],
        ]);

        $harness->assertSame(2, substr_count($html, '>Mark reviewed</button>'));
        $harness->assertSame(true, str_contains($html, 'name="check_code" value="fixed_asset_review_placeholder"'));
        $harness->assertSame(true, str_contains($html, 'name="check_code" value="director_loan_tax_review"'));
        $harness->assertSame(false, str_contains($html, 'name="check_code" value="companies_house_mismatch_acknowledgement"'));
        $harness->assertSame(true, str_contains($html, 'Accounts comparison metrics'));
        $harness->assertSame(true, str_contains($html, '<input type="hidden" name="show_card" value="year_end_companies_house_comparison">'));
    });

    $harness->check(_year_end_checklistCard::class, 'bookkeeping workflow link uses selected site context', static function () use ($harness, $card): void {
        $html = $card->render([
            'year_end' => [
                'checklist' => [
                    'company_id' => 12,
                    'accounting_period' => ['id' => 34],
                    'overall_status' => 'in_progress',
                    'sections' => [
                        'bookkeeping_completeness' => [
                            [
                                'check_code' => 'source_data_present',
                                'title' => 'Source data present',
                                'status' => 'pass',
                                'detail_text' => 'Source data is present.',
                                'metric_value' => '1',
                            ],
                        ],
                    ],
                    'month_tiles' => [],
                ],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, '<form method="post" action="?page=transactions" data-ajax="true"'));
        $harness->assertSame(true, str_contains($html, '<input type="hidden" name="company_id" value="12">'));
        $harness->assertSame(true, str_contains($html, '<input type="hidden" name="accounting_period_id" value="34">'));
        $harness->assertSame(true, str_contains($html, '<input type="hidden" name="show_card" value="year_end_empty_month_confirmations">'));
        $harness->assertSame(false, str_contains($html, 'company_id=12'));
        $harness->assertSame(false, str_contains($html, 'accounting_period_id=34'));
    });

    $harness->check(_year_end_checklistCard::class, 'bookkeeping summary warns when visible coverage is incomplete', static function () use ($harness, $card): void {
        $html = $card->render([
            'year_end' => [
                'checklist' => [
                    'company_id' => 12,
                    'accounting_period' => ['id' => 34],
                    'overall_status' => 'in_progress',
                    'sections' => [
                        'bookkeeping_completeness' => [
                            [
                                'check_code' => 'source_data_present',
                                'title' => 'Source data present',
                                'status' => 'pass',
                                'detail_text' => 'Source data is present.',
                                'metric_value' => '1',
                            ],
                            [
                                'check_code' => 'missing_month_warning',
                                'title' => 'Expected month coverage',
                                'status' => 'pass',
                                'detail_text' => 'Every month inside the accounting period has at least some source activity.',
                                'metric_value' => 'All months covered',
                            ],
                        ],
                    ],
                    'month_tiles' => [
                        ['month_key' => '2025-08-01', 'status' => 'green'],
                        ['month_key' => '2025-09-01', 'status' => 'amber'],
                    ],
                ],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, '1 of 2'));
        $harness->assertSame(true, str_contains($html, 'year-end-check-panel-warning'));
        $harness->assertSame(true, str_contains($html, '>Warning</span>'));
        $harness->assertSame(false, str_contains($html, '>Pass</span>'));
    });

    $harness->check(_year_end_checklistCard::class, 'renders posted-only integrity as separate source cards', static function () use ($harness, $card): void {
        $html = $card->render([
            'year_end' => [
                'checklist' => [
                    'company_id' => 12,
                    'accounting_period' => ['id' => 34],
                    'overall_status' => 'needs_attention',
                    'sections' => [
                        'ledger_integrity' => [
                            [
                                'check_code' => 'posted_transactions_integrity',
                                'title' => 'Posted transactions',
                                'status' => 'pass',
                                'detail_text' => 'All postable transactions have posted journals for this period.',
                                'metric_value' => '0 transaction(s)',
                                'action_url' => '?page=transactions&show_card=transactions_imported&category_filter=not_posted',
                            ],
                            [
                                'check_code' => 'posted_expense_claims_integrity',
                                'title' => 'Posted expense claims',
                                'status' => 'fail',
                                'detail_text' => 'Post or confirm the remaining expense claims before locking this period.',
                                'metric_value' => '7 expense claim(s)',
                                'action_url' => '?page=expense_claims',
                            ],
                            [
                                'check_code' => 'posted_assets_integrity',
                                'title' => 'Posted assets',
                                'status' => 'pass',
                                'detail_text' => 'All fixed assets have posted journals for this period.',
                                'metric_value' => '0 asset(s)',
                                'action_url' => '?page=assets',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'C. Ledger integrity'));
        $harness->assertSame(true, str_contains($html, 'Posted transactions'));
        $harness->assertSame(true, str_contains($html, '0 transaction(s)'));
        $harness->assertSame(true, str_contains($html, 'Posted expense claims'));
        $harness->assertSame(true, str_contains($html, '7 expense claim(s)'));
        $harness->assertSame(true, str_contains($html, 'Posted assets'));
        $harness->assertSame(true, str_contains($html, '0 asset(s)'));
        $harness->assertSame(false, str_contains($html, 'Posted-only period integrity'));
    });

    $harness->check(_year_end_checklistCard::class, 'renders tax readiness acknowledgement in corporation tax section', static function () use ($harness, $card): void {
        $html = $card->render([
            'year_end' => [
                'checklist' => [
                    'company_id' => 12,
                    'accounting_period' => ['id' => 34],
                    'overall_status' => 'in_progress',
                    'sections' => [
                        'corporation_tax_readiness' => [
                            [
                                'check_code' => 'tax_readiness_acknowledgement',
                                'title' => 'Tax readiness acknowledgement',
                                'status' => 'warning',
                                'detail_text' => 'Review the corporation tax workings before closing this accounting period.',
                                'metric_value' => 'Pending',
                                'formula_text' => 'CT periods: 05/09/2022 to 04/09/2023; 05/09/2023 to 30/09/2023',
                                'action_url' => '?page=tax&company_id=12&accounting_period_id=34',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'G. Corporation tax readiness'));
        $harness->assertSame(true, str_contains($html, 'Tax readiness acknowledgement'));
        $harness->assertSame(true, str_contains($html, '05/09/2022 to 04/09/2023; 05/09/2023 to 30/09/2023'));
        $harness->assertSame(true, str_contains($html, '<form method="post" action="?page=tax" data-ajax="true"'));
        $harness->assertSame(true, str_contains($html, '<input type="hidden" name="company_id" value="12">'));
        $harness->assertSame(true, str_contains($html, '<input type="hidden" name="accounting_period_id" value="34">'));
        $harness->assertSame(false, str_contains($html, 'company_id=12'));
        $harness->assertSame(false, str_contains($html, 'accounting_period_id=34'));
    });

    $harness->check(_year_end_checklistCard::class, 'renders expense position acknowledgement in expense section', static function () use ($harness, $card): void {
        $html = $card->render([
            'year_end' => [
                'checklist' => [
                    'company_id' => 12,
                    'accounting_period' => ['id' => 34],
                    'overall_status' => 'in_progress',
                    'sections' => [
                        'director_loan_expenses' => [
                            [
                                'check_code' => 'expense_position_acknowledgement',
                                'title' => 'Expense position acknowledgement',
                                'status' => 'warning',
                                'detail_text' => 'Review the expense claim balance brought forward, claims, payments, and carried-forward position before closing this accounting period.',
                                'metric_value' => 'UNPAID £ 225.00',
                                'action_url' => '?page=expense_claims&company_id=12&accounting_period_id=34&show_card=year_end_expenses_confirmation',
                            ],
                            [
                                'check_code' => 'expense_position_acknowledgement',
                                'title' => 'Expense position acknowledgement',
                                'status' => 'pass',
                                'detail_text' => 'Expense claim position has been acknowledged for this period.',
                                'metric_value' => 'OWED -£ 42.00',
                                'action_url' => '?page=expense_claims&company_id=12&accounting_period_id=34&show_card=year_end_expenses_confirmation',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'E. Director loan and expense claims'));
        $harness->assertSame(true, str_contains($html, 'Expense position acknowledgement'));
        $harness->assertSame(true, str_contains($html, 'UNPAID £ 225.00'));
        $harness->assertSame(true, str_contains($html, 'OWED -£ 42.00'));
        $harness->assertSame(true, str_contains($html, '<form method="post" action="?page=expense_claims" data-ajax="true"'));
        $harness->assertSame(true, str_contains($html, '<input type="hidden" name="show_card" value="year_end_expenses_confirmation">'));
        $harness->assertSame(true, str_contains($html, '<input type="hidden" name="company_id" value="12">'));
        $harness->assertSame(true, str_contains($html, '<input type="hidden" name="accounting_period_id" value="34">'));
        $harness->assertSame(false, str_contains($html, 'company_id=12'));
        $harness->assertSame(false, str_contains($html, 'accounting_period_id=34'));
    });
});
