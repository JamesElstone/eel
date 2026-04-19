<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _dashboard_year_end_readinessCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'dashboard_year_end_readiness';
    }

    public function services(): array
    {
        return [];
    }

    public function invalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $summary = (array)(($context['page'] ?? [])['year_end_dashboard_summary'] ?? []);
        $status = (string)($summary['status'] ?? 'not_started');
        $badgeClass = match ($status) {
            'ready_for_review' => 'success',
            'needs_attention' => 'danger',
            'in_progress' => 'warning',
            'locked' => 'info',
            default => 'info',
        };

        $issuesHtml = '';
        foreach ((array)($summary['top_issues'] ?? []) as $issue) {
            $issuesHtml .= '<div class="list-item">
                <strong>' . HelperFramework::escape((string)($issue['title'] ?? '')) . '</strong>
                <span>' . HelperFramework::escape((string)($issue['detail'] ?? '')) . '</span>
            </div>';
        }

        if ($issuesHtml === '') {
            $issuesHtml = '<div class="list-item">
                <strong>No issues surfaced yet</strong>
                <span>Open the Year End To Do page to calculate the detailed checklist.</span>
            </div>';
        }

        return '<div class="card">
            <div class="card-header">
                <h2 class="card-title">Year End Readiness</h2>
                <span class="badge ' . HelperFramework::escape($badgeClass) . '">' . HelperFramework::escape(HelperFramework::labelFromKey($status, '_')) . '</span>
            </div>
            <div class="card-body">
                <div class="helper" style="margin-bottom: 12px;">' . HelperFramework::escape((string)($summary['period_label'] ?? 'No accounting period selected')) . '</div>
                <div class="list">' . $issuesHtml . '</div>
                <div style="margin-top: 14px;">
                    <a class="button primary" href="' . HelperFramework::escape((string)($summary['action_url'] ?? '?page=year-end')) . '">Open Year End To Do</a>
                </div>
            </div>
        </div>';
    }
}
