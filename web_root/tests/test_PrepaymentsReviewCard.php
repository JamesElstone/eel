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
            $harness->assertTrue(str_contains($html, 'data-autosave-submit-target=".prepayment-autosave-transaction-9307"'));
            $harness->assertTrue(str_contains($html, '<button class="prepayment-autosave-transaction-9307" type="submit" hidden>Autosave decision</button>'));
            $harness->assertSame(false, str_contains($html, 'Save decision'));
            $harness->assertSame(false, str_contains($html, 'value="not_prepaid" selected'));
            $harness->assertTrue(str_contains($html, 'Excluded source items'));
            $harness->assertTrue(str_contains($html, 'do not block Year End'));
            $harness->assertTrue(str_contains($html, 'Synthetic excluded credit candidate'));
            $harness->assertTrue(str_contains($html, 'The source journal is not posted.'));
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
