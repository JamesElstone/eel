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
                    'prepaymentsReview' => [
                        'available' => true,
                        'accounting_period' => [
                            'id' => 0,
                            'period_end' => '2025-12-31',
                        ],
                        'total_count' => 1,
                        'reviewed_count' => 0,
                        'prepaid_count' => 0,
                        'pending_count' => 1,
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
            ]);

            $harness->assertTrue(str_contains($html, 'Awaiting decision'));
            $harness->assertTrue(str_contains($html, 'Review required — choose a decision'));
            $harness->assertTrue(str_contains($html, 'value="pending" selected disabled'));
            $harness->assertTrue(str_contains($html, 'Save decision'));
            $harness->assertSame(false, str_contains($html, 'value="not_prepaid" selected'));
        });
    }
);
