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
$harness->run(_incorporation_payment_matchingCard::class, static function (
    GeneratedServiceClassTestHarness $harness,
    _incorporation_payment_matchingCard $card
): void {
    $harness->check(_incorporation_payment_matchingCard::class, 'shows recategorised matches as not paid up and keeps matching actions on this card', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'id' => 7,
                'settings' => [],
            ],
            'services' => [
                'incorporationShares' => [
                    'available' => true,
                    'share_classes' => [
                        [
                            'id' => 12,
                            'share_class' => 'Ordinary',
                            'expected_paid_total' => 500,
                            'unpaid_total' => 0,
                            'paid_up_unpaid_total' => 500,
                            'payment_status' => 'not_paid_up',
                            'current_match' => [
                                'transaction_id' => 44,
                                'matched_amount' => 500,
                                'txn_date' => '2026-01-10',
                                'description' => 'Share capital receipt',
                                'match_valid' => false,
                                'match_invalid_reason' => 'transaction_recategorised',
                            ],
                            'payment_candidates' => [
                                [
                                    'id' => 45,
                                    'txn_date' => '2026-01-11',
                                    'description' => 'Replacement share receipt',
                                    'reference' => 'SHARES',
                                    'amount' => 500,
                                    'category_status' => 'uncategorised',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'Not paid up'));
        $harness->assertSame(true, str_contains($html, 'Unpaid share capital'));
        $harness->assertSame(true, str_contains($html, 'Candidate Payments'));
        $harness->assertSame(false, str_contains($html, 'Candidate receipts'));
        $harness->assertSame(true, str_contains($html, 're-categorised away from Ordinary Share Capital'));
        $harness->assertSame(true, str_contains($html, 'Current Matches'));
        $harness->assertSame(true, str_contains($html, '<th>Transaction</th>'));
        $harness->assertSame(true, str_contains($html, '<th>Manage</th>'));
        $harness->assertSame(true, str_contains($html, 'class="button" href="?page=transactions&amp;show_card=transaction_search&amp;transaction_search_keyword=44">#44</a>'));
        $harness->assertSame(true, str_contains($html, 'clear_share_payment_match'));
        $harness->assertSame(true, str_contains($html, 'match_share_payment'));
        $harness->assertSame(false, str_contains($html, 'save_incorporation_shares'));
        $harness->assertSame(false, str_contains($html, 'mark_shares_unpaid'));
    });

    $harness->check(_incorporation_payment_matchingCard::class, 'does not look for candidate receipts once the payment is matched', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'id' => 7,
                'settings' => [],
            ],
            'services' => [
                'incorporationShares' => [
                    'available' => true,
                    'share_classes' => [
                        [
                            'id' => 12,
                            'share_class' => 'Ordinary',
                            'expected_paid_total' => 500,
                            'unpaid_total' => 0,
                            'paid_up_unpaid_total' => 0,
                            'payment_status' => 'payment_matched',
                            'current_match' => [
                                'transaction_id' => 44,
                                'matched_amount' => 500,
                                'txn_date' => '2026-01-10',
                                'description' => 'Share capital receipt',
                                'match_valid' => true,
                                'match_invalid_reason' => '',
                            ],
                            'payment_candidates' => [
                                [
                                    'id' => 45,
                                    'txn_date' => '2026-01-11',
                                    'description' => 'Replacement share receipt',
                                    'reference' => 'SHARES',
                                    'amount' => 500,
                                    'category_status' => 'uncategorised',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'Payment matched'));
        $harness->assertSame(false, str_contains($html, 'Candidate receipts'));
        $harness->assertSame(false, str_contains($html, 'Candidate Payments'));
        $harness->assertSame(false, str_contains($html, 'match_share_payment'));
    });

    $harness->check(_incorporation_payment_matchingCard::class, 'renders candidate payments with TableFramework pagination and export', static function () use ($harness, $card): void {
        $candidates = [];
        for ($i = 1; $i <= 11; $i++) {
            $candidates[] = [
                'id' => $i,
                'txn_date' => '2026-01-' . str_pad((string)$i, 2, '0', STR_PAD_LEFT),
                'description' => 'Candidate payment ' . $i,
                'reference' => 'SHARES-' . $i,
                'amount' => 500,
                'category_status' => 'manual',
            ];
        }

        $context = [
            'company' => [
                'id' => 7,
                'settings' => [],
            ],
            'page' => [
                'page_id' => 'incorporation',
                'page_cards' => ['incorporation_payment_matching'],
            ],
            'services' => [
                'incorporationShares' => [
                    'available' => true,
                    'share_classes' => [
                        [
                            'id' => 12,
                            'share_class' => 'Ordinary',
                            'expected_paid_total' => 500,
                            'unpaid_total' => 0,
                            'paid_up_unpaid_total' => 500,
                            'payment_status' => 'not_paid_up',
                            'current_match' => null,
                            'payment_candidates' => $candidates,
                        ],
                    ],
                ],
            ],
        ];
        $html = $card->render($context);
        $tables = $card->tables($context);

        $harness->assertTrue(($tables[0] ?? null) instanceof TableFramework);
        $harness->assertSame(true, str_contains($html, 'Candidate Payments'));
        $harness->assertSame(true, str_contains($html, 'Candidate payment 1'));
        $harness->assertSame(true, str_contains($html, 'Candidate payment 10'));
        $harness->assertSame(false, str_contains($html, 'Candidate payment 11'));
        $harness->assertSame(true, str_contains($html, 'CSV'));
        $harness->assertSame(true, str_contains($html, 'name="table_key" value="candidate_payments_12"'));

        $csv = $tables[0]->exportCsv();
        $harness->assertSame(true, str_contains($csv, 'Candidate payment 11'));
        $harness->assertSame(false, str_contains($csv, 'match_share_payment'));
    });
});
