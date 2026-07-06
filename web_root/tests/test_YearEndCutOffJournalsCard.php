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

$harness->run(_journal_cut_offsCard::class, static function (GeneratedServiceClassTestHarness $harness, _journal_cut_offsCard $card): void {
    $harness->check(_journal_cut_offsCard::class, 'renders cut-off journal posting without review acknowledgement action', static function () use ($harness, $card): void {
        $html = $card->render(yearEndCutOffJournalsCardContext());

        $harness->assertSame('Use this for known year-end items that are not fully represented by bank CSVs or expense claim evidence in the correct period.', $card->helper([]));
        $harness->assertSame(true, str_contains($html, 'Post Cut-off Journal'));
        $harness->assertSame(true, str_contains($html, 'Posted cut-off journals'));
        $harness->assertSame(3, substr_count($html, 'class="panel-soft settings-stack"'));
        $harness->assertSame(false, str_contains($html, 'Use this for known year-end items'));
        $harness->assertSame(false, str_contains($html, 'name="intent" value="acknowledge_review_check"'));
        $harness->assertSame(false, str_contains($html, 'name="check_code" value="cut_off_journals_review"'));
        $harness->assertSame(false, str_contains($html, 'Mark reviewed'));
        $harness->assertSame(false, str_contains($html, 'Mark cut-off journals review complete'));
        $harness->assertSame(false, str_contains($html, 'Reopen review'));
    });
});

$harness->run(_journal_cut_off_confirmationCard::class, static function (GeneratedServiceClassTestHarness $harness, _journal_cut_off_confirmationCard $card): void {
    $harness->check(_journal_cut_off_confirmationCard::class, 'renders local cut-off review acknowledgement action', static function () use ($harness, $card): void {
        $services = $card->services();

        $harness->assertSame(\eel_accounts\Service\YearEndAdjustmentService::class, (string)($services[0]['service'] ?? ''));
        $harness->assertSame('fetchContext', (string)($services[0]['method'] ?? ''));
        $harness->assertSame(\eel_accounts\Service\YearEndChecklistService::class, (string)($services[1]['service'] ?? ''));
        $harness->assertSame('fetchReviewAcknowledgement', (string)($services[1]['method'] ?? ''));
        $harness->assertSame('cut_off_journals_review', (string)(($services[1]['params'] ?? [])['checkCode'] ?? ''));

        $html = $card->render(yearEndJournalCutOffCardContext(null));

        $harness->assertSame(true, strpos($html, 'Posted cut-off journals') < strpos($html, '<div class="eyebrow">Approval</div>'));
        $harness->assertSame(true, str_contains($html, '<th>Date</th><th>Description</th><th>Type</th><th>Lines</th>'));
        $harness->assertSame(true, str_contains($html, 'Accrual fixture'));
        $harness->assertSame(true, str_contains($html, '<td>2</td>'));
        $harness->assertSame(true, str_contains($html, 'name="intent" value="acknowledge_review_check"'));
        $harness->assertSame(true, str_contains($html, 'name="check_code" value="cut_off_journals_review"'));
        $harness->assertSame(true, str_contains($html, '<section class="panel-soft warn full settings-stack">'));
        $harness->assertSame(true, str_contains($html, '<div class="eyebrow">Approval</div>'));
        $harness->assertSame(true, str_contains($html, 'class="form-grid"'));
        $harness->assertSame(true, str_contains($html, 'name="review_acknowledgement_note"'));
        $harness->assertSame(true, str_contains($html, 'Approve for Year End'));
        $harness->assertSame(false, str_contains($html, 'Mark cut-off journals review complete'));
        $harness->assertSame(false, str_contains($html, 'Post Cut-off Journal'));
        $harness->assertSame(false, str_contains($html, 'Revoke approval'));
    });

    $harness->check(_journal_cut_off_confirmationCard::class, 'renders local cut-off review reopen action after acknowledgement', static function () use ($harness, $card): void {
        $html = $card->render(yearEndJournalCutOffCardContext([
            'acknowledged_at' => '2026-07-06 10:00:00',
            'acknowledged_by' => 'James using the web_app',
            'note' => 'Reviewed the year-end cut-off position.',
        ]));

        $harness->assertSame(true, str_contains($html, 'name="intent" value="reopen_review_check"'));
        $harness->assertSame(true, str_contains($html, '<section class="panel-soft success settings-stack">'));
        $harness->assertSame(true, str_contains($html, 'Reviewed the year-end cut-off position.'));
        $harness->assertSame(true, str_contains($html, 'Approved at 2026-07-06 10:00:00 by James using the web_app.'));
        $harness->assertSame(true, str_contains($html, 'Revoke approval'));
        $harness->assertSame(false, str_contains($html, 'name="intent" value="acknowledge_review_check"'));
        $harness->assertSame(false, str_contains($html, 'Mark cut-off journals review complete'));
    });
});

function yearEndCutOffJournalsCardContext(): array
{
    return [
        'company' => [
            'id' => 12,
            'accounting_period_id' => 34,
        ],
        'services' => [
            'cutOffJournals' => [
                'available' => true,
                'accounting_period' => [
                    'id' => 34,
                    'period_end' => '2026-03-31',
                ],
                'nominals' => [
                    [
                        'id' => 56,
                        'code' => '7000',
                        'name' => 'Accruals',
                    ],
                ],
                'adjustments' => [
                    [
                        'journal_date' => '2026-03-31',
                        'description' => 'Accrual fixture',
                        'journal_tag' => 'year_end_adjustment',
                        'lines' => [
                            ['id' => 1],
                            ['id' => 2],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

function yearEndJournalCutOffCardContext(?array $acknowledgement): array
{
    return [
        'company' => [
            'id' => 12,
            'accounting_period_id' => 34,
        ],
        'services' => [
            'cutOffJournals' => [
                'adjustments' => [
                    [
                        'journal_date' => '2026-03-31',
                        'description' => 'Accrual fixture',
                        'journal_tag' => 'year_end_adjustment',
                        'lines' => [
                            ['id' => 1],
                            ['id' => 2],
                        ],
                    ],
                ],
            ],
            'journalCutOffAcknowledgement' => $acknowledgement,
        ],
    ];
}
