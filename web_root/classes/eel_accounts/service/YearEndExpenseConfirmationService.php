<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class YearEndExpenseConfirmationService
{
    public function __construct(
        private readonly ?\eel_accounts\Service\YearEndMetricsService $metricsService = null,
        private readonly ?\eel_accounts\Service\ExpenseClaimService $expenseClaimService = null,
        private readonly ?\eel_accounts\Service\YearEndLockService $lockService = null,
    ) {
    }

    public function fetchContext(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [
                'available' => false,
                'errors' => ['Select a company and accounting period before reviewing expenses.'],
            ];
        }

        foreach (['expense_claims', 'expense_claimants', 'expense_claim_lines', 'expense_claim_payment_links'] as $table) {
            if ($this->tableExists($table)) {
                continue;
            }

            return [
                'available' => false,
                'errors' => ['Expense claim tables are not available.'],
            ];
        }

        $metrics = $this->metricsService ?? new \eel_accounts\Service\YearEndMetricsService();
        $accountingPeriod = $metrics->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null) {
            return [
                'available' => false,
                'errors' => ['The selected accounting period could not be found.'],
            ];
        }

        $claimants = ($this->expenseClaimService ?? new \eel_accounts\Service\ExpenseClaimService())->fetchStatisticsClaimantBalances(
            $companyId,
            ['accounting_period_id' => $accountingPeriodId]
        );

        $totals = [
            'brought_forward' => 0.0,
            'claimed_total' => 0.0,
            'payments_made' => 0.0,
            'carried_forward' => 0.0,
        ];

        foreach ($claimants as $claimant) {
            $totals['brought_forward'] += (float)($claimant['brought_forward'] ?? 0);
            $totals['claimed_total'] += (float)($claimant['claimed_total'] ?? 0);
            $totals['payments_made'] += (float)($claimant['payments_made'] ?? 0);
            $totals['carried_forward'] += (float)($claimant['carried_forward'] ?? 0);
        }

        foreach ($totals as $key => $value) {
            $totals[$key] = round((float)$value, 2);
        }

        $review = (array)(($this->lockService ?? new \eel_accounts\Service\YearEndLockService())->fetchReview($companyId, $accountingPeriodId) ?? []);

        return [
            'available' => true,
            'accounting_period' => $accountingPeriod,
            'claimants' => $claimants,
            'totals' => $totals,
            'expense_position_acknowledged' => trim((string)($review['expense_position_acknowledged_at'] ?? '')) !== '',
            'expense_position_acknowledged_at' => (string)($review['expense_position_acknowledged_at'] ?? ''),
            'expense_position_acknowledged_by' => (string)($review['expense_position_acknowledged_by'] ?? ''),
        ];
    }

    private function tableExists(string $table): bool
    {
        try {
            return \InterfaceDB::tableExists($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
