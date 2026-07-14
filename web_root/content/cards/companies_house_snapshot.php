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
        ];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $snapshot = (array)($context['services']['companiesHouseSnapshot'] ?? []);
        $companySettings = (array)(($context['company'] ?? [])['settings'] ?? []);
        if (empty($snapshot['available'])) {
            return $this->panel('Companies House Snapshot', $this->renderErrors((array)($snapshot['errors'] ?? ['Companies House snapshot is not available.'])));
        }

        $warningHtml = '';
        foreach ((array)($snapshot['warnings'] ?? []) as $warning) {
            $warningHtml .= '<div class="helper">' . HelperFramework::escape((string)$warning) . '</div>';
        }
        $reliable = !empty($snapshot['reliable_closing_balance']);
        $balanced = !empty($snapshot['is_balance_sheet_balanced']);
        $statusClass = $reliable && $balanced ? 'success' : 'warning';
        $statusLabel = !$reliable ? 'Provisional' : ($balanced ? 'Balanced' : 'Review');

        $fieldsHtml = '';
        foreach ((array)($snapshot['fields'] ?? []) as $field) {
            $value = !empty($field['is_money'])
                ? $this->money($companySettings, $field['value'] ?? 0)
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
                <td><strong>' . HelperFramework::escape($this->displayValue($check['value'] ?? '', $companySettings)) . '</strong><div class="helper">' . HelperFramework::escape((string)($check['detail'] ?? '')) . '</div></td>
            </tr>';
        }

        $sourceHtml = '';
        foreach ((array)($snapshot['sources'] ?? []) as $source) {
            $sourceHtml .= '<tr>
                <td>' . HelperFramework::escape((string)($source['label'] ?? '')) . '</td>
                <td>' . (int)($source['count'] ?? 0) . '</td>
                <td>' . HelperFramework::escape($this->money($companySettings, $source['amount'] ?? 0)) . '</td>
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
                    <span class="badge ' . $statusClass . '">' . $statusLabel . '</span>
                </div>
                <div class="helper">Manual Companies House balance-sheet entry only. Profit and loss figures remain in the HMRC/iXBRL workflow and are not shown here.</div>
                ' . $warningHtml . '
                <div class="table-scroll">
                    <table><thead><tr><th>Companies House field</th><th>Value</th></tr></thead><tbody>' . $fieldsHtml . '</tbody></table>
                </div>
            </section>
            <section class="panel-soft">
                <h3 class="card-title">Checks</h3>
                <div class="table-scroll">
                    <table><thead><tr><th>Check</th><th>Value</th></tr></thead><tbody>' . $checksHtml . '</tbody></table>
                </div>
            </section>
            <section class="panel-soft">
                <h3 class="card-title">Source summary</h3>
                <div class="table-scroll">
                    <table><thead><tr><th>Bucket</th><th>Rows</th><th>Amount</th></tr></thead><tbody>' . $sourceHtml . '</tbody></table>
                </div>
                ' . ($assumptionsHtml !== '' ? '<div class="helper"><ul>' . $assumptionsHtml . '</ul></div>' : '') . '
                <div class="helper">Current assets exclude fixed assets. Bank balances are current assets; asset register values should flow through fixed-asset and depreciation ledger postings.</div>
            </section>
        </div>';
    }

    private function displayValue(mixed $value, array $companySettings): string
    {
        return is_numeric($value) ? $this->money($companySettings, $value) : (string)$value;
    }

    private function panel(string $title, string $body): string
    {
        return '<section class="panel-soft"><div class="status-head"><h3 class="card-title">' . HelperFramework::escape($title) . '</h3></div>' . $body . '</section>';
    }

    private function money(array $companySettings, mixed $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
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
