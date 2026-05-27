<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _year_end_stateCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'year_end_state';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'yearEndChecklist',
                'service' => YearEndChecklistService::class,
                'method' => 'fetchChecklist',
                'params' => [
                    'companyId' => ':company_id',
                    'accountingPeriodId' => ':accounting_period_id',
                    'persist' => false,
                ],
            ],
            [
                'key' => 'yearEndTaxReadiness',
                'service' => YearEndTaxReadinessService::class,
                'method' => 'fetchSummary',
                'params' => [
                    'companyId' => ':company_id',
                    'accountingPeriodId' => ':accounting_period_id',
                ],
            ],
            [
                'key' => 'yearEndOpeningBalances',
                'service' => OpeningBalanceService::class,
                'method' => 'fetchContext',
                'params' => [
                    'companyId' => ':company_id',
                    'accountingPeriodId' => ':accounting_period_id',
                ],
            ],
            [
                'key' => 'yearEndAdjustments',
                'service' => YearEndAdjustmentService::class,
                'method' => 'fetchContext',
                'params' => [
                    'companyId' => ':company_id',
                    'accountingPeriodId' => ':accounting_period_id',
                ],
            ],
            [
                'key' => 'yearEndCompaniesHouseComparison',
                'service' => YearEndCompaniesHouseComparisonService::class,
                'method' => 'fetchComparison',
                'params' => [
                    'companyId' => ':company_id',
                    'accountingPeriodId' => ':accounting_period_id',
                ],
            ],
        ];
    }

    public function title(): string
    {
        return 'Year-End Readiness';
    }

    protected function additionalInvalidationFacts(): array
    {
        return [];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $checklist = (array)($context['services']['yearEndChecklist'] ?? []);
        $taxReadiness = (array)($context['services']['yearEndTaxReadiness'] ?? []);
        $openingBalances = (array)($context['services']['yearEndOpeningBalances'] ?? []);
        $adjustments = (array)($context['services']['yearEndAdjustments'] ?? []);
        $comparison = (array)($context['services']['yearEndCompaniesHouseComparison'] ?? []);
        if ($checklist === []) {
            return '<div class="helper">Year-end checklist is not available for the selected accounting period.</div>';
        }

        return $this->renderControls($context, $checklist)
            . $this->renderBookkeepingSection($checklist)
            . $this->renderCheckSections($checklist)
            . $this->renderOpeningBalances($context, $openingBalances)
            . $this->renderAdjustments($context, $adjustments)
            . $this->renderTaxReadiness($taxReadiness)
            . $this->renderCompaniesHouseComparison($comparison);
    }

    private function renderControls(array $context, array $checklist): string
    {
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriod = (array)($checklist['accounting_period'] ?? []);
        $accountingPeriodId = (int)($accountingPeriod['id'] ?? ($company['accounting_period_id'] ?? 0));
        $review = (array)($checklist['review'] ?? []);
        $isLocked = !empty($review['is_locked']);
        $lockIntent = $isLocked ? 'unlock_period' : 'lock_period';
        $lockLabel = $isLocked ? 'Unlock Period' : 'Lock Period';

        return '
            <section class="settings-stack">
                <div class="form-grid">
                    <div class="form-row">
                        <label>Company</label>
                        <input class="input" value="' . HelperFramework::escape((string)($company['name'] ?? '')) . '" readonly>
                    </div>
                    <div class="form-row">
                        <label>Status</label>
                        <div><span class="badge ' . HelperFramework::escape($this->badgeClass((string)($checklist['overall_status'] ?? ''))) . '">' . HelperFramework::escape(HelperFramework::labelFromKey((string)($checklist['overall_status'] ?? ''), '_')) . '</span></div>
                    </div>
                    <div class="form-row">
                        <label>Last recalculated</label>
                        <div>' . HelperFramework::escape((string)($checklist['last_recalculated_at'] ?? '')) . '</div>
                    </div>
                </div>
                <div class="actions-row">
                    ' . $this->actionForm($companyId, $accountingPeriodId, 'recalculate', 'Recalculate') . '
                    ' . $this->actionForm($companyId, $accountingPeriodId, $lockIntent, $lockLabel) . '
                    <button class="button" type="button" disabled>Export checklist</button>
                </div>
                <form method="post" data-ajax="true">
                    <input type="hidden" name="card_action" value="YearEnd">
                    <input type="hidden" name="intent" value="save_notes">
                    <input type="hidden" name="company_id" value="' . $companyId . '">
                    <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                    <div class="form-row full">
                        <label for="year-end-review-notes">Year end notes</label>
                        <textarea class="input" id="year-end-review-notes" name="review_notes" style="min-height:120px;">' . HelperFramework::escape((string)($review['review_notes'] ?? '')) . '</textarea>
                    </div>
                    <div><button class="button primary" type="submit">Save notes</button></div>
                </form>
            </section>';
    }

    private function actionForm(int $companyId, int $accountingPeriodId, string $intent, string $label): string
    {
        return '<form method="post" data-ajax="true">
            <input type="hidden" name="card_action" value="YearEnd">
            <input type="hidden" name="intent" value="' . HelperFramework::escape($intent) . '">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
            <button class="button primary" type="submit">' . HelperFramework::escape($label) . '</button>
        </form>';
    }

    private function renderBookkeepingSection(array $checklist): string
    {
        $tilesHtml = '';
        foreach ((array)($checklist['month_tiles'] ?? []) as $tile) {
            $label = (string)($tile['label'] ?? '');
            $parts = explode(' ', $label);
            $month = (string)($tile['month_short_name'] ?? ($parts[0] ?? ''));
            $year = (string)($parts[1] ?? '');
            $tilesHtml .= '<div class="month-tile ' . HelperFramework::escape((string)($tile['status'] ?? 'red')) . '">
                <div class="month-head">
                    <div><div class="month-name">' . HelperFramework::escape($month) . '</div><div class="month-year">' . HelperFramework::escape($year) . '</div></div>
                    <span class="month-dot"></span>
                </div>
                <div class="month-metric">' . (int)($tile['transaction_count'] ?? 0) . '</div>
                <div class="helper">' . (int)($tile['statement_upload_count'] ?? 0) . ' upload(s)</div>
                <div class="helper">' . (int)($tile['uncategorised_count'] ?? 0) . ' uncategorised</div>
                <div class="helper">' . (int)($tile['suspense_count'] ?? 0) . ' suspense</div>
            </div>';
        }

        if ($tilesHtml === '') {
            return '';
        }

        return '<section class="settings-stack"><h3 class="card-title">A. Bookkeeping completeness</h3><div class="month-grid">' . $tilesHtml . '</div></section>';
    }

    private function renderCheckSections(array $checklist): string
    {
        $sections = (array)($checklist['sections'] ?? []);
        unset($sections['bookkeeping_completeness']);

        $html = '';
        foreach ($sections as $key => $checks) {
            $html .= '<section class="settings-stack"><h3 class="card-title">' . HelperFramework::escape($this->sectionTitle((string)$key)) . '</h3><div class="settings-stack">';
            foreach ((array)$checks as $check) {
                $html .= $this->renderCheck((array)$check);
            }
            $html .= '</div></section>';
        }

        return $html;
    }

    private function renderCheck(array $check): string
    {
        $actionUrl = trim((string)($check['action_url'] ?? ''));

        return '<div class="panel-soft">
            <div class="status-head">
                <h4 class="card-title">' . HelperFramework::escape((string)($check['title'] ?? '')) . '</h4>
                <span class="badge ' . HelperFramework::escape($this->badgeClass((string)($check['status'] ?? ''))) . '">' . HelperFramework::escape(HelperFramework::labelFromKey((string)($check['status'] ?? ''), '_')) . '</span>
            </div>
            <div class="helper">' . HelperFramework::escape((string)($check['detail_text'] ?? '')) . '</div>
            ' . (trim((string)($check['metric_value'] ?? '')) !== '' ? '<div><strong>' . HelperFramework::escape((string)$check['metric_value']) . '</strong></div>' : '') . '
            ' . ($actionUrl !== '' ? '<div><a class="button" href="' . HelperFramework::escape($actionUrl) . '">Open related workflow</a></div>' : '') . '
        </div>';
    }

    private function renderTaxReadiness(array $taxReadiness): string
    {
        if (empty($taxReadiness['available'])) {
            return '<section class="settings-stack" id="tax-readiness"><h3 class="card-title">Tax Readiness Snapshot</h3><div class="helper">' . HelperFramework::escape((string)($taxReadiness['errors'][0] ?? 'Tax readiness is not available.')) . '</div></section>';
        }

        $stepsHtml = '';
        foreach ((array)($taxReadiness['steps'] ?? []) as $step) {
            $stepsHtml .= '<tr><td>' . HelperFramework::escape((string)($step['label'] ?? '')) . '</td><td>' . HelperFramework::escape(FormattingFramework::money($step['amount'] ?? 0)) . '</td></tr>';
        }

        $scheduleHtml = '';
        foreach ((array)($taxReadiness['schedule'] ?? []) as $row) {
            $scheduleHtml .= '<tr>
                <td>' . HelperFramework::escape((string)($row['label'] ?? '')) . '</td>
                <td>' . HelperFramework::escape(FormattingFramework::money($row['loss_created'] ?? 0)) . '</td>
                <td>' . HelperFramework::escape(FormattingFramework::money($row['loss_brought_forward'] ?? 0)) . '</td>
                <td>' . HelperFramework::escape(FormattingFramework::money($row['loss_utilised'] ?? 0)) . '</td>
                <td>' . HelperFramework::escape(FormattingFramework::money($row['loss_carried_forward'] ?? 0)) . '</td>
            </tr>';
        }

        return '<section class="settings-stack" id="tax-readiness">
            <h3 class="card-title">Tax Readiness Snapshot</h3>
            <div class="summary-grid">
                <div class="summary-card"><div class="summary-label">Taxable profit</div><div class="summary-value">' . HelperFramework::escape(FormattingFramework::money($taxReadiness['taxable_profit'] ?? 0)) . '</div></div>
                <div class="summary-card"><div class="summary-label">Taxable loss</div><div class="summary-value">' . HelperFramework::escape(FormattingFramework::money($taxReadiness['taxable_loss'] ?? 0)) . '</div></div>
                <div class="summary-card"><div class="summary-label">Estimated CT</div><div class="summary-value">' . HelperFramework::escape(FormattingFramework::money($taxReadiness['estimated_corporation_tax'] ?? 0)) . '</div></div>
                <div class="summary-card"><div class="summary-label">Losses c/f</div><div class="summary-value">' . HelperFramework::escape(FormattingFramework::money($taxReadiness['losses_carried_forward'] ?? 0)) . '</div></div>
            </div>
            <h3 class="card-title">Corporation Tax Computation</h3>
            <div class="table-scroll"><table><thead><tr><th>Step</th><th>Amount</th></tr></thead><tbody>' . $stepsHtml . '</tbody></table></div>
            <h3 class="card-title">Loss schedule</h3>
            <div class="table-scroll"><table><thead><tr><th>Period</th><th>Loss created</th><th>Brought forward</th><th>Used</th><th>Carried forward</th></tr></thead><tbody>' . $scheduleHtml . '</tbody></table></div>
        </section>';
    }

    private function renderOpeningBalances(array $context, array $openingBalances): string
    {
        if (empty($openingBalances['available'])) {
            return '<section class="settings-stack" id="opening-balances"><h3 class="card-title">Opening Balances</h3>' . $this->renderErrors((array)($openingBalances['errors'] ?? [])) . '</section>';
        }

        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriod = (array)($openingBalances['accounting_period'] ?? []);
        $accountingPeriodId = (int)($accountingPeriod['id'] ?? ($company['accounting_period_id'] ?? 0));
        $existing = is_array($openingBalances['existing_journal'] ?? null) ? (array)$openingBalances['existing_journal'] : [];
        $defaultRows = (array)($existing['lines'] ?? []);
        if ($defaultRows === []) {
            $defaultRows = (array)($openingBalances['suggestions'] ?? []);
        }

        $formId = 'year-end-opening-balance-form';
        $description = $existing !== []
            ? (string)($existing['description'] ?? '')
            : 'Opening balances for ' . (string)($accountingPeriod['label'] ?? 'selected period');

        return '<section class="settings-stack" id="opening-balances">
            <h3 class="card-title">Opening Balances</h3>
            <div class="helper">Post one balanced opening balance journal for this accounting period. Suggestions from the immediately prior Companies House figures can be edited before saving.</div>
            <form id="' . $formId . '" method="post" data-ajax="true">
                <input type="hidden" name="card_action" value="YearEnd">
                <input type="hidden" name="intent" value="save_opening_balance">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
            </form>
            ' . $this->lineEditorTable('opening_balance', $formId, (array)($openingBalances['nominals'] ?? []), $defaultRows, 8) . '
            <div class="form-grid">
                <div class="form-row">
                    <label for="opening-balance-description">Description</label>
                    <input class="input" id="opening-balance-description" name="opening_balance_description" form="' . $formId . '" value="' . HelperFramework::escape($description) . '">
                </div>
                <div class="form-row">
                    <label for="opening-balance-notes">Notes</label>
                    <input class="input" id="opening-balance-notes" name="opening_balance_notes" form="' . $formId . '" value="' . HelperFramework::escape((string)($existing['notes'] ?? '')) . '">
                </div>
            </div>
            <div class="actions-row">
                <label class="checkbox-item"><input type="checkbox" id="opening-balance-system-mode" name="opening_balance_system_mode" form="' . $formId . '" value="1"><div class="checkbox-copy"><strong>Mark as system-generated</strong><span>Use when the journal is seeded from prior filed figures or another controlled source.</span></div></label>
                <label class="checkbox-item"><input type="checkbox" id="opening-balance-replace" name="opening_balance_replace" form="' . $formId . '" value="1"' . ($existing !== [] ? ' checked' : '') . '><div class="checkbox-copy"><strong>Replace existing opening balance</strong><span>Required when an active opening balance journal already exists.</span></div></label>
            </div>
            <div class="actions-row"><button class="button primary" type="submit" form="' . $formId . '">Save Opening Balances</button></div>
        </section>';
    }

    private function renderAdjustments(array $context, array $adjustments): string
    {
        if (empty($adjustments['available'])) {
            return '<section class="settings-stack" id="year-end-adjustments"><h3 class="card-title">Year End Adjustments</h3>' . $this->renderErrors((array)($adjustments['errors'] ?? [])) . '</section>';
        }

        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriod = (array)($adjustments['accounting_period'] ?? []);
        $accountingPeriodId = (int)($accountingPeriod['id'] ?? ($company['accounting_period_id'] ?? 0));
        $formId = 'year-end-adjustment-form';

        return '<section class="settings-stack" id="year-end-adjustments">
            <h3 class="card-title">Year End Adjustments</h3>
            <form id="' . $formId . '" method="post" data-ajax="true">
                <input type="hidden" name="card_action" value="YearEnd">
                <input type="hidden" name="intent" value="create_adjustment">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
            </form>
            <div class="form-grid">
                <div class="form-row"><label for="adjustment-template-type">Template</label><select class="select" id="adjustment-template-type" name="adjustment_template_type" form="' . $formId . '">' . $this->options(['accrual' => 'Create accrual', 'prepayment' => 'Create prepayment', 'deferred_income' => 'Create deferred income', 'custom' => 'Custom journal'], 'accrual') . '</select></div>
                <div class="form-row"><label for="adjustment-date">Date</label><input class="input" id="adjustment-date" name="adjustment_date" form="' . $formId . '" type="date" value="' . HelperFramework::escape((string)($accountingPeriod['period_end'] ?? '')) . '"></div>
                <div class="form-row"><label for="adjustment-description">Description</label><input class="input" id="adjustment-description" name="adjustment_description" form="' . $formId . '" value=""></div>
                <div class="form-row"><label for="adjustment-notes">Notes</label><input class="input" id="adjustment-notes" name="adjustment_notes" form="' . $formId . '" value=""></div>
                <div class="form-row"><label for="adjustment-primary-nominal">Primary nominal</label><select class="select" id="adjustment-primary-nominal" name="adjustment_primary_nominal_id" form="' . $formId . '">' . $this->nominalOptions((array)($adjustments['nominals'] ?? []), 0) . '</select></div>
                <div class="form-row"><label for="adjustment-offset-nominal">Offset nominal</label><select class="select" id="adjustment-offset-nominal" name="adjustment_offset_nominal_id" form="' . $formId . '">' . $this->nominalOptions((array)($adjustments['nominals'] ?? []), 0) . '</select></div>
                <div class="form-row"><label for="adjustment-amount">Amount</label><input class="input" id="adjustment-amount" name="adjustment_amount" form="' . $formId . '" inputmode="decimal"></div>
                <div class="form-row"><label>&nbsp;</label><label class="checkbox-item"><input type="checkbox" id="adjustment-auto-reverse" name="adjustment_auto_reverse" form="' . $formId . '" value="1"><div class="checkbox-copy"><strong>Auto reverse into next period</strong><span>Create the reversing journal on the next period start date.</span></div></label></div>
            </div>
            <h4 class="card-title">Custom journal lines</h4>
            ' . $this->lineEditorTable('adjustment', $formId, (array)($adjustments['nominals'] ?? []), [], 8) . '
            <div class="actions-row"><button class="button primary" type="submit" form="' . $formId . '">Post Adjustment</button></div>
            ' . $this->renderPostedAdjustments((array)($adjustments['adjustments'] ?? [])) . '
        </section>';
    }

    private function lineEditorTable(string $prefix, string $formId, array $nominals, array $rows, int $rowCount): string
    {
        $html = '';
        for ($index = 0; $index < $rowCount; $index++) {
            $row = (array)($rows[$index] ?? []);
            $nominalId = (int)($row['nominal_account_id'] ?? 0);
            $description = (string)($row['line_description'] ?? '');
            $html .= '<tr>
                <td><select class="select" name="' . $prefix . '_line_' . $index . '_nominal_id" form="' . $formId . '">' . $this->nominalOptions($nominals, $nominalId) . '</select></td>
                <td><input class="input" name="' . $prefix . '_line_' . $index . '_debit" form="' . $formId . '" value="' . HelperFramework::escape($this->lineAmount($row, 'debit')) . '" inputmode="decimal"></td>
                <td><input class="input" name="' . $prefix . '_line_' . $index . '_credit" form="' . $formId . '" value="' . HelperFramework::escape($this->lineAmount($row, 'credit')) . '" inputmode="decimal"></td>
                <td><input class="input" name="' . $prefix . '_line_' . $index . '_description" form="' . $formId . '" value="' . HelperFramework::escape($description) . '"></td>
            </tr>';
        }

        return '<div class="table-scroll"><table><thead><tr><th>Nominal</th><th>Debit</th><th>Credit</th><th>Description</th></tr></thead><tbody>' . $html . '</tbody></table></div>';
    }

    private function renderPostedAdjustments(array $adjustments): string
    {
        if ($adjustments === []) {
            return '<div><h4 class="card-title">Posted adjustments</h4><div class="helper">No year-end adjustments have been posted for this period yet.</div></div>';
        }

        $rows = '';
        foreach ($adjustments as $adjustment) {
            $rows .= '<tr>
                <td>' . HelperFramework::escape(HelperFramework::displayDate((string)($adjustment['journal_date'] ?? ''))) . '</td>
                <td>' . HelperFramework::escape((string)($adjustment['description'] ?? '')) . '</td>
                <td>' . HelperFramework::escape(HelperFramework::labelFromKey((string)($adjustment['journal_tag'] ?? ''), '_')) . '</td>
                <td>' . count((array)($adjustment['lines'] ?? [])) . '</td>
            </tr>';
        }

        return '<div><h4 class="card-title">Posted adjustments</h4><div class="table-scroll"><table><thead><tr><th>Date</th><th>Description</th><th>Type</th><th>Lines</th></tr></thead><tbody>' . $rows . '</tbody></table></div></div>';
    }

    private function nominalOptions(array $nominals, int $selectedNominalId): string
    {
        $html = '<option value="">Select nominal</option>';
        foreach ($nominals as $nominal) {
            $nominalId = (int)($nominal['id'] ?? 0);
            $html .= '<option value="' . $nominalId . '"' . ($nominalId === $selectedNominalId ? ' selected' : '') . '>' . HelperFramework::escape(FormattingFramework::nominalLabel($nominal)) . '</option>';
        }

        return $html;
    }

    private function options(array $options, string $selectedValue): string
    {
        $html = '';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . HelperFramework::escape((string)$value) . '"' . ((string)$value === $selectedValue ? ' selected' : '') . '>' . HelperFramework::escape((string)$label) . '</option>';
        }

        return $html;
    }

    private function lineAmount(array $row, string $key): string
    {
        $value = $row[$key] ?? '';
        if ($value === '' || $value === null) {
            return '';
        }

        return FormattingFramework::money($value);
    }

    private function renderCompaniesHouseComparison(array $comparison): string
    {
        if (empty($comparison['available'])) {
            return '<section class="settings-stack" id="companies-house-comparison"><h3 class="card-title">Companies House Comparison</h3><div class="helper">' . HelperFramework::escape((string)($comparison['errors'][0] ?? 'No Companies House comparison is available.')) . '</div></section>';
        }

        $rowsHtml = '';
        foreach ((array)($comparison['rows'] ?? []) as $row) {
            $rowsHtml .= '<tr>
                <td>' . HelperFramework::escape((string)($row['label'] ?? '')) . '</td>
                <td>' . HelperFramework::escape(FormattingFramework::nullableMoney($row['app_value'] ?? null, '-')) . '</td>
                <td>' . HelperFramework::escape(FormattingFramework::nullableMoney($row['filed_value'] ?? null, '-')) . '</td>
                <td>' . HelperFramework::escape(FormattingFramework::nullableMoney($row['variance'] ?? null, '-')) . '</td>
                <td><span class="badge ' . HelperFramework::escape($this->badgeClass((string)($row['status'] ?? ''))) . '">' . HelperFramework::escape(HelperFramework::labelFromKey((string)($row['status'] ?? ''), '_')) . '</span></td>
            </tr>';
        }

        return '<section class="settings-stack" id="companies-house-comparison">
            <h3 class="card-title">Companies House Comparison</h3>
            <div class="helper">' . HelperFramework::escape((string)($comparison['comparison_note'] ?? '')) . '</div>
            <div class="table-scroll"><table><thead><tr><th>Metric</th><th>App</th><th>Filed</th><th>Variance</th><th>Status</th></tr></thead><tbody>' . $rowsHtml . '</tbody></table></div>
        </section>';
    }

    private function sectionTitle(string $key): string
    {
        return match ($key) {
            'categorisation_suspense' => 'B. Categorisation and suspense',
            'ledger_integrity' => 'C. Ledger integrity',
            'bank_source_completeness' => 'D. Bank and source completeness',
            'director_loan_expenses' => 'E. Director loan and expense claims',
            'year_end_accounts_review' => 'F. Year end accounts review',
            'corporation_tax_readiness' => 'G. Corporation tax readiness',
            'companies_house_comparison' => 'H. Companies House comparison',
            'final_review_lock' => 'I. Final review and lock',
            default => HelperFramework::labelFromKey($key, '_'),
        };
    }

    private function badgeClass(string $status): string
    {
        return match ($status) {
            'pass', 'ready', 'locked' => 'success',
            'fail', 'needs_attention' => 'danger',
            'warning', 'not_started' => 'warning',
            default => 'info',
        };
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
