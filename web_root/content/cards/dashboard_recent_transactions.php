<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _dashboard_recent_transactionsCard extends CardBaseFramework
{
    private const PAGE_SIZE = 5;

    public function key(): string
    {
        return 'dashboard_recent_transactions';
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
                    'recentLimit' => 100,
                ],
            ],
        ];
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
        $dashboardData = (array)(($context['services'] ?? [])['dashboard_data'] ?? []);
        $recentTransactions = (array)($dashboardData['recent_transactions'] ?? (($context['page'] ?? [])['recent_transactions'] ?? []));
        $pagination = HelperFramework::paginateArray(
            $recentTransactions,
            $this->paginationPage($context),
            self::PAGE_SIZE
        );
        $recentTransactions = (array)$pagination['items'];
        $rowsHtml = '';

        foreach ($recentTransactions as $row) {
            $status = (string)($row['status'] ?? '');
            $statusClass = 'info';
            if ($status === 'Matched') {
                $statusClass = 'success';
            } elseif ($status === 'Needs review') {
                $statusClass = 'warning';
            }

            $amount = (float)($row['amount'] ?? 0);
            $rowsHtml .= '<tr>
                <td>' . HelperFramework::escape((string)($row['date'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($row['account'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($row['description'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($row['category'] ?? '')) . '</td>
                <td class="' . ($amount >= 0 ? 'amount-positive' : 'amount-negative') . '">'
                    . HelperFramework::escape(FormattingFramework::money($amount)) .
                '</td>
                <td><span class="badge ' . HelperFramework::escape($statusClass) . '">' . HelperFramework::escape($status) . '</span></td>
            </tr>';
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="6">No recent transactions are available.</td></tr>';
        }

        return '
            <div class="toolbar">
                <input class="input" type="search" placeholder="Search description, category, account...">
                <select class="select">
                    <option>All statuses</option>
                    <option>Matched</option>
                    <option>Needs review</option>
                    <option>Posted</option>
                </select>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Account</th>
                        <th>Description</th>
                        <th>Category</th>
                        <th>Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>' . $rowsHtml . '</tbody>
            </table>
            ' . $this->paginationControls($context, $pagination, 'Recent transactions') . '
        ';
    }

}
