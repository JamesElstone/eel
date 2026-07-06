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

$harness->run(_year_end_expenses_confirmationCard::class, static function (GeneratedServiceClassTestHarness $harness, _year_end_expenses_confirmationCard $card): void {
    $harness->check(_year_end_expenses_confirmationCard::class, 'renders expense position acknowledgement details and revoke action', static function () use ($harness, $card): void {
        $html = $card->render(yearEndExpensesConfirmationCardContext([
            'available' => true,
            'accounting_period' => ['id' => 70, 'period_end' => '2025-12-31'],
            'totals' => [
                'brought_forward' => 100,
                'claimed_total' => 250,
                'payments_made' => 125,
                'carried_forward' => 225,
            ],
            'claimants' => [
                [
                    'claimant_name' => 'Alex Example',
                    'brought_forward' => 100,
                    'claimed_total' => 250,
                    'payments_made' => 125,
                    'carried_forward' => 225,
                ],
            ],
            'expense_position_acknowledged' => true,
            'expense_position_acknowledged_at' => '2026-07-06 10:00:00',
            'expense_position_acknowledged_by' => 'Alex Example using the web_app',
        ]));

        $harness->assertSame(true, str_contains($html, 'Balance brought forward (b/f)'));
        $harness->assertSame(true, str_contains($html, 'Claimed in period'));
        $harness->assertSame(true, str_contains($html, 'Payments in period'));
        $harness->assertSame(true, str_contains($html, 'Balance carried forward (c/f)'));
        $harness->assertSame(true, str_contains($html, 'Alex Example'));
        $harness->assertSame(true, str_contains($html, 'save_expense_position_acknowledgement'));
        $harness->assertSame(true, str_contains($html, 'Confirmed at 2026-07-06 10:00:00 by Alex Example using the web_app.'));
        $harness->assertSame(true, str_contains($html, 'name="expense_position_acknowledgement" value="0"'));
        $harness->assertSame(true, str_contains($html, 'Revoke acknowledgement'));
        $harness->assertSame(false, str_contains($html, 'data-chicken-check="true"'));
        $harness->assertSame(false, str_contains($html, 'checked required'));

        $uncheckedHtml = $card->render(yearEndExpensesConfirmationCardContext([
            'available' => true,
            'accounting_period' => ['id' => 70, 'period_end' => '2025-12-31'],
            'totals' => [],
            'claimants' => [],
            'expense_position_acknowledged' => false,
        ]));

        $harness->assertSame(true, str_contains($uncheckedHtml, 'No expense claim balances were found'));
        $harness->assertSame(true, str_contains($uncheckedHtml, 'I acknowledge that the year-end expense claim position has been reviewed'));
        $harness->assertSame(true, str_contains($uncheckedHtml, 'disabled data-year-end-ack-submit'));
        $harness->assertSame(false, str_contains($uncheckedHtml, 'checked required'));
    });
});

function yearEndExpensesConfirmationCardContext(array $expenses): array
{
    return [
        'company' => [
            'id' => 33,
            'name' => 'Expenses Fixture Limited',
            'accounting_period_id' => 70,
            'settings' => [
                'default_currency_symbol' => '&#163;',
            ],
        ],
        'services' => [
            'yearEndExpensesConfirmation' => $expenses,
        ],
    ];
}
