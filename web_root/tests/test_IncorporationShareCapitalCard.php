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
    $harness->check(_incorporation_share_capitalCard::class, 'renders recorded share classes in a read-only TableFramework table', static function () use ($harness, $card): void {
        $context = incorporationShareCapitalCardContext([
            [
                'id' => 12,
                'share_class' => 'Ordinary',
                'currency' => 'GBP',
                'quantity' => 100,
                'nominal_value_per_share' => '5.000000',
                'paid_value_per_share' => '5.000000',
                'unpaid_value_per_share' => '0.000000',
                'nominal_total' => 500.00,
                'unpaid_total' => 0.00,
                'payment_status' => 'payment_matched',
                'source_note' => 'FULL RIGHTS REGARDING VOTING, PAYMENT OF DIVIDENDS AND DISTRIBUTIONS',
                'document_reference' => 'Model articles adopted',
            ],
        ]);
        $html = $card->render($context);
        $tables = $card->tables($context);

        $harness->assertTrue(($tables[0] ?? null) instanceof TableFramework);
        $harness->assertSame(true, str_contains($html, 'Class of shares'));
        $harness->assertSame(true, str_contains($html, 'Number allotted'));
        $harness->assertSame(true, str_contains($html, 'Aggregate nominal value'));
        $harness->assertSame(true, str_contains($html, '500'));
        $harness->assertSame(true, str_contains($html, 'Total aggregate unpaid'));
        $harness->assertSame(true, str_contains($html, 'Paid-Up'));
        $harness->assertSame(true, str_contains($html, 'Prescribed particulars'));
        $harness->assertSame(true, str_contains($html, 'FULL RIGHTS REGARDING VOTING, PAYMENT OF DIVIDENDS AND DISTRIBUTIONS'));
        $harness->assertSame(true, str_contains($html, 'Review payment'));
        $harness->assertSame(false, str_contains($html, 'name="share_class"'));
        $harness->assertSame(false, str_contains($html, 'name="quantity"'));
        $harness->assertSame(false, str_contains($html, 'name="nominal_value_per_share"'));
        $harness->assertSame(false, str_contains($html, '<textarea'));
        $harness->assertSame(false, str_contains($html, 'Save Share Class'));
        $harness->assertSame(false, str_contains($html, 'Mark Not Paid Up'));
        $harness->assertSame(false, str_contains($html, 'mark_shares_unpaid'));
        $harness->assertSame(false, str_contains($html, 'Pull data from Companies House NEWINC Filled Document'));

        $csv = $tables[0]->exportCsv();
        $harness->assertSame(true, str_contains($csv, 'Ordinary'));
        $harness->assertSame(true, str_contains($csv, '500'));
        $harness->assertSame(false, str_contains($csv, '<input'));
    });

    $harness->check(_incorporation_share_capitalCard::class, 'paginates recorded share classes at five rows while export includes all rows', static function () use ($harness, $card): void {
        $rows = [];
        for ($i = 1; $i <= 6; $i++) {
            $rows[] = [
                'id' => $i,
                'share_class' => 'Class ' . $i,
                'currency' => 'GBP',
                'quantity' => $i,
                'nominal_value_per_share' => '1.000000',
                'paid_value_per_share' => '1.000000',
                'unpaid_value_per_share' => '0.000000',
                'nominal_total' => $i,
                'unpaid_total' => 0,
                'payment_status' => 'not_paid_up',
                'source_note' => '',
                'document_reference' => 'doc-' . $i,
            ];
        }

        $context = incorporationShareCapitalCardContext($rows);
        $html = $card->render($context);
        $csv = $card->tables($context)[0]->exportCsv();

        $harness->assertSame(true, str_contains($html, 'Class 1'));
        $harness->assertSame(true, str_contains($html, 'Class 5'));
        $harness->assertSame(false, str_contains($html, 'Class 6'));
        $harness->assertSame(true, str_contains($csv, 'Class 6'));
    });
});

function incorporationShareCapitalCardContext(array $shareClasses): array
{
    return [
        'company' => ['id' => 7],
        'page' => [
            'page_id' => 'incorporation',
            'page_cards' => ['incorporation_share_capital'],
        ],
        'services' => [
            'incorporationShares' => [
                'available' => true,
                'share_classes' => $shareClasses,
            ],
        ],
    ];
}
