<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _transactions extends BaseModulePageFramework
{
    public function id(): string
    {
        return 'transactions';
    }

    public function title(): string
    {
        return 'Transactions';
    }

    public function subtitle(): string
    {
        return 'Categorise imported transactions, manage rules, and review month-by-month posting readiness.';
    }

    public function cards(): array
    {
        return ['transactions_year_summary', 'transactions_imported', 'transactions_rules', 'transactions_rule_form'];
    }

    protected function moduleContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult,
        array $baseContext
    ): array {
        $companyId = (int)($baseContext['company_id'] ?? 0);
        $taxYearId = (int)($baseContext['tax_year_id'] ?? 0);
        $dashboardRepository = new DashboardRepository();
        $monthStatus = ($companyId > 0 && $taxYearId > 0) ? $dashboardRepository->buildMonthStatus($companyId, $taxYearId) : [];
        $monthKey = $dashboardRepository->normaliseTransactionMonthFilter((string)$request->input('month_key', $request->query('month_key', '')));
        $monthKey = $monthKey !== '' ? $monthKey : $dashboardRepository->defaultTransactionMonth($monthStatus);
        $categoryFilter = $dashboardRepository->normaliseTransactionCategoryFilter((string)$request->input('category_filter', $request->query('category_filter', 'all')));

        return [
            'month_status' => $monthStatus,
            'month_key' => $monthKey,
            'selected_transaction_month' => $monthKey,
            'category_filter' => $categoryFilter,
            'selected_transaction_filter' => $categoryFilter,
            'transactions_by_month' => ($companyId > 0 && $taxYearId > 0)
                ? $dashboardRepository->fetchTransactionsForMonth($companyId, $taxYearId, $monthKey, $categoryFilter)
                : [],
            'nominal_accounts' => (new NominalAccountRepository())->fetchNominalAccounts(),
            'active_bank_company_accounts' => array_values(array_filter(
                (new CompanyAccountService())->fetchAccounts($companyId, true),
                static fn(array $account): bool => (string)($account['account_type'] ?? '') === CompanyAccountService::TYPE_BANK
            )),
            'categorisation_rules' => $companyId > 0 ? (new CategorisationRuleService())->fetchRules($companyId) : [],
            'editing_rule' => $companyId > 0
                ? (new CategorisationRuleService())->fetchRule(
                    $companyId,
                    max(0, (int)$request->input('rule_id', $request->query('rule_id', 0)))
                )
                : null,
        ];
    }
}
