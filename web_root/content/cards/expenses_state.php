<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _expenses_stateCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'expenses_state';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'expensesPageData',
                'service' => ExpenseClaimService::class,
                'method' => 'fetchPageData',
                'params' => [
                    'companyId' => ':company_id',
                    'filters' => ':expense_filters',
                ],
            ],
        ];
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
        $selectedCompanyId = (int)($page['selected_company_id'] ?? $page['company_id'] ?? 0);
        $settings = (array)($page['settings'] ?? []);
        $expensesPageData = $context['services']['expensesPageData'] ?? [];
        $expenseBootstrapJson = json_encode($expensesPageData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($expenseBootstrapJson)) {
            $expenseBootstrapJson = '{}';
        }

        return '<section hidden aria-hidden="true">
            <div
                id="expenses-page"
                class="expenses-page"
                data-company-id="' . $selectedCompanyId . '"
                data-director-loan-nominal-id="' . (int)((string)($settings['director_loan_nominal_id'] ?? '') !== '' ? $settings['director_loan_nominal_id'] : 0) . '"
                data-default-bank-nominal-id="' . (int)((string)($settings['default_bank_nominal_id'] ?? '') !== '' ? $settings['default_bank_nominal_id'] : 0) . '"
                data-default-expense-nominal-id="' . (int)((string)($settings['default_expense_nominal_id'] ?? '') !== '' ? $settings['default_expense_nominal_id'] : 0) . '"
            >
                <script type="application/json" id="expenses-page-bootstrap">' . $expenseBootstrapJson . '</script>
            </div>
        </section>';
    }
}
