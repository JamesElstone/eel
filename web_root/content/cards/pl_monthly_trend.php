<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _pl_monthly_trendCard extends CardBaseFramework
{
    public function key(): string { return 'pl_monthly_trend'; }

    public function title(): string { return 'Monthly P&L Trend'; }

    protected function additionalInvalidationFacts(): array { return ['page.context']; }

    public function render(array $context): string
    {
        $rows = (array)($context['profit_loss']['monthly_trend'] ?? []);
        if ($rows === []) {
            return '<div class="helper">No monthly Profit & Loss data is available for the selected period.</div>';
        }

        $html = '';
        foreach ($rows as $row) {
            $net = (float)($row['net_profit'] ?? 0);
            $html .= '<tr>
                <td>' . HelperFramework::escape((string)($row['month_label'] ?? '')) . '</td>
                <td>' . HelperFramework::escape(FormattingFramework::money($row['income_total'] ?? 0)) . '</td>
                <td>' . HelperFramework::escape(FormattingFramework::money($row['cost_of_sales_total'] ?? 0)) . '</td>
                <td>' . HelperFramework::escape(FormattingFramework::money($row['expense_total'] ?? 0)) . '</td>
                <td><span class="badge ' . ($net >= 0 ? 'success' : 'danger') . '">' . HelperFramework::escape(FormattingFramework::money($net)) . '</span></td>
            </tr>';
        }

        return '<div class="table-scroll"><table>
            <thead><tr><th>Month</th><th>Income</th><th>Cost of sales</th><th>Expenses</th><th>Net</th></tr></thead>
            <tbody>' . $html . '</tbody>
        </table></div>';
    }
}
