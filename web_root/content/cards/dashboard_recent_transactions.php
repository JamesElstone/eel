<?php
declare(strict_types=1);

final class _dashboard_recent_transactionsCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'dashboard_recent_transactions';
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
        $recentTransactions = (array)(($context['page'] ?? [])['recent_transactions'] ?? []);
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

        return '<div class="card">
            <div class="card-header">
                <h2 class="card-title">Recent Transactions</h2>
            </div>
            <div class="card-body">
                <div class="toolbar">
                    <input class="input" type="search" placeholder="Search description, category, account...">
                    <select class="select" style="max-width: 180px;">
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
            </div>
        </div>';
    }

}
