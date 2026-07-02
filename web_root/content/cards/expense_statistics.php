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
        $company = (array)($context['company'] ?? []);
        $companySettings = (array)($context['expense_page_settings'] ?? $company['settings'] ?? []);

        return '<div class="settings-stack expense-statistics">
            ' . $this->renderHealthPanel((array)($statistics['health_checks'] ?? []), $companySettings) . '
            ' . $this->renderClaimantPanel((array)($statistics['claimants'] ?? [])) . '
            ' . $this->renderUnassignedEntriesPanel((array)($statistics['unassigned_entries'] ?? [])) . '
            ' . $this->renderNominalPanel((array)($statistics['nominals'] ?? [])) . '
            ' . $this->renderTrendPanel((array)($statistics['monthly_trend'] ?? [])) . '
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
                <td class="numeric">' . (int)($row['unassigned_item_count'] ?? 0) . '</td>
                <td class="numeric">' . HelperFramework::escape(FormattingFramework::money((float)($row['brought_forward'] ?? 0))) . '</td>
                <td class="numeric">' . HelperFramework::escape(FormattingFramework::money((float)($row['claimed_total'] ?? 0))) . '</td>
                <td class="numeric">' . HelperFramework::escape(FormattingFramework::money((float)($row['payments_made'] ?? 0))) . '</td>
                <td class="numeric">' . HelperFramework::escape(FormattingFramework::money((float)($row['carried_forward'] ?? 0))) . '</td>
            </tr>';
        }

        return '<section class="panel-soft">
            <h3 class="card-title">Claimant Balances</h3>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Claimant</th><th>Claims</th><th>Items</th><th>Unassigned</th><th>Balance b/f</th><th>Claimed</th><th>Payments</th><th>Balance c/f</th></tr></thead>
                    <tbody>' . $body . '</tbody>
                </table>
            </div>
        </section>';
    }

    private function renderUnassignedEntriesPanel(array $rows): string
    {
        if ($rows === []) {
            return $this->emptyPanel('Unassigned Claim Entries', 'No unassigned expense claim entries were found for the selected accounting period.');
        }

        $body = '';
        foreach ($rows as $row) {
            $claimReference = trim((string)($row['claim_reference_code'] ?? ''));
            if ($claimReference === '') {
                $claimReference = '#' . (string)(int)($row['claim_id'] ?? 0);
            }

            $body .= '<tr>
                <td>' . HelperFramework::escape((string)($row['claimant_name'] ?? '')) . '</td>
                <td>' . HelperFramework::escape($claimReference) . '</td>
                <td>' . HelperFramework::escape((string)($row['month'] ?? '')) . '</td>
                <td>' . HelperFramework::escape($this->displayDate((string)($row['expense_date'] ?? ''))) . '</td>
                <td class="numeric">' . HelperFramework::escape(FormattingFramework::money((float)($row['amount'] ?? 0))) . '</td>
            </tr>';
        }

        return '<section class="panel-soft">
            <h3 class="card-title">Unassigned Claim Entries</h3>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Claimant</th><th>Claim ID</th><th>Month</th><th>Date unassigned</th><th>Amount</th></tr></thead>
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
        $nominalColours = $this->chartColours($rows, 'claimed_total');
        $nominalColourIndex = 0;

        foreach ($rows as $row) {
            $label = $this->nominalLabel($row);
            $value = (float)($row['claimed_total'] ?? 0);
            $colour = '';

            if ($value > 0) {
                $colour = $nominalColours[$nominalColourIndex] ?? '';
                $nominalColourIndex++;
            }

            $tableRows .= '<tr>
                <td class="expense-statistics-colour-column">' . $this->colourSwatch($colour) . '</td>
                <td>' . HelperFramework::escape($label) . '</td>
                <td class="numeric">' . (int)($row['line_count'] ?? 0) . '</td>
                <td class="numeric">' . HelperFramework::escape(FormattingFramework::money($value)) . '</td>
            </tr>';
        }

        return '<section class="panel-soft">
            <h3 class="card-title">Claims By Nominal</h3>
            <div class="expense-statistics-nominal-layout">
                <div class="table-scroll">
                    <table>
                        <thead><tr><th class="expense-statistics-colour-column"><span class="sr-only">Colour</span></th><th>Nominal</th><th>Items</th><th>Total</th></tr></thead>
                        <tbody>' . $tableRows . '</tbody>
                    </table>
                </div>
                <div class="expense-statistics-nominal-chart">
                    ' . $this->pieChart($rows, 'claimed_total', 'nominal', 'Expense total by nominal', true, ['legend' => false], $nominalColours) . '
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
            <div class="expense-statistics-trend-chart">
                ' . (new ChartService())->line($points, ['title' => 'Expense claims over time']) . '
            </div>
        </section>';
    }

    private function renderHealthPanel(array $health, array $companySettings): string
    {
        $companySettingsService = new \eel_accounts\Service\CompanySettingsService();

        return '<section class="panel-soft">
            <h3 class="card-title">Health Checks</h3>
            <div class="grid-stats">
                ' . $this->metric('Draft claims', (string)(int)(($health['draft'] ?? [])['claim_count'] ?? 0), $companySettingsService->money($companySettings, (float)(($health['draft'] ?? [])['claimed_total'] ?? 0))) . '
                ' . $this->metric('Posted claims', (string)(int)(($health['posted'] ?? [])['claim_count'] ?? 0), $companySettingsService->money($companySettings, (float)(($health['posted'] ?? [])['claimed_total'] ?? 0))) . '
                ' . $this->metric('Missing receipts', (string)(int)(($health['missing_receipts'] ?? [])['count'] ?? 0), $companySettingsService->money($companySettings, (float)(($health['missing_receipts'] ?? [])['value'] ?? 0))) . '
                ' . $this->metric('Missing nominals', (string)(int)(($health['missing_nominals'] ?? [])['count'] ?? 0), $companySettingsService->money($companySettings, (float)(($health['missing_nominals'] ?? [])['value'] ?? 0))) . '
            </div>
        </section>';
    }

    private function pieChart(array $rows, string $valueKey, string $labelType, string $title, bool $useProjectColours = false, array $options = [], ?array $colours = null): string
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

        if ($useProjectColours) {
            $colours ??= (new \eel_accounts\Service\ColourService())->generateColours(count($segments));
            foreach ($segments as $index => $segment) {
                $segments[$index]['color'] = $colours[$index] ?? '';
            }
        }

        return (new ChartService())->pie($segments, array_merge(['title' => $title], $options));
    }

    private function chartColours(array $rows, string $valueKey): array
    {
        $segmentCount = 0;
        foreach ($rows as $row) {
            if ((float)($row[$valueKey] ?? 0) > 0) {
                $segmentCount++;
            }
        }

        return (new \eel_accounts\Service\ColourService())->generateColours($segmentCount);
    }

    private function colourSwatch(string $colour): string
    {
        if ($colour === '') {
            return '<svg class="expense-statistics-colour-swatch" width="20" height="20" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><rect class="expense-statistics-colour-swatch-muted" x="0" y="0" width="20" height="20" rx="2"></rect></svg>';
        }

        return '<svg class="expense-statistics-colour-swatch" width="20" height="20" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><rect class="expense-statistics-colour-swatch-square" x="0" y="0" width="20" height="20" rx="2" fill="' . HelperFramework::escape($colour) . '"></rect></svg>';
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

    private function displayDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return (new DateTimeImmutable($value))->format('d/m/Y');
    }

    private function emptyPanel(string $title, string $message): string
    {
        return '<section class="panel-soft"><h3 class="card-title">' . HelperFramework::escape($title) . '</h3><div class="helper">' . HelperFramework::escape($message) . '</div></section>';
    }
}
