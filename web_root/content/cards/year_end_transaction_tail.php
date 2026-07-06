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
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriod = (array)($tail['accounting_period'] ?? []);
        $accountingPeriodId = (int)($accountingPeriod['id'] ?? ($company['accounting_period_id'] ?? 0));
        $acknowledgement = $tail['acknowledgement'] ?? null;
        $rowsHtml = '';
        foreach ((array)($tail['rows'] ?? []) as $row) {
            $amount = array_key_exists('last_transaction_amount', $row) ? $row['last_transaction_amount'] : null;
            $rowsHtml .= '<tr>
                <td>' . HelperFramework::escape((string)($row['account'] ?? '')) . '</td>
                <td>' . HelperFramework::escape(HelperFramework::labelFromKey((string)($row['account_type'] ?? ''), '_')) . '</td>
                <td>' . HelperFramework::escape($this->displayDate((string)($row['last_transaction_date'] ?? ''))) . '</td>
                <td>' . HelperFramework::escape((string)($row['last_transaction_desc'] ?? '')) . '</td>
                <td class="numeric">' . HelperFramework::escape($amount === null ? '' : $this->money($companySettings, $amount)) . '</td>
            </tr>';
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="5">No company accounts were found for this company.</td></tr>';
        }

        return '<section class="settings-stack" id="year-end-transaction-tail">
            <div class="helper">Review the last imported transaction on each company account before closing the accounting period.</div>
            <div class="table-scroll panel-soft">
                <table>
                    <thead><tr><th>Account</th><th>Type</th><th>Last transaction date</th><th>Last transaction desc</th><th>Last transaction amount</th></tr></thead>
                    <tbody>' . $rowsHtml . '</tbody>
                </table>
            </div>
            ' . $this->acknowledgementHtml(is_array($acknowledgement) ? $acknowledgement : null, $companyId, $accountingPeriodId) . '
        </section>';
    }

    private function acknowledgementHtml(?array $acknowledgement, int $companyId, int $accountingPeriodId): string
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return '';
        }

        $acknowledged = $acknowledgement !== null;
        $acknowledgedAt = $acknowledged ? trim((string)($acknowledgement['acknowledged_at'] ?? '')) : '';
        $acknowledgedBy = $acknowledged ? trim((string)($acknowledgement['acknowledged_by'] ?? '')) : '';

        return '<div class="actions-row"><form method="post" data-ajax="true" class="panel-soft stack" data-year-end-transaction-tail-ack-form="true">
                <input type="hidden" name="card_action" value="YearEnd">
                <input type="hidden" name="intent" value="save_transaction_tail_acknowledgement">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="transaction_tail_acknowledgement" value="0">
                <label class="checkbox-row">
                    <input type="checkbox" name="transaction_tail_acknowledgement" value="1"' . ($acknowledged ? ' checked' : '') . ' required data-year-end-transaction-tail-ack-checkbox>
                    <span>I acknowledge that the latest transaction line for each company account has been reviewed before closing this Accounting Period</span>
                </label>
                ' . ($acknowledged ? '<div class="helper">Confirmed' . ($acknowledgedAt !== '' ? ' at ' . HelperFramework::escape($acknowledgedAt) : '') . ($acknowledgedBy !== '' ? ' by ' . HelperFramework::escape($acknowledgedBy) : '') . '.</div>' : '') . '
                <button class="button primary" type="submit"
                    data-year-end-transaction-tail-ack-submit
                    data-chicken-check="true"
                    data-chicken-title="Save transaction cut-off acknowledgement"
                    data-chicken-message="This records that the latest transaction line for each company account has been reviewed for this accounting period.<br><br>Continue?"
                    data-chicken-confirm-text="I Agree"
                    data-chicken-button-class="button danger">I Agree</button>
            </form></div>';
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
