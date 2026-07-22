<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    _prepayments_reviewCard::class,
    static function (GeneratedServiceClassTestHarness $harness, _prepayments_reviewCard $card): void {
        $harness->check(_prepayments_reviewCard::class, 'shows missing persisted decisions as review required', static function () use ($harness, $card): void {
            $html = $card->render([
                'company' => [
                    'id' => 0,
                    'accounting_period_id' => 0,
                    'settings' => ['default_currency_symbol' => '&#163;'],
                ],
                'services' => [
                    'prepaymentWorkflowContext' => [
                        'review' => [
                            'available' => true,
                            'accounting_period' => [
                                'id' => 0,
                                'period_end' => '2025-12-31',
                            ],
                            'total_count' => 1,
                            'reviewed_count' => 0,
                            'prepaid_count' => 0,
                            'pending_count' => 1,
                            'excluded_count' => 1,
                            'excluded_items' => [[
                                'source_type' => 'transaction',
                                'source_id' => 9308,
                                'source_date' => '2025-06-16',
                                'nominal_code' => '6200',
                                'nominal_name' => 'Software',
                                'description' => 'Synthetic excluded credit candidate',
                                'amount' => 25.00,
                                'exclusion_reason' => 'The source journal is not posted.',
                            ]],
                            'items' => [[
                                'source_type' => 'transaction',
                                'source_id' => 9307,
                                'source_date' => '2025-06-15',
                                'nominal_code' => '6200',
                                'nominal_name' => 'Software',
                                'description' => 'Synthetic pending service candidate',
                                'amount' => 120.00,
                                'review' => [
                                    'status' => 'pending',
                                    'persisted' => false,
                                    'service_start_date' => '',
                                    'service_end_date' => '',
                                ],
                            ]],
                        ],
                    ],
                ],
            ]);

            $harness->assertTrue(str_contains($html, 'Awaiting decision'));
            $harness->assertTrue(str_contains($html, '<div class="month-grid prepayments-summary-grid">'));
            $harness->assertTrue(str_contains($html, 'Review required — choose a decision'));
            $harness->assertTrue(str_contains($html, 'value="pending" selected disabled'));
            $harness->assertSame(false, str_contains($html, 'data-autosave-submit-target'));
            $harness->assertSame(false, str_contains($html, 'Autosave decision'));
            $harness->assertTrue(str_contains($html, 'name="service_start_date" value="2025-06-15" required'));
            $harness->assertTrue(str_contains($html, 'name="service_end_date" value="2025-12-31" required'));
            $harness->assertTrue(str_contains($html, '<button class="button primary" type="submit">Save decision</button>'));
            $harness->assertSame(false, str_contains($html, 'value="not_prepaid" selected'));
            $harness->assertTrue(str_contains($html, 'Excluded source items'));
            $harness->assertTrue(str_contains($html, 'do not block Year End'));
            $harness->assertTrue(str_contains($html, 'Synthetic excluded credit candidate'));
            $harness->assertTrue(str_contains($html, 'The source journal is not posted.'));
        });

        $harness->check(_prepayments_reviewCard::class, 'renders carried schedules between the summary cards and current-period review table', static function () use ($harness, $card): void {
            $html = $card->render([
                'company' => [
                    'id' => 0,
                    'accounting_period_id' => 0,
                    'settings' => ['default_currency_symbol' => '&#163;'],
                ],
                'services' => [
                    'prepaymentWorkflowContext' => [
                        'review' => [
                            'available' => true,
                            'accounting_period' => ['id' => 0, 'period_end' => '2025-12-31'],
                            'total_count' => 1,
                            'reviewed_count' => 1,
                            'prepaid_count' => 1,
                            'pending_count' => 0,
                            'carried_schedule_count' => 1,
                            'excluded_items' => [],
                            'items' => [[
                                'source_type' => 'transaction',
                                'source_id' => 9309,
                                'source_date' => '2025-01-01',
                                'nominal_code' => '6200',
                                'nominal_name' => 'Software',
                                'description' => 'Current-period annual licence',
                                'amount' => 120.00,
                                'review' => [
                                    'id' => 9409,
                                    'status' => 'prepaid',
                                    'service_start_date' => '2025-01-01',
                                    'service_end_date' => '2025-12-31',
                                    'schedule' => [
                                        'total_days' => 365,
                                        'unallocated_pence' => 0,
                                        'allocations' => [[
                                            'accounting_period_id' => 0,
                                            'period_start' => '2025-01-01',
                                            'period_end' => '2025-12-31',
                                            'overlap_days' => 365,
                                            'expense_pence' => 12000,
                                            'closing_deferred_pence' => 0,
                                            'posting_count' => 0,
                                        ]],
                                    ],
                                ],
                            ]],
                            'carried_schedules' => [[
                                'source_description' => 'Annual insurance',
                                'service_start_date' => '2024-07-01',
                                'service_end_date' => '2025-06-30',
                                'selected_allocation' => [
                                    'overlap_days' => 181,
                                    'expense_pence' => 6000,
                                    'opening_deferred_pence' => 6000,
                                    'closing_deferred_pence' => 0,
                                    'journal_state' => 'posted',
                                ],
                            ]],
                        ],
                    ],
                ],
            ]);

            $summaryPosition = strpos($html, '<div class="month-grid prepayments-summary-grid">');
            $carriedPosition = strpos($html, 'Pre-Payment Schedules - Carried Forwards');
            $currentPeriodPosition = strpos($html, 'Pre-Payment Schedules - During Accounting Period');
            $reviewTablePosition = strpos($html, '<thead><tr><th>Source</th><th>Date</th><th>Nominal</th>');

            $harness->assertTrue($summaryPosition !== false);
            $harness->assertTrue($carriedPosition !== false && $carriedPosition > $summaryPosition);
            $harness->assertTrue($currentPeriodPosition !== false && $currentPeriodPosition > $carriedPosition);
            $harness->assertTrue($reviewTablePosition !== false && $reviewTablePosition > $currentPeriodPosition);
            $harness->assertTrue(str_contains($html, '<th>Source</th><th>Description</th><th>Service dates</th><th>Amount</th><th>Accounting period</th><th>Days</th><th>Expense</th><th>Closing asset</th><th>Actions</th>'));
            $harness->assertTrue(str_contains($html, 'Current-period annual licence'));
            $harness->assertTrue(str_contains($html, '01/01/2025–31/12/2025'));
            $harness->assertTrue(str_contains($html, '<td class="numeric">365</td>'));
            $harness->assertTrue(str_contains($html, '<td class="numeric">£ 120.00</td>'));
            $harness->assertTrue(str_contains($html, '<td class="numeric">£ 0.00</td>'));
            $harness->assertSame(false, str_contains($html, '<summary>Full AP schedule</summary>'));
            $harness->assertTrue(str_contains($html, '<th>Amount</th><th>Status</th></tr></thead>'));
            $harness->assertSame(false, str_contains($html, '<th>Amount</th><th>Status</th><th>Schedule</th>'));
        });

        $harness->check(_prepayments_reviewCard::class, 'shows schedule repair without filed-period correction controls', static function () use ($harness, $card): void {
            $html = $card->render([
                'company' => [
                    'id' => 9107,
                    'accounting_period_id' => 9207,
                    'settings' => ['default_currency_symbol' => '&#163;'],
                ],
                'services' => [
                    'prepaymentWorkflowContext' => [
                        'review' => [
                            'available' => true,
                            'accounting_period' => ['id' => 9207, 'period_end' => '2023-09-30'],
                            'total_count' => 0,
                            'reviewed_count' => 0,
                            'prepaid_count' => 0,
                            'pending_count' => 0,
                            'excluded_count' => 0,
                            'excluded_items' => [],
                            'items' => [],
                        ],
                        'repair' => [
                            'available' => true,
                            'missing_count' => 1,
                            'missing_reviews' => [[
                                'review_id' => 9407,
                                'source_type' => 'transaction',
                                'source_id' => 9307,
                                'source_date' => '2022-12-30',
                                'source_amount_pence' => 73000,
                                'service_start_date' => '2022-12-30',
                                'service_end_date' => '2023-12-29',
                                'selected_allocation' => [
                                    'overlap_days' => 275,
                                    'expense_pence' => 55000,
                                    'closing_deferred_pence' => 18000,
                                ],
                            ]],
                        ],
                    ],
                ],
            ]);

            $harness->assertTrue(str_contains($html, 'Saved prepayments missing automated schedules'));
            $harness->assertTrue(str_contains($html, 'id="prepayment-schedule-repair"'));
            $harness->assertTrue(str_contains($html, 'Recalculate schedule'));
            $harness->assertTrue(str_contains($html, 'Recalculation creates append-only schedule snapshots only'));
            $harness->assertSame(false, str_contains($html, 'Filed-period'));
            $harness->assertSame(false, str_contains($html, 'Companies House'));
            $harness->assertSame(false, str_contains($html, 'HMRC'));
        });
    }
);
