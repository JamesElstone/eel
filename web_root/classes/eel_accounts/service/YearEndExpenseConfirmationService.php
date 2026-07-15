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
        private readonly ?\eel_accounts\Service\YearEndAcknowledgementService $acknowledgementService = null,
    ) {
    }

    public function fetchContext(int $companyId, int $accountingPeriodId): array
    {
        $context = $this->fetchApprovalContext($companyId, $accountingPeriodId);
        if (empty($context['available'])) {
            return $context;
        }

        $service = $this->acknowledgementService ?? new \eel_accounts\Service\YearEndAcknowledgementService();
        $acknowledgement = $service->fetch($companyId, $accountingPeriodId, 'expense_position_acknowledgement');
        $evaluation = $service->evaluate(
            $acknowledgement,
            $service->buildBasis('expense_position_acknowledgement', $context),
            ($this->lockService ?? new \eel_accounts\Service\YearEndLockService())->isLocked($companyId, $accountingPeriodId)
        );

        return $context + [
            'expense_position_acknowledged' => !empty($evaluation['current']),
            'expense_position_acknowledgement_state' => (string)($evaluation['state'] ?? 'absent'),
            'expense_position_acknowledged_at' => (string)($acknowledgement['acknowledged_at'] ?? ''),
            'expense_position_acknowledged_by' => (string)($acknowledgement['acknowledged_by'] ?? ''),
            'expense_position_approval_note' => (string)($acknowledgement['note'] ?? ''),
        ];
    }

    /**
     * Returns only the live expense facts that can be approved. This deliberately
     * avoids acknowledgement evaluation and the wider Year End checklist.
     */
    public function fetchApprovalContext(int $companyId, int $accountingPeriodId): array
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

        $lastClaimLines = $this->fetchLastClaimLines($companyId, $accountingPeriodId);
        foreach ($claimants as $index => $claimant) {
            $claimantId = (int)($claimant['claimant_id'] ?? $claimant['id'] ?? 0);
            $claimants[$index]['last_claimed'] = (string)($lastClaimLines[$claimantId]['expense_date'] ?? '');
            $claimants[$index]['last_item_desc'] = (string)($lastClaimLines[$claimantId]['description'] ?? '');
            $claimants[$index]['last_expense_amount'] = $lastClaimLines[$claimantId]['amount'] ?? null;
        }

        foreach ($totals as $key => $value) {
            $totals[$key] = round((float)$value, 2);
        }

        $context = [
            'available' => true,
            'accounting_period' => $accountingPeriod,
            'claimants' => $claimants,
            'totals' => $totals,
        ];

        return $context;
    }

    private function tableExists(string $table): bool
    {
        try {
            return \InterfaceDB::tableExists($table);
        } catch (\Throwable) {
            return false;
        }
    }

    private function fetchLastClaimLines(int $companyId, int $accountingPeriodId): array
    {
        $rows = \InterfaceDB::fetchAll(
            'SELECT ec.claimant_id,
                    ecl.expense_date,
                    ecl.description,
                    ecl.amount
             FROM expense_claim_lines ecl
             INNER JOIN expense_claims ec ON ec.id = ecl.expense_claim_id
             WHERE ec.company_id = :company_id
               AND ec.accounting_period_id = :accounting_period_id
             ORDER BY ecl.expense_date DESC, ec.id DESC, ecl.line_number DESC, ecl.id DESC',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        );

        $lastLines = [];
        foreach ((array)$rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $claimantId = (int)($row['claimant_id'] ?? 0);
            if ($claimantId > 0 && !isset($lastLines[$claimantId])) {
                $lastLines[$claimantId] = $row;
            }
        }

        return $lastLines;
    }
}
