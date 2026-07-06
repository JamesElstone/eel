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
            $amountDisplay = $amount === null || trim((string)$amount) === '' ? '-' : $this->money($companySettings, $amount);
            $rowsHtml .= '<tr>
                <td>' . HelperFramework::escape((string)($row['account'] ?? '')) . '</td>
                <td>' . HelperFramework::escape(HelperFramework::labelFromKey((string)($row['account_type'] ?? ''), '_')) . '</td>
                <td>' . HelperFramework::escape($this->blankToDash($this->displayDate((string)($row['last_transaction_date'] ?? '')))) . '</td>
                <td>' . HelperFramework::escape($this->blankToDash((string)($row['last_transaction_desc'] ?? ''))) . '</td>
                <td class="numeric">' . HelperFramework::escape($amountDisplay) . '</td>
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
        $note = $acknowledged ? trim((string)($acknowledgement['note'] ?? '')) : '';

        if ($acknowledged) {
            return '<section class="panel-soft success settings-stack">
                <div class="eyebrow">Acknowledgement</div>
                ' . ($note !== '' ? '<div class="summary-value">' . HelperFramework::escape($note) . '</div>' : '') . '
                <div class="stat-foot">' . HelperFramework::escape($this->confirmationFoot($acknowledgedAt, $acknowledgedBy)) . '</div>
                <form method="post" data-ajax="true">
                    <input type="hidden" name="card_action" value="YearEnd">
                    <input type="hidden" name="intent" value="save_transaction_tail_acknowledgement">
                    <input type="hidden" name="company_id" value="' . $companyId . '">
                    <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                    <input type="hidden" name="transaction_tail_acknowledgement" value="0">
                    <button class="button" type="submit">Revoke acknowledgement</button>
                </form>
            </section>';
        }

        return '<section class="panel-soft warn full settings-stack">
            <div class="eyebrow">Acknowledgement</div>
            <form method="post" data-ajax="true" class="form-grid" data-year-end-ack-form="true">
                <input type="hidden" name="card_action" value="YearEnd">
                <input type="hidden" name="intent" value="save_transaction_tail_acknowledgement">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <label class="checkbox-row full">
                    <input type="checkbox" name="transaction_tail_acknowledgement" value="1" required data-year-end-ack-checkbox>
                    <span>I acknowledge that the latest transaction line for each company account has been reviewed before closing this Accounting Period</span>
                </label>
                <div class="form-row full">
                    <label for="transaction-tail-acknowledgement-note">Acknowledgement notes</label>
                    <textarea class="input" id="transaction-tail-acknowledgement-note" name="transaction_tail_acknowledgement_note" rows="3"></textarea>
                </div>
                <div class="actions-row"><button class="button primary" type="submit" disabled data-year-end-ack-submit>Save acknowledgement</button></div>
            </form>
        </section>';
    }

    private function confirmationFoot(string $acknowledgedAt, string $acknowledgedBy): string
    {
        return 'Confirmed'
            . ($acknowledgedAt !== '' ? ' at ' . $acknowledgedAt : '')
            . ($acknowledgedBy !== '' ? ' by ' . $acknowledgedBy : '')
            . '.';
    }

    private function money(array $companySettings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }

    private function displayDate(string $date): string
    {
        return trim($date) !== '' ? HelperFramework::displayDate($date) : '';
    }

    private function blankToDash(string $value): string
    {
        return trim($value) !== '' ? $value : '-';
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
