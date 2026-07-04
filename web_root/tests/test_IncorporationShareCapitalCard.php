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
$harness->run(_incorporation_share_capitalCard::class, static function (
    GeneratedServiceClassTestHarness $harness,
    _incorporation_share_capitalCard $card
): void {
    $harness->check(_incorporation_share_capitalCard::class, 'renders Companies House statement of capital fields', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => ['id' => 7],
            'services' => [
                'incorporationShares' => [
                    'available' => true,
                    'share_classes' => [[
                        'id' => 12,
                        'share_class' => 'Ordinary',
                        'currency' => 'GBP',
                        'quantity' => 100,
                        'nominal_value_per_share' => '5.000000',
                        'paid_value_per_share' => '5.000000',
                        'unpaid_value_per_share' => '0.000000',
                        'nominal_total' => 500.00,
                        'unpaid_total' => 0.00,
                        'source_note' => 'FULL RIGHTS REGARDING VOTING, PAYMENT OF DIVIDENDS AND DISTRIBUTIONS',
                        'document_reference' => 'Model articles adopted',
                    ]],
                ],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'Class of shares'));
        $harness->assertSame(true, str_contains($html, 'Number allotted'));
        $harness->assertSame(true, str_contains($html, 'Aggregate nominal value'));
        $harness->assertSame(true, str_contains($html, 'name="aggregate_nominal_value" value="500"'));
        $harness->assertSame(true, str_contains($html, 'Total aggregate unpaid'));
        $harness->assertSame(true, str_contains($html, 'name="total_aggregate_unpaid" value="0"'));
        $harness->assertSame(true, str_contains($html, 'Prescribed particulars'));
        $harness->assertSame(true, str_contains($html, 'FULL RIGHTS REGARDING VOTING, PAYMENT OF DIVIDENDS AND DISTRIBUTIONS'));
        $harness->assertSame(false, str_contains($html, 'Paid amount per share'));
        $harness->assertSame(false, str_contains($html, 'Unpaid amount per share'));
        $harness->assertSame(false, str_contains($html, 'name="paid_value_per_share"'));
        $harness->assertSame(false, str_contains($html, 'name="unpaid_value_per_share"'));
    });
});
