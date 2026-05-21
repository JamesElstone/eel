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
                'service' => TrialBalanceService::class,
                'method' => 'fetchTrialBalance',
                'params' => [
                    'companyId' => ':company_id',
                    'taxYearId' => ':tax_year_id',
                    'includeZero' => ':trial_balance_include_zero',
                    'includeUnposted' => ':trial_balance_include_unposted',
                    'filters' => ':trial_balance_filters',
                ],
            ],
            [
                'key' => 'trialBalanceValidation',
                'service' => TrialBalanceValidationService::class,
                'method' => 'fetchValidation',
                'params' => [
                    'companyId' => ':company_id',
                    'taxYearId' => ':tax_year_id',
                ],
            ],
            [
                'key' => 'trialBalanceComparison',
                'service' => TrialBalanceComparisonService::class,
                'method' => 'fetchComparison',
                'params' => [
                    'companyId' => ':company_id',
                    'taxYearId' => ':tax_year_id',
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

        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $taxYearId = (int)($company['tax_year_id'] ?? 0);
        $pageId = (string)(($context['page'] ?? [])['page_id'] ?? 'trial_balance');
        $validation = (array)($context['services']['trialBalanceValidation'] ?? []);
        $comparison = (array)($context['services']['trialBalanceComparison'] ?? []);
        $filters = (array)($trialBalance['filters'] ?? []);
        $viewMode = (string)($context['trial_balance_view_mode'] ?? 'summary');
        $includeZero = !empty($trialBalance['include_zero']);
        $includeUnposted = !empty($trialBalance['include_unposted']);
        $summary = (array)($trialBalance['summary'] ?? []);
        $taxComputation = (array)($summary['tax_computation'] ?? []);

        return '<div id="trial-balance-app" class="settings-stack">
            ' . $this->renderFilterPanel($company, (array)($trialBalance['tax_year'] ?? []), $companyId, $taxYearId, $pageId, $filters, $viewMode, $includeZero, $includeUnposted) . '
            ' . $this->renderSummaryPanel($summary, (string)($validation['ready_for_ct_working_papers'] ?? 'Not ready')) . '
            ' . $this->renderValidationPanel($validation) . '
            ' . $this->renderNominalRowsPanel((array)($trialBalance['rows'] ?? []), $viewMode) . '
            ' . $this->renderTaxLossPanel($taxComputation) . '
            ' . $this->renderComparisonPanel($comparison) . '
        </div>';
    }

    private function renderFilterPanel(
        array $company,
        array $taxYear,
        int $companyId,
        int $taxYearId,
        string $pageId,
        array $filters,
        string $viewMode,
        bool $includeZero,
        bool $includeUnposted
    ): string {
        $formId = 'trial-balance-filter-form';
        $search = (string)($filters['search'] ?? '');
        $accountType = (string)($filters['account_type'] ?? 'all');
        $focus = (string)($filters['focus'] ?? 'all');
        $csvUrl = 'api/trial-balance/export-csv.php?' . http_build_query([
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
            'include_zero' => $includeZero ? '1' : '0',
            'include_unposted' => $includeUnposted ? '1' : '0',
            'search' => $search,
            'account_type' => $accountType,
            'focus' => $focus,
        ]);

        return '<section class="panel-soft">
            <form id="' . $formId . '" method="get" action="?page=trial-balance" data-ajax="true">
                <input type="hidden" name="page" value="' . HelperFramework::escape($pageId) . '">
                <input type="hidden" name="card_action" value="TrialBalance">
                <input type="hidden" name="intent" value="filter">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="tax_year_id" value="' . $taxYearId . '">
            </form>
            <div class="form-grid">
                <div class="form-row">
                    <label>Company</label>
                    <input class="input" value="' . HelperFramework::escape((string)($company['name'] ?? '')) . '" readonly>
                </div>
                <div class="form-row">
                    <label>Accounting period</label>
                    <input class="input" value="' . HelperFramework::escape((string)($taxYear['label'] ?? '')) . '" readonly>
                </div>
                <div class="form-row">
                    <label for="trial-balance-view-mode">View mode</label>
                    <select class="select" id="trial-balance-view-mode" name="view_mode" form="' . $formId . '">' . $this->options(['summary' => 'Summary', 'detailed' => 'Detailed'], $viewMode) . '</select>
                </div>
                <div class="form-row">
                    <label for="trial-balance-search">Search nominal</label>
                    <input class="input" id="trial-balance-search" name="search" form="' . $formId . '" value="' . HelperFramework::escape($search) . '" placeholder="Code or name">
                </div>
                <div class="form-row">
                    <label for="trial-balance-account-type">Account type</label>
                    <select class="select" id="trial-balance-account-type" name="account_type" form="' . $formId . '">' . $this->options($this->accountTypeOptions(), $accountType) . '</select>
                </div>
                <div class="form-row">
                    <label for="trial-balance-focus">Quick toggle</label>
                    <select class="select" id="trial-balance-focus" name="focus" form="' . $formId . '">' . $this->options($this->focusOptions(), $focus) . '</select>
                </div>
            </div>
            <div class="actions-row">
                <label class="checkbox-item">
                    <input type="checkbox" id="trial-balance-include-zero" name="include_zero" value="1" form="' . $formId . '"' . ($includeZero ? ' checked' : '') . '>
                    <div class="checkbox-copy"><strong>Include zero-balance accounts</strong><span>Show the full nominal list for the selected period.</span></div>
                </label>
                <label class="checkbox-item">
                    <input type="checkbox" id="trial-balance-include-unposted" name="include_unposted" value="1" form="' . $formId . '"' . ($includeUnposted ? ' checked' : '') . '>
                    <div class="checkbox-copy"><strong>Include unposted journals</strong><span>Advisory only. The standard TB remains posted-ledger first.</span></div>
                </label>
            </div>
            <div class="actions-row">
                <button class="button primary" type="submit" form="' . $formId . '">Refresh</button>
                <a class="button" href="' . HelperFramework::escape($csvUrl) . '">CSV</a>
                <button class="button" type="button" disabled>Printable view</button>
            </div>
        </section>';
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
                ' . $this->metricValue((mixed)($check['metric_value'] ?? null)) . '
            </div>';
        }

        return '<section class="panel-soft">
            <div class="status-head">
                <h3 class="card-title">Validation</h3>
            </div>
            <div class="settings-stack">' . $checksHtml . '</div>
            <div>
                <h3 class="card-title">Month readiness</h3>
                <div class="month-grid">' . $this->monthTiles((array)($validation['month_tiles'] ?? [])) . '</div>
            </div>
        </section>';
    }

    private function renderNominalRowsPanel(array $rows, string $viewMode): string
    {
        if ($rows === []) {
            return $this->panel('', '<div class="helper">No nominal accounts match the current filters.</div>');
        }

        $html = '';
        foreach ($rows as $row) {
            $flags = implode(' ', array_map(
                static fn(array $flag): string => '<span class="badge info">' . HelperFramework::escape((string)($flag['label'] ?? '')) . '</span>',
                (array)($row['flags'] ?? [])
            ));
            $html .= '<tr>
                <td>' . HelperFramework::escape((string)($row['nominal_code'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($row['nominal_name'] ?? '')) . '</td>
                <td>' . HelperFramework::escape(HelperFramework::labelFromKey((string)($row['account_type'] ?? ''), '_')) . '</td>
                <td>' . HelperFramework::escape((string)($row['subtype_name'] ?? '')) . '</td>
                <td>' . HelperFramework::escape(FormattingFramework::money($row[$viewMode === 'detailed' ? 'total_debit' : 'display_debit'] ?? 0)) . '</td>
                <td>' . HelperFramework::escape(FormattingFramework::money($row[$viewMode === 'detailed' ? 'total_credit' : 'display_credit'] ?? 0)) . '</td>
                <td>' . HelperFramework::escape(FormattingFramework::money($row['net_movement'] ?? 0)) . '</td>
                <td>' . $flags . '</td>
            </tr>';
        }

        return '<section class="panel-soft">
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Code</th><th>Nominal</th><th>Type</th><th>Subtype</th><th>Debit</th><th>Credit</th><th>Net</th><th>Flags</th></tr></thead>
                    <tbody>' . $html . '</tbody>
                </table>
            </div>
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
            <div class="summary-grid">
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

    private function options(array $options, string $selectedValue): string
    {
        $html = '';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . HelperFramework::escape((string)$value) . '"' . ((string)$value === $selectedValue ? ' selected' : '') . '>' . HelperFramework::escape((string)$label) . '</option>';
        }

        return $html;
    }

    private function accountTypeOptions(): array
    {
        return [
            'all' => 'All',
            'asset' => 'Asset',
            'liability' => 'Liability',
            'equity' => 'Equity',
            'income' => 'Income',
            'cost_of_sales' => 'Cost of sales',
            'expense' => 'Expense',
        ];
    }

    private function focusOptions(): array
    {
        return [
            'all' => 'All accounts',
            'income_statement' => 'Income statement',
            'balance_sheet' => 'Balance sheet',
            'exception' => 'Exception accounts only',
        ];
    }

    private function summaryCard(string $label, string $value, bool $trustedValue = false): string
    {
        return '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . ($trustedValue ? $value : HelperFramework::escape($value)) . '</div></div>';
    }

    private function metricValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_array($value)) {
            $value = implode(', ', array_map(
                static fn(string $key, mixed $metric): string => HelperFramework::labelFromKey($key, '_') . ': ' . (is_numeric($metric) ? FormattingFramework::money($metric) : (string)$metric),
                array_keys($value),
                $value
            ));
        } elseif (is_numeric($value)) {
            $value = FormattingFramework::money($value);
        }

        return '<div><strong>' . HelperFramework::escape((string)$value) . '</strong></div>';
    }

    private function monthTiles(array $tiles): string
    {
        $html = '';
        foreach ($tiles as $tile) {
            $monthKey = (string)($tile['month_key'] ?? '');
            $year = $monthKey !== '' ? substr($monthKey, 0, 4) : '';
            $html .= '<div class="month-tile ' . HelperFramework::escape((string)($tile['status'] ?? 'red')) . '">
                <div class="month-head"><div><div class="month-name">' . HelperFramework::escape((string)($tile['month_short_name'] ?? '')) . '</div><div class="month-year">' . HelperFramework::escape($year) . '</div></div><span class="month-dot"></span></div>
                <div class="month-metric">' . (int)($tile['transaction_count'] ?? 0) . '</div>
                <div class="helper">' . (int)($tile['statement_upload_count'] ?? 0) . ' upload(s)</div>
                <div class="helper">' . (int)($tile['uncategorised_count'] ?? 0) . ' uncategorised</div>
                <div class="helper">' . (int)($tile['suspense_count'] ?? 0) . ' suspense</div>
            </div>';
        }

        return $html !== '' ? $html : '<div class="helper">No month readiness data is available for this period.</div>';
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
