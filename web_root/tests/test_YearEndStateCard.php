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
    $harness->check(_year_end_stateCard::class, 'uses page shared checklist context and backup status service', static function () use ($harness, $card): void {
        $services = $card->services();
        $harness->assertSame('backup_status', (string)($services[0]['key'] ?? ''));
        $harness->assertSame(\eel_accounts\Service\DatabaseBackupService::class, (string)($services[0]['service'] ?? ''));

        $html = $card->render(yearEndStateCardContext(false));

        $harness->assertSame(true, str_contains($html, 'Ready to Close and Lock'));
        $harness->assertSame(true, str_contains($html, 'All blocking checks are clear. You can now run the year-end close tasks and lock this accounting period.'));
        $harness->assertSame(true, str_contains($html, 'Refresh Year-End Checklist'));
        $harness->assertSame(true, str_contains($html, 'Run Year-End Close and Lock'));
        $harness->assertSame(true, str_contains($html, '<div class="summary-grid">'));
        $harness->assertSame(true, str_contains($html, '<div class="summary-card">'));
        $harness->assertSame(true, str_contains($html, '<div class="summary-label">Status</div>'));
        $harness->assertSame(true, str_contains($html, '<div class="summary-label">Checklist last changed</div>'));
        $harness->assertSame(true, str_contains($html, 'Latest backup'));
        $harness->assertSame(true, str_contains($html, '2026-01-01 10:05:00'));
        $harness->assertSame(false, str_contains($html, '<div class="summary-card warn">
                        <div class="summary-label">Latest backup</div>'));
        $harness->assertSame(true, str_contains($html, 'name="card_action" value="Backup"'));
        $harness->assertSame(true, str_contains($html, 'name="intent" value="create_database_backup"'));
        $harness->assertSame(true, str_contains($html, 'name="csrf_token"'));
        $harness->assertSame(true, str_contains($html, 'Backup'));
        $harness->assertSame(true, str_contains($html, 'data-year-end-state-card="true"'));
        $harness->assertSame(true, str_contains($html, 'data-year-end-state-form="true"'));
        $harness->assertSame(true, str_contains($html, 'data-year-end-state-submit="true"'));
        $harness->assertSame(true, str_contains($html, 'data-year-end-state-running-label="Running Year-End Close...'));
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

    $harness->check(_year_end_stateCard::class, 'disables lock when latest backup is older than checklist', static function () use ($harness, $card): void {
        $context = yearEndStateCardContext(false);
        $context['services']['backup_status']['recent_backups'][0]['created_at'] = '2026-01-01 09:59:59';

        $html = $card->render($context);

        $harness->assertSame(true, str_contains($html, 'Latest backup'));
        $harness->assertSame(true, str_contains($html, '2026-01-01 09:59:59'));
        $harness->assertSame(true, str_contains($html, '<div class="summary-card warn">
                        <div class="summary-label">Latest backup</div>'));
        $harness->assertSame(true, str_contains($html, 'Create a new database backup because the checklist changed after the latest backup.'));
        $harness->assertSame(true, str_contains($html, 'disabled title="Create a fresh database backup after the latest checklist change before running the year-end close and locking this accounting period."'));
    });

    $harness->check(_year_end_stateCard::class, 'disables lock when no backup is available', static function () use ($harness, $card): void {
        $context = yearEndStateCardContext(false);
        $context['services']['backup_status']['recent_backups'] = [];

        $html = $card->render($context);

        $harness->assertSame(true, str_contains($html, 'No backup available'));
        $harness->assertSame(true, str_contains($html, '<div class="summary-card warn">
                        <div class="summary-label">Latest backup</div>'));
        $harness->assertSame(true, str_contains($html, 'Create a database backup before running the year-end close.'));
        $harness->assertSame(true, str_contains($html, 'disabled title="Create a fresh database backup after the latest checklist change before running the year-end close and locking this accounting period."'));
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
        'page' => [
            'csrf_token' => 'test-csrf',
            'page_cards' => ['year_end_state'],
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
        'services' => [
            'backup_status' => [
                'recent_backups' => [
                    [
                        'filename' => 'fixture.sql.zip',
                        'created_at' => '2026-01-01 10:05:00',
                        'size_bytes' => 1024,
                    ],
                ],
            ],
        ],
    ];
}
