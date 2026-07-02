<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _expense_statisticsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'expense_statistics';
    }

    public function title(): string
    {
        return 'Expense Statistics';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'expenseStatistics',
                'service' => \eel_accounts\Service\ExpenseClaimService::class,
                'method' => 'fetchStatistics',
                'params' => [
                    'companyId' => ':company.id',
                    'filters' => ':expense_filters',
                ],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['expense.claimants', 'expenses.state', 'expense.claim.editor'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $statistics = (array)($context['services']['expenseStatistics'] ?? []);

        return '<div class="settings-stack expense-statistics">
            ' . $this->renderClaimantPanel((array)($statistics['claimants'] ?? [])) . '
            ' . $this->renderNominalPanel((array)($statistics['nominals'] ?? [])) . '
            ' . $this->renderClaimantBreakdownPanel((array)($statistics['claimant_breakdown'] ?? [])) . '
            ' . $this->renderTrendPanel((array)($statistics['monthly_trend'] ?? [])) . '
            ' . $this->renderHealthPanel((array)($statistics['health_checks'] ?? [])) . '
        </div>';
    }

    private function renderClaimantPanel(array $rows): string
    {
        if ($rows === []) {
            return $this->emptyPanel('Claimant Balances', 'No expense claims were found for the selected accounting period.');
        }

        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>
                <td>' . HelperFramework::escape((string)($row['claimant_name'] ?? '')) . '</td>
                <td class="numeric">' . (int)($row['claim_count'] ?? 0) . '</td>
                <td class="numeric">' . (int)($row['item_count'] ?? 0) . '</td>
                <td class="numeric">' . HelperFramework::escape(FormattingFramework::money((float)($row['claimed_total'] ?? 0))) . '</td>
                <td class="numeric">' . HelperFramework::escape(FormattingFramework::money((float)($row['payments_made'] ?? 0))) . '</td>
                <td class="numeric">' . HelperFramework::escape(FormattingFramework::money((float)($row['carried_forward'] ?? 0))) . '</td>
            </tr>';
        }

        return '<section class="panel-soft">
            <h3 class="card-title">Claimant Balances</h3>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Claimant</th><th>Claims</th><th>Items</th><th>Claimed</th><th>Payments</th><th>Balance c/f</th></tr></thead>
                    <tbody>' . $body . '</tbody>
                </table>
            </div>
        </section>';
    }

    private function renderNominalPanel(array $rows): string
    {
        if ($rows === []) {
            return $this->emptyPanel('Claims By Nominal', 'No expense claim lines were found for the selected accounting period.');
        }

        $tableRows = '';
        foreach ($rows as $row) {
            $label = $this->nominalLabel($row);
            $tableRows .= '<tr>
                <td>' . HelperFramework::escape($label) . '</td>
                <td class="numeric">' . (int)($row['line_count'] ?? 0) . '</td>
                <td class="numeric">' . HelperFramework::escape(FormattingFramework::money((float)($row['claimed_total'] ?? 0))) . '</td>
            </tr>';
        }

        return '<section class="panel-soft">
            <h3 class="card-title">Claims By Nominal</h3>
            <div class="settings-stack">
                ' . $this->pieChart($rows, 'claimed_total', 'nominal', 'Expense total by nominal') . '
                <div class="table-scroll">
                    <table>
                        <thead><tr><th>Nominal</th><th>Items</th><th>Total</th></tr></thead>
                        <tbody>' . $tableRows . '</tbody>
                    </table>
                </div>
            </div>
        </section>';
    }

    private function renderClaimantBreakdownPanel(array $rows): string
    {
        if ($rows === []) {
            return $this->emptyPanel('Claims By Claimant', 'No claimant totals were found for the selected accounting period.');
        }

        $tableRows = '';
        foreach ($rows as $row) {
            $tableRows .= '<tr>
                <td>' . HelperFramework::escape((string)($row['claimant_name'] ?? '')) . '</td>
                <td class="numeric">' . HelperFramework::escape(FormattingFramework::money((float)($row['claimed_total'] ?? 0))) . '</td>
            </tr>';
        }

        return '<section class="panel-soft">
            <h3 class="card-title">Claims By Claimant</h3>
            <div class="settings-stack">
                ' . $this->pieChart($rows, 'claimed_total', 'claimant', 'Expense total by claimant') . '
                <div class="table-scroll">
                    <table>
                        <thead><tr><th>Claimant</th><th>Total</th></tr></thead>
                        <tbody>' . $tableRows . '</tbody>
                    </table>
                </div>
            </div>
        </section>';
    }

    private function renderTrendPanel(array $rows): string
    {
        if ($rows === []) {
            return $this->emptyPanel('Claims Over Time', 'No monthly expense claim totals were found for the selected accounting period.');
        }

        $points = array_map(
            static fn(array $row): array => [
                'label' => (string)($row['label'] ?? $row['period'] ?? ''),
                'value' => (float)($row['claimed_total'] ?? 0),
            ],
            $rows
        );

        return '<section class="panel-soft">
            <h3 class="card-title">Claims Over Time</h3>
            ' . (new ChartService())->line($points, ['title' => 'Expense claims over time']) . '
        </section>';
    }

    private function renderHealthPanel(array $health): string
    {
        $oldest = $health['oldest_outstanding_claim'] ?? null;
        $largest = $health['largest_outstanding_claimant'] ?? null;

        return '<section class="panel-soft">
            <h3 class="card-title">Health Checks</h3>
            <div class="grid-stats">
                ' . $this->metric('Draft claims', (string)(int)(($health['draft'] ?? [])['claim_count'] ?? 0), FormattingFramework::money((float)(($health['draft'] ?? [])['claimed_total'] ?? 0))) . '
                ' . $this->metric('Posted claims', (string)(int)(($health['posted'] ?? [])['claim_count'] ?? 0), FormattingFramework::money((float)(($health['posted'] ?? [])['claimed_total'] ?? 0))) . '
                ' . $this->metric('Missing receipts', (string)(int)(($health['missing_receipts'] ?? [])['count'] ?? 0), FormattingFramework::money((float)(($health['missing_receipts'] ?? [])['value'] ?? 0))) . '
                ' . $this->metric('Missing nominals', (string)(int)(($health['missing_nominals'] ?? [])['count'] ?? 0), FormattingFramework::money((float)(($health['missing_nominals'] ?? [])['value'] ?? 0))) . '
                ' . $this->metric('Oldest outstanding', $oldest !== null ? (string)($oldest['claim_reference_code'] ?? '-') : '-', $oldest !== null ? (string)($oldest['claimant_name'] ?? '') . ' ' . FormattingFramework::money((float)($oldest['carried_forward'] ?? 0)) : 'No outstanding claims') . '
                ' . $this->metric('Largest balance', $largest !== null ? (string)($largest['claimant_name'] ?? '-') : '-', $largest !== null ? FormattingFramework::money((float)($largest['carried_forward'] ?? 0)) : 'No outstanding balances') . '
            </div>
        </section>';
    }

    private function pieChart(array $rows, string $valueKey, string $labelType, string $title): string
    {
        $segments = [];

        foreach ($rows as $row) {
            $value = (float)($row[$valueKey] ?? 0);
            if ($value <= 0) {
                continue;
            }

            $segments[] = [
                'label' => $labelType === 'nominal' ? $this->nominalLabel($row) : (string)($row['claimant_name'] ?? ''),
                'value' => $value,
            ];
        }

        return (new ChartService())->pie($segments, ['title' => $title]);
    }

    private function metric(string $label, string $value, string $foot): string
    {
        return '<article class="card stat-card">
            <div class="eyebrow">' . HelperFramework::escape($label) . '</div>
            <div class="stat-value">' . HelperFramework::escape($value) . '</div>
            <div class="stat-foot">' . HelperFramework::escape($foot) . '</div>
        </article>';
    }

    private function nominalLabel(array $row): string
    {
        $code = trim((string)($row['code'] ?? ''));
        $name = trim((string)($row['name'] ?? ''));
        if ($code === '' && $name === '') {
            return 'Unassigned';
        }

        return trim($code . ' ' . ($name !== '' ? $name : 'Unassigned'));
    }

    private function emptyPanel(string $title, string $message): string
    {
        return '<section class="panel-soft"><h3 class="card-title">' . HelperFramework::escape($title) . '</h3><div class="helper">' . HelperFramework::escape($message) . '</div></section>';
    }
}
