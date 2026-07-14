<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _profit_loss extends PageContextFramework
{
    public function id(): string
    {
        return 'profit_loss';
    }

    public function title(): string
    {
        return 'Profit & Loss';
    }

    public function subtitle(): string
    {
        return 'Review ledger-derived profit and loss, monthly trends, and the data quality behind the numbers.';
    }

    public function ajaxPendingBlurScope(): string
    {
        return 'page';
    }

    public function cards(): array
    {
        return [
            'pl_summary',
            'pl_monthly_trend',
            'pl_income_breakdown',
            'pl_expense_breakdown',
            'pl_net_profit_bridge',
            'pl_source_coverage',
            'year_end_retained_earnings',
        ];
    }

    public function cardLayout(): array
    {
        return [
            [
                'tab' => 'Overview',
                'cards' => [
                    'pl_summary',
                    'pl_monthly_trend',
                ],
            ],
            [
                'tab' => 'Breakdown',
                'cards' => [
                    'pl_income_breakdown',
                    'pl_expense_breakdown',
                    'pl_net_profit_bridge',
                ],
            ],
            [
                'tab' => 'Data Quality',
                'cards' => [
                    'pl_source_coverage',
                ],
            ],
            [
                'tab' => 'Year End Confirmation',
                'cards' => [
                    'year_end_retained_earnings',
                ],
            ],
        ];
    }

    protected function moduleContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult,
        array $baseContext
    ): array {
        $company = (array)($baseContext['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $profitLossService = new \eel_accounts\Service\ProfitLossService();

        return [
            'profit_loss' => [
                'summary' => $profitLossService->getProfitLossSummary($companyId, $accountingPeriodId),
                'ct_period_reconciliation' => $profitLossService->getCtPeriodProfitReconciliation($companyId, $accountingPeriodId),
                'breakdown' => $profitLossService->getProfitLossBreakdown($companyId, $accountingPeriodId),
                'monthly_trend' => $profitLossService->getMonthlyProfitLossTrend($companyId, $accountingPeriodId),
                'health' => $profitLossService->getProfitLossHealth($companyId, $accountingPeriodId),
                'source_coverage' => $profitLossService->getSourceCoverage($companyId, $accountingPeriodId),
            ],
        ];
    }
}
