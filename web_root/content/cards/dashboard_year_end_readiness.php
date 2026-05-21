<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _dashboard_year_end_readinessCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'dashboard_year_end_readiness';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'year_end_dashboard_summary',
                'service' => YearEndChecklistService::class,
                'method' => 'fetchDashboardSummary',
                'params' => [
                    'companyId' => ':company.id',
                    'taxYearId' => ':company.tax_year_id',
                ],
            ],
        ];
    }

    public function helper(array $context): string
    {
        return (string)(($context['services']['year_end_dashboard_summary']['period_label'] ?? null) ?? 'No accounting period selected');
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $summary = (array)(($context['services'] ?? [])['year_end_dashboard_summary'] ?? (($context['page'] ?? [])['year_end_dashboard_summary'] ?? []));
        $status = (string)($summary['status'] ?? 'not_started');

        $issuesHtml = '';
        foreach ((array)($summary['top_issues'] ?? []) as $issue) {
            $issuesHtml .= '
                <div class="list-item">
                    <strong>' . HelperFramework::escape((string)($issue['title'] ?? '')) . '</strong>
                    <span>' . HelperFramework::escape((string)($issue['detail'] ?? '')) . '</span>
                </div>
            ';
        }

        if ($issuesHtml === '') {
            $issuesHtml = '
                <div class="list-item">
                    <strong>No issues surfaced yet</strong>
                    <span>Open the Year End To Do page to calculate the detailed checklist.</span>
                </div>
            ';
        }

        return '
            <div class="panel-soft">
                <div class="list">' . $issuesHtml . '</div>
                <div class="mini-field">
                    <a class="button primary" href="' . HelperFramework::escape((string)($summary['action_url'] ?? '?page=year-end')) . '">Open Year End To Do</a>
                </div>
            </div>
        ';
    }
}
