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

$harness->run(_year_end_retained_earningsCard::class, static function (GeneratedServiceClassTestHarness $harness, _year_end_retained_earningsCard $card): void {
    $harness->check(_year_end_retained_earningsCard::class, 'renders layman close wording and acknowledgement', static function () use ($harness, $card): void {
        $html = $card->render(yearEndRetainedEarningsCardContext(false, false));

        $harness->assertSame(true, str_contains($html, 'Retained Earnings'));
        $harness->assertSame(true, str_contains($html, 'carry current profit/loss into 3000 Retained Earnings'));
        $harness->assertSame(true, str_contains($html, 'reset income and expense nominal balances for the next period (clear them)'));
        $harness->assertSame(true, str_contains($html, 'Original transactions, expense claims, and source journals are not changed.'));
        $harness->assertSame(true, str_contains($html, 'I confirm that I have reviewed the retained earnings close shown above and approve it as accurate for Year End.'));
        $harness->assertSame(true, str_contains($html, 'disabled data-year-end-ack-submit'));
    });

    $harness->check(_year_end_retained_earningsCard::class, 'renders agreement details and revoke action when current', static function () use ($harness, $card): void {
        $html = $card->render(yearEndRetainedEarningsCardContext(true, false));

        $harness->assertSame(true, str_contains($html, 'Approved at 2026-07-06 10:00:00 by Alex Example using the web_app.'));
        $harness->assertSame(true, str_contains($html, 'name="retained_earnings_close_acknowledgement" value="0"'));
        $harness->assertSame(true, str_contains($html, 'Revoke approval'));
        $harness->assertSame(false, str_contains($html, 'checked required'));
    });

    $harness->check(_year_end_retained_earningsCard::class, 'warns when agreement is stale', static function () use ($harness, $card): void {
        $html = $card->render(yearEndRetainedEarningsCardContext(true, true));

        $harness->assertSame(true, str_contains($html, 'Figures have changed since the last agreement.'));
        $harness->assertSame(true, str_contains($html, 'disabled data-year-end-ack-submit'));
    });
});

function yearEndRetainedEarningsCardContext(bool $acknowledged, bool $stale): array
{
    return [
        'company' => [
            'id' => 49,
            'accounting_period_id' => 79,
            'settings' => ['default_currency_symbol' => '&#163;'],
        ],
        'services' => [
            'yearEndRetainedEarnings' => [
                'available' => true,
                'accounting_period' => [
                    'id' => 79,
                    'period_start' => '2022-09-05',
                    'period_end' => '2023-09-30',
                ],
                'acknowledged' => $acknowledged,
                'acknowledgement_stale' => $stale,
                'review' => [
                    'retained_earnings_close_acknowledged_at' => $acknowledged ? '2026-07-06 10:00:00' : '',
                    'retained_earnings_close_acknowledged_by' => $acknowledged ? 'Alex Example using the web_app' : '',
                ],
                'summary' => [
                    'opening_equity' => 0,
                    'current_profit_loss' => -396.91,
                    'closing_equity_before_close' => 0,
                    'retained_earnings_movement' => -396.91,
                    'assets' => 990.44,
                    'liabilities' => 1387.35,
                    'equity' => -396.91,
                ],
                'journal_lines' => [
                    [
                        'nominal_code' => '4000',
                        'nominal_name' => 'Sales',
                        'debit' => '9676.95',
                        'credit' => '0.00',
                        'line_description' => 'Move 4000 Sales into retained earnings',
                    ],
                    [
                        'nominal_code' => '3000',
                        'nominal_name' => 'Retained Earnings',
                        'debit' => '396.91',
                        'credit' => '0.00',
                        'line_description' => 'Carry loss into retained earnings',
                    ],
                ],
            ],
        ],
    ];
}
