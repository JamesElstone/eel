<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class YearEndChecklistService
{
    private const REVIEW_ACKNOWLEDGEABLE_CHECKS = [
        'fixed_asset_review_placeholder',
        'prepayments_accruals_placeholder',
        'filing_basis_reminder',
        'director_loan_tax_review',
    ];

    public function __construct(
        private readonly ?\eel_accounts\Service\YearEndMetricsService $metricsService = null,
        private readonly ?\eel_accounts\Service\YearEndTaxReadinessService $taxReadinessService = null,
        private readonly ?\eel_accounts\Service\YearEndCompaniesHouseComparisonService $companiesHouseComparisonService = null,
        private readonly ?\eel_accounts\Service\YearEndLockService $lockService = null,
        private readonly ?\eel_accounts\Service\AssetService $assetService = null,
        private readonly ?\eel_accounts\Service\RetainedEarningsCloseService $retainedEarningsCloseService = null,
    ) {
    }

    public function fetchDashboardSummary(int $companyId, ?int $accountingPeriodId = null): array {
        $metrics = $this->metricsService ?? new \eel_accounts\Service\YearEndMetricsService();
        $resolvedAccountingPeriodId = $accountingPeriodId !== null && $accountingPeriodId > 0
            ? $accountingPeriodId
            : $metrics->resolveLatestOpenAccountingPeriodId($companyId);

        $accountingPeriod = $resolvedAccountingPeriodId > 0
            ? $metrics->fetchAccountingPeriod($companyId, $resolvedAccountingPeriodId)
            : null;

        if (!is_array($accountingPeriod)) {
            return [
                'available' => false,
                'status' => 'not_started',
                'period_label' => 'No accounting period selected',
                'top_issues' => [],
                'action_url' => '?page=year_end',
            ];
        }

        $lock = $this->lockService ?? new \eel_accounts\Service\YearEndLockService();
        $review = $lock->fetchReview($companyId, $resolvedAccountingPeriodId);
        $topIssues = $this->fetchPersistedDashboardTopIssues($companyId, $resolvedAccountingPeriodId);
        $hasPersistedSnapshot = is_array($review) && trim((string)($review['last_recalculated_at'] ?? '')) !== '';

        if ($hasPersistedSnapshot || $topIssues !== []) {
            return [
                'available' => true,
                'status' => $this->dashboardReviewStatus($review),
                'period_label' => (string)($accountingPeriod['label'] ?? ''),
                'accounting_period_id' => (int)($accountingPeriod['id'] ?? $resolvedAccountingPeriodId),
                'top_issues' => $topIssues,
                'action_url' => $this->dashboardActionUrl($companyId, $resolvedAccountingPeriodId),
            ];
        }

        $bootstrap = $this->buildDashboardBootstrapChecks($companyId, $resolvedAccountingPeriodId, $accountingPeriod, $review);
        $checks = (array)($bootstrap['checks'] ?? []);
        $isLocked = is_array($review) && (int)($review['is_locked'] ?? 0) === 1;

        return [
            'available' => true,
            'status' => $this->determineOverallStatus($checks, (bool)($bootstrap['has_source_data'] ?? false), $isLocked),
            'period_label' => (string)($accountingPeriod['label'] ?? ''),
            'accounting_period_id' => (int)($accountingPeriod['id'] ?? $resolvedAccountingPeriodId),
            'top_issues' => $this->topIssuesFromChecks($checks),
            'action_url' => $this->dashboardActionUrl($companyId, $resolvedAccountingPeriodId),
        ];
    }

    private function dashboardReviewStatus(?array $review): string
    {
        $status = (string)($review['status'] ?? 'not_started');

        return in_array($status, ['not_started', 'in_progress', 'needs_attention', 'ready_for_review', 'locked'], true)
            ? $status
            : 'not_started';
    }

    private function dashboardActionUrl(int $companyId, int $accountingPeriodId): string
    {
        return '?page=year_end&show_card=year_end_checklist';
    }

    private function fetchPersistedDashboardTopIssues(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || !$this->tableExists('year_end_check_results')) {
            return [];
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT title,
                    detail_text,
                    metric_value,
                    status
             FROM year_end_check_results
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND status IN (:warning_status, :fail_status)
             ORDER BY id ASC
             LIMIT 5',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'warning_status' => 'warning',
                'fail_status' => 'fail',
            ]
        );

        return $this->topIssuesFromChecks((array)$rows);
    }

    private function buildDashboardBootstrapChecks(int $companyId, int $accountingPeriodId, array $accountingPeriod, ?array $review): array
    {
        $metrics = $this->metricsService ?? new \eel_accounts\Service\YearEndMetricsService();
        $periodStart = (string)($accountingPeriod['period_start'] ?? '');
        $periodEnd = (string)($accountingPeriod['period_end'] ?? '');
        $transactionCount = $this->dashboardCountTransactions($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $postedJournalCount = $this->dashboardCountPostedJournals($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $uncategorisedCount = $this->dashboardCountUncategorisedTransactions($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $trialBalance = $this->dashboardTrialBalanceStatus($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $postedSourceWork = $metrics->postedSourceWorkSummary($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $settings = $metrics->fetchCompanySettings($companyId);
        $unpostedSourceWorkCount = (int)($postedSourceWork['total_unposted'] ?? 0);
        $hasSourceData = ($transactionCount + $postedJournalCount) > 0;
        $isLocked = is_array($review) && (int)($review['is_locked'] ?? 0) === 1;
        $checks = [];

        $checks[] = $this->makeCheck(
            'source_data_present',
            'Source data present',
            'fail',
            $hasSourceData ? 'pass' : 'fail',
            $hasSourceData
                ? 'Transactions or posted journals exist for this period.'
                : 'No committed bank transactions or posted journals were found in this period.',
            (string)($transactionCount + $postedJournalCount),
            '?page=uploads&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
        );
        $checks[] = $this->makeCheck(
            'uncategorised_transactions',
            'Uncategorised transactions',
            'fail',
            $uncategorisedCount > 0 ? 'fail' : 'pass',
            $uncategorisedCount > 0
                ? 'Transactions still need a nominal account before the period is ready.'
                : 'Every transaction in the selected period has a nominal account.',
            (string)$uncategorisedCount,
            '?page=transactions&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId . '&category_filter=uncategorised'
        );
        $checks[] = $this->makeCheck(
            'trial_balance_exists',
            'Trial balance exists',
            'fail',
            !empty($trialBalance['exists']) ? 'pass' : 'fail',
            !empty($trialBalance['exists'])
                ? 'A trial balance can be generated from posted journals in this period.'
                : 'No posted journal data exists to generate a trial balance for this period.',
            (string)($trialBalance['line_count'] ?? 0),
            '?page=journal&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
        );
        $checks[] = $this->makeCheck(
            'trial_balance_balances',
            'Trial balance balances',
            'fail',
            !empty($trialBalance['balances']) ? 'pass' : 'fail',
            !empty($trialBalance['balances'])
                ? 'Total debits equal total credits.'
                : 'Total debits and credits do not match for the selected period.',
            $this->money($settings, $trialBalance['difference'] ?? 0),
            '?page=journal&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
        );
        $checks[] = $this->makeCheck(
            'posted_only_period_integrity',
            'Posted-only period integrity',
            'fail',
            $unpostedSourceWorkCount > 0 ? 'fail' : 'pass',
            $this->postedSourceWorkDetail($postedSourceWork),
            $this->postedSourceWorkMetric($postedSourceWork),
            $this->postedSourceWorkActionUrl($postedSourceWork)
        );

        return [
            'has_source_data' => $hasSourceData,
            'checks' => $checks,
        ];
    }

    private function dashboardCountTransactions(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): int
    {
        return (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM transactions
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND txn_date BETWEEN :period_start AND :period_end',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]
        );
    }

    private function dashboardCountUncategorisedTransactions(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): int
    {
        return (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM transactions
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND txn_date BETWEEN :period_start AND :period_end
               AND (category_status = :category_status OR nominal_account_id IS NULL)',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'category_status' => 'uncategorised',
            ]
        );
    }

    private function dashboardCountPostedJournals(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): int
    {
        return (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM journals
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND is_posted = 1
               AND journal_date BETWEEN :period_start AND :period_end',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]
        );
    }

    private function dashboardTrialBalanceStatus(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT COUNT(jl.id) AS line_count,
                    COALESCE(SUM(jl.debit), 0) AS total_debits,
                    COALESCE(SUM(jl.credit), 0) AS total_credits
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
               AND j.is_posted = 1
               AND j.journal_date BETWEEN :period_start AND :period_end',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]
        ) ?: [];
        $difference = round((float)($row['total_debits'] ?? 0) - (float)($row['total_credits'] ?? 0), 2);
        $lineCount = (int)($row['line_count'] ?? 0);

        return [
            'exists' => $lineCount > 0,
            'line_count' => $lineCount,
            'difference' => $difference,
            'balances' => $lineCount > 0 && abs($difference) < 0.005,
        ];
    }

    private function topIssuesFromChecks(array $checks): array
    {
        $topIssues = [];
        foreach ($checks as $check) {
            if (!is_array($check) || !in_array((string)($check['status'] ?? ''), ['warning', 'fail'], true)) {
                continue;
            }

            $topIssues[] = [
                'title' => (string)($check['title'] ?? ''),
                'detail' => (string)($check['detail_text'] ?? ''),
                'metric_value' => (string)($check['metric_value'] ?? ''),
                'status' => (string)($check['status'] ?? 'pass'),
            ];

            if (count($topIssues) >= 5) {
                break;
            }
        }

        return $topIssues;
    }

    public function lockPeriod(int $companyId, int $accountingPeriodId, string $lockedBy = 'web_app'): array {
        $checklistResult = $this->fetchChecklistResult($companyId, $accountingPeriodId, true);
        if (empty($checklistResult['success'])) {
            return $checklistResult;
        }

        $checklist = (array)$checklistResult['checklist'];
        $overallStatus = (string)($checklist['overall_status'] ?? 'not_started');
        if (in_array($overallStatus, ['needs_attention', 'not_started'], true)) {
            return [
                'success' => false,
                'status' => 422,
                'errors' => ['Resolve the blocking year-end checks before locking this period.'],
                'checklist' => $checklist,
            ];
        }

        $directorLoanOffsetResult = $this->applyDirectorLoanOffsetBeforeLock($companyId, $accountingPeriodId, $checklist, $lockedBy);
        if (empty($directorLoanOffsetResult['success'])) {
            return [
                'success' => false,
                'status' => (int)($directorLoanOffsetResult['status'] ?? 422),
                'errors' => (array)($directorLoanOffsetResult['errors'] ?? ['Director loan offset could not be applied before locking this period.']),
                'checklist' => $checklist,
                'director_loan_offset' => $directorLoanOffsetResult,
            ];
        }

        $depreciationResult = ($this->assetService ?? new \eel_accounts\Service\AssetService())->runDepreciation($companyId, $accountingPeriodId);
        if (empty($depreciationResult['success'])) {
            return [
                'success' => false,
                'status' => (int)($depreciationResult['status'] ?? 422),
                'errors' => (array)($depreciationResult['errors'] ?? ['Depreciation could not be posted before locking this period.']),
                'checklist' => $checklist,
                'director_loan_offset' => $directorLoanOffsetResult,
                'depreciation' => $depreciationResult,
            ];
        }

        $retainedEarningsCloseResult = ($this->retainedEarningsCloseService ?? new \eel_accounts\Service\RetainedEarningsCloseService())
            ->postClose($companyId, $accountingPeriodId, $lockedBy);
        if (empty($retainedEarningsCloseResult['success'])) {
            return [
                'success' => false,
                'status' => (int)($retainedEarningsCloseResult['status'] ?? 422),
                'errors' => (array)($retainedEarningsCloseResult['errors'] ?? ['Retained earnings close could not be posted before locking this period.']),
                'checklist' => $checklist,
                'director_loan_offset' => $directorLoanOffsetResult,
                'depreciation' => $depreciationResult,
                'retained_earnings_close' => $retainedEarningsCloseResult,
            ];
        }

        $lock = $this->lockService ?? new \eel_accounts\Service\YearEndLockService();
        $result = $lock->lockPeriod($companyId, $accountingPeriodId, $lockedBy);
        if (empty($result['success'])) {
            return $result;
        }

        return $result + [
            'depreciation' => $depreciationResult,
            'director_loan_offset' => $directorLoanOffsetResult,
            'retained_earnings_close' => $retainedEarningsCloseResult,
            'checklist' => $this->fetchChecklist($companyId, $accountingPeriodId, true),
        ];
    }

    private function applyDirectorLoanOffsetBeforeLock(int $companyId, int $accountingPeriodId, array $checklist, string $changedBy): array {
        $offsetService = new \eel_accounts\Service\DirectorLoanReconciliationService();
        $offsetContext = $offsetService->fetchContext($companyId, $accountingPeriodId);

        if (empty($offsetContext['available']) || empty($offsetContext['can_post'])) {
            return [
                'success' => true,
                'skipped' => true,
                'context' => $offsetContext,
            ];
        }

        $review = (array)($checklist['review'] ?? []);
        if (trim((string)($review['director_loan_closing_acknowledged_at'] ?? '')) === '') {
            return [
                'success' => false,
                'status' => 422,
                'errors' => ['Save the director loan offset acknowledgement before locking this accounting period.'],
                'context' => $offsetContext,
            ];
        }

        return $offsetService->postOffset($companyId, $accountingPeriodId, $changedBy);
    }

    public function saveNotes(int $companyId, int $accountingPeriodId, string $notes, string $changedBy = 'web_app'): array {
        $checklistResult = $this->fetchChecklistResult($companyId, $accountingPeriodId, true);
        if (empty($checklistResult['success'])) {
            return $checklistResult;
        }

        $lock = $this->lockService ?? new \eel_accounts\Service\YearEndLockService();
        $result = $lock->saveNotes($companyId, $accountingPeriodId, $notes, $changedBy);
        if (empty($result['success'])) {
            return $result;
        }

        return $result + [
            'checklist' => $this->fetchChecklist($companyId, $accountingPeriodId, true),
        ];
    }

    public function saveDirectorLoanClosingAcknowledgement(int $companyId, int $accountingPeriodId, bool $acknowledged, string $changedBy = 'web_app'): array {
        if (!$acknowledged) {
            return [
                'success' => false,
                'errors' => ['Tick the director loan offset acknowledgement before saving.'],
            ];
        }

        $checklistResult = $this->fetchChecklistResult($companyId, $accountingPeriodId, true);
        if (empty($checklistResult['success'])) {
            return $checklistResult;
        }

        $lock = $this->lockService ?? new \eel_accounts\Service\YearEndLockService();
        $result = $lock->saveDirectorLoanClosingAcknowledgement($companyId, $accountingPeriodId, $acknowledged, $changedBy);
        if (empty($result['success'])) {
            return $result;
        }

        return $result + [
            'checklist' => $this->fetchChecklist($companyId, $accountingPeriodId, true),
        ];
    }

    public function saveTaxReadinessAcknowledgement(int $companyId, int $accountingPeriodId, bool $acknowledged, string $changedBy = 'web_app'): array {
        if (!$acknowledged) {
            return [
                'success' => false,
                'errors' => ['Tick the tax readiness acknowledgement before saving.'],
            ];
        }

        $checklistResult = $this->fetchChecklistResult($companyId, $accountingPeriodId, true);
        if (empty($checklistResult['success'])) {
            return $checklistResult;
        }

        $lock = $this->lockService ?? new \eel_accounts\Service\YearEndLockService();
        $result = $lock->saveTaxReadinessAcknowledgement($companyId, $accountingPeriodId, $acknowledged, $changedBy);
        if (empty($result['success'])) {
            return $result;
        }

        return $result + [
            'checklist' => $this->fetchChecklist($companyId, $accountingPeriodId, true),
        ];
    }

    public function saveExpensePositionAcknowledgement(int $companyId, int $accountingPeriodId, bool $acknowledged, string $changedBy = 'web_app'): array {
        if (!$acknowledged) {
            return [
                'success' => false,
                'errors' => ['Tick the expense position acknowledgement before saving.'],
            ];
        }

        $checklistResult = $this->fetchChecklistResult($companyId, $accountingPeriodId, true);
        if (empty($checklistResult['success'])) {
            return $checklistResult;
        }

        $lock = $this->lockService ?? new \eel_accounts\Service\YearEndLockService();
        $result = $lock->saveExpensePositionAcknowledgement($companyId, $accountingPeriodId, $acknowledged, $changedBy);
        if (empty($result['success'])) {
            return $result;
        }

        return $result + [
            'checklist' => $this->fetchChecklist($companyId, $accountingPeriodId, true),
        ];
    }

    public function saveRetainedEarningsCloseAcknowledgement(int $companyId, int $accountingPeriodId, bool $acknowledged, string $changedBy = 'web_app'): array {
        if (!$acknowledged) {
            return [
                'success' => false,
                'errors' => ['Tick the retained earnings acknowledgement before saving.'],
            ];
        }

        $checklistResult = $this->fetchChecklistResult($companyId, $accountingPeriodId, true);
        if (empty($checklistResult['success'])) {
            return $checklistResult;
        }

        $result = ($this->retainedEarningsCloseService ?? new \eel_accounts\Service\RetainedEarningsCloseService())
            ->saveAcknowledgement($companyId, $accountingPeriodId, $acknowledged, $changedBy);
        if (empty($result['success'])) {
            return $result;
        }

        return $result + [
            'checklist' => $this->fetchChecklist($companyId, $accountingPeriodId, true),
        ];
    }

    public function acknowledgeReviewCheck(
        int $companyId,
        int $accountingPeriodId,
        string $checkCode,
        bool $acknowledged,
        string $note = '',
        string $changedBy = 'web_app'
    ): array {
        $checkCode = trim($checkCode);
        if (!in_array($checkCode, self::REVIEW_ACKNOWLEDGEABLE_CHECKS, true)) {
            return [
                'success' => false,
                'errors' => ['This year-end check cannot be cleared by acknowledgement.'],
            ];
        }

        if (!$this->tableExists('year_end_review_acknowledgements')) {
            return [
                'success' => false,
                'errors' => ['Run the Year End review acknowledgement migration before saving this review.'],
            ];
        }

        $checklistResult = $this->fetchChecklistResult($companyId, $accountingPeriodId, false);
        if (empty($checklistResult['success'])) {
            return $checklistResult;
        }

        $currentCheck = $this->findChecklistCheck((array)$checklistResult['checklist'], $checkCode);
        if ($currentCheck === null) {
            return [
                'success' => false,
                'errors' => ['The selected year-end check could not be found.'],
            ];
        }

        if ((string)($currentCheck['status'] ?? '') === 'fail') {
            return [
                'success' => false,
                'errors' => ['Resolve the blocking year-end check instead of acknowledging it.'],
            ];
        }

        $existing = $this->fetchReviewAcknowledgements($companyId, $accountingPeriodId)[$checkCode] ?? null;
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $actor = $this->actorValue($changedBy);
        $note = trim($note);

        if ($acknowledged) {
            \InterfaceDB::execute(
                'INSERT INTO year_end_review_acknowledgements (
                    company_id,
                    accounting_period_id,
                    check_code,
                    acknowledged_at,
                    acknowledged_by,
                    note,
                    created_at,
                    updated_at
                 ) VALUES (
                    :company_id,
                    :accounting_period_id,
                    :check_code,
                    :acknowledged_at,
                    :acknowledged_by,
                    :note,
                    :created_at,
                    :updated_at
                 )
                 ON DUPLICATE KEY UPDATE
                    acknowledged_at = VALUES(acknowledged_at),
                    acknowledged_by = VALUES(acknowledged_by),
                    note = VALUES(note),
                    updated_at = VALUES(updated_at)',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'check_code' => $checkCode,
                    'acknowledged_at' => $now,
                    'acknowledged_by' => $actor,
                    'note' => $note !== '' ? $note : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        } else {
            \InterfaceDB::execute(
                'DELETE FROM year_end_review_acknowledgements
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                   AND check_code = :check_code',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'check_code' => $checkCode,
                ]
            );
        }

        ($this->lockService ?? new \eel_accounts\Service\YearEndLockService())->writeAuditLog(
            $companyId,
            $accountingPeriodId,
            $acknowledged ? 'review_check_acknowledged' : 'review_check_reopened',
            $changedBy,
            is_array($existing) ? $existing : null,
            [
                'check_code' => $checkCode,
                'acknowledged' => $acknowledged,
                'note' => $note !== '' ? $note : null,
            ]
        );

        return [
            'success' => true,
            'checklist' => $this->fetchChecklist($companyId, $accountingPeriodId, true),
        ];
    }

    public function unlockPeriod(int $companyId, int $accountingPeriodId, string $changedBy = 'web_app', ?string $notes = null): array {
        $checklistResult = $this->fetchChecklistResult($companyId, $accountingPeriodId, true);
        if (empty($checklistResult['success'])) {
            return $checklistResult;
        }

        $lock = $this->lockService ?? new \eel_accounts\Service\YearEndLockService();
        $result = $lock->unlockPeriod($companyId, $accountingPeriodId, $changedBy, $notes);
        if (empty($result['success'])) {
            return $result;
        }

        return $result + [
            'checklist' => $this->fetchChecklist($companyId, $accountingPeriodId, true),
        ];
    }

    public function recalculateChecklist(int $companyId, int $accountingPeriodId, string $changedBy = 'web_app'): array {
        $checklistResult = $this->fetchChecklistResult($companyId, $accountingPeriodId, true);
        if (empty($checklistResult['success'])) {
            return $checklistResult;
        }

        $checklist = (array)$checklistResult['checklist'];
        ($this->lockService ?? new \eel_accounts\Service\YearEndLockService())->writeAuditLog(
            $companyId,
            $accountingPeriodId,
            'recalculate',
            $changedBy,
            null,
            ['overall_status' => $checklist['overall_status'] ?? 'not_started']
        );

        return [
            'success' => true,
            'checklist' => $checklist,
        ];
    }

    public function fetchChecklist(int $companyId, int $accountingPeriodId, bool $persist = true): ?array {
        $metrics = $this->metricsService ?? new \eel_accounts\Service\YearEndMetricsService();
        
        $tax = $this->taxReadinessService ?? new \eel_accounts\Service\YearEndTaxReadinessService($metrics, null);

        $comparison = $this->companiesHouseComparisonService ?? new \eel_accounts\Service\YearEndCompaniesHouseComparisonService($metrics, null);
        $lock = $this->lockService ?? new \eel_accounts\Service\YearEndLockService();
        $accountingPeriod = $metrics->fetchAccountingPeriod($companyId, $accountingPeriodId);

        if ($accountingPeriod === null) {
            return null;
        }

        $settings = $metrics->fetchCompanySettings($companyId);
        $bankNominalId = (int)($settings['default_bank_nominal_id'] ?? 0);
        $periodStart = (string)$accountingPeriod['period_start'];
        $periodEnd = (string)$accountingPeriod['period_end'];
        $review = $lock->fetchReview($companyId, $accountingPeriodId);
        $reviewAcknowledgements = $this->fetchReviewAcknowledgements($companyId, $accountingPeriodId);

        $monthTiles = $metrics->buildMonthTiles($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $sourceData = $metrics->sourceDataSummary($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $uncategorisedCount = $metrics->uncategorisedTransactionsCount($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $autoDecisionSummary = $metrics->autoCategorisedDecisionSummary($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $autoAttentionCount = (int)($autoDecisionSummary['total_attention_count'] ?? 0);
        $suspenseSummary = $metrics->suspenseSummary($companyId, $accountingPeriodId, $periodEnd);
        $trialBalance = $metrics->trialBalanceSummary($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $journalIntegrity = $metrics->journalIntegritySummary($companyId, $accountingPeriodId);
        $postedSourceWork = $metrics->postedSourceWorkSummary($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $statementContinuity = $metrics->statementContinuitySummary($companyId, $accountingPeriodId, $bankNominalId);
        $duplicateAudit = $metrics->duplicateImportAudit($companyId, $accountingPeriodId);
        $strandedRows = $metrics->strandedCommittedSourceRowsCount($companyId, $accountingPeriodId);
        $directorLoan = $metrics->directorLoanSummary($companyId, $accountingPeriodId);
        $directorLoanTaxReview = (new \eel_accounts\Service\DirectorLoanService())->fetchTaxReview($companyId, $accountingPeriodId);
        $expensePosition = (new \eel_accounts\Service\YearEndExpenseConfirmationService($metrics))->fetchContext($companyId, $accountingPeriodId);
        $duplicateRepayments = $metrics->duplicateRepaymentRiskSummary($companyId, $periodStart, $periodEnd);
        $financialStatements = $metrics->financialStatementsSummary($companyId, $accountingPeriodId, $periodStart, $periodEnd, $trialBalance);
        $retainedEarningsClose = ($this->retainedEarningsCloseService ?? new \eel_accounts\Service\RetainedEarningsCloseService())
            ->fetchContext($companyId, $accountingPeriodId);
        $incorporationShares = (new \eel_accounts\Service\IncorporationShareCapitalService())->fetchSummary($companyId);
        $potentialAssetThreshold = \eel_accounts\Service\AssetService::normalisePotentialAssetThreshold($settings['potential_asset_threshold'] ?? 250);
        $potentialAssetCandidateCount = ($this->assetService ?? new \eel_accounts\Service\AssetService())->potentialAssetCandidateCount(
            $companyId,
            $accountingPeriodId,
            (int)($settings['tools_small_equipment_nominal_id'] ?? 0),
            $potentialAssetThreshold
        );
        $taxReadiness = $tax->fetchCurrentPeriodEstimate(
            $companyId,
            $accountingPeriodId,
            $accountingPeriod,
            (array)($financialStatements['profit_and_loss'] ?? [])
        );
        $chComparison = $comparison->fetchComparison(
            $companyId,
            $accountingPeriodId,
            $accountingPeriod,
            (array)(($financialStatements['balance_sheet'] ?? [])['metrics'] ?? [])
        );

        $sections = [];
        $checks = [];

        $sections['bookkeeping_completeness'][] = $this->makeCheck(
            'period_exists',
            'Period exists',
            'fail',
            'pass',
            'The selected accounting period was found and can be used for year-end review.',
            (string)$accountingPeriod['label'],
            '?page=companies&company_id=' . $companyId
        );
        $hasSourceData = array_sum($sourceData) > 0;
        $sections['bookkeeping_completeness'][] = $this->makeCheck(
            'source_data_present',
            'Source data present',
            'fail',
            $hasSourceData ? 'pass' : 'fail',
            $hasSourceData
                ? 'Transactions or posted journals exist for this period.'
                : 'No committed bank transactions or posted journals were found in this period.',
            (string)array_sum($sourceData),
            '?page=uploads&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
        );
        $missingMonths = count(array_filter($monthTiles, static fn(array $tile): bool => (string)$tile['status'] === 'red'));
        $sections['bookkeeping_completeness'][] = $this->makeCheck(
            'missing_month_warning',
            'Expected month coverage',
            'warning',
            $missingMonths > 0 ? 'warning' : 'pass',
            $missingMonths > 0
                ? 'Some months inside the accounting period have no uploads or transactions and should be reviewed.'
                : 'Every month inside the accounting period has at least some source activity.',
            $missingMonths > 0 ? $missingMonths . ' missing month' . ($missingMonths === 1 ? '' : 's') : 'All months covered',
            '?page=uploads&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
        );

        $sections['categorisation_suspense'][] = $this->makeCheck(
            'uncategorised_transactions',
            'Uncategorised transactions',
            'fail',
            $uncategorisedCount > 0 ? 'fail' : 'pass',
            $uncategorisedCount > 0
                ? 'Transactions still need a nominal account before the period is ready.'
                : 'Every transaction in the selected period has a nominal account.',
            (string)$uncategorisedCount,
            '?page=transactions&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId . '&category_filter=uncategorised'
        );
        $sections['categorisation_suspense'][] = $this->makeCheck(
            'suspense_balance',
            'Suspense balance',
            'fail',
            abs((float)$suspenseSummary['closing_balance']) > 0.004 ? 'fail' : ((bool)$suspenseSummary['has_nominal'] ? 'pass' : 'not_applicable'),
            (bool)$suspenseSummary['has_nominal']
                ? 'Suspense should clear to nil before locking the period.'
                : 'No suspense nominal is configured, so this check is advisory only.',
            $this->money($settings, $suspenseSummary['closing_balance'] ?? 0),
            '?page=journal&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
        );
        $sections['categorisation_suspense'][] = $this->makeCheck(
            'auto_categorisations_pending_review',
            'Transaction auto categorisations pending review',
            'warning',
            $autoAttentionCount > 0 ? 'warning' : 'pass',
            $this->autoDecisionReviewDetail($autoDecisionSummary),
            $this->autoDecisionReviewMetric($autoDecisionSummary),
            $this->autoDecisionReviewActionUrl($autoDecisionSummary)
        );

        $sections['ledger_integrity'][] = $this->makeCheck(
            'trial_balance_exists',
            'Trial balance exists',
            'fail',
            !empty($trialBalance['exists']) ? 'pass' : 'fail',
            !empty($trialBalance['exists'])
                ? 'A trial balance can be generated from posted journals in this period.'
                : 'No posted journal data exists to generate a trial balance for this period.',
            (string)($trialBalance['line_count'] ?? 0),
            '?page=journal&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
        );
        $sections['ledger_integrity'][] = $this->makeCheck(
            'trial_balance_balances',
            'Trial balance balances',
            'fail',
            !empty($trialBalance['balances']) ? 'pass' : 'fail',
            !empty($trialBalance['balances'])
                ? 'Total debits equal total credits.'
                : 'Total debits and credits do not match for the selected period.',
            $this->money($settings, $trialBalance['difference'] ?? 0),
            '?page=journal&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
        );
        $journalIntegrityIssues = (int)$journalIntegrity['line_count_failures'] + (int)$journalIntegrity['unbalanced_journals'] + (int)$journalIntegrity['missing_nominal_lines'];
        $unpostedSourceWorkCount = (int)($postedSourceWork['total_unposted'] ?? 0);
        $sections['ledger_integrity'][] = $this->makeCheck(
            'journal_structural_integrity',
            'Journal structural integrity',
            'fail',
            $journalIntegrityIssues > 0 ? 'fail' : 'pass',
            $journalIntegrityIssues > 0
                ? 'Some journals have structural issues that must be resolved before year end is locked.'
                : 'Journal structures look valid for this accounting period.',
            (string)$journalIntegrityIssues,
            '?page=journal&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
        );
        $sections['ledger_integrity'][] = $this->makeCheck(
            'posted_only_period_integrity',
            'Posted-only period integrity',
            'fail',
            $unpostedSourceWorkCount > 0 ? 'fail' : 'pass',
            $this->postedSourceWorkDetail($postedSourceWork),
            $this->postedSourceWorkMetric($postedSourceWork),
            $this->postedSourceWorkActionUrl($postedSourceWork)
        );

        $continuityWarningCount = (int)$statementContinuity['continuity_warnings'] + (int)$statementContinuity['ledger_warnings'];
        $sections['bank_source_completeness'][] = $this->makeCheck(
            'statement_continuity',
            'Statement continuity',
            'warning',
            $continuityWarningCount > 0 ? 'warning' : 'pass',
            $continuityWarningCount > 0
                ? 'At least one bank account has running-balance or continuity breaks.'
                : 'Statement continuity checks passed where statement balance data exists.',
            (string)$continuityWarningCount,
            '?page=source_accounts&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
        );
        $sections['bank_source_completeness'][] = $this->makeCheck(
            'duplicate_import_audit',
            'Duplicate import audit',
            'warning',
            ((int)$duplicateAudit['duplicate_rows'] > 0 || (int)$duplicateAudit['duplicate_files'] > 0) ? 'warning' : 'pass',
            'Duplicate files blocked and duplicate rows skipped are informational checks for import quality.',
            (int)$duplicateAudit['duplicate_files'] . ' file(s), ' . (int)$duplicateAudit['duplicate_rows'] . ' row(s)',
            '?page=uploads&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
        );
        $sections['bank_source_completeness'][] = $this->makeCheck(
            'source_to_ledger_completeness',
            'Source-to-ledger completeness',
            'fail',
            $strandedRows > 0 ? 'fail' : 'pass',
            $strandedRows > 0
                ? 'Some committed source rows are missing their downstream transaction or journal output.'
                : 'Committed source rows can be traced into the current ledger model.',
            (string)$strandedRows,
            '?page=uploads&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
        );

        $dlaClosing = (float)($directorLoan['closing_balance'] ?? 0);
        $directorLoanClosingAcknowledged = trim((string)($review['director_loan_closing_acknowledged_at'] ?? '')) !== '';
        $sections['director_loan_expenses'][] = $this->makeCheck(
            'director_loan_closing_balance',
            'Director loan closing balance',
            'warning',
            empty($directorLoan['available']) ? 'not_applicable' : (abs($dlaClosing) >= 0.005 && !$directorLoanClosingAcknowledged ? 'warning' : 'pass'),
            empty($directorLoan['available'])
                ? (string)($directorLoan['error'] ?? 'Director loan summary unavailable.')
                : ($directorLoanClosingAcknowledged
                    ? 'Director loan closing balance has been acknowledged for this period.'
                    : 'Review whether the period-end director loan balance is expected before filing.'),
            empty($directorLoan['available']) ? '' : $this->money($settings, $dlaClosing),
            '?page=year_end&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId . '&show_card=year_end_director_loan_offset'
        );
        $directorLoanTaxReviewRequired = !empty($directorLoanTaxReview['available']) && !empty($directorLoanTaxReview['review_required']);
        $directorLoanTaxReviewAcknowledged = isset($reviewAcknowledgements['director_loan_tax_review']);
        $directorLoanTaxReviewCleared = !$directorLoanTaxReviewRequired || $directorLoanTaxReviewAcknowledged;
        $sections['director_loan_expenses'][] = $this->applyReviewAcknowledgement($this->makeCheck(
            'director_loan_tax_review',
            'Director loan tax review',
            'warning',
            empty($directorLoanTaxReview['available']) ? 'not_applicable' : ($directorLoanTaxReviewRequired ? 'warning' : 'pass'),
            empty($directorLoanTaxReview['available'])
                ? (string)(($directorLoanTaxReview['errors'] ?? [])[0] ?? 'Director loan tax review is not available.')
                : ($directorLoanTaxReviewRequired
                    ? 'Director owes the company at period end. Review s455, repayment timing, beneficial loan interest/BIK, write-off, and CT600 supplementary treatment before locking.'
                    : 'No director receivable tax review flags are currently raised for this period.'),
            empty($directorLoanTaxReview['available'])
                ? ''
                : ($directorLoanTaxReviewRequired ? $this->money($settings, $directorLoanTaxReview['exposure_amount'] ?? 0) : 'No exposure flagged'),
            '?page=year_end&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId . '&show_card=year_end_director_loan_offset'
        ), $reviewAcknowledgements);
        $expensePositionAcknowledged = trim((string)($review['expense_position_acknowledged_at'] ?? '')) !== '';
        $expensePositionBalance = (float)((($expensePosition['totals'] ?? [])['carried_forward'] ?? 0));
        $sections['director_loan_expenses'][] = $this->makeCheck(
            'expense_position_acknowledgement',
            'Expense position acknowledgement',
            'warning',
            empty($expensePosition['available']) ? 'not_applicable' : ($expensePositionAcknowledged ? 'pass' : 'warning'),
            empty($expensePosition['available'])
                ? (string)(($expensePosition['errors'] ?? [])[0] ?? 'Expense claim register is not available yet.')
                : ($expensePositionAcknowledged
                    ? 'Expense claim position has been acknowledged for this period.'
                    : 'Review the expense claim balance brought forward, claims, payments, and carried-forward position before closing this accounting period.'),
            empty($expensePosition['available'])
                ? ''
                : $this->expensePositionMetric($settings, $expensePositionBalance),
            '?page=year_end&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId . '&show_card=year_end_expenses_confirmation'
        );
        $sections['director_loan_expenses'][] = $this->makeCheck(
            'duplicate_repayment_protection',
            'Duplicate repayment protection',
            'warning',
            !empty($duplicateRepayments['available']) && (int)$duplicateRepayments['risk_count'] > 0 ? 'warning' : (!empty($duplicateRepayments['available']) ? 'pass' : 'not_applicable'),
            !empty($duplicateRepayments['available'])
                ? 'Potentially duplicated repayment recognition should be checked where the same bank transaction is linked more than once.'
                : 'Expense repayment links are not available yet.',
            !empty($duplicateRepayments['available']) ? (string)$duplicateRepayments['risk_count'] : '',
            '?page=expense_claims&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
        );

        $profitBeforeTax = (float)($financialStatements['profit_and_loss']['profit_before_tax'] ?? 0);
        $sections['year_end_accounts_review'][] = $this->makeCheck(
            'profit_and_loss_generated',
            'Profit and loss generated',
            'fail',
            !empty($trialBalance['exists']) ? 'pass' : 'fail',
            !empty($trialBalance['exists'])
                ? 'The app can derive a period P&L from posted journals.'
                : 'The P&L cannot be generated because no posted journal data exists.',
            $this->money($settings, $profitBeforeTax),
            '?page=journal&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
        );
        $sections['year_end_accounts_review'][] = $this->makeCheck(
            'balance_sheet_generated',
            'Balance sheet generated',
            'fail',
            !empty($financialStatements['balance_sheet']['generated']) ? 'pass' : 'fail',
            !empty($financialStatements['balance_sheet']['generated'])
                ? 'The app can derive a balance sheet snapshot from posted journals.'
                : 'The balance sheet cannot be generated because no posted journals exist.',
            '',
            '?page=companies_house&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId . '#companies-house-comparison'
        );
        $equityMovement = abs((float)($financialStatements['retained_earnings']['unexplained_movement'] ?? 0));
        $retainedEarningsCloseCurrent = !empty($retainedEarningsClose['available'])
            && !empty($retainedEarningsClose['acknowledged'])
            && empty($retainedEarningsClose['acknowledgement_stale']);
        $retainedEarningsMovementCheck = $this->makeCheck(
            'retained_earnings_movement',
            'Retained earnings movement',
            'warning',
            $equityMovement > 0.99 && !$retainedEarningsCloseCurrent ? 'warning' : 'pass',
            $equityMovement > 0.99
                ? 'Current profit/loss has not yet been carried into retained earnings for this period.'
                : 'Opening equity, profit, and closing equity look internally consistent.',
            $this->money($settings, $financialStatements['retained_earnings']['unexplained_movement'] ?? 0),
            '?page=year_end&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId . '&show_card=year_end_retained_earnings'
        );
        $retainedEarningsMovementCheck['formula_text'] = $this->balanceEquationText(
            $settings,
            (array)((($retainedEarningsClose['summary'] ?? []) ?: []))
        );
        $sections['year_end_accounts_review'][] = $retainedEarningsMovementCheck;
        $retainedEarningsCloseAvailable = !empty($retainedEarningsClose['available']);
        $retainedEarningsCloseStatus = !$retainedEarningsCloseAvailable
            ? 'fail'
            : ($retainedEarningsCloseCurrent ? 'pass' : 'fail');
        $sections['year_end_accounts_review'][] = $this->makeCheck(
            'retained_earnings_close_confirmation',
            'Retained earnings close confirmation',
            'fail',
            $retainedEarningsCloseStatus,
            !$retainedEarningsCloseAvailable
                ? (string)(($retainedEarningsClose['errors'] ?? [])[0] ?? 'Retained earnings close preview is not available.')
                : ($retainedEarningsCloseCurrent
                    ? 'Retained earnings close has been reviewed and agreed for the current figures.'
                    : (!empty($retainedEarningsClose['acknowledgement_stale'])
                        ? 'Retained earnings figures have changed since they were agreed. Review and agree them again before locking.'
                        : 'Review and agree how current profit/loss will be carried into retained earnings before locking.')),
            !$retainedEarningsCloseAvailable
                ? ''
                : (!empty($retainedEarningsClose['acknowledgement_stale']) ? 'Figures changed' : ($retainedEarningsCloseCurrent ? 'Agreed' : 'Pending')),
            '?page=year_end&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId . '&show_card=year_end_retained_earnings'
        );
        $incorporationShareStatus = (string)($incorporationShares['status'] ?? '');
        $sections['year_end_accounts_review'][] = $this->makeCheck(
            'incorporation_share_payment_review',
            'Incorporation share payment review',
            'warning',
            empty($incorporationShares['available']) ? 'not_applicable' : ($incorporationShareStatus === 'complete' ? 'pass' : 'warning'),
            empty($incorporationShares['available'])
                ? (string)(($incorporationShares['errors'] ?? [])[0] ?? 'Incorporation share capital summary is not available.')
                : match ($incorporationShareStatus) {
                    'shares_not_paid_up' => 'Formation shares include unpaid amounts and should be reviewed before filing.',
                    'payment_unmatched' => 'Formation share capital is recorded, but the incoming payment has not been matched yet.',
                    'missing' => 'Formation share capital has not been recorded yet.',
                    default => 'Formation share capital and payment matching are complete.',
                },
            empty($incorporationShares['available'])
                ? ''
                : $this->money($settings, (($incorporationShares['totals'] ?? [])['paid_up_unpaid_total'] ?? (($incorporationShares['totals'] ?? [])['unpaid_total'] ?? 0))),
            '?page=incorporation&company_id=' . $companyId
        );
        $sections['year_end_accounts_review'][] = $this->applyReviewAcknowledgement($this->makeCheck(
            'fixed_asset_review_placeholder',
            'Fixed asset review',
            'warning',
            $potentialAssetCandidateCount > 0 ? 'warning' : 'pass',
            $potentialAssetCandidateCount > 0
                ? 'Tools & Small Equipment items over the potential asset threshold should be reviewed for fixed asset treatment.'
                : 'No Tools & Small Equipment items are over the potential asset threshold.',
            (string)$potentialAssetCandidateCount,
            '?page=assets&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId . '&show_card=not_an_asset'
        ), $reviewAcknowledgements);
        $vehicleReviewWarnings = (new \eel_accounts\Service\VehicleService())->periodReviewWarnings($companyId, $accountingPeriodId);
        $sections['year_end_accounts_review'][] = $this->makeCheck(
            'vehicle_tax_review',
            'Vehicle tax review',
            'warning',
            $vehicleReviewWarnings === [] ? 'pass' : 'warning',
            $vehicleReviewWarnings === []
                ? 'Motor vehicle assets have no outstanding vehicle tax review warnings for this period.'
                : 'Review vehicle type, CO2 emissions, and car/van nominal classification before relying on capital allowances.',
            $vehicleReviewWarnings === [] ? '' : (count($vehicleReviewWarnings) . ' warning' . (count($vehicleReviewWarnings) === 1 ? '' : 's')),
            '?page=vehicles&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
        );
        $sections['year_end_accounts_review'][] = $this->applyReviewAcknowledgement($this->makeCheck(
            'prepayments_accruals_placeholder',
            'Prepayments and accruals review',
            'warning',
            'warning',
            'Manual review reminder: consider year-end accruals, prepayments, and other cut-off journals before filing.',
            '',
            '?page=journal&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId . '&show_card=nominal_closing_balances'
        ), $reviewAcknowledgements);

        $sections['corporation_tax_readiness'][] = $this->makeCheck(
            'tax_adjusted_profit_basis_available',
            'Tax-adjusted profit basis available',
            'warning',
            !empty($taxReadiness['available']) && ((int)($taxReadiness['unknown_treatment_count'] ?? 0) > 0 || (int)($taxReadiness['other_treatment_count'] ?? 0) > 0) ? 'warning' : (!empty($taxReadiness['available']) ? 'pass' : 'fail'),
            !empty($taxReadiness['available'])
                ? 'Nominal tax treatments are being used to estimate the tax-adjusted result.'
                : 'Tax readiness could not be calculated for this period.',
            !empty($taxReadiness['available']) ? $this->money($settings, $taxReadiness['taxable_profit'] ?? 0) : '',
            '?page=nominals&company_id=' . $companyId
        );
        $sections['corporation_tax_readiness'][] = $this->makeCheck(
            'corporation_tax_estimate_generated',
            'Corporation tax estimate generated',
            'info',
            !empty($taxReadiness['available']) ? 'pass' : 'fail',
            !empty($taxReadiness['available'])
                ? 'Estimated taxable profit/loss and corporation tax have been generated for review. This is not final filing-grade tax computation.'
                : 'No tax estimate could be generated for this period.',
            !empty($taxReadiness['available'])
                ? ('Tax ' . $this->money($settings, $taxReadiness['estimated_corporation_tax'] ?? 0))
                : '',
            '?page=tax&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
        );
        $taxConfidenceStatus = (string)($taxReadiness['confidence_status'] ?? 'review_required');
        $taxWarningCount = count((array)($taxReadiness['warnings'] ?? []));
        $sections['corporation_tax_readiness'][] = $this->makeCheck(
            'corporation_tax_estimate_confidence',
            'Corporation tax estimate confidence',
            'warning',
            empty($taxReadiness['available']) ? 'not_applicable' : ($taxConfidenceStatus === 'ready_for_review' ? 'pass' : 'warning'),
            empty($taxReadiness['available'])
                ? 'Tax readiness must be available before estimate confidence can be assessed.'
                : ($taxConfidenceStatus === 'ready_for_review'
                    ? 'No scope warnings are currently attached to the corporation tax estimate.'
                    : 'Review the estimate warnings before relying on the corporation tax number.'),
            empty($taxReadiness['available']) ? '' : ($taxWarningCount . ' warning' . ($taxWarningCount === 1 ? '' : 's')),
            '?page=tax&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
        );
        $sections['corporation_tax_readiness'][] = $this->makeCheck(
            'losses_carried_forward',
            'Losses carried forward',
            'info',
            !empty($taxReadiness['available']) ? 'pass' : 'not_applicable',
            'Losses brought forward, used, and carried forward are shown on a simple basis ready for later CT engine refinement.',
            !empty($taxReadiness['available']) ? $this->money($settings, $taxReadiness['losses_carried_forward'] ?? 0) : '',
            '?page=tax&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
        );
        $taxReadinessAcknowledged = trim((string)($review['tax_readiness_acknowledged_at'] ?? '')) !== '';
        $sections['corporation_tax_readiness'][] = $this->makeCheck(
            'tax_readiness_acknowledgement',
            'Tax readiness acknowledgement',
            'warning',
            empty($taxReadiness['available']) ? 'not_applicable' : ($taxReadinessAcknowledged ? 'pass' : 'warning'),
            empty($taxReadiness['available'])
                ? 'Tax readiness must be available before this review can be acknowledged.'
                : ($taxReadinessAcknowledged
                    ? 'Tax readiness has been acknowledged for this period.'
                    : 'Review the corporation tax workings before closing this accounting period.'),
            empty($taxReadiness['available']) ? '' : ($taxReadinessAcknowledged ? 'Acknowledged' : 'Pending'),
            '?page=year_end&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId . '&show_card=year_end_tax_readiness#tax-readiness'
        );
        $sections['corporation_tax_readiness'][] = $this->applyReviewAcknowledgement($this->makeCheck(
            'filing_basis_reminder',
            'Filing basis reminder',
            'warning',
            'warning',
            'App numbers remain working figures until final adjustments and filing outputs are finalised.',
            '',
            '?page=year_end&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId . '&show_card=year_end_tax_readiness'
        ), $reviewAcknowledgements);

        $comparisonFailures = 0;
        if (!empty($chComparison['available'])) {
            foreach ((array)($chComparison['rows'] ?? []) as $row) {
                if (($row['status'] ?? '') === 'fail') {
                    $comparisonFailures++;
                }
            }
        }
        $sections['companies_house_comparison'][] = $this->makeCheck(
            'latest_filed_accounts_found',
            'Latest filed accounts found',
            'warning',
            !empty($chComparison['available']) ? 'pass' : 'warning',
            !empty($chComparison['available'])
                ? 'A stored Companies House accounts filing is available for comparison.'
                : (string)($chComparison['errors'][0] ?? 'No Companies House filing available.'),
            !empty($chComparison['available']) ? (string)($chComparison['filing']['filing_date'] ?? '') : '',
            '?page=companies&company_id=' . $companyId
        );
        $sections['companies_house_comparison'][] = $this->makeCheck(
            'period_match_or_nearest_comparison',
            'Period match / nearest comparison',
            'warning',
            !empty($chComparison['available']) && ($chComparison['comparison_scope'] ?? '') === 'exact_match' ? 'pass' : (!empty($chComparison['available']) ? 'warning' : 'not_applicable'),
            !empty($chComparison['available'])
                ? (string)($chComparison['comparison_note'] ?? '')
                : 'No Companies House comparison is available yet.',
            '',
            '?page=companies_house&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId . '#companies-house-comparison'
        );
        $sections['companies_house_comparison'][] = $this->makeCheck(
            'accounts_comparison_metrics',
            'Accounts comparison metrics',
            'warning',
            !empty($chComparison['available']) && $comparisonFailures > 0 ? 'fail' : (!empty($chComparison['available']) ? 'pass' : 'not_applicable'),
            !empty($chComparison['available'])
                ? 'Compare app-computed balance sheet values against the stored filed accounts.'
                : 'No comparison metrics are available.',
            !empty($chComparison['available']) ? (string)$comparisonFailures : '',
            '?page=companies_house&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId . '#companies-house-comparison'
        );

        $blockingChecksPass = $uncategorisedCount === 0
            && abs((float)$suspenseSummary['closing_balance']) < 0.005
            && !empty($trialBalance['balances'])
            && !empty($trialBalance['exists'])
            && $journalIntegrityIssues === 0
            && $unpostedSourceWorkCount === 0
            && $retainedEarningsCloseCurrent
            && $directorLoanTaxReviewCleared;
        $sections['final_review_lock'][] = $this->makeCheck(
            'lock_readiness_checklist',
            'Lock readiness checklist',
            'fail',
            $blockingChecksPass ? 'pass' : 'fail',
            $blockingChecksPass
                ? 'All blocking year-end checks currently pass.'
                : 'One or more blocking checks still fail, so this period cannot be locked yet.',
            $blockingChecksPass ? 'Ready to lock' : 'Not ready',
            '?page=year_end&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId . '&show_card=year_end_state'
        );
        $sections['final_review_lock'][] = $this->makeCheck(
            'year_end_notes',
            'Year end notes',
            'info',
            trim((string)($review['review_notes'] ?? '')) !== '' ? 'pass' : 'warning',
            trim((string)($review['review_notes'] ?? '')) !== ''
                ? 'Review notes are stored for this period.'
                : 'No year-end notes have been saved for this period yet.',
            trim((string)($review['review_notes'] ?? '')) !== '' ? 'Saved' : 'Blank',
            '?page=year_end&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId . '&show_card=year_end_notes'
        );

        foreach ($sections as $sectionChecks) {
            foreach ($sectionChecks as $check) {
                $checks[] = $check;
            }
        }

        $overallStatus = $this->determineOverallStatus($checks, $hasSourceData, !empty($review['is_locked']));
        if ($persist) {
            $lock->saveRecalculationSnapshot($companyId, $accountingPeriodId, $overallStatus, $checks);
        }

        return [
            'company_id' => $companyId,
            'accounting_period' => $accountingPeriod,
            'overall_status' => $overallStatus,
            'last_recalculated_at' => $persist
                ? (string)(($lock->fetchReview($companyId, $accountingPeriodId)['last_recalculated_at'] ?? '') ?: '')
                : (string)($review['last_recalculated_at'] ?? ''),
            'review' => $lock->fetchReview($companyId, $accountingPeriodId),
            'review_acknowledgements' => $reviewAcknowledgements,
            'month_tiles' => $monthTiles,
            'auto_decision_summary' => $autoDecisionSummary,
            'sections' => $sections,
            'checks_flat' => $checks,
            'expense_position' => $expensePosition,
            'tax_readiness' => $taxReadiness,
            'companies_house_comparison' => $chComparison,
            'retained_earnings_close' => $retainedEarningsClose,
        ];
    }

    private function autoDecisionReviewDetail(array $summary): string
    {
        $unreviewed = (int)($summary['unreviewed_count'] ?? 0);
        $postConfirmationPending = (int)($summary['post_confirmation_pending_count'] ?? 0);

        if ($unreviewed + $postConfirmationPending <= 0) {
            return 'All transaction auto decisions have been reviewed and post-confirmed.';
        }

        return 'Auto-categorised transactions need attention before final accounts work: '
            . $unreviewed . ' unreviewed row decision(s), '
            . $postConfirmationPending . ' checked decision(s) awaiting post confirmation.';
    }

    private function autoDecisionReviewMetric(array $summary): string
    {
        $unreviewed = (int)($summary['unreviewed_count'] ?? 0);
        $postConfirmationPending = (int)($summary['post_confirmation_pending_count'] ?? 0);

        if ($unreviewed + $postConfirmationPending <= 0) {
            return 'All reviewed';
        }

        return $unreviewed . ' unreviewed, ' . $postConfirmationPending . ' not post-confirmed';
    }

    private function autoDecisionReviewActionUrl(array $summary): string
    {
        $filter = (int)($summary['unreviewed_count'] ?? 0) > 0 ? 'pending' : 'post_pending';

        return '?page=transactions&show_card=transaction_search&transaction_search_category_status=auto&transaction_search_auto_approval_filter=' . $filter;
    }

    private function expensePositionMetric(array $settings, float $carriedForward): string
    {
        $carriedForward = round($carriedForward, 2);
        $amount = $this->money($settings, $carriedForward);

        if ($carriedForward > 0.004) {
            return 'UNPAID ' . $amount;
        }

        if ($carriedForward < -0.004) {
            return 'OWED ' . $amount;
        }

        return $amount;
    }

    private function money(array $settings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($settings, $value);
    }

    private function balanceEquationText(array $settings, array $summary): string
    {
        if ($summary === []) {
            return '';
        }

        return 'Assets (' . $this->money($settings, $summary['assets'] ?? 0) . ') - Liabilities ('
            . $this->money($settings, $summary['liabilities'] ?? 0) . ') = Equity ('
            . $this->money($settings, $summary['equity'] ?? 0) . ')';
    }

    private function postedSourceWorkDetail(array $postedSourceWork): string
    {
        if ((int)($postedSourceWork['total_unposted'] ?? 0) <= 0) {
            return 'All postable transactions, expense claims, and fixed assets have posted journals for this period.';
        }

        return 'Post or confirm the remaining source records before locking this period: '
            . $this->postedSourceWorkBreakdown($postedSourceWork) . '.';
    }

    private function postedSourceWorkMetric(array $postedSourceWork): string
    {
        if ((int)($postedSourceWork['total_unposted'] ?? 0) <= 0) {
            return 'All posted';
        }

        return $this->postedSourceWorkBreakdown($postedSourceWork);
    }

    private function postedSourceWorkBreakdown(array $postedSourceWork): string
    {
        $parts = [
            (int)($postedSourceWork['unposted_transactions'] ?? 0) . ' transaction(s)',
            (int)($postedSourceWork['unposted_expense_claims'] ?? 0) . ' expense claim(s)',
            (int)($postedSourceWork['unposted_assets'] ?? 0) . ' asset(s)',
        ];

        return implode(', ', $parts);
    }

    private function postedSourceWorkActionUrl(array $postedSourceWork): string
    {
        if ((int)($postedSourceWork['unposted_transactions'] ?? 0) > 0) {
            return '?page=transactions&show_card=transactions_imported&category_filter=not_posted';
        }

        if ((int)($postedSourceWork['unposted_expense_claims'] ?? 0) > 0) {
            return '?page=expense_claims';
        }

        if ((int)($postedSourceWork['unposted_assets'] ?? 0) > 0) {
            return '?page=assets';
        }

        return '?page=year_end&show_card=year_end_state';
    }

    private function makeCheck(string $code, string $title, string $severity, string $status, string $detail, string $metricValue = '', ?string $actionUrl = null): array {
        return [
            'check_code' => $code,
            'title' => $title,
            'severity' => $severity,
            'status' => $status,
            'detail_text' => $detail,
            'metric_value' => $metricValue,
            'action_url' => $this->workflowActionUrl($actionUrl),
        ];
    }

    private function workflowActionUrl(?string $actionUrl): ?string
    {
        if ($actionUrl === null) {
            return null;
        }

        $actionUrl = trim($actionUrl);
        if ($actionUrl === '') {
            return '';
        }

        $fragment = '';
        $hashPosition = strpos($actionUrl, '#');
        if ($hashPosition !== false) {
            $fragment = substr($actionUrl, $hashPosition);
            $actionUrl = substr($actionUrl, 0, $hashPosition);
        }

        $query = str_starts_with($actionUrl, '?') ? substr($actionUrl, 1) : $actionUrl;
        parse_str($query, $params);
        unset($params['company_id'], $params['accounting_period_id']);

        $rebuiltQuery = http_build_query($params);
        return ($rebuiltQuery !== '' ? '?' . $rebuiltQuery : '') . $fragment;
    }

    private function applyReviewAcknowledgement(array $check, array $acknowledgements): array
    {
        $checkCode = (string)($check['check_code'] ?? '');
        if (!in_array($checkCode, self::REVIEW_ACKNOWLEDGEABLE_CHECKS, true)) {
            return $check;
        }

        $check['review_clearable'] = true;
        $acknowledgement = $acknowledgements[$checkCode] ?? null;
        if (!is_array($acknowledgement)) {
            return $check;
        }

        $check['review_acknowledgement'] = $acknowledgement;
        if ((string)($check['status'] ?? '') === 'warning') {
            $check['status'] = 'pass';
            $check['metric_value'] = trim((string)($check['metric_value'] ?? '')) !== ''
                ? (string)$check['metric_value']
                : 'Reviewed';
            $check['detail_text'] = 'Review acknowledged for this period. ' . (string)($check['detail_text'] ?? '');
        }

        return $check;
    }

    private function findChecklistCheck(array $checklist, string $checkCode): ?array
    {
        foreach ((array)($checklist['checks_flat'] ?? []) as $check) {
            if (is_array($check) && (string)($check['check_code'] ?? '') === $checkCode) {
                return $check;
            }
        }

        return null;
    }

    private function fetchReviewAcknowledgements(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || !$this->tableExists('year_end_review_acknowledgements')) {
            return [];
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT check_code,
                    acknowledged_at,
                    acknowledged_by,
                    note
             FROM year_end_review_acknowledgements
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        );

        $acknowledgements = [];
        foreach ((array)$rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $checkCode = (string)($row['check_code'] ?? '');
            if ($checkCode !== '') {
                $acknowledgements[$checkCode] = $row;
            }
        }

        return $acknowledgements;
    }

    private function actorValue(string $value): string
    {
        $value = trim($value);
        return $value !== '' ? $value : 'web_app';
    }

    private function fetchChecklistResult(int $companyId, int $accountingPeriodId, bool $persist = true): array {
        $checklist = $this->fetchChecklist($companyId, $accountingPeriodId, $persist);
        if ($checklist === null) {
            return [
                'success' => false,
                'status' => 404,
                'errors' => ['The selected accounting period could not be found.'],
            ];
        }

        return [
            'success' => true,
            'checklist' => $checklist,
        ];
    }

    private function determineOverallStatus(array $checks, bool $hasSourceData, bool $isLocked): string {
        if ($isLocked) {
            return 'locked';
        }

        if (!$hasSourceData) {
            return 'not_started';
        }

        $hasFail = false;
        $hasWarning = false;
        foreach ($checks as $check) {
            if (($check['status'] ?? '') === 'fail') {
                $hasFail = true;
                break;
            }
            if (($check['status'] ?? '') === 'warning') {
                $hasWarning = true;
            }
        }

        if ($hasFail) {
            return 'needs_attention';
        }

        if ($hasWarning) {
            return 'in_progress';
        }

        return 'ready_for_review';
    }

    private function tableExists(string $table): bool {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        try {
            $cache[$table] = \InterfaceDB::tableExists($table);
        } catch (\Throwable) {
            $cache[$table] = false;
        }

        return $cache[$table];
    }
}
