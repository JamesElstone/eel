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

$harness->run(_year_end_empty_month_confirmationsCard::class, static function (
    GeneratedServiceClassTestHarness $harness,
    _year_end_empty_month_confirmationsCard $card
): void {
    $harness->check(_year_end_empty_month_confirmationsCard::class, 'declares empty month confirmation service', static function () use ($harness, $card): void {
        $services = $card->services();
        $service = (array)($services[0] ?? []);

        $harness->assertSame('emptyMonthConfirmations', (string)($service['key'] ?? ''));
        $harness->assertSame(\eel_accounts\Service\EmptyMonthConfirmationService::class, (string)($service['service'] ?? ''));
        $harness->assertSame('fetchContext', (string)($service['method'] ?? ''));
        $params = (array)($service['params'] ?? []);
        $harness->assertSame(':company.id', (string)($params['companyId'] ?? ''));
        $harness->assertSame(':company.accounting_period_id', (string)($params['accountingPeriodId'] ?? ''));
    });

    $harness->check(_year_end_empty_month_confirmationsCard::class, 'renders confirm and revoke flows', static function () use ($harness, $card): void {
        $html = $card->render(yearEndEmptyMonthConfirmationsCardContext([
            [
                'month_start' => '2022-09-01',
                'month_label' => 'September 2022',
                'status' => 'available',
                'can_confirm' => true,
                'reason' => 'First-month no-activity confirmation is available.',
                'evidence' => [
                    'incorporation_date' => '2022-09-14',
                    'activity_counts' => ['transactions' => 0, 'uploads' => 0, 'posted_journals' => 0],
                    'first_later_statement' => [
                        'chosen_txn_date' => '2022-10-05',
                        'original_filename' => 'october.csv',
                        'opening_balance' => 12.34,
                    ],
                ],
            ],
            [
                'month_start' => '2022-09-01',
                'month_label' => 'September 2022',
                'status' => 'confirmed',
                'can_confirm' => false,
                'reason' => 'First-month no-activity confirmation is available.',
                'confirmation' => [
                    'notes' => 'Bank account was not open.',
                    'confirmed_at' => '2026-07-02 10:00:00',
                    'confirmed_by' => 'unit_test',
                ],
                'evidence' => [
                    'incorporation_date' => '2022-09-14',
                    'activity_counts' => ['transactions' => 0, 'uploads' => 0, 'posted_journals' => 0],
                    'first_later_statement' => ['opening_balance' => 56.78],
                ],
            ],
        ]));

        $harness->assertSame(true, str_contains($html, 'Empty Month Confirmations') || str_contains($card->title(), 'Empty Month'));
        $harness->assertSame(true, str_contains($html, 'Needs confirmation'));
        $harness->assertSame(true, str_contains($html, 'confirm_empty_month'));
        $harness->assertSame(true, str_contains($html, 'Confirm no financial activity'));
        $harness->assertSame(true, str_contains($html, 'Confirmed'));
        $harness->assertSame(true, str_contains($html, 'revoke_empty_month'));
        $harness->assertSame(true, str_contains($html, 'Bank account was not open.'));
        $harness->assertSame(true, str_contains($html, '$ 12.34'));
        $harness->assertSame(true, str_contains($html, '$ 56.78'));
    });
});

function yearEndEmptyMonthConfirmationsCardContext(array $months): array
{
    return [
        'company' => [
            'id' => 41,
            'name' => 'Empty Month Fixture Limited',
            'accounting_period_id' => 90,
            'settings' => [
                'default_currency_symbol' => '&#36;',
            ],
        ],
        'services' => [
            'emptyMonthConfirmations' => [
                'available' => true,
                'months' => $months,
            ],
        ],
    ];
}
