<?php
declare(strict_types=1);

final class TrialBalanceValidationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?TrialBalanceService $trialBalanceService = null,
        private readonly ?YearEndMetricsService $metricsService = null,
        private readonly ?YearEndLockService $lockService = null,
    ) {
    }

    public function fetchValidation(int $companyId, int $taxYearId): array {
        $trialBalanceService = $this->trialBalanceService ?? new TrialBalanceService($this->pdo);
        $metrics = $this->metricsService ?? new YearEndMetricsService($this->pdo);
        $context = $trialBalanceService->fetchPageContext($companyId, $taxYearId);

        if ($context === null) {
            return [
                'available' => false,
                'errors' => ['The selected company or accounting period could not be found.'],
            ];
        }

        $periodStart = (string)($context['tax_year']['period_start'] ?? '');
        $periodEnd = (string)($context['tax_year']['period_end'] ?? '');
        $tb = $trialBalanceService->fetchTrialBalance($companyId, $taxYearId, true, false);
        $summary = (array)($tb['summary'] ?? []);
        $totals = (array)($tb['totals'] ?? []);
        $uncategorisedCount = $metrics->uncategorisedTransactionsCount($companyId, $taxYearId, $periodStart, $periodEnd);
        $unpostedCount = $this->countUnpostedJournals($companyId, $taxYearId);
        $missingPostingRoutes = $metrics->strandedCommittedSourceRowsCount($companyId, $taxYearId);
        $monthTiles = $metrics->buildMonthTiles($companyId, $taxYearId, $periodStart, $periodEnd);
        $review = ($this->lockService ?? new YearEndLockService($this->pdo))->fetchReview($companyId, $taxYearId);

        $bankCheck = $this->bankLedgerReasonableness($companyId, $taxYearId, (int)($context['settings']['default_bank_nominal_id'] ?? 0));
        $suspenseBalance = (float)($summary['uncategorised_exposure'] ?? 0);
        $hasJournals = !empty($tb['has_rows']);
        $monthAllGreen = $monthTiles !== [] && count(array_filter($monthTiles, static fn(array $tile): bool => (string)($tile['status'] ?? '') !== 'green')) === 0;
        $reviewWarningsAcknowledged = trim((string)($review['review_notes'] ?? '')) !== '';
        $comparisonDifferences = count(array_filter(
            (array)((new TrialBalanceComparisonService($this->pdo))->fetchComparison($companyId, $taxYearId)['rows'] ?? []),
            static fn(array $row): bool => (string)($row['status'] ?? '') === 'differs'
        ));

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
            'has_posted_ledger' => $hasJournals,
        ];
    }

    private function countUnpostedJournals(int $companyId, int $taxYearId): int {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM journals
             WHERE company_id = :company_id
               AND tax_year_id = :tax_year_id
               AND COALESCE(is_posted, 0) = 0'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
        ]);

        return (int)$stmt->fetchColumn();
    }

    private function bankLedgerReasonableness(int $companyId, int $taxYearId, int $bankNominalId): array {
        $txnStmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0)
             FROM transactions
             WHERE company_id = :company_id
               AND tax_year_id = :tax_year_id
               AND nominal_account_id IS NOT NULL
               AND category_status IN (:auto_status, :manual_status)'
        );
        $txnStmt->execute([
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
            'auto_status' => 'auto',
            'manual_status' => 'manual',
        ]);
        $transactionMovement = round((float)$txnStmt->fetchColumn(), 2);

        $ledgerMovement = 0.0;
        if ($bankNominalId > 0) {
            $ledgerStmt = $this->pdo->prepare(
                'SELECT COALESCE(SUM(COALESCE(jl.debit, 0) - COALESCE(jl.credit, 0)), 0)
                 FROM journals j
                 INNER JOIN journal_lines jl ON jl.journal_id = j.id
                 WHERE j.company_id = :company_id
                   AND j.tax_year_id = :tax_year_id
                   AND j.is_posted = 1
                   AND jl.nominal_account_id = :nominal_account_id'
            );
            $ledgerStmt->execute([
                'company_id' => $companyId,
                'tax_year_id' => $taxYearId,
                'nominal_account_id' => $bankNominalId,
            ]);
            $ledgerMovement = round((float)$ledgerStmt->fetchColumn(), 2);
        }

        return [
            'transaction_movement' => $transactionMovement,
            'ledger_movement' => $ledgerMovement,
            'difference' => round($transactionMovement - $ledgerMovement, 2),
        ];
    }
}
