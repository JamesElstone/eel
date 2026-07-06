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

$harness->run(_cut_off_journalsCard::class, static function (GeneratedServiceClassTestHarness $harness, _cut_off_journalsCard $card): void {
    $harness->check(_cut_off_journalsCard::class, 'renders local cut-off review acknowledgement action', static function () use ($harness, $card): void {
        $html = $card->render(yearEndCutOffJournalsCardContext(null));

        $harness->assertSame(true, str_contains($html, 'Post Cut-off Journal'));
        $harness->assertSame(true, str_contains($html, 'Posted cut-off journals'));
        $harness->assertSame(true, str_contains($html, 'name="intent" value="acknowledge_review_check"'));
        $harness->assertSame(true, str_contains($html, 'name="check_code" value="cut_off_journals_review"'));
        $harness->assertSame(true, str_contains($html, 'Mark reviewed'));
        $harness->assertSame(true, str_contains($html, 'Mark cut-off journals review complete'));
        $harness->assertSame(false, str_contains($html, 'Reopen review'));
    });

    $harness->check(_cut_off_journalsCard::class, 'renders local cut-off review reopen action after acknowledgement', static function () use ($harness, $card): void {
        $html = $card->render(yearEndCutOffJournalsCardContext([
            'acknowledged_at' => '2026-07-06 10:00:00',
            'acknowledged_by' => 'James using the web_app',
            'note' => null,
        ]));

        $harness->assertSame(true, str_contains($html, 'name="intent" value="reopen_review_check"'));
        $harness->assertSame(true, str_contains($html, 'Reviewed at 2026-07-06 10:00:00 by James using the web_app.'));
        $harness->assertSame(true, str_contains($html, 'Reopen review'));
        $harness->assertSame(false, str_contains($html, 'name="intent" value="acknowledge_review_check"'));
        $harness->assertSame(false, str_contains($html, 'Mark cut-off journals review complete'));
    });
});

function yearEndCutOffJournalsCardContext(?array $acknowledgement): array
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
                'review_acknowledgement' => $acknowledgement,
            ],
        ],
    ];
}
