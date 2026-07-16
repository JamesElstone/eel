<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class TrialBalanceValidationService
{
    public function __construct(
        private readonly ?\eel_accounts\Service\TrialBalanceService $trialBalanceService = null,
        private readonly ?\eel_accounts\Service\YearEndMetricsService $metricsService = null,
        private readonly ?\eel_accounts\Service\YearEndLockService $lockService = null,
        private readonly ?\eel_accounts\Service\TrialBalanceComparisonService $comparisonService = null,
    ) {
    }

    public function fetchValidation(int $companyId, int $accountingPeriodId): array {
        $trialBalanceService = $this->trialBalanceService ?? new \eel_accounts\Service\TrialBalanceService();
        $snapshot = $trialBalanceService->fetchStateSnapshot($companyId, $accountingPeriodId);

        return $this->fetchValidationFromSnapshot($companyId, $accountingPeriodId, $snapshot);
    }

    public function fetchValidationFromSnapshot(int $companyId, int $accountingPeriodId, array $snapshot): array
    {
        if (empty($snapshot['available']) || !is_array($snapshot['context'] ?? null)) {
            return [
                'available' => false,
                'errors' => (array)($snapshot['errors'] ?? ['The selected company or accounting period could not be found.']),
            ];
        }

        $balanceSheetMetrics = is_array($snapshot['balance_sheet_metrics'] ?? null)
            ? $snapshot['balance_sheet_metrics']
            : null;

        return $this->buildValidation(
            $companyId,
            $accountingPeriodId,
            (array)$snapshot['context'],
            $snapshot,
            $balanceSheetMetrics
        );
    }

    private function buildValidation(int $companyId, int $accountingPeriodId, array $context, array $tb, ?array $balanceSheetMetrics): array
    {
        $metrics = $this->metricsService ?? new \eel_accounts\Service\YearEndMetricsService();
        $periodStart = (string)($context['accounting_period']['period_start'] ?? '');
        $periodEnd = (string)($context['accounting_period']['period_end'] ?? '');
        $summary = (array)($tb['summary'] ?? []);
        $totals = (array)($tb['totals'] ?? []);
        $uncategorisedCount = $metrics->uncategorisedTransactionsCount($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $unpostedCount = $this->countUnpostedJournals($companyId, $accountingPeriodId);
        $missingPostingRoutes = $metrics->strandedCommittedSourceRowsCount($companyId, $accountingPeriodId);
        $monthTiles = $metrics->buildMonthTiles($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $review = ($this->lockService ?? new \eel_accounts\Service\YearEndLockService())->fetchReview($companyId, $accountingPeriodId);

        $bankCheck = $this->bankLedgerReasonableness($companyId, $accountingPeriodId, (int)($context['settings']['default_bank_nominal_id'] ?? 0));
        $suspenseBalance = (float)($summary['uncategorised_exposure'] ?? 0);
        $hasJournals = !empty($tb['has_rows']);
        $monthAllGreen = $monthTiles !== [] && count(array_filter($monthTiles, static fn(array $tile): bool => (string)($tile['status'] ?? '') !== 'green')) === 0;
        $reviewWarningsAcknowledged = trim((string)($review['review_notes'] ?? '')) !== '';
        $filingComparisonDifferences = count(array_filter(
            (array)(($this->comparisonService ?? new \eel_accounts\Service\TrialBalanceComparisonService($metrics))->fetchComparison($companyId, $accountingPeriodId, $balanceSheetMetrics)['rows'] ?? []),
            static fn(array $row): bool => (string)($row['status'] ?? '') === 'differs'
        ));
        $comparisonDifferences = 0;
        $deferredTaxExposure = (new \eel_accounts\Service\Frs105ValidationService())->deferredTaxNominalExposure($companyId, $accountingPeriodId);

        $checks = [
            [
                'code' => 'trial_balance_equality',
                'title' => 'Trial balance equality',
                'status' => abs((float)($totals['difference'] ?? 0)) < 0.005 ? 'pass' : 'fail',
                'detail' => abs((float)($totals['difference'] ?? 0)) < 0.005
                    ? 'Total debits equal total credits for the selected period.'
                    : 'Total debits and credits do not match for the selected period.',
                'metric_value' => round((float)($totals['difference'] ?? 0), 2),
            ],
            [
                'code' => 'uncategorised_transactions',
                'title' => 'Uncategorised and posting route check',
                'status' => ($uncategorisedCount > 0 || $missingPostingRoutes > 0) ? 'warning' : 'pass',
                'detail' => ($uncategorisedCount > 0 || $missingPostingRoutes > 0)
                    ? 'Some transactions still need categorisation or have not yet produced a ledger posting.'
                    : 'Transactions and posting routes look complete for this period.',
                'metric_value' => [
                    'uncategorised_transactions' => $uncategorisedCount,
                    'missing_posting_routes' => $missingPostingRoutes,
                ],
            ],
            [
                'code' => 'suspense_check',
                'title' => 'Suspense and uncategorised exposure',
                'status' => abs($suspenseBalance) < 0.005 ? 'pass' : 'warning',
                'detail' => abs($suspenseBalance) < 0.005
                    ? 'Suspense and uncategorised exposure is nil.'
                    : 'Suspense or uncategorised balances remain in the ledger output.',
                'metric_value' => round($suspenseBalance, 2),
            ],
            [
                'code' => 'unposted_journals',
                'title' => 'Unposted journals',
                'status' => $unpostedCount > 0 ? 'warning' : 'pass',
                'detail' => $unpostedCount > 0
                    ? 'Some journals exist in the selected period but are not posted.'
                    : 'All journals in the selected period are posted.',
                'metric_value' => $unpostedCount,
            ],
            [
                'code' => 'bank_ledger_reasonableness',
                'title' => 'Bank-to-ledger reasonableness',
                'status' => abs((float)$bankCheck['difference']) < 0.005 ? 'pass' : 'warning',
                'detail' => abs((float)$bankCheck['difference']) < 0.005
                    ? 'Transaction-derived bank movement agrees with posted bank nominal movement.'
                    : 'Transaction-derived bank movement does not agree with the posted bank nominal movement. Manual journals or incomplete posting may explain the variance.',
                'metric_value' => $bankCheck,
            ],
            [
                'code' => 'period_completeness',
                'title' => 'Period completeness',
                'status' => $monthAllGreen ? 'pass' : 'warning',
                'detail' => $monthAllGreen
                    ? 'Every month in the selected accounting period is green.'
                    : 'Some months still show no data or unresolved categorisation/suspense issues.',
                'metric_value' => $monthTiles,
            ],
            [
                'code' => 'frs105_deferred_tax_nominal',
                'title' => 'FRS 105 deferred tax recognition',
                'status' => !empty($deferredTaxExposure['exists']) ? 'warning' : 'pass',
                'detail' => (string)($deferredTaxExposure['detail'] ?? ''),
                'metric_value' => [
                    'deferred_tax_nominal_count' => (int)($deferredTaxExposure['count'] ?? 0),
                    'total_debit' => round((float)($deferredTaxExposure['total_debit'] ?? 0), 2),
                    'total_credit' => round((float)($deferredTaxExposure['total_credit'] ?? 0), 2),
                    'net_movement' => round((float)($deferredTaxExposure['net_movement'] ?? 0), 2),
                ],
            ],
        ];

        $readiness = 'Not ready';
        if ($hasJournals && abs((float)($totals['difference'] ?? 0)) < 0.005 && $uncategorisedCount === 0 && abs($suspenseBalance) < 0.005) {
            $readiness = 'Nearly ready';
            if ($monthAllGreen && $unpostedCount === 0 && $reviewWarningsAcknowledged && $comparisonDifferences === 0) {
                $readiness = 'Ready for CT working papers';
            }
        }

        return [
            'available' => true,
            'checks' => $checks,
            'month_tiles' => $monthTiles,
            'ready_for_ct_working_papers' => $readiness,
            'review_warnings_acknowledged' => $reviewWarningsAcknowledged,
            'comparison_differences' => $comparisonDifferences,
            'filing_comparison_differences' => $filingComparisonDifferences,
            'has_posted_ledger' => $hasJournals,
        ];
    }

    private function countUnpostedJournals(int $companyId, int $accountingPeriodId): int {
        $stmt = \InterfaceDB::prepare(
            'SELECT COUNT(*)
             FROM journals
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND COALESCE(is_posted, 0) = 0'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
        ]);

        return (int)$stmt->fetchColumn();
    }

    private function bankLedgerReasonableness(int $companyId, int $accountingPeriodId, int $bankNominalId): array {
        $readySplitIds = (new \eel_accounts\Service\TransactionSplitService())
            ->fetchReadySplitTransactionIds($companyId, $accountingPeriodId);
        $readySplitPredicate = $readySplitIds !== []
            ? ' OR t.id IN (' . implode(', ', array_map('intval', $readySplitIds)) . ')'
            : '';
        $txnStmt = \InterfaceDB::prepare(
            'SELECT COALESCE(SUM(t.amount), 0)
             FROM transactions t
             LEFT JOIN company_accounts ca ON ca.id = t.account_id
             WHERE t.company_id = :company_id
               AND t.accounting_period_id = :accounting_period_id
               AND ca.account_type = :account_type
               AND (
                    (t.nominal_account_id IS NOT NULL AND t.category_status IN (:auto_status, :manual_status))
                    OR (t.transfer_account_id IS NOT NULL AND t.category_status = :manual_status)
                    ' . $readySplitPredicate . '
               )'
        );
        $txnStmt->execute([
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
            'auto_status' => 'auto',
            'manual_status' => 'manual',
        ]);
        $transactionMovement = round((float)$txnStmt->fetchColumn(), 2);

        $ledgerMovement = 0.0;
        $bankNominalIds = $this->bankNominalIds($companyId, $bankNominalId);
        if ($bankNominalIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($bankNominalIds), '?'));
            $ledgerStmt = \InterfaceDB::prepare(
                'SELECT COALESCE(SUM(COALESCE(jl.debit, 0) - COALESCE(jl.credit, 0)), 0)
                 FROM journals j
                 INNER JOIN journal_lines jl ON jl.journal_id = j.id
                 WHERE j.company_id = ?
                   AND j.accounting_period_id = ?
                   AND j.is_posted = 1
                   AND jl.nominal_account_id IN (' . $placeholders . ')'
            );
            $ledgerStmt->execute(array_merge([$companyId, $accountingPeriodId], $bankNominalIds));
            $ledgerMovement = round((float)$ledgerStmt->fetchColumn(), 2);
        }

        return [
            'transaction_movement' => $transactionMovement,
            'ledger_movement' => $ledgerMovement,
            'difference' => round($transactionMovement - $ledgerMovement, 2),
        ];
    }

    private function bankNominalIds(int $companyId, int $defaultBankNominalId): array
    {
        $ids = [];
        if ($defaultBankNominalId > 0) {
            $ids[$defaultBankNominalId] = $defaultBankNominalId;
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT DISTINCT ca.nominal_account_id
             FROM company_accounts ca
             INNER JOIN nominal_accounts na ON na.id = ca.nominal_account_id
             LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             WHERE ca.company_id = :company_id
               AND ca.account_type = :account_type
               AND ca.nominal_account_id IS NOT NULL
               AND na.account_type = :nominal_type
               AND (nas.code = :subtype_code OR nas.code IS NULL)',
            [
                'company_id' => $companyId,
                'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
                'nominal_type' => 'asset',
                'subtype_code' => 'bank',
            ]
        );

        foreach ($rows as $row) {
            $id = (int)($row['nominal_account_id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}
