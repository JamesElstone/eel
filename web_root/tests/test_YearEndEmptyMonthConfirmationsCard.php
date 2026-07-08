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
                'confirmation_basis' => 'initial_opening_month',
                'basis_label' => 'First-period initial month',
                'status' => 'available',
                'can_confirm' => true,
                'reason' => 'First-period initial-month no-activity confirmation is available.',
                'evidence' => [
                    'confirmation_basis' => 'initial_opening_month',
                    'confirmation_basis_label' => 'First-period initial month',
                    'incorporation_date' => '2022-09-14',
                    'activity_counts' => ['transactions' => 0, 'raw_rows' => 0, 'posted_journals' => 0],
                    'first_later_statement' => [
                        'chosen_txn_date' => '2022-10-05',
                        'original_filename' => 'october.csv',
                        'opening_balance' => 12.34,
                    ],
                ],
            ],
            [
                'month_start' => '2022-11-01',
                'month_label' => 'November 2022',
                'confirmation_basis' => 'no_activity_month',
                'basis_label' => 'No-activity month',
                'status' => 'available',
                'can_confirm' => true,
                'reason' => 'No-activity month confirmation is available.',
                'evidence' => [
                    'confirmation_basis' => 'no_activity_month',
                    'confirmation_basis_label' => 'No-activity month',
                    'activity_counts' => ['transactions' => 0, 'raw_rows' => 0, 'posted_journals' => 0],
                ],
            ],
            [
                'month_start' => '2022-12-01',
                'month_label' => 'December 2022',
                'confirmation_basis' => 'no_activity_month',
                'basis_label' => 'No-activity month',
                'status' => 'confirmed',
                'can_confirm' => false,
                'reason' => 'No-activity month confirmation is available.',
                'confirmation' => [
                    'notes' => 'Bank account was not open.',
                    'confirmed_at' => '2026-07-02 10:00:00',
                    'confirmed_by' => 'unit_test',
                ],
                'evidence' => [
                    'confirmation_basis' => 'no_activity_month',
                    'confirmation_basis_label' => 'No-activity month',
                    'activity_counts' => ['transactions' => 0, 'raw_rows' => 0, 'posted_journals' => 0],
                ],
            ],
        ]));

        $harness->assertSame(true, str_contains($html, 'Empty Month Confirmations') || str_contains($card->title(), 'Empty Month'));
        $harness->assertSame(true, str_contains($html, 'First-period initial month'));
        $harness->assertSame(true, str_contains($html, 'No-activity month'));
        $harness->assertSame(false, str_contains($html, 'Needs confirmation'));
        $harness->assertSame(false, str_contains($html, '<section class="panel-soft settings-stack">'));
        $harness->assertSame(true, str_contains($html, '<h3 class="card-title">No activity in Month: 09/2022</h3>'));
        $harness->assertSame(true, str_contains($html, '<h3 class="card-title">No activity in Month: 11/2022</h3>'));
        $harness->assertSame(true, str_contains($html, '<div class="summary-grid four">'));
        $harness->assertSame(true, str_contains($html, '<section class="panel-soft warn full settings-stack">'));
        $harness->assertSame(true, str_contains($html, 'value="confirm_empty_months"'));
        $harness->assertSame(false, str_contains($html, 'value="confirm_empty_month"'));
        $harness->assertSame(1, substr_count($html, 'Approve for Year End'));
        $harness->assertSame(true, str_contains($html, 'name="month_start[]" value="2022-09-01"'));
        $harness->assertSame(true, str_contains($html, 'name="month_start[]" value="2022-11-01"'));
        $harness->assertSame(true, str_contains($html, 'empty-month confirmations for 09/2022 and 11/2022'));
        $harness->assertSame(true, str_contains($html, '<div class="eyebrow">Year End Confirmation</div>'));
        $harness->assertSame(true, str_contains($html, '<section class="panel-soft success settings-stack">'));
        $harness->assertSame(true, str_contains($html, '<div class="summary-value">Bank account was not open.</div>'));
        $harness->assertSame(true, str_contains($html, '<div class="stat-foot">Approved at 2026-07-02 10:00:00 by unit_test.</div>'));
        $harness->assertSame(true, str_contains($html, 'Confirmed'));
        $harness->assertSame(true, str_contains($html, 'revoke_empty_month'));
        $harness->assertSame(true, str_contains($html, '<div class="year-end-related-workflow">'));
        $harness->assertSame(true, str_contains($html, 'Bank account was not open.'));
        $harness->assertSame(true, str_contains($html, '$ 12.34'));
        $harness->assertSame(true, str_contains($html, 'Raw rows'));
    });

    $harness->check(_year_end_empty_month_confirmationsCard::class, 'renders dynamic empty-state wording from service context', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'id' => 41,
                'accounting_period_id' => 90,
            ],
            'services' => [
                'emptyMonthConfirmations' => [
                    'available' => true,
                    'empty_message' => 'No initial/opening or ordinary empty-month confirmations are available for this accounting period.',
                    'months' => [],
                ],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'No initial/opening or ordinary empty-month confirmations'));
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
