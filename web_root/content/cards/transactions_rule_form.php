<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _transactions_rule_formCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'transactions_rule_form';
    }

    public function services(): array
    {
        return [];
    }

    public function invalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $page = (array)($context['page'] ?? []);
        $editingRuleId = (int)($page['editing_rule_id'] ?? $page['edit_rule_id'] ?? 0);
        $ruleForm = (array)($page['rule_form'] ?? []);
        $nominalAccounts = (array)($page['nominal_accounts'] ?? []);
        $selectedCompanyId = (int)($page['selected_company_id'] ?? $page['company_id'] ?? 0);
        $selectedTaxYearId = (int)($page['selected_tax_year_id'] ?? $page['tax_year_id'] ?? 0);
        $selectedTransactionMonth = (string)($page['selected_transaction_month'] ?? $page['month_key'] ?? '');
        $selectedTransactionFilter = (string)($page['selected_transaction_filter'] ?? $page['category_filter'] ?? 'all');
        $ruleFormTitle = $editingRuleId > 0 ? 'Edit Rule' : 'Add Rule';
        $transactionsCardUpdateList = 'transactions-year-summary,transactions-imported,transactions-rules,transactions-rule-form';
        $transactionQueryArgs = [
            'company_id' => $selectedCompanyId,
            'tax_year_id' => $selectedTaxYearId,
            'month_key' => $selectedTransactionMonth,
            'category_filter' => $selectedTransactionFilter,
        ];

        $nominalOptions = '';
        foreach ($nominalAccounts as $nominal) {
            if (!is_array($nominal)) {
                continue;
            }

            $nominalOptions .= '<option value="' . (int)($nominal['id'] ?? 0) . '"' . ((string)($nominal['id'] ?? '') === (string)($ruleForm['nominal_account_id'] ?? '') ? ' selected' : '') . '>' . HelperFramework::escape(FormattingFramework::nominalLabel($nominal)) . '</option>';
        }

        return '<section class="eel-card-fragment" data-card="transactions-rule-form">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">' . HelperFramework::escape($ruleFormTitle) . '</h2>
                </div>
                <div class="card-body">
                    <form method="post" action="' . HelperFramework::escape($this->buildPageUrl('transactions', $transactionQueryArgs)) . '" data-ajax-card-form="true" data-ajax-card-update="' . HelperFramework::escape($transactionsCardUpdateList) . '">
                        <input type="hidden" name="company_id" value="' . $selectedCompanyId . '">
                        <input type="hidden" name="tax_year_id" value="' . $selectedTaxYearId . '">
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
                                <input class="input" id="rule_priority" name="priority" value="' . HelperFramework::escape((string)($ruleForm['priority'] ?? '100')) . '">
                            </div>
                            <div class="form-row">
                                <label for="rule_match_type">Match type</label>
                                <select class="select" id="rule_match_type" name="match_type">
                                    <option value="contains"' . ((string)($ruleForm['match_type'] ?? '') === 'contains' ? ' selected' : '') . '>Contains</option>
                                    <option value="equals"' . ((string)($ruleForm['match_type'] ?? '') === 'equals' ? ' selected' : '') . '>Equals</option>
                                    <option value="starts_with"' . ((string)($ruleForm['match_type'] ?? '') === 'starts_with' ? ' selected' : '') . '>Starts with</option>
                                </select>
                            </div>
                            <div class="form-row full">
                                <label for="rule_match_value">Description match</label>
                                <input class="input" id="rule_match_value" name="match_value" value="' . HelperFramework::escape((string)($ruleForm['match_value'] ?? '')) . '" required>
                            </div>
                            <div class="form-row">
                                <label for="rule_source_category_value">Optional source category</label>
                                <input class="input" id="rule_source_category_value" name="source_category_value" value="' . HelperFramework::escape((string)($ruleForm['source_category_value'] ?? '')) . '">
                            </div>
                            <div class="form-row">
                                <label for="rule_source_account_value">Optional source account</label>
                                <input class="input" id="rule_source_account_value" name="source_account_value" value="' . HelperFramework::escape((string)($ruleForm['source_account_value'] ?? '')) . '">
                            </div>
                            <div class="form-row full">
                                <label for="rule_nominal_account_id">Nominal account</label>
                                <select class="select" id="rule_nominal_account_id" name="nominal_account_id" required>
                                    <option value="">Select nominal</option>' . $nominalOptions . '
                                </select>
                            </div>
                        </div>
                        <label class="checkbox-item" style="margin-top: 14px;">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1"' . (!isset($ruleForm['is_active']) || !empty($ruleForm['is_active']) ? ' checked' : '') . '>
                            <div class="checkbox-copy">
                                <strong>Rule active</strong>
                                <span>Only active rules are evaluated during import and batch auto categorisation.</span>
                            </div>
                        </label>
                        <div style="margin-top: 16px; display: flex; gap: 10px; flex-wrap: wrap;">
                            <button class="button primary" type="submit">' . HelperFramework::escape($editingRuleId > 0 ? 'Save Rule' : 'Add Rule') . '</button>'
                            . ($editingRuleId > 0
                                ? '<a class="button" href="' . HelperFramework::escape($this->buildPageUrl('transactions', $transactionQueryArgs)) . '" data-ajax-card-link="true" data-ajax-card-update="' . HelperFramework::escape($transactionsCardUpdateList) . '">Cancel</a>'
                                : '') . '
                        </div>
                    </form>
                </div>
            </div>
        </section>';
    }

    private function buildPageUrl(string $page, array $params = []): string
    {
        return '?' . http_build_query(['page' => $page] + $params);
    }
}
