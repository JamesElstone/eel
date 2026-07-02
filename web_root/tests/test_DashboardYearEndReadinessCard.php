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
    $harness->check(_dashboard_year_end_readinessCard::class, 'declares lightweight dashboard summary service', static function () use ($harness, $card): void {
        $services = $card->services();

        $harness->assertSame('year_end_dashboard_summary', (string)($services[0]['key'] ?? ''));
        $harness->assertSame(\eel_accounts\Service\YearEndChecklistService::class, (string)($services[0]['service'] ?? ''));
        $harness->assertSame('fetchDashboardSummary', (string)($services[0]['method'] ?? ''));
    });

    $harness->check(\eel_accounts\Service\YearEndChecklistService::class, 'dashboard summary includes only warning and fail checks as top issues', static function () use ($harness): void {
        $method = new ReflectionMethod(\eel_accounts\Service\YearEndChecklistService::class, 'topIssuesFromChecks');
        $method->setAccessible(true);
        $topIssues = $method->invoke(new \eel_accounts\Service\YearEndChecklistService(), [
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

    $harness->check(\eel_accounts\Service\YearEndChecklistService::class, 'dashboard summary reads persisted review and top issues', static function () use ($harness): void {
        dashboardYearEndReadinessRequireSchema($harness);

        InterfaceDB::beginTransaction();
        try {
            $fixture = dashboardYearEndReadinessCreateFixture('Persisted');
            dashboardYearEndReadinessInsertReview($fixture, 'needs_attention', '2026-07-02 12:00:00');

            $statuses = ['pass', 'warning', 'fail', 'warning', 'fail', 'warning', 'fail'];
            foreach ($statuses as $index => $status) {
                dashboardYearEndReadinessInsertCheck($fixture, $index, $status);
            }

            $summary = (new \eel_accounts\Service\YearEndChecklistService())->fetchDashboardSummary(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id']
            );

            $harness->assertSame(true, (bool)($summary['available'] ?? false));
            $harness->assertSame('needs_attention', (string)($summary['status'] ?? ''));
            $harness->assertSame((int)$fixture['accounting_period_id'], (int)($summary['accounting_period_id'] ?? 0));
            $harness->assertCount(5, (array)($summary['top_issues'] ?? []));
            $harness->assertSame('Persisted check 1', (string)($summary['top_issues'][0]['title'] ?? ''));
            $harness->assertSame(false, in_array('Persisted check 0', array_column((array)$summary['top_issues'], 'title'), true));
            $harness->assertSame(false, in_array('Persisted check 6', array_column((array)$summary['top_issues'], 'title'), true));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\YearEndChecklistService::class, 'dashboard summary bootstraps when no snapshot exists', static function () use ($harness): void {
        dashboardYearEndReadinessRequireSchema($harness);

        InterfaceDB::beginTransaction();
        try {
            $fixture = dashboardYearEndReadinessCreateFixture('Bootstrap');

            $summary = (new \eel_accounts\Service\YearEndChecklistService())->fetchDashboardSummary(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id']
            );

            $harness->assertSame(true, (bool)($summary['available'] ?? false));
            $harness->assertSame('not_started', (string)($summary['status'] ?? ''));
            $harness->assertSame((int)$fixture['accounting_period_id'], (int)($summary['accounting_period_id'] ?? 0));
            $harness->assertSame(true, count((array)($summary['top_issues'] ?? [])) > 0);
            $harness->assertSame('Source data present', (string)($summary['top_issues'][0]['title'] ?? ''));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
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
                    'action_url' => '?page=year-end&company_id=1&accounting_period_id=2',
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

function dashboardYearEndReadinessRequireSchema(GeneratedServiceClassTestHarness $harness): void
{
    foreach (['companies', 'accounting_periods', 'year_end_reviews', 'year_end_check_results'] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' table is not available.');
        }
    }
}

function dashboardYearEndReadinessCreateFixture(string $labelPrefix): array
{
    $marker = (string)random_int(100000, 999999);
    $companyId = (int)('71' . $marker);
    $accountingPeriodId = (int)('72' . $marker);

    InterfaceDB::prepareExecute(
        'INSERT INTO companies (id, company_name, company_number, is_active)
         VALUES (:id, :company_name, :company_number, 1)',
        [
            'id' => $companyId,
            'company_name' => 'Year End Dashboard ' . $labelPrefix . ' Fixture ' . $marker,
            'company_number' => 'YDS' . substr($marker, 0, 5),
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
         VALUES (:id, :company_id, :label, :period_start, :period_end)',
        [
            'id' => $accountingPeriodId,
            'company_id' => $companyId,
            'label' => $labelPrefix . ' FY ' . $marker,
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
        ]
    );

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
    ];
}

function dashboardYearEndReadinessInsertReview(array $fixture, string $status, string $lastRecalculatedAt): void
{
    InterfaceDB::prepareExecute(
        'INSERT INTO year_end_reviews (
            company_id,
            accounting_period_id,
            status,
            is_locked,
            last_recalculated_at,
            created_at,
            updated_at
         ) VALUES (
            :company_id,
            :accounting_period_id,
            :status,
            0,
            :last_recalculated_at,
            :created_at,
            :updated_at
         )',
        [
            'company_id' => (int)$fixture['company_id'],
            'accounting_period_id' => (int)$fixture['accounting_period_id'],
            'status' => $status,
            'last_recalculated_at' => $lastRecalculatedAt,
            'created_at' => $lastRecalculatedAt,
            'updated_at' => $lastRecalculatedAt,
        ]
    );
}

function dashboardYearEndReadinessInsertCheck(array $fixture, int $index, string $status): void
{
    InterfaceDB::prepareExecute(
        'INSERT INTO year_end_check_results (
            company_id,
            accounting_period_id,
            check_code,
            severity,
            status,
            title,
            detail_text,
            metric_value,
            action_url,
            calculated_at
         ) VALUES (
            :company_id,
            :accounting_period_id,
            :check_code,
            :severity,
            :status,
            :title,
            :detail_text,
            :metric_value,
            :action_url,
            :calculated_at
         )',
        [
            'company_id' => (int)$fixture['company_id'],
            'accounting_period_id' => (int)$fixture['accounting_period_id'],
            'check_code' => 'dashboard_test_' . $index,
            'severity' => $status === 'fail' ? 'fail' : 'warning',
            'status' => $status,
            'title' => 'Persisted check ' . $index,
            'detail_text' => 'Persisted detail ' . $index,
            'metric_value' => (string)$index,
            'action_url' => '?page=year-end',
            'calculated_at' => '2026-07-02 12:00:00',
        ]
    );
}
