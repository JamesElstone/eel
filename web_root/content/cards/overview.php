<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _overviewCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'overview';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'dashboard_data',
                'service' => DashboardRepository::class,
                'method' => 'fetchDashboardData',
                'params' => [
                    'companyId' => ':company.id',
                    'taxYearId' => ':company.tax_year_id',
                ],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['dashboard.metrics'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $statsHtml = '';
        $page = (array)($context['page'] ?? []);
        $dashboardData = (array)(($context['services'] ?? [])['dashboard_data'] ?? []);
        $rawStats = (array)($dashboardData['stats'] ?? []);
        $stats = (array)($page['stats'] ?? []);

        if ($stats === [] && $rawStats !== []) {
            $stats = $this->statsFromDashboardData($rawStats);
        }

        foreach ($stats as $stat) {
            $statsHtml .= '<article class="card stat-card">
                <div class="eyebrow">' . HelperFramework::escape((string)($stat['label'] ?? '')) . '</div>
                <div class="stat-value">' . HelperFramework::escape((string)($stat['value'] ?? '')) . '</div>
                <div class="stat-foot">' . HelperFramework::escape((string)($stat['foot'] ?? '')) . '</div>
            </article>';
        }

        return '<div class="grid-stats">' . $statsHtml . '</div>';
    }

    private function statsFromDashboardData(array $dashboardStats): array
    {
        return [
            [
                'label' => 'Bank accounts',
                'value' => (string)(int)($dashboardStats['bank_accounts'] ?? 0),
                'foot' => 'Active company accounts available for statement upload and reconciliation.',
            ],
            [
                'label' => 'Uncategorised',
                'value' => (string)(int)($dashboardStats['unreconciled_items'] ?? 0),
                'foot' => 'Transactions still waiting for nominal assignment.',
            ],
            [
                'label' => 'Manual journals',
                'value' => (string)(int)($dashboardStats['draft_journals'] ?? 0),
                'foot' => 'Manual-source journals currently sitting in the selected period.',
            ],
            [
                'label' => 'Staged rows',
                'value' => (string)(int)($dashboardStats['staged_upload_rows'] ?? 0),
                'foot' => 'Statement import rows staged but not yet committed.',
            ],
        ];
    }
}
