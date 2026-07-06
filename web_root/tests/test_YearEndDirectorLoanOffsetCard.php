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

$harness->run(_year_end_director_loan_offsetCard::class, static function (GeneratedServiceClassTestHarness $harness, _year_end_director_loan_offsetCard $card): void {
    $harness->check(_year_end_director_loan_offsetCard::class, 'renders director loan acknowledgement details and revoke action', static function () use ($harness, $card): void {
        $html = $card->render(yearEndDirectorLoanOffsetCardContext([
            'available' => true,
            'accounting_period' => ['id' => 70, 'period_end' => '2025-12-31'],
            'asset_nominal' => ['id' => 3, 'code' => '1200', 'name' => 'Director Loan Asset'],
            'liability_nominal' => ['id' => 5, 'code' => '2100', 'name' => 'Director Loan Liability'],
            'asset_receivable' => 1000,
            'liability_payable' => 1500,
            'offset_amount' => 1000,
            'net_position' => 500,
            'net_position_label' => 'Company owes director',
            'posted_offset_amount' => 0,
            'offset_status' => 'missing',
            'offset_status_label' => 'Missing',
            'warnings' => [],
            'can_post' => true,
            'closing_balance_acknowledged' => true,
            'closing_balance_acknowledged_at' => '2026-07-06 10:00:00',
            'closing_balance_acknowledged_by' => 'Alex Example using the web_app',
        ]));

        $harness->assertSame(true, str_contains($html, 'save_director_loan_offset_acknowledgement'));
        $harness->assertSame(true, str_contains($html, 'Confirmed at 2026-07-06 10:00:00 by Alex Example using the web_app.'));
        $harness->assertSame(true, str_contains($html, 'name="director_loan_offset_acknowledgement" value="0"'));
        $harness->assertSame(true, str_contains($html, 'Revoke acknowledgement'));
        $harness->assertSame(false, str_contains($html, 'data-chicken-check="true"'));
        $harness->assertSame(false, str_contains($html, 'checked required'));
        $harness->assertSame(false, str_contains($html, 'Post Offset Journal'));
    });
});

function yearEndDirectorLoanOffsetCardContext(array $offset): array
{
    return [
        'company' => [
            'id' => 33,
            'name' => 'Director Loan Fixture Limited',
            'accounting_period_id' => 70,
            'settings' => [
                'default_currency_symbol' => '&#163;',
            ],
        ],
        'services' => [
            'directorLoanOffset' => $offset,
        ],
    ];
}
