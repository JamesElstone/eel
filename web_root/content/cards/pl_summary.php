<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _pl_summaryCard extends CardBaseFramework
{
    public function key(): string { return 'pl_summary'; }

    public function title(): string { return 'P&L Summary'; }

    protected function additionalInvalidationFacts(): array { return ['page.context']; }

    public function render(array $context): string
    {
        $summary = (array)($context['profit_loss']['summary'] ?? []);
        $companySettings = (array)(($context['company'] ?? [])['settings'] ?? []);
        if (empty($summary['available'])) {
            return $this->messages((array)($summary['errors'] ?? ['Profit & Loss is not available.']));
        }

        $hasJournals = !empty($summary['has_journals']);
        $hasTransactions = !empty($summary['has_transactions']);
        $netProfit = (float)($summary['net_profit'] ?? 0);
        $chart = $this->incomeFlowChart((array)($context['profit_loss']['breakdown'] ?? []), $companySettings);
        $notice = '';
        if (!$hasJournals && $hasTransactions) {
            $notice = '<div class="helper">Transactions exist but no posted journals were found for this period.</div>';
        } elseif (!$hasJournals) {
            $notice = '<div class="helper">No posted journals or transactions exist yet for this period.</div>';
        }

        return '<div class="settings-stack">
            <div class="pl-summary-topline">
                <div class="summary-card summary-card-fit"><div class="summary-label">Profitability</div><div class="summary-value ' . HelperFramework::escape($this->resultClass($netProfit)) . '">' . HelperFramework::escape($this->resultLabel($netProfit)) . '</div></div>
                ' . ($chart !== '' ? '<div class="pl-summary-income-flow">' . $chart . '</div>' : '<div class="helper">No incoming or outgoing nominal flow is available for the selected period.</div>') . '
            </div>
            ' . $notice . '
            <div class="summary-grid">
                ' . $this->summaryCard('Income', $summary['income_total'] ?? 0, $companySettings) . '
                ' . $this->summaryCard('Cost of sales', $summary['cost_of_sales_total'] ?? 0, $companySettings) . '
                ' . $this->summaryCard('Gross profit', $summary['gross_profit'] ?? 0, $companySettings) . '
                ' . $this->summaryCard('Expenses', $summary['expense_total'] ?? 0, $companySettings) . '
                ' . $this->summaryCard('Net profit / loss', $summary['net_profit'] ?? 0, $companySettings) . '
                <div class="summary-card"><div class="summary-label">Profit margin</div><div class="summary-value">' . HelperFramework::escape(number_format((float)($summary['profit_margin_percent'] ?? 0), 1)) . '%</div></div>
            </div>
            ' . $this->healthMetrics($context) . '
        </div>';
    }

    private function incomeFlowChart(array $breakdown, array $companySettings): string
    {
        $nodes = [[
            'id' => 'income_flow_total',
            'label' => 'Income Flow',
            'column' => 1,
            'color' => '#475569',
        ]];
        $links = [];
        $incomeFlowTotal = 0.0;
        $outgoingFlowTotal = 0.0;

        foreach ((array)($breakdown['income'] ?? []) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $amount = round((float)($row['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }

            $id = 'income_' . $index . '_' . (int)($row['nominal_account_id'] ?? 0);
            $nodes[] = [
                'id' => $id,
                'label' => $this->flowNominalLabel($row),
                'column' => 0,
                'color' => '#1d4ed8',
            ];
            $links[] = [
                'source' => $id,
                'target' => 'income_flow_total',
                'value' => $amount,
                'color' => '#1d4ed8',
            ];
            $incomeFlowTotal += $amount;
        }

        foreach ($this->positiveFlowRows((array)($breakdown['cost_of_sales'] ?? [])) as $index => $row) {
            $amount = (float)$row['amount'];
            $id = 'cost_of_sales_' . $index . '_' . (int)($row['nominal_account_id'] ?? 0);
            $nodes[] = [
                'id' => $id,
                'label' => $this->flowNominalLabel($row),
                'column' => 2,
                'color' => '#d97706',
            ];
            $links[] = [
                'source' => 'income_flow_total',
                'target' => $id,
                'value' => $amount,
                'color' => '#d97706',
            ];
            $outgoingFlowTotal += $amount;
        }

        $expenseRows = $this->positiveFlowRows((array)($breakdown['expense'] ?? []));
        $topExpenseCount = $expenseRows === [] ? 0 : max(1, (int)ceil(count($expenseRows) * 0.1));
        foreach (array_slice($expenseRows, 0, $topExpenseCount) as $index => $row) {
            $amount = (float)$row['amount'];
            $id = 'expense_' . $index . '_' . (int)($row['nominal_account_id'] ?? 0);
            $nodes[] = [
                'id' => $id,
                'label' => $this->flowNominalLabel($row),
                'column' => 2,
                'color' => '#dc2626',
            ];
            $links[] = [
                'source' => 'income_flow_total',
                'target' => $id,
                'value' => $amount,
                'color' => '#dc2626',
            ];
            $outgoingFlowTotal += $amount;
        }

        $otherExpenseTotal = 0.0;
        foreach (array_slice($expenseRows, $topExpenseCount) as $row) {
            $otherExpenseTotal += (float)$row['amount'];
        }
        $otherExpenseTotal = round($otherExpenseTotal, 2);
        if ($otherExpenseTotal > 0) {
            $nodes[] = [
                'id' => 'other_expenses',
                'label' => 'Other Expenses',
                'column' => 2,
                'color' => '#991b1b',
            ];
            $links[] = [
                'source' => 'income_flow_total',
                'target' => 'other_expenses',
                'value' => $otherExpenseTotal,
                'color' => '#991b1b',
            ];
            $outgoingFlowTotal += $otherExpenseTotal;
        }

        $profitLossAmount = round($incomeFlowTotal - $outgoingFlowTotal, 2);
        if ($profitLossAmount > 0) {
            $nodes[] = [
                'id' => 'profit',
                'label' => 'Profit',
                'column' => 2,
                'color' => '#16a34a',
            ];
            $links[] = [
                'source' => 'income_flow_total',
                'target' => 'profit',
                'value' => $profitLossAmount,
                'color' => '#16a34a',
            ];
        } elseif ($profitLossAmount < 0) {
            $nodes[] = [
                'id' => 'loss',
                'label' => 'Loss',
                'column' => 0,
                'color' => '#dc2626',
            ];
            $links[] = [
                'source' => 'loss',
                'target' => 'income_flow_total',
                'value' => abs($profitLossAmount),
                'color' => '#dc2626',
            ];
        }

        if ($links === []) {
            return '';
        }

        return (new ChartService())->sankey($nodes, $links, [
            'title' => 'Income flow by nominal',
            'value_prefix' => (new \eel_accounts\Service\CompanySettingsService())->defaultCurrencySymbol($companySettings),
            'balance_node' => 'income_flow_total',
            'width' => 900,
            'height' => max(360, min(400, count($nodes) * 44)),
        ]);
    }

    private function positiveFlowRows(array $rows): array
    {
        $positiveRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $amount = round((float)($row['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }

            $row['amount'] = $amount;
            $positiveRows[] = $row;
        }

        usort($positiveRows, static fn(array $left, array $right): int => (float)$right['amount'] <=> (float)$left['amount']);

        return $positiveRows;
    }

    private function flowNominalLabel(array $row): string
    {
        return FormattingFramework::nominalLabel([
            'code' => (string)($row['code'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
        ], ' ');
    }

    private function summaryCard(string $label, mixed $value, array $companySettings): string
    {
        return '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape($this->money($companySettings, $value)) . '</div></div>';
    }

    private function money(array $companySettings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }

    private function resultLabel(float $netProfit): string
    {
        if ($netProfit > 0) {
            return 'Profit';
        }

        if ($netProfit < 0) {
            return 'Loss';
        }

        return 'Nill';
    }

    private function resultClass(float $netProfit): string
    {
        if ($netProfit > 0) {
            return 'pl-profitability-value pl-profitability-value-profit';
        }

        if ($netProfit < 0) {
            return 'pl-profitability-value pl-profitability-value-loss';
        }

        return 'pl-profitability-value pl-profitability-value-nill';
    }

    private function healthMetrics(array $context): string
    {
        $health = (array)($context['profit_loss']['health'] ?? []);
        if ($health === []) {
            return '';
        }

        if (empty($health['available'])) {
            return $this->messages((array)($health['errors'] ?? ['Profit & Loss health is not available.']));
        }

        return '<div class="summary-grid">
            ' . $this->metric('Categorised', number_format((float)($health['categorised_percent'] ?? 0), 1) . '%') . '
            ' . $this->metric('Uncategorised transactions', (string)(int)($health['uncategorised_transactions'] ?? 0)) . '
            ' . $this->metric('Missing months', (string)(int)($health['missing_month_count'] ?? 0)) . '
            ' . $this->metric('Uploaded months', (string)(int)($health['uploaded_month_count'] ?? 0)) . '
            ' . $this->metric('Committed months', (string)(int)($health['committed_month_count'] ?? 0)) . '
            ' . $this->metric('Uploads in progress', (string)(int)($health['upload_in_progress_count'] ?? 0)) . '
        </div>';
    }

    private function metric(string $label, string $value): string
    {
        return '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape($value) . '</div></div>';
    }

    private function messages(array $messages): string
    {
        return implode('', array_map(static fn(mixed $message): string => '<div class="helper">' . HelperFramework::escape((string)$message) . '</div>', $messages));
    }
}
