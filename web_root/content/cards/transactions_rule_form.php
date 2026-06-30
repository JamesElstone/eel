<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _transactions_rule_formCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'transactions_rule_form';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'nominal_accounts',
                'service' => \eel_accounts\Repository\NominalAccountRepository::class,
                'method' => 'fetchNominalAccounts',
            ],
            [
                'key' => 'editing_rule',
                'service' => \eel_accounts\Service\CategorisationRuleService::class,
                'method' => 'fetchRule',
                'params' => [
                    'companyId' => ':company.id',
                    'ruleId' => ':page.editing_rule_id',
                ],
            ],
            [
                'key' => 'blank_rule_form',
                'service' => \eel_accounts\Service\CategorisationRuleService::class,
                'method' => 'blankRuleForm',
                'params' => [
                    'companyId' => ':company.id',
                ],
            ],
            [
                'key' => 'source_category_options',
                'service' => \eel_accounts\Service\CategorisationRuleService::class,
                'method' => 'fetchSourceCategoryOptions',
                'params' => [
                    'companyId' => ':company.id',
                ],
            ],
            [
                'key' => 'source_account_options',
                'service' => \eel_accounts\Service\CategorisationRuleService::class,
                'method' => 'fetchSourceAccountOptions',
                'params' => [
                    'companyId' => ':company.id',
                ],
            ],
        ];
    }

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        return TransactionAction::withTransactionCardContext($request, $services, $pageContext, $actionResult);
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $page = (array)($context['page'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $selectedTransactionMonth = (string)($page['month_key'] ?? '');
        if ($companyId <= 0) {
            return '<div class="helper">A company has to be added and selected before transaction categorisation can occur.</div>';
        }
        
        $services = (array)($context['services'] ?? []);
        $editingRuleId = (int)($page['editing_rule_id'] ?? $page['edit_rule_id'] ?? 0);
        $ruleForm = (array)($page['rule_form'] ?? $services['editing_rule'] ?? $services['blank_rule_form'] ?? []);
        $nominalAccounts = (array)($services['nominal_accounts'] ?? []);
        $sourceCategoryOptions = $this->sourceOptionsHtml(
            (array)($services['source_category_options'] ?? []),
            (string)($ruleForm['source_category_value'] ?? ''),
            'Any Category'
        );
        $sourceAccountOptions = $this->sourceOptionsHtml(
            (array)($services['source_account_options'] ?? []),
            (string)($ruleForm['source_account_value'] ?? ''),
            'Any Account'
        );
        $selectedTransactionFilter = (string)($page['category_filter'] ?? 'all');
        $cancelFormId = 'transactions-rule-cancel-form';
        $descMatchType = (string)($ruleForm['desc_match_type'] ?? $ruleForm['match_type'] ?? 'contains');
        $descMatchValue = (string)($ruleForm['desc_match_value'] ?? $ruleForm['match_value'] ?? '');
        $refMatchType = (string)($ruleForm['ref_match_type'] ?? 'none');
        $refMatchValue = (string)($ruleForm['ref_match_value'] ?? '');

        $nominalOptions = '';
        foreach ($nominalAccounts as $nominal) {
            if (!is_array($nominal)) {
                continue;
            }

            $nominalOptions .= '<option value="' . (int)($nominal['id'] ?? 0) . '"' . ((string)($nominal['id'] ?? '') === (string)($ruleForm['nominal_account_id'] ?? '') ? ' selected' : '') . '>' . HelperFramework::escape(FormattingFramework::nominalLabel($nominal)) . '</option>';
        }

        return '
            ' . ($editingRuleId > 0
                ? '<form id="' . $cancelFormId . '" method="post" action="?page=transactions" data-ajax="true">
                    <input type="hidden" name="card_action" value="Transaction">
                    <input type="hidden" name="global_action" value="cancel_categorisation_rule">
                    <input type="hidden" name="month_key" value="' . HelperFramework::escape($selectedTransactionMonth) . '">
                    <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
                </form>'
                : '') . '
            <form method="post" action="?page=transactions" data-ajax="true">
                <input type="hidden" name="card_action" value="Transaction">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="month_key" value="' . HelperFramework::escape($selectedTransactionMonth) . '">
                <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">'
                . ($editingRuleId > 0
                    ? '<input type="hidden" name="rule_id" value="' . $editingRuleId . '">'
                    : '') . '
                <input type="hidden" name="transaction_id" value="' . (int)($ruleForm['transaction_id'] ?? 0) . '">
                <input type="hidden" name="global_action" value="save_categorisation_rule">
                <div class="form-grid">
                    <div class="form-row">
                        <label for="rule_priority">Priority</label>
                        <input class="input" id="rule_priority" name="rule_priority" value="' . HelperFramework::escape((string)($ruleForm['priority'] ?? '100')) . '" type="number" min="1" step="1" inputmode="numeric">
                    </div>
                    <fieldset class="form-row full settings-fieldset">
                        <legend>Description Matching</legend>
                        <div class="form-grid">
                            <div class="form-row">
                                <label for="rule_desc_type">Type</label>
                                <select class="select" id="rule_desc_type" name="rule_desc_type" data-no-submit-on-change="true">
                                    ' . $this->matchTypeOptionsHtml($descMatchType, false) . '
                                </select>
                            </div>
                            <div class="form-row">
                                <label for="rule_desc_value">String</label>
                                <input class="input" id="rule_desc_value" name="rule_desc_value" value="' . HelperFramework::escape($descMatchValue) . '" required>
                            </div>
                        </div>
                    </fieldset>
                    <fieldset class="form-row full settings-fieldset">
                        <legend>Reference Matching</legend>
                        <div class="form-grid">
                            <div class="form-row">
                                <label for="rule_ref_type">Type</label>
                                <select class="select" id="rule_ref_type" name="rule_ref_type" data-no-submit-on-change="true">
                                    ' . $this->matchTypeOptionsHtml($refMatchType, true) . '
                                </select>
                            </div>
                            <div class="form-row">
                                <label for="rule_ref_value">String</label>
                                <input class="input" id="rule_ref_value" name="rule_ref_value" value="' . HelperFramework::escape($refMatchValue) . '">
                            </div>
                        </div>
                    </fieldset>
                    <fieldset class="form-row full settings-fieldset">
                        <legend>Optional</legend>
                        <div class="form-grid">
                            <div class="form-row">
                                <label for="rule_source_category_value">Source category</label>
                                <select class="select" id="rule_source_category_value" name="source_category_value" data-no-submit-on-change="true">
                                    ' . $sourceCategoryOptions . '
                                </select>
                            </div>
                            <div class="form-row">
                                <label for="rule_source_account_value">Source account</label>
                                <select class="select" id="rule_source_account_value" name="source_account_value" data-no-submit-on-change="true">
                                    ' . $sourceAccountOptions . '
                                </select>
                            </div>
                        </div>
                    </fieldset>
                    <div class="form-row full">
                        <label for="rule_nominal_account_id">Nominal account</label>
                        <select class="select" id="rule_nominal_account_id" name="nominal_account_id" required data-no-submit-on-change="true">
                            <option value="">Select nominal</option>' . $nominalOptions . '
                        </select>
                    </div>
                </div>
                <label class="checkbox-item">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1"' . (!isset($ruleForm['is_active']) || !empty($ruleForm['is_active']) ? ' checked' : '') . '>
                    <div class="checkbox-copy">
                        <strong>Rule active</strong>
                        <span>Only active rules are evaluated during import and batch auto categorisation.</span>
                    </div>
                </label>
                <div>
                    <button class="button primary" type="submit">' . HelperFramework::escape($editingRuleId > 0 ? 'Save Rule' : 'Add Rule') . '</button>'
                    . ($editingRuleId > 0
                        ? '<button class="button" type="submit" form="' . $cancelFormId . '" formnovalidate>Cancel</button>'
                        : '') . '
                </div>
            </form>
        ';
    }

    private function sourceOptionsHtml(array $options, string $selectedValue, string $anyLabel): string
    {
        $selectedValue = trim($selectedValue);
        $normalisedOptions = [];

        foreach ($options as $option) {
            $value = is_array($option)
                ? trim((string)($option['value'] ?? $option['label'] ?? ''))
                : trim((string)$option);

            if ($value !== '') {
                $normalisedOptions[$value] = $value;
            }
        }

        if ($selectedValue !== '' && !isset($normalisedOptions[$selectedValue])) {
            $normalisedOptions[$selectedValue] = $selectedValue;
        }

        $html = '<option value=""' . ($selectedValue === '' ? ' selected' : '') . '>' . HelperFramework::escape($anyLabel) . '</option>';
        foreach ($normalisedOptions as $value) {
            $html .= '<option value="' . HelperFramework::escape($value) . '"' . ($value === $selectedValue ? ' selected' : '') . '>' . HelperFramework::escape($value) . '</option>';
        }

        return $html;
    }

    private function matchTypeOptionsHtml(string $selectedValue, bool $includeNone): string
    {
        $options = $includeNone ? ['none' => 'None'] : [];
        $options += [
            'contains' => 'Contains',
            'equals' => 'Equals',
            'starts_with' => 'Starts with',
        ];

        $html = '';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . HelperFramework::escape($value) . '"' . ($value === $selectedValue ? ' selected' : '') . '>' . HelperFramework::escape($label) . '</option>';
        }

        return $html;
    }
}
