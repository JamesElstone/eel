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

$harness->run(_year_end_prepayment_approvalsCard::class, static function (GeneratedServiceClassTestHarness $harness, _year_end_prepayment_approvalsCard $card): void {
    $harness->check(_year_end_prepayment_approvalsCard::class, 'renders prepayment acknowledgement action and revoke state', static function () use ($harness, $card): void {
        $services = $card->services();

        $harness->assertSame(\eel_accounts\Service\PrepaymentReviewService::class, (string)($services[0]['service'] ?? ''));
        $harness->assertSame('fetchContext', (string)($services[0]['method'] ?? ''));
        $harness->assertSame(\eel_accounts\Service\YearEndChecklistService::class, (string)($services[1]['service'] ?? ''));
        $harness->assertSame('fetchReviewAcknowledgement', (string)($services[1]['method'] ?? ''));
        $harness->assertSame('prepayment_approvals', (string)(($services[1]['params'] ?? [])['checkCode'] ?? ''));

        $uncheckedHtml = $card->render(yearEndPrepaymentApprovalsCardContext(null));

        $harness->assertSame(true, str_contains($uncheckedHtml, 'name="_table_export_prepare" value="csv"'));
        $harness->assertSame(true, str_contains($uncheckedHtml, 'Prepayments 1-10 of 11'));
        $harness->assertSame(true, str_contains($uncheckedHtml, 'Prepayment fixture 10'));
        $harness->assertSame(false, str_contains($uncheckedHtml, 'Prepayment fixture 11'));
        $harness->assertSame(true, str_contains($uncheckedHtml, 'name="intent" value="acknowledge_review_check"'));
        $harness->assertSame(true, str_contains($uncheckedHtml, 'name="check_code" value="prepayment_approvals"'));
        $harness->assertSame(true, str_contains($uncheckedHtml, 'I acknowledge that the year-end prepayment position has been reviewed'));
        $harness->assertSame(true, str_contains($uncheckedHtml, 'disabled data-year-end-ack-submit'));
        $harness->assertSame(false, str_contains($uncheckedHtml, 'checked required'));

        $tables = $card->tables(yearEndPrepaymentApprovalsCardContext(null));
        $harness->assertCount(1, $tables);
        $harness->assertTrue($tables[0] instanceof TableFramework);
        $csv = $tables[0]->exportCsv();
        $harness->assertSame(true, str_contains($csv, 'Prepayment fixture 11'));
        $harness->assertSame(false, str_contains($csv, 'Not prepaid fixture'));

        $acknowledgedHtml = $card->render(yearEndPrepaymentApprovalsCardContext([
            'acknowledged_at' => '2026-07-06 10:00:00',
            'acknowledged_by' => 'Alex Example using the web_app',
        ]));

        $harness->assertSame(true, str_contains($acknowledgedHtml, 'name="intent" value="reopen_review_check"'));
        $harness->assertSame(true, str_contains($acknowledgedHtml, 'Approved at 2026-07-06 10:00:00 by Alex Example using the web_app.'));
        $harness->assertSame(true, str_contains($acknowledgedHtml, 'Revoke approval'));
        $harness->assertSame(false, str_contains($acknowledgedHtml, 'name="intent" value="acknowledge_review_check"'));

        $blockedHtml = $card->render(yearEndPrepaymentApprovalsCardContext(null, 2));
        $harness->assertSame(true, str_contains($blockedHtml, 'Incomplete: 2.'));
        $harness->assertSame(true, str_contains($blockedHtml, 'data-year-end-ack-checkbox disabled'));
        $harness->assertSame(false, str_contains($blockedHtml, 'disabled data-year-end-ack-submit'));
    });
});

function yearEndPrepaymentApprovalsCardContext(?array $acknowledgement, int $pendingCount = 0): array
{
    $items = [];
    for ($index = 1; $index <= 11; $index++) {
        $items[] = [
            'source_type' => 'transaction',
            'source_id' => $index,
            'source_date' => '2026-03-' . str_pad((string)$index, 2, '0', STR_PAD_LEFT),
            'nominal_code' => '400' . $index,
            'nominal_name' => 'Prepayment Nominal',
            'description' => 'Prepayment fixture ' . $index,
            'amount' => 100 + $index,
            'review' => [
                'status' => 'prepaid',
                'service_start_date' => '2026-04-01',
                'service_end_date' => '2027-03-31',
                'reviewed_by' => 'Alex Example using the web_app',
                'reviewed_at' => '2026-07-06 10:00:00',
            ],
        ];
    }
    $items[] = [
        'source_type' => 'transaction',
        'source_id' => 99,
        'source_date' => '2026-03-31',
        'nominal_code' => '4999',
        'nominal_name' => 'Prepayment Nominal',
        'description' => 'Not prepaid fixture',
        'amount' => 12.34,
        'review' => [
            'status' => 'not_prepaid',
        ],
    ];

    return [
        'page' => [
            'page_id' => 'year_end',
            'page_cards' => ['year_end_prepayment_approvals'],
        ],
        'company' => [
            'id' => 33,
            'accounting_period_id' => 70,
            'settings' => [
                'default_currency_symbol' => '&#163;',
            ],
        ],
        'services' => [
            'prepaymentsReview' => [
                'available' => true,
                'items' => $items,
                'prepaid_count' => 11,
                'pending_count' => $pendingCount,
            ],
            'prepaymentApproval' => $acknowledgement,
        ],
    ];
}
