<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _companies_house_snapshotCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'companies_house_snapshot';
    }

    public function title(): string
    {
        return 'Companies House Snapshot';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'companiesHouseSnapshot',
                'service' => \eel_accounts\Service\CompaniesHouseSnapshotService::class,
                'method' => 'fetchSnapshot',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
            [
                'key' => 'trialBalanceComparison',
                'service' => \eel_accounts\Service\TrialBalanceComparisonService::class,
                'method' => 'fetchComparison',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
        ];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $snapshot = (array)($context['services']['companiesHouseSnapshot'] ?? []);
        $comparison = (array)($context['services']['trialBalanceComparison'] ?? []);
        if (empty($snapshot['available'])) {
            return $this->panel('Companies House Snapshot', $this->renderErrors((array)($snapshot['errors'] ?? ['Companies House snapshot is not available.'])));
        }

        $warningHtml = '';
        foreach ((array)($snapshot['warnings'] ?? []) as $warning) {
            $warningHtml .= '<div class="helper">' . HelperFramework::escape((string)$warning) . '</div>';
        }

        $fieldsHtml = '';
        foreach ((array)($snapshot['fields'] ?? []) as $field) {
            $value = !empty($field['is_money'])
                ? FormattingFramework::money($field['value'] ?? 0)
                : (string)($field['value'] ?? '');
            $note = trim((string)($field['note'] ?? ''));
            $fieldsHtml .= '<tr>
                <td>' . HelperFramework::escape((string)($field['label'] ?? '')) . '</td>
                <td><strong>' . HelperFramework::escape($value) . '</strong>' . ($note !== '' ? '<div class="helper">' . HelperFramework::escape($note) . '</div>' : '') . '</td>
            </tr>';
        }

        $checksHtml = '';
        foreach ((array)($snapshot['checks'] ?? []) as $check) {
            $checksHtml .= '<tr>
                <td>' . HelperFramework::escape((string)($check['label'] ?? '')) . '</td>
                <td><strong>' . HelperFramework::escape($this->displayValue($check['value'] ?? '')) . '</strong><div class="helper">' . HelperFramework::escape((string)($check['detail'] ?? '')) . '</div></td>
            </tr>';
        }

        $sourceHtml = '';
        foreach ((array)($snapshot['sources'] ?? []) as $source) {
            $sourceHtml .= '<tr>
                <td>' . HelperFramework::escape((string)($source['label'] ?? '')) . '</td>
                <td>' . (int)($source['count'] ?? 0) . '</td>
                <td>' . HelperFramework::escape(FormattingFramework::money($source['amount'] ?? 0)) . '</td>
            </tr>';
        }

        $assumptionsHtml = '';
        foreach ((array)($snapshot['assumptions'] ?? []) as $assumption) {
            $assumptionsHtml .= '<li>' . HelperFramework::escape((string)$assumption) . '</li>';
        }

        return '<div class="settings-stack">
            <section class="panel-soft">
                <div class="status-head">
                    <h3 class="card-title">Companies House Snapshot</h3>
                    <span class="badge ' . (!empty($snapshot['is_balance_sheet_balanced']) ? 'success' : 'warning') . '">' . (!empty($snapshot['is_balance_sheet_balanced']) ? 'Balanced' : 'Review') . '</span>
                </div>
                <div class="helper">Manual Companies House balance-sheet entry only. Profit and loss figures remain in the HMRC/iXBRL workflow and are not shown here.</div>
                ' . $warningHtml . '
                <div class="table-scroll">
                    <table><thead><tr><th>Companies House field</th><th>Value</th></tr></thead><tbody>' . $fieldsHtml . '</tbody></table>
                </div>
                <h3 class="card-title">Checks</h3>
                <div class="table-scroll">
                    <table><thead><tr><th>Check</th><th>Value</th></tr></thead><tbody>' . $checksHtml . '</tbody></table>
                </div>
                <h3 class="card-title">Source summary</h3>
                <div class="table-scroll">
                    <table><thead><tr><th>Bucket</th><th>Rows</th><th>Amount</th></tr></thead><tbody>' . $sourceHtml . '</tbody></table>
                </div>
                ' . ($assumptionsHtml !== '' ? '<div class="helper"><ul>' . $assumptionsHtml . '</ul></div>' : '') . '
                <div class="helper">Current assets exclude fixed assets. Bank balances are current assets; asset register values should flow through fixed-asset and depreciation ledger postings.</div>
            </section>
            ' . $this->renderComparisonPanel($comparison) . '
        </div>';
    }

    private function displayValue(mixed $value): string
    {
        return is_numeric($value) ? FormattingFramework::money($value) : (string)$value;
    }

    private function panel(string $title, string $body): string
    {
        return '<section class="panel-soft"><div class="status-head"><h3 class="card-title">' . HelperFramework::escape($title) . '</h3></div>' . $body . '</section>';
    }

    private function renderComparisonPanel(array $comparison): string
    {
        if (empty($comparison['available'])) {
            return $this->panel('Filed Accounts Comparison', $this->renderErrors((array)($comparison['errors'] ?? [])));
        }

        $rowsHtml = '';
        foreach ((array)($comparison['rows'] ?? []) as $row) {
            $status = (string)($row['status'] ?? '');
            $rowsHtml .= '<tr>
                <td>' . HelperFramework::escape((string)($row['label'] ?? '')) . '</td>
                <td>' . HelperFramework::escape($this->nullableMoney($row['filed_value'] ?? null)) . '</td>
                <td>' . HelperFramework::escape($this->nullableMoney($row['current_ledger_value'] ?? null)) . '</td>
                <td>' . HelperFramework::escape($this->nullableMoney($row['difference'] ?? null)) . '</td>
                <td><span class="badge ' . $this->badgeClass($status) . '">' . HelperFramework::escape(HelperFramework::labelFromKey($status, '_')) . '</span></td>
            </tr>';
        }

        return '<section class="panel-soft">
            <div class="status-head"><h3 class="card-title">Filed Accounts Comparison</h3></div>
            <div class="helper">Stored filing date: ' . HelperFramework::escape((string)($comparison['filing']['filing_date'] ?? '')) . '</div>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Metric</th><th>Filed value</th><th>Current ledger-derived value</th><th>Difference</th><th>Status</th></tr></thead>
                    <tbody>' . $rowsHtml . '</tbody>
                </table>
            </div>
        </section>';
    }

    private function badgeClass(string $status): string
    {
        return match ($status) {
            'pass', 'success', 'matches' => 'success',
            'fail', 'danger', 'differs' => 'danger',
            'warning' => 'warning',
            default => 'info',
        };
    }

    private function nullableMoney(mixed $value): string
    {
        return $value === null || $value === '' ? '-' : FormattingFramework::money($value);
    }

    private function renderErrors(array $errors): string
    {
        $html = '';
        foreach ($errors as $error) {
            $html .= '<div class="helper">' . HelperFramework::escape((string)$error) . '</div>';
        }

        return $html;
    }
}
