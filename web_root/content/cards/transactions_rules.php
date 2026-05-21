<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _transactions_rulesCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'transactions_rules';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'categorisation_rules',
                'service' => CategorisationRuleService::class,
                'method' => 'fetchRules',
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
        $taxYears = (array)($page['tax_years'] ?? []);

        if ($companyId <= 0) {
            return '<div class="helper">A company has to be added and selected before transaction categorisation can occur.</div>';
        }
        
        $page = (array)($context['page'] ?? []);
        $services = (array)($context['services'] ?? []);
        $categorisationRules = (array)($services['categorisation_rules'] ?? []);
        $ruleImportJson = (string)($page['rule_import_json'] ?? '');
        $selectedTransactionMonth = (string)($page['month_key'] ?? '');
        $selectedTransactionFilter = (string)($page['category_filter'] ?? 'all');

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
                    <div>
                        <form method="post" action="?page=transactions" data-ajax="true">
                            <input type="hidden" name="card_action" value="Transaction">
                            <input type="hidden" name="global_action" value="edit_categorisation_rule">
                            <input type="hidden" name="company_id" value="' . $companyId . '">
                            <input type="hidden" name="tax_year_id" value="' . $taxYearId . '">
                            <input type="hidden" name="month_key" value="' . HelperFramework::escape($selectedTransactionMonth) . '">
                            <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
                            <input type="hidden" name="rule_id" value="' . (int)($rule['id'] ?? 0) . '">
                            <button class="button" type="submit">Edit</button>
                        </form>
                        <form method="post" action="?page=transactions" data-ajax="true">
                            <input type="hidden" name="card_action" value="Transaction">
                            <input type="hidden" name="company_id" value="' . $companyId . '">
                            <input type="hidden" name="tax_year_id" value="' . $taxYearId . '">
                            <input type="hidden" name="month_key" value="' . HelperFramework::escape($selectedTransactionMonth) . '">
                            <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
                            <input type="hidden" name="rule_id" value="' . (int)($rule['id'] ?? 0) . '">
                            <input type="hidden" name="target_is_active" value="' . ($isActive ? '0' : '1') . '">
                            <input type="hidden" name="global_action" value="toggle_categorisation_rule">
                            <button class="button" type="submit">' . ($isActive ? 'Pause' : 'Activate') . '</button>
                        </form>
                        <form method="post" action="?page=transactions" data-ajax="true">
                            <input type="hidden" name="card_action" value="Transaction">
                            <input type="hidden" name="company_id" value="' . $companyId . '">
                            <input type="hidden" name="tax_year_id" value="' . $taxYearId . '">
                            <input type="hidden" name="month_key" value="' . HelperFramework::escape($selectedTransactionMonth) . '">
                            <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
                            <input type="hidden" name="rule_id" value="' . (int)($rule['id'] ?? 0) . '">
                            <input type="hidden" name="global_action" value="delete_categorisation_rule">
                            <button class="button danger" type="submit" data-chicken-check="true" data-chicken-message="Delete this categorisation rule?<br><br>This cannot be undone." data-chicken-confirm-text="Delete">Delete</button>
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

        return '
            <div>
                <form method="post" action="?page=transactions">
                    <input type="hidden" name="card_action" value="Transaction">
                    <input type="hidden" name="company_id" value="' . $companyId . '">
                    <input type="hidden" name="tax_year_id" value="' . $taxYearId . '">
                    <input type="hidden" name="month_key" value="' . HelperFramework::escape($selectedTransactionMonth) . '">
                    <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
                    <input type="hidden" name="global_action" value="export_categorisation_rules">
                    <button class="button" type="submit">Export Rules</button>
                </form>
            </div>'
            . $rulesSection . '
            <form method="post" action="?page=transactions">
                <input type="hidden" name="card_action" value="Transaction">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="tax_year_id" value="' . $taxYearId . '">
                <input type="hidden" name="month_key" value="' . HelperFramework::escape($selectedTransactionMonth) . '">
                <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
                <input type="hidden" name="global_action" value="import_categorisation_rules">
                <div class="form-row">
                    <label for="rules_import_json">Import rules JSON</label>
                    <textarea class="input" id="rules_import_json" name="rules_import_json" rows="8" placeholder="Paste exported transaction rules JSON here">' . HelperFramework::escape($ruleImportJson) . '</textarea>
                    <div class="helper">Import uses the current company and matches nominal accounts by id, code, or name.</div>
                </div>
                <div>
                    <button class="button primary" type="submit">Import Rules</button>
                </div>
            </form>
        ';
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
}
