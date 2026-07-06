<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _year_end_expenses_confirmationCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'year_end_expenses_confirmation';
    }

    public function title(): string
    {
        return 'Year End Expenses Confirmation';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'yearEndExpensesConfirmation',
                'service' => \eel_accounts\Service\YearEndExpenseConfirmationService::class,
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
        return ['year.end.state', 'year.end.checklist', 'year.end.expenses.confirmation'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $expenses = (array)($context['services']['yearEndExpensesConfirmation'] ?? []);
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $companySettings = (array)($company['settings'] ?? []);
        $accountingPeriod = (array)($expenses['accounting_period'] ?? []);
        $accountingPeriodId = (int)($accountingPeriod['id'] ?? ($company['accounting_period_id'] ?? 0));

        if (empty($expenses['available'])) {
            return '<section class="settings-stack" id="year-end-expenses-confirmation">' . $this->renderErrors((array)($expenses['errors'] ?? ['Year-end expense confirmation is not available.'])) . '</section>';
        }

        $totals = (array)($expenses['totals'] ?? []);
        $acknowledged = !empty($expenses['expense_position_acknowledged']);
        $rowsHtml = '';
        foreach ((array)($expenses['claimants'] ?? []) as $claimant) {
            $rowsHtml .= '<tr>
                <td>' . HelperFramework::escape((string)($claimant['claimant_name'] ?? '')) . '</td>
                <td>' . HelperFramework::escape($this->displayDate((string)($claimant['last_claimed'] ?? ''))) . '</td>
                <td>' . HelperFramework::escape((string)($claimant['last_item_desc'] ?? '')) . '</td>
                <td class="numeric">' . HelperFramework::escape(!array_key_exists('last_expense_amount', $claimant) || $claimant['last_expense_amount'] === null ? '' : $this->money($companySettings, $claimant['last_expense_amount'])) . '</td>
                <td class="numeric">' . HelperFramework::escape($this->money($companySettings, $claimant['brought_forward'] ?? 0)) . '</td>
                <td class="numeric">' . HelperFramework::escape($this->money($companySettings, $claimant['claimed_total'] ?? 0)) . '</td>
                <td class="numeric">' . HelperFramework::escape($this->money($companySettings, $claimant['payments_made'] ?? 0)) . '</td>
                <td class="numeric">' . HelperFramework::escape($this->money($companySettings, $claimant['carried_forward'] ?? 0)) . '</td>
            </tr>';
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="8">No expense claim balances were found for this accounting period.</td></tr>';
        }

        $acknowledgementForm = '';
        if ($companyId > 0 && $accountingPeriodId > 0) {
            $acknowledgementForm = '<form method="post" data-ajax="true" class="panel-soft stack" data-year-end-ack-form="true">
                <input type="hidden" name="card_action" value="YearEnd">
                <input type="hidden" name="intent" value="save_expense_position_acknowledgement">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <label class="checkbox-row">
                    <input type="checkbox" name="expense_position_acknowledgement" value="1"' . ($acknowledged ? ' checked' : '') . ' required data-year-end-ack-checkbox>
                    <span>I acknowledge that the year-end expense claim position has been reviewed before closing this Accounting Period</span>
                </label>
                <button class="button primary" type="submit"
                    ' . ($acknowledged ? '' : 'disabled ') . 'data-year-end-ack-submit
                    data-chicken-check="true"
                    data-chicken-title="Save expense position acknowledgement"
                    data-chicken-message="This records that the expense claim position has been reviewed for this accounting period.<br><br>Continue?"
                    data-chicken-confirm-text="I Agree"
                    data-chicken-button-class="button danger">I Agree</button>
            </form>';
        }

        return '<section class="settings-stack" id="year-end-expenses-confirmation">
            <div class="month-grid">
                ' . $this->summaryCard('Balance brought forward (b/f)', $this->money($companySettings, $totals['brought_forward'] ?? 0)) . '
                ' . $this->summaryCard('Claimed in period', $this->money($companySettings, $totals['claimed_total'] ?? 0)) . '
                ' . $this->summaryCard('Payments in period', $this->money($companySettings, $totals['payments_made'] ?? 0)) . '
                ' . $this->summaryCard('Balance carried forward (c/f)', $this->money($companySettings, $totals['carried_forward'] ?? 0)) . '
            </div>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Claimant</th><th>Last claimed</th><th>Last item desc</th><th>Last expense amount</th><th>Balance brought forward (b/f)</th><th>Claimed</th><th>Payments</th><th>Balance carried forward (c/f)</th></tr></thead>
                    <tbody>' . $rowsHtml . '</tbody>
                </table>
            </div>
            <div class="actions-row">' . $acknowledgementForm . '</div>
        </section>';
    }

    private function summaryCard(string $label, string $value): string
    {
        return '<div class="panel-soft"><div class="eyebrow">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape($value) . '</div></div>';
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
