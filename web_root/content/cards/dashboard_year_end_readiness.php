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
                'service' => \eel_accounts\Service\YearEndChecklistService::class,
                'method' => 'fetchDashboardSummary',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
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
        $topIssues = (array)($summary['top_issues'] ?? []);
        $periodLabel = (string)(($summary['period_label'] ?? '') ?: 'No accounting period selected');
        $company = (array)($context['company'] ?? []);
        $workflowButton = \eel_accounts\Renderer\WorkflowHandoffRenderer::fromUrl(
            (string)($summary['action_url'] ?? '?page=year-end'),
            'Open Year End To Do',
            [
                'company_id' => (int)($company['id'] ?? 0),
                'accounting_period_id' => (int)($company['accounting_period_id'] ?? 0),
            ],
            'button primary'
        );

        $statsHtml = $this->statCard(
            'Year end status',
            $this->statusLabel($status),
            $periodLabel,
            $this->statusCardClass($status)
        );
        $statsHtml .= $this->statCard(
            'Issues surfaced',
            (string)count($topIssues),
            $topIssues === [] ? 'No blocking checks are currently surfaced.' : 'Top checks needing attention.',
            $this->issuesCardClass($topIssues)
        );

        foreach ($topIssues as $issue) {
            if (!is_array($issue)) {
                continue;
            }

            $statsHtml .= $this->statCard(
                (string)($issue['title'] ?? 'Year end check'),
                $this->issueValue($issue),
                $this->issueFoot($issue),
                $this->statusCardClass((string)($issue['status'] ?? 'warning'))
            );
        }

        if ($topIssues === []) {
            $nextStep = match ($status) {
                'locked' => 'Locked',
                'ready_for_review' => 'Close and lock',
                default => 'Review',
            };
            $nextStepFoot = match ($status) {
                'locked' => 'This accounting period is closed and locked; no further year-end action is required.',
                'ready_for_review' => 'Open Year End to run the close tasks and lock this accounting period.',
                default => 'Open Year End to refresh the detailed checklist.',
            };
            $nextStepState = in_array($status, ['locked', 'ready_for_review'], true) ? 'ok' : 'warn';

            $statsHtml .= $this->statCard(
                'Next step',
                $nextStep,
                $nextStepFoot,
                $nextStepState
            );
        }

        return '
            <div class="panel-soft">
                <div class="grid-stats">' . $statsHtml . '</div>
                <div class="mini-field">
                    ' . $workflowButton . '
                </div>
            </div>
        ';
    }

    private function statCard(string $label, string $value, string $foot, string $state): string
    {
        return '<article class="card stat-card stat-card-status-' . HelperFramework::escape($state) . '">
            <div class="eyebrow">' . HelperFramework::escape($label) . '</div>
            <div class="stat-value">' . HelperFramework::escape($value) . '</div>
            <div class="stat-foot">' . HelperFramework::escape($foot) . '</div>
        </article>';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'locked' => 'Locked',
            'ready_for_review', 'pass' => 'Ready to Close',
            'in_progress', 'warning' => 'Warning',
            'needs_attention', 'fail' => 'Missing',
            default => 'Not started',
        };
    }

    private function issueFoot(array $issue): string
    {
        $detail = trim((string)($issue['detail'] ?? ''));
        $metricValue = trim((string)($issue['metric_value'] ?? ''));

        if ($detail === '') {
            return $metricValue;
        }

        return $detail;
    }

    private function issueValue(array $issue): string
    {
        $metricValue = trim((string)($issue['metric_value'] ?? ''));

        if ($metricValue !== '' && !is_numeric($metricValue)) {
            return $metricValue;
        }

        return $this->statusLabel((string)($issue['status'] ?? 'warning'));
    }

    private function statusCardClass(string $status): string
    {
        return match ($status) {
            'locked', 'ready_for_review', 'pass' => 'ok',
            'needs_attention', 'fail' => 'bad',
            default => 'warn',
        };
    }

    private function issuesCardClass(array $topIssues): string
    {
        if ($topIssues === []) {
            return 'ok';
        }

        foreach ($topIssues as $issue) {
            if (is_array($issue) && (string)($issue['status'] ?? '') === 'fail') {
                return 'bad';
            }
        }

        return 'warn';
    }
}
