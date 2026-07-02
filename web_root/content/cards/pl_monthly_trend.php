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

        return '<div class="pl-monthly-trend-layout">
            <div class="table-scroll pl-monthly-trend-table"><table>
                <thead><tr><th>Month</th><th>Income</th><th>Cost of sales</th><th>Expenses</th><th>Net</th></tr></thead>
                <tbody>' . $html . '</tbody>
            </table></div>
            <div class="pl-monthly-trend-chart">
                ' . $this->trendChart($rows) . '
            </div>
        </div>';
    }

    private function trendChart(array $rows): string
    {
        $series = [
            [
                'label' => 'Income',
                'color' => '#1d4ed8',
                'points' => $this->points($rows, 'income_total'),
            ],
            [
                'label' => 'Cost of sales',
                'color' => '#d97706',
                'points' => $this->points($rows, 'cost_of_sales_total'),
            ],
            [
                'label' => 'Expenses',
                'color' => '#7c3aed',
                'points' => $this->points($rows, 'expense_total'),
            ],
            [
                'label' => 'Net',
                'color' => '#16a34a',
                'points' => $this->points($rows, 'net_profit'),
            ],
        ];

        return (new ChartService())->line($series, [
            'title' => 'Monthly Profit and Loss trend',
            'width' => 760,
            'height' => 320,
        ]);
    }

    private function points(array $rows, string $valueKey): array
    {
        return array_map(
            fn(array $row): array => [
                'label' => $this->chartMonthNumber($row),
                'value' => (float)($row[$valueKey] ?? 0),
            ],
            $rows
        );
    }

    private function chartMonthNumber(array $row): string
    {
        $monthStart = trim((string)($row['month_start'] ?? ''));
        if ($monthStart !== '') {
            try {
                return (new DateTimeImmutable($monthStart))->format('n');
            } catch (Throwable) {
            }
        }

        $monthLabel = trim((string)($row['month_label'] ?? ''));
        if ($monthLabel !== '') {
            try {
                return (new DateTimeImmutable($monthLabel))->format('n');
            } catch (Throwable) {
            }
        }

        return '';
    }
}
