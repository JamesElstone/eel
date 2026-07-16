<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'PageServiceTestFactory.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->run(_year_end_retained_earningsCard::class, static function (GeneratedServiceClassTestHarness $harness, _year_end_retained_earningsCard $card): void {
    $harness->check(_year_end_retained_earningsCard::class, 'renders layman close wording and acknowledgement', static function () use ($harness, $card): void {
        $html = $card->render(yearEndRetainedEarningsCardContext(false, false));

        $harness->assertSame(true, str_contains($html, 'Retained Earnings'));
        $harness->assertSame(true, str_contains($html, 'carry current profit/loss into 3000 Retained Earnings'));
        $harness->assertSame(true, str_contains($html, 'reset income and expense nominal balances for the next period (clear them)'));
        $harness->assertSame(true, str_contains($html, 'Original transactions, expense claims, and source journals are not changed.'));
        $harness->assertSame(true, str_contains($html, 'Direct equity movements'));
        $harness->assertSame(true, str_contains($html, 'Share capital movement'));
        $harness->assertSame(true, str_contains($html, 'I confirm that I have reviewed the retained earnings close shown above and approve it as accurate for Year End.'));
        $harness->assertSame(true, str_contains($html, 'type="submit" disabled title="Complete and lock the prior accounting period before closing retained earnings."'));
        $harness->assertSame(false, str_contains($html, 'disabled data-year-end-ack-submit'));
        $harness->assertSame(false, str_contains($html, 'Open Year End Confirmation'));
        $harness->assertSame(false, str_contains($html, 'preview_deferred'));
    });

    $harness->check(_year_end_retained_earningsCard::class, 'renders agreement details and revoke action when current', static function () use ($harness, $card): void {
        $html = $card->render(yearEndRetainedEarningsCardContext(true, false));

        $harness->assertSame(true, str_contains($html, 'Approved at 2026-07-06 10:00:00 by Fixture Reviewer using the web_app.'));
        $harness->assertSame(true, str_contains($html, 'name="retained_earnings_close_acknowledgement" value="0"'));
        $harness->assertSame(true, str_contains($html, 'Revoke approval'));
        $harness->assertSame(false, str_contains($html, 'checked required'));
    });

    $harness->check(_year_end_retained_earningsCard::class, 'warns when agreement is stale', static function () use ($harness, $card): void {
        $html = $card->render(yearEndRetainedEarningsCardContext(true, true));

        $harness->assertSame(true, str_contains($html, 'Figures have changed since the last agreement.'));
        $harness->assertSame(true, str_contains($html, 'Review required — underlying data changed.'));
        $harness->assertSame(true, str_contains($html, 'type="submit" disabled title="Complete and lock the prior accounting period before closing retained earnings."'));
    });

    $harness->check(_year_end_retained_earningsCard::class, 'does not present an unbalanced balance sheet as an equality', static function () use ($harness, $card): void {
        $html = $card->render(yearEndRetainedEarningsCardContext(false, false, [
            'summary' => [
                'opening_equity' => 0,
                'current_profit_loss' => -275.50,
                'closing_equity_before_close' => 0,
                'retained_earnings_movement' => -275.50,
                'assets' => 1850.00,
                'liabilities' => 2125.50,
                'equity' => -200.00,
                'balance_equation_difference' => -75.50,
                'is_balance_sheet_balanced' => false,
            ],
        ]));

        $harness->assertSame(true, str_contains($html, 'do not agree to equity'));
        $harness->assertSame(true, str_contains($html, 'difference -£ 75.50'));
    });

    $harness->check(_year_end_retained_earningsCard::class, 'reuses the profit and loss corporation tax provision', static function () use ($harness, $card): void {
        $services = $card->services();
        $params = (array)($services[0]['params'] ?? []);

        $harness->assertSame(false, array_key_exists('loadFullPreview', $params));
        $harness->assertSame(
            ':profit_loss.summary.corporation_tax_provision',
            (string)($params['corporationTaxProvision'] ?? '')
        );
        $harness->assertSame(
            ':profit_loss.summary.depreciation_preview',
            (string)($params['depreciationPreview'] ?? '')
        );
    });
});

function yearEndRetainedEarningsCardContext(bool $acknowledged, bool $stale, array $closeOverrides = []): array
{
    $close = array_merge([
        'available' => true,
        'accounting_period' => [
            'id' => 9456,
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
        ],
        'acknowledged' => $acknowledged,
        'acknowledgement_stale' => $stale,
        'acknowledgement_state' => $stale ? 'stale' : ($acknowledged ? 'current' : 'absent'),
        'acknowledgement' => $acknowledged ? [
            'acknowledged_at' => '2026-07-06 10:00:00',
            'acknowledged_by' => 'Fixture Reviewer using the web_app',
        ] : null,
        'can_acknowledge' => false,
        'prior_period_dependency' => [
            'status' => 'prior_period_unlocked',
            'satisfied' => false,
            'detail' => 'Complete and lock the prior accounting period before closing retained earnings.',
        ],
        'warnings' => [
            'Complete and lock the prior accounting period before closing retained earnings.',
        ],
        'summary' => [
            'opening_equity' => 0,
            'current_profit_loss' => -275.50,
            'closing_equity_before_close' => 0,
            'retained_earnings_movement' => -275.50,
            'assets' => 1850.00,
            'liabilities' => 2125.50,
            'equity' => -275.50,
        ],
        'journal_lines' => [
            [
                'nominal_code' => '4000',
                'nominal_name' => 'Sales',
                'debit' => '8250.00',
                'credit' => '0.00',
                'line_description' => 'Move 4000 Sales into retained earnings',
            ],
            [
                'nominal_code' => '3000',
                'nominal_name' => 'Retained Earnings',
                'debit' => '275.50',
                'credit' => '0.00',
                'line_description' => 'Carry loss into retained earnings',
            ],
        ],
    ], $closeOverrides);

    return [
        'page' => [
            'page_id' => 'profit_loss',
        ],
        'company' => [
            'id' => 9123,
            'accounting_period_id' => 9456,
            'settings' => ['default_currency_symbol' => '&#163;'],
        ],
        'services' => [
            'yearEndRetainedEarnings' => $close,
        ],
    ];
}
