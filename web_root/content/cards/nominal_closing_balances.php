<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _nominal_closing_balancesCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'nominal_closing_balances';
    }

    public function title(): string
    {
        return 'Nominal Closing Balances';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'yearEndAdjustments',
                'service' => \eel_accounts\Service\YearEndAdjustmentService::class,
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
        return ['year.end.state', 'trial.balance.state'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $adjustments = (array)($context['services']['yearEndAdjustments'] ?? []);

        if (empty($adjustments['available'])) {
            return '<section class="settings-stack" id="nominal-closing-balances"><h3 class="card-title">Nominal Closing Balances</h3>' . $this->renderErrors((array)($adjustments['errors'] ?? [])) . '</section>';
        }

        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriod = (array)($adjustments['accounting_period'] ?? []);
        $accountingPeriodId = (int)($accountingPeriod['id'] ?? ($company['accounting_period_id'] ?? 0));
        $formId = 'nominal-closing-balance-form';

        return '<section class="settings-stack" id="nominal-closing-balances">
            <div class="helper">Post year-end nominal adjustments for accruals, prepayments, deferred income, or custom closing journals.</div>
            <form id="' . $formId . '" method="post" data-ajax="true">
                <input type="hidden" name="card_action" value="YearEnd">
                <input type="hidden" name="intent" value="create_adjustment">
                <input type="hidden" name="show_card" value=".self">
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

        return number_format((float)$value, 2, '.', '');
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
