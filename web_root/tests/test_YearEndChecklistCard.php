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
                                'action_url' => '?page=year_end&company_id=12&accounting_period_id=34&show_card=journal_cut_off_confirmation',
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
                                'action_url' => '?page=year_end&company_id=12&accounting_period_id=34&show_card=year_end_prepayment_approvals',
                                'review_clearable' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'Open Related Workflow'));
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
                                'action_url' => '?page=year_end&company_id=12&accounting_period_id=34&show_card=year_end_tax_readiness',
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
        $harness->assertSame(true, str_contains($html, '<form method="post" action="?page=year_end" data-ajax="true"'));
        $harness->assertSame(true, str_contains($html, '<input type="hidden" name="show_card" value="year_end_tax_readiness">'));
        $harness->assertSame(false, str_contains($html, 'name="intent" value="acknowledge_review_check"'));
        $harness->assertSame(false, str_contains($html, 'name="intent" value="reopen_review_check"'));
        $harness->assertSame(false, str_contains($html, 'Mark reviewed'));
        $harness->assertSame(false, str_contains($html, 'Reopen review'));
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
        $harness->assertSame(false, str_contains($html, 'company_id=12'));
        $harness->assertSame(false, str_contains($html, 'accounting_period_id=34'));
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
                                'action_url' => '?page=year_end&company_id=12&accounting_period_id=34&show_card=year_end_expenses_confirmation',
                            ],
                            [
                                'check_code' => 'expense_position_acknowledgement',
                                'title' => 'Expense position acknowledgement',
                                'status' => 'pass',
                                'detail_text' => 'Expense claim position has been acknowledged for this period.',
                                'metric_value' => 'OWED -£ 42.00',
                                'action_url' => '?page=year_end&company_id=12&accounting_period_id=34&show_card=year_end_expenses_confirmation',
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
        $harness->assertSame(true, str_contains($html, '<form method="post" action="?page=year_end" data-ajax="true"'));
        $harness->assertSame(true, str_contains($html, '<input type="hidden" name="show_card" value="year_end_expenses_confirmation">'));
        $harness->assertSame(true, str_contains($html, '<input type="hidden" name="company_id" value="12">'));
        $harness->assertSame(true, str_contains($html, '<input type="hidden" name="accounting_period_id" value="34">'));
        $harness->assertSame(false, str_contains($html, 'company_id=12'));
        $harness->assertSame(false, str_contains($html, 'accounting_period_id=34'));
    });
});
