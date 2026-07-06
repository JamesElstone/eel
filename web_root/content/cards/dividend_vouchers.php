<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _dividend_vouchersCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'dividend_vouchers';
    }

    public function title(): string
    {
        return 'Dividend Vouchers';
    }

    public function services(): array
    {
        return [$this->dividendContextService()];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['dividend.vouchers', 'dividend.history'];
    }

    public function render(array $context): string
    {
        $rows = (array)($this->dividendsContext($context)['vouchers'] ?? []);
        $companySettings = (array)(($context['company'] ?? [])['settings'] ?? []);
        if ($rows === []) {
            return '<div class="helper">No dividend vouchers have been issued for the selected company and accounting period.</div>';
        }

        $rowsHtml = '';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $isVoided = trim((string)($row['voided_at'] ?? '')) !== '';
            $statusHtml = $isVoided
                ? '<span class="badge danger">Voided</span><div class="helper">' . HelperFramework::escape((string)($row['void_reason'] ?? '')) . '</div>'
                : '<span class="badge success">Issued</span><div class="helper">' . HelperFramework::escape((string)($row['issued_at'] ?? '')) . '</div>';

            $rowsHtml .= '<tr>
                <td>' . HelperFramework::escape((string)($row['declaration_date'] ?? '')) . '</td>
                <td>
                    <div>' . HelperFramework::escape((string)($row['shareholder_name'] ?? '')) . '</div>
                    <div class="helper">' . HelperFramework::escape((string)($row['company_name'] ?? '')) . '</div>
                </td>
                <td class="numeric">' . HelperFramework::escape($this->money($companySettings, $row['amount'] ?? 0)) . '</td>
                <td>' . $this->documentDetailsHtml('Voucher', (string)($row['voucher_text'] ?? '')) . '</td>
                <td>' . $this->documentDetailsHtml('Minutes', (string)($row['minutes_text'] ?? '')) . '</td>
                <td>' . $statusHtml . '</td>
                <td><div class="helper">' . HelperFramework::escape((string)($row['source_ref'] ?? '')) . '</div></td>
            </tr>';
        }

        return '<div class="table-scroll">
            <table>
                <thead><tr><th>Date</th><th>Shareholder</th><th>Amount</th><th>Voucher</th><th>Minutes</th><th>Status</th><th>Source</th></tr></thead>
                <tbody>' . $rowsHtml . '</tbody>
            </table>
        </div>';
    }

    private function money(array $companySettings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }

    private function documentDetailsHtml(string $label, string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '<span class="helper">-</span>';
        }

        return '<details>
            <summary>' . HelperFramework::escape($label) . '</summary>
            <pre class="helper">' . HelperFramework::escape($text) . '</pre>
        </details>';
    }

    private function dividendContextService(): array
    {
        return [
            'key' => 'dividendContext',
            'service' => \eel_accounts\Service\DividendViewDataService::class,
            'method' => 'fetchContext',
            'params' => [
                'companyId' => ':company.id',
                'accountingPeriodId' => ':company.accounting_period_id',
            ],
        ];
    }

    private function dividendsContext(array $context): array
    {
        $serviceContext = $context['services']['dividendContext'] ?? null;
        if (is_array($serviceContext)) {
            return $serviceContext;
        }

        return (array)($context['dividends'] ?? []);
    }
}
