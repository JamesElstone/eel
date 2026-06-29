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
    private const PAGE_SIZE = 15;

    public function key(): string
    {
        return 'transactions_rules';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'categorisation_rules',
                'service' => \eel_accounts\Service\CategorisationRuleService::class,
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
        $pageContext = TransactionAction::withTransactionCardContext($request, $services, $pageContext, $actionResult);
        $pageContext['page'][$this->paginationPageField()] = max(1, (int)$request->input($this->paginationPageField(), 1));

        return $pageContext;
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
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);

        if ($companyId <= 0) {
            return '<div class="helper">A company has to be added and selected before transaction categorisation can occur.</div>';
        }
        
        $page = (array)($context['page'] ?? []);
        $services = (array)($context['services'] ?? []);
        $categorisationRules = (array)($services['categorisation_rules'] ?? []);
        $ruleImportJson = (string)($page['rule_import_json'] ?? '');
        $selectedTransactionMonth = (string)($page['month_key'] ?? '');
        $selectedTransactionFilter = (string)($page['category_filter'] ?? 'all');

        $rulesSection = $this->configuredRulesTable(
            $categorisationRules,
            $companyId,
            $accountingPeriodId,
            $selectedTransactionMonth,
            $selectedTransactionFilter,
            $context
        )->render($context, [
                'cards[]' => (array)($context['page']['page_cards'] ?? []),
            ]);

        return '
            <section class="panel-soft">
                <h3 class="card-title">Categorisation rules</h3>
                ' . $rulesSection . '
            </section>
            <section class="panel-soft">
                <h3 class="card-title">Upload exported JSON rules</h3>
                <form method="post" action="?page=transactions">
                    <input type="hidden" name="card_action" value="Transaction">
                    <input type="hidden" name="company_id" value="' . $companyId . '">
                    <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                    <input type="hidden" name="month_key" value="' . HelperFramework::escape($selectedTransactionMonth) . '">
                    <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
                    <input type="hidden" name="global_action" value="import_categorisation_rules">
                    <div class="form-row">
                        <label for="rules_import_json" class="sr-only">Exported rules JSON</label>
                        <textarea class="input" id="rules_import_json" name="rules_import_json" rows="8" placeholder="Paste exported transaction rules JSON here">' . HelperFramework::escape($ruleImportJson) . '</textarea>
                        <div class="helper">Import uses the current company and matches nominal accounts by id, code, or name.</div>
                    </div>
                    <div>
                        <button class="button primary" type="submit">Import Rules</button>
                    </div>
                </form>
            </section>
        ';
    }

    public function tables(array $context): array
    {
        $company = (array)($context['company'] ?? []);
        $page = (array)($context['page'] ?? []);
        $services = (array)($context['services'] ?? []);

        return [
            $this->rulesTable(
                (array)($services['categorisation_rules'] ?? []),
                (int)($company['id'] ?? 0),
                (int)($company['accounting_period_id'] ?? 0),
                (string)($page['month_key'] ?? ''),
                (string)($page['category_filter'] ?? 'all')
            ),
        ];
    }

    private function configuredRulesTable(array $rules, int $companyId, int $accountingPeriodId, string $selectedTransactionMonth, string $selectedTransactionFilter, array $context): TableFramework
    {
        $rows = array_values(array_filter($rules, static fn(mixed $rule): bool => is_array($rule)));
        $pagination = HelperFramework::paginateArray($rows, $this->paginationPage($context), self::PAGE_SIZE);

        return $this->rulesTable($rules, $companyId, $accountingPeriodId, $selectedTransactionMonth, $selectedTransactionFilter)
            ->visibleRows((array)$pagination['items'])
            ->toolbarActions($this->exportRulesToolbarAction($companyId, $accountingPeriodId, $selectedTransactionMonth, $selectedTransactionFilter))
            ->pagination(
                $pagination,
                'Categorisation rules',
                $this->paginationPageField(),
                [
                    'page' => (string)($context['page']['page_id'] ?? ''),
                    '_pagination' => '1',
                    '_invalidate_fact' => $this->tableInvalidationFact(),
                    'cards[]' => [$this->key()],
                ]
            );
    }

    private function rulesTable(array $rules, int $companyId, int $accountingPeriodId, string $selectedTransactionMonth, string $selectedTransactionFilter): TableFramework
    {
        $rows = array_values(array_filter($rules, static fn(mixed $rule): bool => is_array($rule)));

        return TableFramework::make($this->key(), $rows)
            ->filename('transaction-categorisation-rules')
            ->empty('No categorisation rules exist yet. Save a manual categorisation and use Auto to create one from a transaction.')
            ->column('priority', 'Priority', exportType: 'number')
            ->column(
                'match',
                'Match',
                html: fn(array $row): string => HelperFramework::escape($this->categorisationRuleSummary($row)),
                export: fn(array $row): string => $this->categorisationRuleSummary($row)
            )
            ->column(
                'nominal',
                'Nominal',
                html: fn(array $row): string => HelperFramework::escape($this->nominalLabel($row)),
                export: fn(array $row): string => $this->nominalLabel($row)
            )
            ->column(
                'status',
                'Status',
                html: fn(array $row): string => $this->ruleStatusHtml((int)($row['is_active'] ?? 0) === 1),
                export: fn(array $row): string => $this->ruleStatusLabel((int)($row['is_active'] ?? 0) === 1)
            )
            ->column(
                'actions',
                '',
                html: fn(array $row): string => $this->ruleActionsHtml($row, $companyId, $accountingPeriodId, $selectedTransactionMonth, $selectedTransactionFilter),
                exportable: false
            );
    }

    private function exportRulesToolbarAction(int $companyId, int $accountingPeriodId, string $selectedTransactionMonth, string $selectedTransactionFilter): string
    {
        return '<form method="post" action="?page=transactions">
            <input type="hidden" name="card_action" value="Transaction">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
            <input type="hidden" name="month_key" value="' . HelperFramework::escape($selectedTransactionMonth) . '">
            <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
            <input type="hidden" name="global_action" value="export_categorisation_rules">
            <button class="button" type="submit">Export Rules</button>
        </form>';
    }

    private function ruleActionsHtml(array $rule, int $companyId, int $accountingPeriodId, string $selectedTransactionMonth, string $selectedTransactionFilter): string
    {
        $ruleId = (int)($rule['id'] ?? 0);
        $isActive = (int)($rule['is_active'] ?? 0) === 1;
        $commonFields = '<input type="hidden" name="card_action" value="Transaction">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
            <input type="hidden" name="month_key" value="' . HelperFramework::escape($selectedTransactionMonth) . '">
            <input type="hidden" name="category_filter" value="' . HelperFramework::escape($selectedTransactionFilter) . '">
            <input type="hidden" name="rule_id" value="' . $ruleId . '">';

        return '<div class="actions-row">
            <form method="post" action="?page=transactions" data-ajax="true">
                ' . $commonFields . '
                <input type="hidden" name="global_action" value="edit_categorisation_rule">
                <button class="button" type="submit">Edit</button>
            </form>
            <form method="post" action="?page=transactions" data-ajax="true">
                ' . $commonFields . '
                <input type="hidden" name="target_is_active" value="' . ($isActive ? '0' : '1') . '">
                <input type="hidden" name="global_action" value="toggle_categorisation_rule">
                <button class="button" type="submit">' . ($isActive ? 'Pause' : 'Activate') . '</button>
            </form>
            <form method="post" action="?page=transactions" data-ajax="true">
                ' . $commonFields . '
                <input type="hidden" name="global_action" value="delete_categorisation_rule">
                <button class="button danger" type="submit" data-chicken-check="true" data-chicken-message="Delete this categorisation rule?<br><br>This cannot be undone." data-chicken-confirm-text="Delete">Delete</button>
            </form>
        </div>';
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

    private function ruleStatusHtml(bool $isActive): string
    {
        return '<span class="badge ' . HelperFramework::escape($this->ruleStatusBadgeClass($isActive)) . '">'
            . HelperFramework::escape($this->ruleStatusLabel($isActive))
            . '</span>';
    }

    private function ruleStatusLabel(bool $isActive): string
    {
        return $isActive ? 'Active' : 'Paused';
    }

    private function nominalLabel(array $rule): string
    {
        $code = trim((string)($rule['nominal_code'] ?? ''));
        $name = trim((string)($rule['nominal_name'] ?? ''));

        if ($code !== '' && $name !== '') {
            return $code . ' - ' . $name;
        }

        if ($name !== '') {
            return $name;
        }

        if ($code !== '') {
            return $code;
        }

        $nominalAccountId = (int)($rule['nominal_account_id'] ?? 0);

        return $nominalAccountId > 0 ? 'Nominal #' . $nominalAccountId : '';
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? $this->key());
    }
}
