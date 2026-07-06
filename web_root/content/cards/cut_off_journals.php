<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _cut_off_journalsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'cut_off_journals';
    }

    public function title(): string
    {
        return 'Cut-off Journals';
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
            return '<section class="settings-stack" id="cut-off-journals">' . $this->renderErrors((array)($data['errors'] ?? ['Cut-off journals are not available.'])) . '</section>';
        }

        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriod = (array)($data['accounting_period'] ?? []);
        $accountingPeriodId = (int)($accountingPeriod['id'] ?? ($company['accounting_period_id'] ?? 0));
        $formId = 'cut-off-journal-form';

        return '<section class="settings-stack" id="cut-off-journals">
            <div class="helper">Use this for known year-end items that are not fully represented by bank CSVs or expense claim evidence in the correct period.</div>
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
            <h4 class="card-title">Custom journal lines</h4>
            ' . $this->lineEditorTable('adjustment', $formId, (array)($data['nominals'] ?? []), [], 8) . '
            <div class="actions-row"><button class="button primary" type="submit" form="' . $formId . '">Post Cut-off Journal</button></div>
            ' . $this->renderPostedAdjustments((array)($data['adjustments'] ?? [])) . '
            ' . $this->acknowledgementHtml(is_array($data['review_acknowledgement'] ?? null) ? $data['review_acknowledgement'] : null, $companyId, $accountingPeriodId) . '
        </section>';
    }

    private function acknowledgementHtml(?array $acknowledgement, int $companyId, int $accountingPeriodId): string
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return '';
        }

        $acknowledged = $acknowledgement !== null;
        $intent = $acknowledged ? 'reopen_review_check' : 'acknowledge_review_check';
        $buttonClass = $acknowledged ? 'button' : 'button primary';
        $buttonLabel = $acknowledged ? 'Reopen review' : 'Mark reviewed';
        $acknowledgedAt = $acknowledged ? trim((string)($acknowledgement['acknowledged_at'] ?? '')) : '';
        $acknowledgedBy = $acknowledged ? trim((string)($acknowledgement['acknowledged_by'] ?? '')) : '';
        $confirmAttributes = $acknowledged
            ? ''
            : ' data-chicken-check="true" data-chicken-title="Mark cut-off journals review complete" data-chicken-message="This records that accruals, deferred income, prepayments, and other year-end cut-off journals have been reviewed for this accounting period.<br><br>Continue?" data-chicken-confirm-text="Mark Reviewed" data-chicken-button-class="button primary"';

        return '<div class="actions-row"><form method="post" data-ajax="true" class="panel-soft stack">
                <input type="hidden" name="card_action" value="YearEnd">
                <input type="hidden" name="intent" value="' . HelperFramework::escape($intent) . '">
                <input type="hidden" name="show_card" value=".self">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="check_code" value="cut_off_journals_review">
                <div class="helper">Confirm this after reviewing whether accruals, deferred income, prepayments, or other year-end cut-off journals are required.</div>
                ' . ($acknowledged ? '<div class="helper">Reviewed' . ($acknowledgedAt !== '' ? ' at ' . HelperFramework::escape($acknowledgedAt) : '') . ($acknowledgedBy !== '' ? ' by ' . HelperFramework::escape($acknowledgedBy) : '') . '.</div>' : '') . '
                <button class="' . HelperFramework::escape($buttonClass) . '" type="submit"' . $confirmAttributes . '>' . HelperFramework::escape($buttonLabel) . '</button>
            </form></div>';
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
            return '<div><h4 class="card-title">Posted cut-off journals</h4><div class="helper">No cut-off journals have been posted for this accounting period yet.</div></div>';
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

        return '<div><h4 class="card-title">Posted cut-off journals</h4><div class="table-scroll"><table><thead><tr><th>Date</th><th>Description</th><th>Type</th><th>Lines</th></tr></thead><tbody>' . $rows . '</tbody></table></div></div>';
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
