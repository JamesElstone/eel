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
$harness->run(_incorporation_statusCard::class, static function (
    GeneratedServiceClassTestHarness $harness,
    _incorporation_statusCard $card
): void {
    $harness->check(_incorporation_statusCard::class, 'shows derived unpaid share capital when payment is not matched', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'settings' => [],
            ],
            'services' => [
                'incorporationShares' => [
                    'available' => true,
                    'status' => 'shares_not_paid_up',
                    'totals' => [
                        'issued_nominal_total' => 500,
                        'expected_paid_total' => 500,
                        'matched_total' => 0,
                        'unpaid_total' => 0,
                        'paid_up_unpaid_total' => 500,
                    ],
                ],
            ],
        ]);

        $harness->assertSame(false, str_contains($html, 'Shares not paid up'));
        $harness->assertSame(true, str_contains($html, 'class="summary-grid four"'));
        $harness->assertSame(true, str_contains($html, 'Unpaid share capital'));
        $harness->assertSame(true, str_contains($html, '500'));
    });
});
