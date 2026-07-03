<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _year_end_retained_earningsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'year_end_retained_earnings';
    }

    public function title(): string
    {
        return 'Retained Earnings';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'yearEndRetainedEarnings',
                'service' => \eel_accounts\Service\RetainedEarningsCloseService::class,
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
        return ['year.end.state', 'year.end.checklist', 'year.end.retained.earnings'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $close = (array)($context['services']['yearEndRetainedEarnings'] ?? []);
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $companySettings = (array)($company['settings'] ?? []);
        $accountingPeriod = (array)($close['accounting_period'] ?? []);
        $accountingPeriodId = (int)($accountingPeriod['id'] ?? ($company['accounting_period_id'] ?? 0));

        if (empty($close['available'])) {
            return '<section class="settings-stack" id="year-end-retained-earnings">' . $this->renderErrors((array)($close['errors'] ?? ['Retained earnings close preview is not available.'])) . '</section>';
        }

        $summary = (array)($close['summary'] ?? []);
        $acknowledged = !empty($close['acknowledged']);
        $stale = !empty($close['acknowledgement_stale']);
        $journalLinesHtml = $this->journalLinesHtml((array)($close['journal_lines'] ?? []), $companySettings);
        $existingJournal = (array)($close['existing_journal'] ?? []);
        $existingHtml = $existingJournal !== []
            ? '<div class="helper">A retained earnings close journal already exists for this period and will be replaced from these agreed figures when the period is locked.</div>'
            : '';
        $staleHtml = $stale
            ? '<div class="helper">Figures have changed since the last agreement. Review them and agree again before locking.</div>'
            : '';

        $acknowledgementForm = '';
        if ($companyId > 0 && $accountingPeriodId > 0) {
            $acknowledgementForm = '<form method="post" data-ajax="true" class="panel-soft stack" data-year-end-ack-form="true">
                <input type="hidden" name="card_action" value="YearEnd">
                <input type="hidden" name="intent" value="save_retained_earnings_close_acknowledgement">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <label class="checkbox-row">
                    <input type="checkbox" name="retained_earnings_close_acknowledgement" value="1"' . ($acknowledged && !$stale ? ' checked' : '') . ' required data-year-end-ack-checkbox>
                    <span>I agree that current profit/loss will be carried into retained earnings when this Accounting Period is locked</span>
                </label>
                <button class="button primary" type="submit"
                    ' . ($acknowledged && !$stale ? '' : 'disabled ') . 'data-year-end-ack-submit
                    data-chicken-check="true"
                    data-chicken-title="Save retained earnings agreement"
                    data-chicken-message="This records that the retained earnings close has been reviewed for this accounting period.<br><br>Continue?"
                    data-chicken-confirm-text="I Agree"
                    data-chicken-button-class="button danger">I Agree</button>
            </form>';
        }

        return '<section class="settings-stack" id="year-end-retained-earnings">
            <div class="helper">When the period is locked, the app will carry current profit/loss into 3000 Retained Earnings and reset income and expense nominal balances for the next period (clear them). Original transactions, expense claims, and source journals are not changed.</div>
            <div class="month-grid">
                ' . $this->summaryCard('Opening equity', $this->money($companySettings, $summary['opening_equity'] ?? 0)) . '
                ' . $this->summaryCard('Current profit / loss', $this->money($companySettings, $summary['current_profit_loss'] ?? 0)) . '
                ' . $this->summaryCard('Closing equity before close', $this->money($companySettings, $summary['closing_equity_before_close'] ?? 0)) . '
                ' . $this->summaryCard('Retained earnings movement', $this->money($companySettings, $summary['retained_earnings_movement'] ?? 0)) . '
            </div>
            <div class="helper">' . HelperFramework::escape($this->balanceEquation($companySettings, $summary)) . '</div>
            ' . $staleHtml . $existingHtml . '
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Nominal</th><th>Description</th><th>Debit</th><th>Credit</th></tr></thead>
                    <tbody>' . $journalLinesHtml . '</tbody>
                </table>
            </div>
            <div class="actions-row">' . $acknowledgementForm . '</div>
        </section>';
    }

    private function journalLinesHtml(array $journalLines, array $companySettings): string
    {
        if ($journalLines === []) {
            return '<tr><td colspan="4">No retained earnings close journal is needed for the current figures.</td></tr>';
        }

        $html = '';
        foreach ($journalLines as $line) {
            $nominal = trim((string)($line['nominal_code'] ?? '') . ' ' . (string)($line['nominal_name'] ?? ''));
            if ($nominal === '') {
                $nominalId = (int)($line['nominal_account_id'] ?? 0);
                $nominal = $nominalId > 0 ? 'Nominal ' . $nominalId : '-';
            }

            $html .= '<tr>
                <td>' . HelperFramework::escape($nominal) . '</td>
                <td>' . HelperFramework::escape((string)($line['line_description'] ?? '')) . '</td>
                <td class="numeric">' . HelperFramework::escape($this->money($companySettings, $line['debit'] ?? 0)) . '</td>
                <td class="numeric">' . HelperFramework::escape($this->money($companySettings, $line['credit'] ?? 0)) . '</td>
            </tr>';
        }

        return $html;
    }

    private function summaryCard(string $label, string $value): string
    {
        return '<div class="panel-soft"><div class="eyebrow">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape($value) . '</div></div>';
    }

    private function balanceEquation(array $companySettings, array $summary): string
    {
        return 'Assets (' . $this->money($companySettings, $summary['assets'] ?? 0) . ') - Liabilities ('
            . $this->money($companySettings, $summary['liabilities'] ?? 0) . ') = Equity ('
            . $this->money($companySettings, $summary['equity'] ?? 0) . ')';
    }

    private function money(array $companySettings, float|int|string|null $value): string
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
