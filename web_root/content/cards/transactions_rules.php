<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _transactions_rulesCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'transactions_rules';
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
        $categorisationRules = (array)($page['categorisation_rules'] ?? []);
        $ruleImportJson = (string)($page['rule_import_json'] ?? '');
        $selectedCompanyId = (int)($page['selected_company_id'] ?? $page['company_id'] ?? 0);
        $selectedTaxYearId = (int)($page['selected_tax_year_id'] ?? $page['tax_year_id'] ?? 0);
        $selectedTransactionMonth = (string)($page['selected_transaction_month'] ?? $page['month_key'] ?? '');
        $selectedTransactionFilter = (string)($page['selected_transaction_filter'] ?? $page['category_filter'] ?? 'all');
        $transactionsCardUpdateList = 'transactions-year-summary,transactions-imported,transactions-rules,transactions-rule-form';
        $transactionQueryArgs = [
            'company_id' => $selectedCompanyId,
            'tax_year_id' => $selectedTaxYearId,
            'month_key' => $selectedTransactionMonth,
            'category_filter' => $selectedTransactionFilter,
        ];

        $rulesHtml = '';
        foreach ($categorisationRules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $isActive = (int)($rule['is_active'] ?? 0) === 1;
            $rulesHtml .= '<tr>
                <td>' . (int)($rule['priority'] ?? 0) . '</td>
                <td>' . HelperFramework::escape($this->categorisationRuleSummary($rule)) . '</td>
                <td>' . HelperFramework::escape(trim((string)($rule['nominal_code'] ?? '')) !== '' ? (string)$rule['nominal_code'] . ' - ' . (string)($rule['nominal_name'] ?? '') : (string)($rule['nominal_name'] ?? '')) . '</td>
                <td><span class="badge ' . HelperFramework::escape($this->ruleStatusBadgeClass($isActive)) . '">' . HelperFramework::escape($this->ruleStatusLabel($isActive)) . '</span></td>
                <td>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <a class="button" href="' . HelperFramework::escape($this->buildPageUrl('transactions', $transactionQueryArgs + ['edit_rule_id' => (int)($rule['id'] ?? 0)])) . '" data-ajax-card-link="true" data-ajax-card-update="' . HelperFramework::escape($transactionsCardUpdateList) . '">Edit</a>
                        <form method="post" action="' . HelperFramework::escape($this->buildPageUrl('transactions', $transactionQueryArgs)) . '" data-ajax-card-form="true" data-ajax-card-update="' . HelperFramework::escape($transactionsCardUpdateList) . '">
                            <input type="hidden" name="company_id" value="' . $selectedCompanyId . '">
                            <input type="hidden" name="tax_year_id" value="' . $selectedTaxYearId . '">
                            <input type="hidden" name="month_key" value="' . HelperFramework::escape($selectedTransactionMonth) . '">
                            <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
                            <input type="hidden" name="rule_id" value="' . (int)($rule['id'] ?? 0) . '">
                            <input type="hidden" name="target_is_active" value="' . ($isActive ? '0' : '1') . '">
                            <input type="hidden" name="global_action" value="toggle_categorisation_rule">
                            <button class="button" type="submit">' . ($isActive ? 'Pause' : 'Activate') . '</button>
                        </form>
                        <form method="post" action="' . HelperFramework::escape($this->buildPageUrl('transactions', $transactionQueryArgs)) . '" data-ajax-card-form="true" data-ajax-card-update="' . HelperFramework::escape($transactionsCardUpdateList) . '">
                            <input type="hidden" name="company_id" value="' . $selectedCompanyId . '">
                            <input type="hidden" name="tax_year_id" value="' . $selectedTaxYearId . '">
                            <input type="hidden" name="month_key" value="' . HelperFramework::escape($selectedTransactionMonth) . '">
                            <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
                            <input type="hidden" name="rule_id" value="' . (int)($rule['id'] ?? 0) . '">
                            <input type="hidden" name="global_action" value="delete_categorisation_rule">
                            <button class="button danger" type="submit" onclick="return confirm(\'Delete this categorisation rule?\');">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>';
        }

        $rulesSection = $rulesHtml !== ''
            ? '<div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Priority</th>
                            <th>Match</th>
                            <th>Nominal</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>' . $rulesHtml . '</tbody>
                </table>
            </div>'
            : '<div class="helper">No categorisation rules exist yet. Save a manual categorisation and use “Auto” to create one from a transaction.</div>';

        return '<section class="eel-card-fragment" data-card="transactions-rules">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Rules</h2>
                </div>
                <div class="card-body">
                    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px;">
                        <form method="post" action="' . HelperFramework::escape($this->buildPageUrl('transactions', $transactionQueryArgs)) . '">
                            <input type="hidden" name="company_id" value="' . $selectedCompanyId . '">
                            <input type="hidden" name="tax_year_id" value="' . $selectedTaxYearId . '">
                            <input type="hidden" name="month_key" value="' . HelperFramework::escape($selectedTransactionMonth) . '">
                            <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
                            <input type="hidden" name="global_action" value="export_categorisation_rules">
                            <button class="button" type="submit">Export Rules</button>
                        </form>
                    </div>'
                    . $rulesSection . '
                    <form method="post" action="' . HelperFramework::escape($this->buildPageUrl('transactions', $transactionQueryArgs)) . '" style="margin-top: 18px;" data-ajax-card-form="true" data-ajax-card-update="' . HelperFramework::escape($transactionsCardUpdateList) . '">
                        <input type="hidden" name="company_id" value="' . $selectedCompanyId . '">
                        <input type="hidden" name="tax_year_id" value="' . $selectedTaxYearId . '">
                        <input type="hidden" name="month_key" value="' . HelperFramework::escape($selectedTransactionMonth) . '">
                        <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
                        <input type="hidden" name="global_action" value="import_categorisation_rules">
                        <div class="form-row">
                            <label for="rules_import_json">Import rules JSON</label>
                            <textarea class="input" id="rules_import_json" name="rules_import_json" rows="8" placeholder="Paste exported transaction rules JSON here">' . HelperFramework::escape($ruleImportJson) . '</textarea>
                            <div class="helper">Import uses the current company and matches nominal accounts by id, code, or name.</div>
                        </div>
                        <div style="margin-top: 12px;">
                            <button class="button primary" type="submit">Import Rules</button>
                        </div>
                    </form>
                </div>
            </div>
        </section>';
    }

    private function categorisationRuleSummary(array $rule): string
    {
        $matchType = str_replace('_', ' ', (string)($rule['match_type'] ?? 'contains'));
        $parts = [ucfirst($matchType) . ' "' . trim((string)($rule['match_value'] ?? '')) . '"'];

        $sourceCategory = trim((string)($rule['source_category_value'] ?? ''));
        if ($sourceCategory !== '') {
            $parts[] = 'category: ' . $sourceCategory;
        }

        $sourceAccount = trim((string)($rule['source_account_value'] ?? ''));
        if ($sourceAccount !== '') {
            $parts[] = 'account: ' . $sourceAccount;
        }

        return implode(' | ', $parts);
    }

    private function ruleStatusBadgeClass(bool $isActive): string
    {
        return $isActive ? 'success' : 'warning';
    }

    private function ruleStatusLabel(bool $isActive): string
    {
        return $isActive ? 'Active' : 'Paused';
    }

    private function buildPageUrl(string $page, array $params = []): string
    {
        return '?' . http_build_query(['page' => $page] + $params);
    }
}
