<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _year_end_transaction_tailCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'year_end_transaction_tail';
    }

    public function title(): string
    {
        return 'Transaction Cut-off Review';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'yearEndTransactionTail',
                'service' => \eel_accounts\Service\YearEndTransactionTailService::class,
                'method' => 'fetchContext',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['year.end.state', 'year.end.transaction.tail'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $tail = (array)($context['services']['yearEndTransactionTail'] ?? []);
        if (empty($tail['available'])) {
            return '<section class="settings-stack" id="year-end-transaction-tail">' . $this->renderErrors((array)($tail['errors'] ?? ['Transaction cut-off review is not available.'])) . '</section>';
        }

        $companySettings = (array)(((array)($context['company'] ?? []))['settings'] ?? []);
        $rowsHtml = '';
        foreach ((array)($tail['rows'] ?? []) as $row) {
            $rowsHtml .= '<tr>
                <td>' . HelperFramework::escape((string)($row['account'] ?? '')) . '</td>
                <td>' . HelperFramework::escape(HelperFramework::labelFromKey((string)($row['account_type'] ?? ''), '_')) . '</td>
                <td>' . HelperFramework::escape($this->displayDate((string)($row['last_transaction_date'] ?? ''))) . '</td>
                <td>' . HelperFramework::escape((string)($row['last_transaction_desc'] ?? '')) . '</td>
                <td class="numeric">' . HelperFramework::escape($row['last_transaction_amount'] === null ? '' : $this->money($companySettings, $row['last_transaction_amount'])) . '</td>
            </tr>';
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="5">No company accounts were found for this company.</td></tr>';
        }

        return '<section class="settings-stack" id="year-end-transaction-tail">
            <div class="helper">Review the last imported transaction on each company account before closing the accounting period.</div>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Account</th><th>Type</th><th>Last transaction date</th><th>Last transaction desc</th><th>Last transaction amount</th></tr></thead>
                    <tbody>' . $rowsHtml . '</tbody>
                </table>
            </div>
        </section>';
    }

    private function money(array $companySettings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }

    private function displayDate(string $date): string
    {
        return trim($date) !== '' ? HelperFramework::displayDate($date) : '';
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
