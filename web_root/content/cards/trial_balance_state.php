<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _trial_balance_stateCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'trial_balance_state';
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
            [
                'key' => 'trialBalanceValidation',
                'service' => \eel_accounts\Service\TrialBalanceValidationService::class,
                'method' => 'fetchValidation',
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

    public function title(): string
    {
        return 'Trial Balance';
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

        $validation = (array)($context['services']['trialBalanceValidation'] ?? []);
        $comparison = (array)($context['services']['trialBalanceComparison'] ?? []);
        $summary = (array)($trialBalance['summary'] ?? []);
        $taxComputation = (array)($summary['tax_computation'] ?? []);

        return '<div id="trial-balance-app" class="settings-stack">
            ' . $this->renderSummaryPanel($summary, (string)($validation['ready_for_ct_working_papers'] ?? 'Not ready')) . '
            ' . $this->renderValidationPanel($validation) . '
            ' . $this->renderTaxLossPanel($taxComputation) . '
            ' . $this->renderComparisonPanel($comparison) . '
        </div>';
    }

    private function renderSummaryPanel(array $summary, string $readiness): string
    {
        $status = (array)($summary['trial_balance_status'] ?? []);
        $readyClass = str_contains(strtolower($readiness), 'ready') && !str_starts_with(strtolower($readiness), 'not')
            ? 'success'
            : 'danger';

        return '<section class="panel-soft">
            <div class="status-head">
                <h3 class="card-title">Summary</h3>
                <span class="badge ' . $readyClass . '">' . HelperFramework::escape($readiness) . '</span>
            </div>
            <div class="summary-grid">
                ' . $this->summaryCard('Trial Balance status', '<span class="badge ' . (!empty($status['is_balanced']) ? 'success' : 'danger') . '">' . HelperFramework::escape((string)($status['label'] ?? 'Not balanced')) . '</span>', true) . '
                ' . $this->summaryCard('Profit before tax', FormattingFramework::money($summary['profit_before_tax'] ?? 0)) . '
                ' . $this->summaryCard('Net assets', FormattingFramework::money($summary['net_assets'] ?? 0)) . '
                ' . $this->summaryCard('Solvency flag', $this->solvencyFlag($summary['net_assets'] ?? 0), true) . '
                ' . $this->summaryCard('Bank balance total', FormattingFramework::money($summary['bank_balance_total'] ?? 0)) . '
                ' . $this->summaryCard('Director loan balance', FormattingFramework::money($summary['director_loan_balance'] ?? 0)) . '
                ' . $this->summaryCard('VAT control balance', FormattingFramework::money($summary['vat_control_balance'] ?? 0)) . '
                ' . $this->summaryCard('Uncategorised / suspense', FormattingFramework::money($summary['uncategorised_exposure'] ?? 0)) . '
                ' . $this->summaryCard('Corporation tax nominal', FormattingFramework::money($summary['corporation_tax_balance'] ?? 0)) . '
            </div>
        </section>';
    }

    private function renderValidationPanel(array $validation): string
    {
        if (empty($validation['available'])) {
            return $this->panel('Validation', $this->renderErrors((array)($validation['errors'] ?? [])));
        }

        $checksHtml = '';
        foreach ((array)($validation['checks'] ?? []) as $check) {
            $status = (string)($check['status'] ?? 'warning');
            $checksHtml .= '<div class="panel-soft">
                <div class="status-head">
                    <h4 class="card-title">' . HelperFramework::escape((string)($check['title'] ?? 'Check')) . '</h4>
                    <span class="badge ' . $this->badgeClass($status) . '">' . HelperFramework::escape($status) . '</span>
                </div>
                <div class="helper">' . HelperFramework::escape((string)($check['detail'] ?? '')) . '</div>
                ' . $this->metricValue($check['metric_value'] ?? null) . '
            </div>';
        }

        return '<section class="panel-soft">
            <div class="status-head">
                <h3 class="card-title">Validation</h3>
            </div>
            <div class="settings-stack">' . $checksHtml . '</div>
        </section>';
    }

    private function renderTaxLossPanel(array $taxComputation): string
    {
        if (empty($taxComputation['available'])) {
            return $this->panel('Tax Losses / Brought Forward Losses', $this->renderErrors((array)($taxComputation['errors'] ?? ['Tax computation is not available for this period yet.'])));
        }

        $stepsHtml = '';
        foreach ((array)($taxComputation['steps'] ?? []) as $step) {
            $stepsHtml .= '<tr><td>' . HelperFramework::escape((string)($step['label'] ?? '')) . '</td><td>' . HelperFramework::escape(FormattingFramework::money($step['amount'] ?? 0)) . '</td></tr>';
        }

        return '<section class="panel-soft">
            <div class="status-head"><h3 class="card-title">Tax Losses / Brought Forward Losses</h3></div>
            <div class="summary-grid four">
                ' . $this->summaryCard('Loss created', FormattingFramework::money($taxComputation['loss_created_in_period'] ?? 0)) . '
                ' . $this->summaryCard('Brought forward', FormattingFramework::money($taxComputation['losses_brought_forward'] ?? 0)) . '
                ' . $this->summaryCard('Utilised', FormattingFramework::money($taxComputation['losses_used'] ?? 0)) . '
                ' . $this->summaryCard('Carried forward', FormattingFramework::money($taxComputation['losses_carried_forward'] ?? 0)) . '
            </div>
            <h3 class="card-title">Tax computation steps</h3>
            <div class="table-scroll">
                <table><thead><tr><th>Step</th><th>Amount</th></tr></thead><tbody>' . $stepsHtml . '</tbody></table>
            </div>
        </section>';
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

    private function summaryCard(string $label, string $value, bool $trustedValue = false): string
    {
        return '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . ($trustedValue ? $value : HelperFramework::escape($value)) . '</div></div>';
    }

    private function solvencyFlag(mixed $netAssets): string
    {
        $potentiallyInsolvent = (float)$netAssets < 0.0;
        $class = $potentiallyInsolvent ? 'danger' : 'success';
        $label = $potentiallyInsolvent ? 'Potentially Insolvent' : 'OK';

        return '<span class="badge ' . $class . '">' . HelperFramework::escape($label) . '</span>';
    }

    private function metricValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return '<div><strong>' . HelperFramework::escape($this->metricText($value)) . '</strong></div>';
    }

    private function metricText(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_numeric($value)) {
            return FormattingFramework::money($value);
        }

        if (!is_array($value)) {
            return (string)$value;
        }

        if ($this->isListArray($value)) {
            return count($value) . ' item' . (count($value) === 1 ? '' : 's');
        }

        $parts = [];
        foreach ($value as $key => $metric) {
            $label = HelperFramework::labelFromKey((string)$key, '_');
            $parts[] = $label . ': ' . $this->metricText($metric);
        }

        return implode(', ', $parts);
    }

    private function isListArray(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }

    private function panel(string $title, string $body): string
    {
        return '<section class="panel-soft">' . ($title !== '' ? '<div class="status-head"><h3 class="card-title">' . HelperFramework::escape($title) . '</h3></div>' : '') . $body . '</section>';
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
