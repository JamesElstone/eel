<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class TrialBalanceAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $companyId = max(0, (int)$request->input('company_id', 0));
        $accountingPeriodId = max(0, (int)$request->input('accounting_period_id', 0));
        $filters = [
            'search' => trim((string)$request->input('search', '')),
            'account_type' => $this->normaliseOption((string)$request->input('account_type', 'all'), [
                'all',
                'asset',
                'liability',
                'equity',
                'income',
                'cost_of_sales',
                'expense',
            ]),
            'focus' => $this->normaliseOption((string)$request->input('focus', 'all'), [
                'all',
                'income_statement',
                'balance_sheet',
                'exception',
            ]),
            'view_mode' => $this->normaliseOption((string)$request->input('view_mode', 'summary'), ['summary', 'detailed']),
        ];

        return ActionResultFramework::success(
            ['trial.balance.state'],
            [],
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'search' => $filters['search'],
                'account_type' => $filters['account_type'],
                'focus' => $filters['focus'],
                'view_mode' => $filters['view_mode'],
                'include_zero' => $this->truthy($request->input('include_zero', '0')) ? '1' : '0',
                'include_unposted' => $this->truthy($request->input('include_unposted', '0')) ? '1' : '0',
            ],
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'trial_balance_filters' => $filters,
                'trial_balance_include_zero' => $this->truthy($request->input('include_zero', '0')),
                'trial_balance_include_unposted' => $this->truthy($request->input('include_unposted', '0')),
            ]
        );
    }

    private function normaliseOption(string $value, array $allowed): string
    {
        $value = trim($value);

        return in_array($value, $allowed, true) ? $value : (string)$allowed[0];
    }

    private function truthy(mixed $value): bool
    {
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }
}
