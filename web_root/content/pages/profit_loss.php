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

    public function cards(): array
    {
        return [
            'pl_summary',
            'pl_health_indicators',
            'pl_monthly_trend',
            'pl_income_breakdown',
            'pl_expense_breakdown',
            'pl_net_profit_bridge',
            'pl_uncategorised_watch',
            'pl_month_status_grid',
            'pl_source_coverage',
        ];
    }

    public function cardLayout(): array
    {
        return [
            [
                'tab' => 'Overview',
                'cards' => [
                    'pl_summary',
                    'pl_health_indicators',
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
                    'pl_uncategorised_watch',
                    'pl_month_status_grid',
                    'pl_source_coverage',
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
        $taxYearId = (int)($company['tax_year_id'] ?? 0);
        $profitLossService = new ProfitLossService();

        return [
            'profit_loss' => [
                'summary' => $profitLossService->getProfitLossSummary($companyId, $taxYearId),
                'breakdown' => $profitLossService->getProfitLossBreakdown($companyId, $taxYearId),
                'monthly_trend' => $profitLossService->getMonthlyProfitLossTrend($companyId, $taxYearId),
                'health' => $profitLossService->getProfitLossHealth($companyId, $taxYearId),
                'uncategorised_watch' => $profitLossService->getUncategorisedWatch($companyId, $taxYearId, 10),
                'month_status_grid' => $profitLossService->getMonthStatusGrid($companyId, $taxYearId),
                'source_coverage' => $profitLossService->getSourceCoverage($companyId, $taxYearId),
            ],
        ];
    }
}
