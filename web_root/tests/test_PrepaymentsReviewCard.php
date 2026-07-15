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
                                'source_id' => 124,
                                'source_date' => '2025-06-16',
                                'nominal_code' => '6200',
                                'nominal_name' => 'Software',
                                'description' => 'Unposted refund candidate',
                                'amount' => 25.00,
                                'exclusion_reason' => 'The source journal is not posted.',
                            ]],
                            'items' => [[
                                'source_type' => 'transaction',
                                'source_id' => 123,
                                'source_date' => '2025-06-15',
                                'nominal_code' => '6200',
                                'nominal_name' => 'Software',
                                'description' => 'Annual service candidate',
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
            $harness->assertTrue(str_contains($html, 'Review required — choose a decision'));
            $harness->assertTrue(str_contains($html, 'value="pending" selected disabled'));
            $harness->assertTrue(str_contains($html, 'data-autosave-submit-target=".prepayment-autosave-transaction-123"'));
            $harness->assertTrue(str_contains($html, '<button class="prepayment-autosave-transaction-123" type="submit" hidden>Autosave decision</button>'));
            $harness->assertSame(false, str_contains($html, 'Save decision'));
            $harness->assertSame(false, str_contains($html, 'value="not_prepaid" selected'));
            $harness->assertTrue(str_contains($html, 'Excluded source items'));
            $harness->assertTrue(str_contains($html, 'do not block Year End'));
            $harness->assertTrue(str_contains($html, 'Unposted refund candidate'));
            $harness->assertTrue(str_contains($html, 'The source journal is not posted.'));
        });

        $harness->check(_prepayments_reviewCard::class, 'shows historical schedule repair and filing evidence as explicit actions', static function () use ($harness, $card): void {
            $html = $card->render([
                'company' => [
                    'id' => 49,
                    'accounting_period_id' => 79,
                    'settings' => ['default_currency_symbol' => '&#163;'],
                ],
                'services' => [
                    'prepaymentWorkflowContext' => [
                        'review' => [
                            'available' => true,
                            'accounting_period' => ['id' => 79, 'period_end' => '2023-09-30'],
                            'total_count' => 0,
                            'reviewed_count' => 0,
                            'prepaid_count' => 0,
                            'pending_count' => 0,
                            'excluded_count' => 0,
                            'excluded_items' => [],
                            'items' => [],
                        ],
                        'historical_correction' => [
                            'available' => true,
                            'posting_permitted' => false,
                            'repair' => [
                                'missing_count' => 1,
                                'missing_reviews' => [[
                                    'review_id' => 14,
                                    'source_type' => 'transaction',
                                    'source_id' => 6151,
                                    'source_date' => '2022-12-30',
                                    'source_amount_pence' => 57000,
                                    'service_start_date' => '2022-12-30',
                                    'service_end_date' => '2023-12-29',
                                    'selected_allocation' => [
                                        'overlap_days' => 275,
                                        'expense_pence' => 42945,
                                        'closing_deferred_pence' => 14055,
                                    ],
                                ]],
                            ],
                            'companies_house_filed' => true,
                            'companies_house_documents' => [[
                                'filing_date' => '2024-06-01',
                                'filing_description' => 'Micro-company accounts',
                                'document_id' => 'doc-ap79',
                            ]],
                            'hmrc_filing' => ['state' => 'unknown'],
                            'acknowledgement' => ['current' => false],
                            'has_prepayment_work' => true,
                            'expected_profit_change_pence' => 14055,
                        ],
                    ],
                ],
            ]);

            $harness->assertTrue(str_contains($html, 'Saved prepayments missing automated schedules'));
            $harness->assertTrue(str_contains($html, 'Recalculate schedule'));
            $harness->assertTrue(str_contains($html, 'Filed-period prepayment correction'));
            $harness->assertTrue(str_contains($html, 'Record HMRC filing evidence'));
            $harness->assertTrue(str_contains($html, 'Recalculation creates append-only schedule snapshots only'));
        });
    }
);
