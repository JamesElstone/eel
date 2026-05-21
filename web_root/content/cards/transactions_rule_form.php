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
                'service' => NominalAccountRepository::class,
                'method' => 'fetchNominalAccounts',
            ],
            [
                'key' => 'editing_rule',
                'service' => CategorisationRuleService::class,
                'method' => 'fetchRule',
                'params' => [
                    'companyId' => ':company.id',
                    'ruleId' => ':page.editing_rule_id',
                ],
            ],
            [
                'key' => 'blank_rule_form',
                'service' => CategorisationRuleService::class,
                'method' => 'blankRuleForm',
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
        $companyId = (int)($company['id'] ?? 0);
        $taxYearId = (int)($company['tax_year_id'] ?? 0);
        $selectedTransactionMonth = (string)($page['month_key'] ?? '');
        if ($companyId <= 0) {
            return '<div class="helper">A company has to be added and selected before transaction categorisation can occur.</div>';
        }
        
        $page = (array)($context['page'] ?? []);
        $services = (array)($context['services'] ?? []);
        $editingRuleId = (int)($page['editing_rule_id'] ?? $page['edit_rule_id'] ?? 0);
        $ruleForm = (array)($page['rule_form'] ?? $services['editing_rule'] ?? $services['blank_rule_form'] ?? []);
        $nominalAccounts = (array)($services['nominal_accounts'] ?? []);
        $selectedTransactionFilter = (string)($page['category_filter'] ?? 'all');
        $cancelFormId = 'transactions-rule-cancel-form';

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
                <input type="hidden" name="tax_year_id" value="' . $taxYearId . '">
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
}
