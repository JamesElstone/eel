<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _journal_cut_offsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'journal_cut_offs';
    }

    public function title(): string
    {
        return 'Cut-off Journals';
    }

    public function helper(array $context): string
    {
        return 'Use this for known year-end items that are not fully represented by bank CSVs or expense claim evidence in the correct period.';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'cutOffJournals',
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
        return ['cut.off.journals', 'year.end.state', 'trial.balance.state'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $data = (array)($context['services']['cutOffJournals'] ?? []);
        if (empty($data['available'])) {
            return '<section class="settings-stack" id="cut-off-journals"><section class="panel-soft settings-stack">' . $this->renderErrors((array)($data['errors'] ?? ['Cut-off journals are not available.'])) . '</section></section>';
        }

        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriod = (array)($data['accounting_period'] ?? []);
        $accountingPeriodId = (int)($accountingPeriod['id'] ?? ($company['accounting_period_id'] ?? 0));
        $formId = 'cut-off-journal-form';

        return '<section class="settings-stack" id="cut-off-journals">
            <section class="panel-soft settings-stack">
                <form id="' . $formId . '" method="post" data-ajax="true">
                    <input type="hidden" name="card_action" value="YearEnd">
                    <input type="hidden" name="intent" value="create_adjustment">
                    <input type="hidden" name="show_card" value=".self">
                    <input type="hidden" name="company_id" value="' . $companyId . '">
                    <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                </form>
                <div class="form-grid">
                    <div class="form-row"><label for="cutoff-template-type">Template</label><select class="select" id="cutoff-template-type" name="adjustment_template_type" form="' . $formId . '">' . $this->options(['accrual' => 'Create accrual', 'prepayment' => 'Create prepayment', 'deferred_income' => 'Create deferred income', 'custom' => 'Custom journal'], 'accrual') . '</select></div>
                    <div class="form-row"><label for="cutoff-date">Date</label><input class="input" id="cutoff-date" name="adjustment_date" form="' . $formId . '" type="date" value="' . HelperFramework::escape((string)($accountingPeriod['period_end'] ?? '')) . '"></div>
                    <div class="form-row"><label for="cutoff-description">Description</label><input class="input" id="cutoff-description" name="adjustment_description" form="' . $formId . '" value=""></div>
                    <div class="form-row"><label for="cutoff-notes">Notes</label><input class="input" id="cutoff-notes" name="adjustment_notes" form="' . $formId . '" value=""></div>
                    <div class="form-row"><label for="cutoff-primary-nominal">Primary nominal</label><select class="select" id="cutoff-primary-nominal" name="adjustment_primary_nominal_id" form="' . $formId . '">' . $this->nominalOptions((array)($data['nominals'] ?? []), 0) . '</select></div>
                    <div class="form-row"><label for="cutoff-offset-nominal">Offset nominal</label><select class="select" id="cutoff-offset-nominal" name="adjustment_offset_nominal_id" form="' . $formId . '">' . $this->nominalOptions((array)($data['nominals'] ?? []), 0) . '</select></div>
                    <div class="form-row"><label for="cutoff-amount">Amount</label><input class="input" id="cutoff-amount" name="adjustment_amount" form="' . $formId . '" inputmode="decimal"></div>
                    <div class="form-row"><label>&nbsp;</label><label class="checkbox-item"><input type="checkbox" id="cutoff-auto-reverse" name="adjustment_auto_reverse" form="' . $formId . '" value="1"><div class="checkbox-copy"><strong>Auto reverse into next period</strong><span>Create the reversing journal on the next period start date.</span></div></label></div>
                </div>
            </section>
            <section class="panel-soft settings-stack">
                <h4 class="card-title">Custom journal lines</h4>
                ' . $this->lineEditorTable('adjustment', $formId, (array)($data['nominals'] ?? []), [], 8) . '
                <div class="actions-row"><button class="button primary" type="submit" form="' . $formId . '">Post Cut-off Journal</button></div>
            </section>
            ' . $this->renderPostedAdjustments((array)($data['adjustments'] ?? [])) . '
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
            return '<section class="panel-soft settings-stack"><h4 class="card-title">Posted cut-off journals</h4><div class="helper">No cut-off journals have been posted for this accounting period yet.</div></section>';
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

        return '<section class="panel-soft settings-stack"><h4 class="card-title">Posted cut-off journals</h4><div class="table-scroll"><table><thead><tr><th>Date</th><th>Description</th><th>Type</th><th>Lines</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
    }

    private function nominalOptions(array $nominals, int $selectedId): string
    {
        $html = '<option value="">Choose nominal...</option>';
        foreach ($nominals as $nominal) {
            $id = (int)($nominal['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $label = trim((string)($nominal['code'] ?? '') . ' ' . (string)($nominal['name'] ?? ''));
            $html .= '<option value="' . $id . '"' . ($id === $selectedId ? ' selected' : '') . '>' . HelperFramework::escape($label) . '</option>';
        }

        return $html;
    }

    private function options(array $options, string $selected): string
    {
        $html = '';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . HelperFramework::escape((string)$value) . '"' . ((string)$value === $selected ? ' selected' : '') . '>' . HelperFramework::escape((string)$label) . '</option>';
        }

        return $html;
    }

    private function lineAmount(array $row, string $key): string
    {
        $value = (float)($row[$key] ?? 0);
        return abs($value) > 0.00001 ? number_format($value, 2, '.', '') : '';
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
