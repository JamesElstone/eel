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
$harness->run(_dashboard_year_end_readinessCard::class, static function (GeneratedServiceClassTestHarness $harness, _dashboard_year_end_readinessCard $card): void {
    $harness->check(YearEndChecklistService::class, 'dashboard summary includes only warning and fail checks as top issues', static function () use ($harness): void {
        $method = new ReflectionMethod(YearEndChecklistService::class, 'topIssuesFromChecks');
        $method->setAccessible(true);
        $topIssues = $method->invoke(new YearEndChecklistService(), [
            [
                'title' => 'Period exists',
                'status' => 'pass',
                'detail_text' => 'The selected accounting period was found.',
                'metric_value' => '01/04/2025 to 31/03/2026',
            ],
            [
                'title' => 'Source data present',
                'status' => 'fail',
                'detail_text' => 'No committed bank transactions or posted journals were found in this period.',
                'metric_value' => '0',
            ],
        ]);

        $harness->assertCount(1, $topIssues);
        $harness->assertSame('Source data present', $topIssues[0]['title'] ?? '');
        $harness->assertSame('No committed bank transactions or posted journals were found in this period.', $topIssues[0]['detail'] ?? '');
        $harness->assertSame('0', $topIssues[0]['metric_value'] ?? '');
    });

    $harness->check(_dashboard_year_end_readinessCard::class, 'renders year end summary as stat cards', static function () use ($harness, $card): void {
        $html = $card->render([
            'services' => [
                'year_end_dashboard_summary' => [
                    'status' => 'needs_attention',
                    'period_label' => '01/04/2025 to 31/03/2026',
                    'top_issues' => [
                        [
                            'title' => 'Uncategorised transactions',
                            'detail' => '4 transactions need review.',
                            'metric_value' => '4',
                            'status' => 'fail',
                        ],
                        [
                            'title' => 'Source data present',
                            'detail' => 'No committed bank transactions or posted journals were found in this period.',
                            'metric_value' => '0',
                            'status' => 'fail',
                        ],
                        [
                            'title' => 'Posted-only period integrity',
                            'detail' => 'This period is still open for posting changes.',
                            'metric_value' => 'Unlocked',
                            'status' => 'warning',
                        ],
                    ],
                    'action_url' => '?page=year-end&company_id=1&tax_year_id=2',
                ],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'grid-stats'));
        $harness->assertSame(true, str_contains($html, 'stat-card'));
        $harness->assertSame(true, str_contains($html, 'stat-card-status-bad'));
        $harness->assertSame(true, str_contains($html, 'stat-card-status-warn'));
        $harness->assertSame(true, str_contains($html, 'Year end status'));
        $harness->assertSame(true, str_contains($html, 'Missing'));
        $harness->assertSame(false, str_contains($html, 'Needs attention'));
        $harness->assertSame(true, str_contains($html, 'Issues surfaced'));
        $harness->assertSame(true, str_contains($html, 'Uncategorised transactions'));
        $harness->assertSame(true, str_contains($html, '4 transactions need review.'));
        $harness->assertSame(false, str_contains($html, '4 - 4 transactions need review.'));
        $harness->assertSame(true, str_contains($html, 'No committed bank transactions or posted journals were found in this period.'));
        $harness->assertSame(true, str_contains($html, 'Posted-only period integrity'));
        $harness->assertSame(true, str_contains($html, '<div class="stat-value">Unlocked</div>'));
        $harness->assertSame(true, str_contains($html, 'This period is still open for posting changes.'));
        $harness->assertSame(false, str_contains($html, 'Unlocked - This period is still open for posting changes.'));
        $harness->assertSame(false, str_contains($html, '<div class="list">'));
    });

    $harness->check(_dashboard_year_end_readinessCard::class, 'renders empty summary with next step stat card', static function () use ($harness, $card): void {
        $html = $card->render([
            'page' => [
                'year_end_dashboard_summary' => [
                    'status' => 'not_started',
                    'period_label' => '',
                    'top_issues' => [],
                    'action_url' => '?page=year-end',
                ],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'No accounting period selected'));
        $harness->assertSame(true, str_contains($html, 'stat-card-status-ok'));
        $harness->assertSame(true, str_contains($html, 'stat-card-status-warn'));
        $harness->assertSame(true, str_contains($html, 'Next step'));
        $harness->assertSame(true, str_contains($html, 'Open the Year End To Do page to calculate the detailed checklist.'));
    });
});
