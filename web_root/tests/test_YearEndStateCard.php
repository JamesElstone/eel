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

$harness->run(_year_end_stateCard::class, static function (GeneratedServiceClassTestHarness $harness, _year_end_stateCard $card): void {
    $harness->check(_year_end_stateCard::class, 'uses page shared checklist context without declaring card service', static function () use ($harness, $card): void {
        $harness->assertSame([], $card->services());

        $html = $card->render(yearEndStateCardContext(false));

        $harness->assertSame(true, str_contains($html, 'Ready to Close and Lock'));
        $harness->assertSame(true, str_contains($html, 'All blocking checks are clear. You can now run the year-end close tasks and lock this accounting period.'));
        $harness->assertSame(true, str_contains($html, 'Refresh Year-End Checklist'));
        $harness->assertSame(true, str_contains($html, 'Run Year-End Close and Lock'));
        $harness->assertSame(true, str_contains($html, 'intent" value="lock_period"'));
        $harness->assertSame(false, str_contains($html, 'Lock Period'));
        $harness->assertSame(false, str_contains($html, 'disabled title="Resolve year-end checklist warnings'));
    });

    $harness->check(_year_end_stateCard::class, 'disables lock when shared checklist has warnings', static function () use ($harness, $card): void {
        $html = $card->render(yearEndStateCardContext(true));

        $harness->assertSame(true, str_contains($html, 'Run Year-End Close and Lock'));
        $harness->assertSame(true, str_contains($html, 'Complete or acknowledge the remaining checklist items before running the year-end close and lock.'));
        $harness->assertSame(true, str_contains($html, 'disabled title="Resolve year-end checklist warnings before running the year-end close and locking this accounting period."'));
    });

    $harness->check(_year_end_stateCard::class, 'keeps unlock enabled when locked even if shared warnings remain', static function () use ($harness, $card): void {
        $context = yearEndStateCardContext(true);
        $context['year_end']['checklist']['review']['is_locked'] = true;

        $html = $card->render($context);

        $harness->assertSame(true, str_contains($html, 'Unlock Period'));
        $harness->assertSame(true, str_contains($html, 'intent" value="unlock_period"'));
        $harness->assertSame(false, str_contains($html, 'disabled title="Resolve year-end checklist warnings'));
    });
});

function yearEndStateCardContext(bool $hasWarnings): array
{
    return [
        'company' => [
            'id' => 33,
            'name' => 'Year End Fixture Limited',
            'accounting_period_id' => 70,
        ],
        'year_end' => [
            'checklist_has_warnings' => $hasWarnings,
            'checklist' => [
                'overall_status' => $hasWarnings ? 'in_progress' : 'ready_for_review',
                'last_recalculated_at' => '2026-01-01 10:00:00',
                'accounting_period' => ['id' => 70],
                'review' => ['is_locked' => false, 'review_notes' => ''],
                'month_tiles' => [],
                'sections' => [],
                'checks_flat' => $hasWarnings ? [
                    [
                        'check_code' => 'fixture_warning',
                        'status' => 'warning',
                    ],
                ] : [],
            ],
        ],
        'services' => [],
    ];
}
