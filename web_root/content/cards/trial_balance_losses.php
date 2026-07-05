<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _trial_balance_lossesCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'trial_balance_losses';
    }

    public function title(): string
    {
        return 'Trial Balance Losses';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'trialBalancePageData',
                'service' => \eel_accounts\Service\TrialBalanceService::class,
                'method' => 'fetchTrialBalance',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                    'includeZero' => false,
                    'includeUnposted' => false,
                    'filters' => [],
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
        $trialBalance = (array)($context['services']['trialBalancePageData'] ?? []);
        if (empty($trialBalance['available'])) {
            return $this->renderErrors((array)($trialBalance['errors'] ?? ['Trial balance is not available for the selected period.']));
        }

        $summary = (array)($trialBalance['summary'] ?? []);
        $taxComputation = (array)($summary['tax_computation'] ?? []);
        $companySettings = (array)(($context['company'] ?? [])['settings'] ?? []);

        if (empty($taxComputation['available'])) {
            return $this->renderErrors((array)($taxComputation['errors'] ?? ['Tax computation is not available for this period yet.']));
        }

        $periods = array_values(array_filter(
            (array)($taxComputation['periods'] ?? []),
            static fn(mixed $period): bool => is_array($period)
        ));

        return '<div>
            <div class="summary-grid">
                ' . $this->summaryCard('Estimated corporation tax', $this->money($companySettings, $taxComputation['estimated_corporation_tax'] ?? 0)) . '
                ' . $this->summaryCard('Taxable profit', $this->money($companySettings, $taxComputation['taxable_profit'] ?? 0)) . '
                ' . $this->summaryCard('Loss created', $this->money($companySettings, $taxComputation['loss_created_in_period'] ?? 0)) . '
                ' . $this->summaryCard('Brought forward', $this->money($companySettings, $taxComputation['losses_brought_forward'] ?? 0)) . '
                ' . $this->summaryCard('Utilised', $this->money($companySettings, $taxComputation['losses_used'] ?? 0)) . '
                ' . $this->summaryCard('Carried forward', $this->money($companySettings, $taxComputation['losses_carried_forward'] ?? 0)) . '
            </div>
            ' . $this->renderTaxDetail($companySettings, $taxComputation, $periods) . '
        </div>';
    }

    private function renderTaxDetail(array $companySettings, array $taxComputation, array $periods): string
    {
        if (count($periods) > 1) {
            return $this->renderPeriodBreakdown($companySettings, $periods);
        }

        $steps = (array)($taxComputation['steps'] ?? []);
        if ($steps === [] && count($periods) === 1) {
            $steps = (array)($periods[0]['steps'] ?? []);
        }

        if ($steps === []) {
            return count($periods) === 1 ? $this->renderPeriodBreakdown($companySettings, $periods) : '';
        }

        $stepsHtml = '';
        foreach ($steps as $step) {
            $stepsHtml .= '<tr><td>' . HelperFramework::escape((string)($step['label'] ?? '')) . '</td><td>' . HelperFramework::escape($this->money($companySettings, $step['amount'] ?? 0)) . '</td></tr>';
        }

        return '<section class="panel-soft">
            <h3 class="card-title">Tax computation steps</h3>
            <div class="table-scroll">
                <table><thead><tr><th>Step</th><th>Amount</th></tr></thead><tbody>' . $stepsHtml . '</tbody></table>
            </div>
        </section>';
    }

    private function renderPeriodBreakdown(array $companySettings, array $periods): string
    {
        $rows = '';
        foreach ($periods as $period) {
            $warningCount = count((array)($period['warnings'] ?? []));
            $rows .= '<tr>
                <td>' . HelperFramework::escape($this->periodLabel($period)) . '</td>
                <td>' . HelperFramework::escape($this->money($companySettings, $period['taxable_profit'] ?? 0)) . '</td>
                <td>' . HelperFramework::escape($this->money($companySettings, $period['estimated_corporation_tax'] ?? 0)) . '</td>
                <td>' . HelperFramework::escape($this->money($companySettings, $period['loss_created_in_period'] ?? 0)) . '</td>
                <td>' . HelperFramework::escape($this->money($companySettings, $period['losses_used'] ?? 0)) . '</td>
                <td>' . HelperFramework::escape($this->money($companySettings, $period['losses_carried_forward'] ?? 0)) . '</td>
                <td>' . $warningCount . '</td>
            </tr>';
        }

        return '<section class="panel-soft">
            <h3 class="card-title">CT period breakdown</h3>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>CT period</th><th>Taxable profit</th><th>Estimated CT</th><th>Loss created</th><th>Loss used</th><th>Losses c/f</th><th>Warnings</th></tr></thead>
                    <tbody>' . $rows . '</tbody>
                </table>
            </div>
        </section>';
    }

    private function periodLabel(array $period): string
    {
        $label = trim((string)($period['period_label'] ?? $period['label'] ?? ''));
        if ($label !== '') {
            return $label;
        }

        $start = trim((string)($period['period_start'] ?? ''));
        $end = trim((string)($period['period_end'] ?? ''));
        if ($start !== '' && $end !== '') {
            return $start . ' to ' . $end;
        }

        return 'CT period';
    }

    private function summaryCard(string $label, string $value): string
    {
        return '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape($value) . '</div></div>';
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
