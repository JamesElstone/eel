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
        'cut_off_journals_review',
        'prepayment_approvals',
        'director_loan_tax_review',
        'companies_house_mismatch_acknowledgement',
    ];

    private const ACKNOWLEDGEMENT_CHECKS = [
        'director_loan_closing_balance',
        'tax_readiness_acknowledgement',
        'expense_position_acknowledgement',
        'retained_earnings_close_confirmation',
        'transaction_tail_review',
        ...self::REVIEW_ACKNOWLEDGEABLE_CHECKS,
    ];

    public function __construct(
        private readonly ?\eel_accounts\Service\YearEndMetricsService $metricsService = null,
        private readonly ?\eel_accounts\Service\YearEndTaxReadinessService $taxReadinessService = null,
        private readonly ?\eel_accounts\Service\YearEndCompaniesHouseComparisonService $companiesHouseComparisonService = null,
        private readonly ?\eel_accounts\Service\YearEndLockService $lockService = null,
        private readonly ?\eel_accounts\Service\AssetService $assetService = null,
        private readonly ?\eel_accounts\Service\RetainedEarningsCloseService $retainedEarningsCloseService = null,
        private readonly ?\eel_accounts\Service\CorporationTaxProvisionService $corporationTaxProvisionService = null,
        private readonly ?\eel_accounts\Service\YearEndAcknowledgementService $acknowledgementService = null,
        private readonly ?\eel_accounts\Contract\DatabaseBackupCreatorInterface $backupCreator = null,
    ) {
    }

    public function fetchDashboardSummary(int $companyId, ?int $accountingPeriodId = null): array {
        $metrics = $this->metricsService ?? new \eel_accounts\Service\YearEndMetricsService();
        $resolvedAccountingPeriodId = $accountingPeriodId !== null && $accountingPeriodId > 0
            ? $accountingPeriodId
            : $metrics->resolveLatestOpenAccountingPeriodId($companyId);

        $checklist = $resolvedAccountingPeriodId > 0
            ? $this->fetchChecklist($companyId, $resolvedAccountingPeriodId)
            : null;

        if (!is_array($checklist)) {
            return [
                'available' => false,
                'status' => 'not_started',
                'period_label' => 'No accounting period selected',
                'top_issues' => [],
                'action_url' => '?page=year_end',
            ];
        }

        $accountingPeriod = (array)($checklist['accounting_period'] ?? []);

        return [
            'available' => true,
            'status' => (string)($checklist['overall_status'] ?? 'not_started'),
            'period_label' => (string)($accountingPeriod['label'] ?? ''),
            'accounting_period_id' => (int)($accountingPeriod['id'] ?? $resolvedAccountingPeriodId),
            'top_issues' => $this->topIssuesFromChecks((array)($checklist['checks_flat'] ?? [])),
            'action_url' => $this->dashboardActionUrl($companyId, $resolvedAccountingPeriodId),
        ];
    }

    private function dashboardActionUrl(int $companyId, int $accountingPeriodId): string
    {
        return '?page=year_end&show_card=year_end_checklist';
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

    public function lockPeriod(int $companyId, int $accountingPeriodId, string $lockedBy = 'web_app', bool $backupPermitted = false): array {
        $transaction = $this->beginLockTransaction();

        try {
            $checklistResult = $this->fetchChecklistResult($companyId, $accountingPeriodId);
            if (empty($checklistResult['success'])) {
                return $this->rollbackLockTransaction($transaction, $checklistResult);
            }

            $checklist = (array)$checklistResult['checklist'];
            $preflightResult = $this->preflightLockPeriod($companyId, $accountingPeriodId, $checklist);
            if (empty($preflightResult['success'])) {
                return $this->rollbackLockTransaction($transaction, $preflightResult);
            }

            if (!$backupPermitted) {
                return $this->rollbackLockTransaction($transaction, [
                    'success' => false,
                    'status' => 403,
                    'errors' => ['Permission to create the automatic pre-close database backup was not verified.'],
                    'checklist' => $checklist,
                ]);
            }

            try {
                $backup = ($this->backupCreator ?? new \eel_accounts\Service\DatabaseBackupService())->createBackup();
                if (trim((string)($backup['filename'] ?? '')) === ''
                    || (int)($backup['size_bytes'] ?? 0) <= 0
                    || (int)($backup['table_count'] ?? 0) <= 0) {
                    throw new \RuntimeException('The backup service did not return a verified, non-empty full database backup.');
                }
            } catch (\Throwable $exception) {
                return $this->rollbackLockTransaction($transaction, [
                    'success' => false,
                    'status' => 500,
                    'errors' => ['The automatic pre-close database backup failed: ' . $exception->getMessage()],
                    'checklist' => $checklist,
                ]);
            }

            $directorLoanOffsetResult = $this->applyDirectorLoanOffsetBeforeLock($companyId, $accountingPeriodId, $checklist, $lockedBy);
            if (empty($directorLoanOffsetResult['success'])) {
                return $this->rollbackLockTransaction($transaction, [
                    'success' => false,
                    'status' => (int)($directorLoanOffsetResult['status'] ?? 422),
                    'errors' => (array)($directorLoanOffsetResult['errors'] ?? ['Director loan offset could not be applied before locking this period.']),
                    'checklist' => $checklist,
                    'director_loan_offset' => $directorLoanOffsetResult,
                ]);
            }

            $depreciationResult = ($this->assetService ?? new \eel_accounts\Service\AssetService())->runDepreciation($companyId, $accountingPeriodId);
            if (empty($depreciationResult['success'])) {
                return $this->rollbackLockTransaction($transaction, [
                    'success' => false,
                    'status' => (int)($depreciationResult['status'] ?? 422),
                    'errors' => (array)($depreciationResult['errors'] ?? ['Depreciation could not be posted before locking this period.']),
                    'checklist' => $checklist,
                    'director_loan_offset' => $directorLoanOffsetResult,
                    'depreciation' => $depreciationResult,
                ]);
            }

            $ctProvisionResult = ($this->corporationTaxProvisionService ?? new \eel_accounts\Service\CorporationTaxProvisionService())
                ->postProvisionsForAccountingPeriod($companyId, $accountingPeriodId, $lockedBy);
            if (empty($ctProvisionResult['success'])) {
                return $this->rollbackLockTransaction($transaction, [
                    'success' => false,
                    'status' => (int)($ctProvisionResult['status'] ?? 422),
                    'errors' => (array)($ctProvisionResult['errors'] ?? ['Corporation Tax provisions could not be posted before locking this period.']),
                    'checklist' => $checklist,
                    'director_loan_offset' => $directorLoanOffsetResult,
                    'depreciation' => $depreciationResult,
                    'corporation_tax_provision' => $ctProvisionResult,
                ]);
            }

            $retainedEarningsCloseResult = ($this->retainedEarningsCloseService ?? new \eel_accounts\Service\RetainedEarningsCloseService())
                ->postClose($companyId, $accountingPeriodId, $lockedBy, true);
            if (empty($retainedEarningsCloseResult['success'])) {
                return $this->rollbackLockTransaction($transaction, [
                    'success' => false,
                    'status' => (int)($retainedEarningsCloseResult['status'] ?? 422),
                    'errors' => (array)($retainedEarningsCloseResult['errors'] ?? ['Retained earnings close could not be posted before locking this period.']),
                    'checklist' => $checklist,
                    'director_loan_offset' => $directorLoanOffsetResult,
                    'depreciation' => $depreciationResult,
                    'corporation_tax_provision' => $ctProvisionResult,
                    'retained_earnings_close' => $retainedEarningsCloseResult,
                ]);
            }

            $taxPersistenceResult = (new \eel_accounts\Service\CorporationTaxComputationService())
                ->persistSummariesForAccountingPeriod($companyId, $accountingPeriodId);
            if (empty($taxPersistenceResult['success'])) {
                return $this->rollbackLockTransaction($transaction, [
                    'success' => false,
                    'status' => 422,
                    'errors' => (array)($taxPersistenceResult['errors'] ?? ['Corporation Tax close evidence could not be recorded before locking this period.']),
                    'checklist' => $checklist,
                    'director_loan_offset' => $directorLoanOffsetResult,
                    'depreciation' => $depreciationResult,
                    'corporation_tax_provision' => $ctProvisionResult,
                    'retained_earnings_close' => $retainedEarningsCloseResult,
                    'corporation_tax' => $taxPersistenceResult,
                ]);
            }

            $lock = $this->lockService ?? new \eel_accounts\Service\YearEndLockService();
            $result = $lock->lockPeriod($companyId, $accountingPeriodId, $lockedBy);
            if (empty($result['success'])) {
                return $this->rollbackLockTransaction($transaction, $result);
            }

            $result += [
                'depreciation' => $depreciationResult,
                'director_loan_offset' => $directorLoanOffsetResult,
                'corporation_tax_provision' => $ctProvisionResult,
                'retained_earnings_close' => $retainedEarningsCloseResult,
                'corporation_tax' => $taxPersistenceResult,
                'backup' => $backup,
                'checklist' => $this->fetchChecklist($companyId, $accountingPeriodId),
            ];

            $this->commitLockTransaction($transaction);

            return $result;
        } catch (\Throwable $exception) {
            return $this->rollbackLockTransaction($transaction, [
                'success' => false,
                'status' => 500,
                'errors' => [$exception->getMessage()],
            ]);
        }
    }

    private function canLockOverallStatus(string $overallStatus): bool
    {
        return $overallStatus === 'ready_for_review';
    }

    private function preflightLockPeriod(int $companyId, int $accountingPeriodId, array $checklist): array
    {
        $overallStatus = (string)($checklist['overall_status'] ?? 'not_started');
        if (!$this->canLockOverallStatus($overallStatus)) {
            return [
                'success' => false,
                'status' => 422,
                'errors' => ['Resolve the year-end checklist warnings and blocking checks before locking this period.'],
                'checklist' => $checklist,
            ];
        }

        $retainedEarningsClose = (array)($checklist['retained_earnings_close'] ?? []);
        if (!empty($retainedEarningsClose['acknowledgement_stale'])) {
            return [
                'success' => false,
                'status' => 422,
                'errors' => ['The retained earnings figures have changed since acknowledgement. Review and agree the retained earnings close again.'],
                'checklist' => $checklist,
                'retained_earnings_close' => $retainedEarningsClose,
            ];
        }

        $depreciationPreview = ($this->assetService ?? new \eel_accounts\Service\AssetService())->previewDepreciationRun($companyId, $accountingPeriodId);
        if (empty($depreciationPreview['success'])) {
            return [
                'success' => false,
                'status' => 422,
                'errors' => (array)($depreciationPreview['errors'] ?? ['Depreciation could not be checked before locking this period.']),
                'checklist' => $checklist,
                'depreciation' => $depreciationPreview,
            ];
        }

        return [
            'success' => true,
            'depreciation' => $depreciationPreview,
        ];
    }

    private function beginLockTransaction(): array
    {
        if (!\InterfaceDB::inTransaction()) {
            \InterfaceDB::beginTransaction();

            return [
                'owns_transaction' => true,
                'savepoint' => '',
            ];
        }

        $savepoint = 'year_end_lock_' . bin2hex(random_bytes(6));
        \InterfaceDB::execute('SAVEPOINT ' . $savepoint);

        return [
            'owns_transaction' => false,
            'savepoint' => $savepoint,
        ];
    }

    private function commitLockTransaction(array $transaction): void
    {
        if (!empty($transaction['owns_transaction'])) {
            \InterfaceDB::commit();
            return;
        }

        $savepoint = trim((string)($transaction['savepoint'] ?? ''));
        if ($savepoint !== '' && \InterfaceDB::inTransaction()) {
            \InterfaceDB::execute('RELEASE SAVEPOINT ' . $savepoint);
        }
    }

    private function rollbackLockTransaction(array $transaction, array $result): array
    {
        if (!empty($transaction['owns_transaction'])) {
            if (\InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }
        } else {
            $savepoint = trim((string)($transaction['savepoint'] ?? ''));
            if ($savepoint !== '' && \InterfaceDB::inTransaction()) {
                \InterfaceDB::execute('ROLLBACK TO SAVEPOINT ' . $savepoint);
                \InterfaceDB::execute('RELEASE SAVEPOINT ' . $savepoint);
            }
        }

        $errors = (array)($result['errors'] ?? []);
        $errors[] = 'No year-end close tasks were committed.';
        $result['success'] = false;
        $result['errors'] = array_values(array_unique(array_map('strval', $errors)));

        return $result;
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

        $closingBalanceCheck = $this->findChecklistCheck($checklist, 'director_loan_closing_balance');
        if (empty($closingBalanceCheck['acknowledgement_current'])) {
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
        ($this->lockService ?? new \eel_accounts\Service\YearEndLockService())->assertUnlocked($companyId, $accountingPeriodId, 'change the year-end notes for this period');
        $checklistResult = $this->fetchChecklistResult($companyId, $accountingPeriodId);
        if (empty($checklistResult['success'])) {
            return $checklistResult;
        }

        $lock = $this->lockService ?? new \eel_accounts\Service\YearEndLockService();
        $result = $lock->saveNotes($companyId, $accountingPeriodId, $notes, $changedBy);
        if (empty($result['success'])) {
            return $result;
        }

        return $result + [
            'checklist' => $this->fetchChecklist($companyId, $accountingPeriodId),
        ];
    }

    public function saveDirectorLoanClosingAcknowledgement(int $companyId, int $accountingPeriodId, bool $acknowledged, string $changedBy = 'web_app', string $note = ''): array {
        return $this->saveAcknowledgement($companyId, $accountingPeriodId, 'director_loan_closing_balance', $acknowledged, $note, $changedBy);
    }

    public function saveTaxReadinessAcknowledgement(int $companyId, int $accountingPeriodId, bool $acknowledged, string $changedBy = 'web_app', string $note = ''): array {
        return $this->saveAcknowledgement($companyId, $accountingPeriodId, 'tax_readiness_acknowledgement', $acknowledged, $note, $changedBy);
    }

    public function saveExpensePositionAcknowledgement(int $companyId, int $accountingPeriodId, bool $acknowledged, string $changedBy = 'web_app', string $note = ''): array {
        return $this->saveAcknowledgement($companyId, $accountingPeriodId, 'expense_position_acknowledgement', $acknowledged, $note, $changedBy);
    }

    public function saveRetainedEarningsCloseAcknowledgement(int $companyId, int $accountingPeriodId, bool $acknowledged, string $changedBy = 'web_app', string $note = ''): array {
        return $this->saveAcknowledgement($companyId, $accountingPeriodId, 'retained_earnings_close_confirmation', $acknowledged, $note, $changedBy);
    }

    public function saveTransactionTailAcknowledgement(int $companyId, int $accountingPeriodId, bool $acknowledged, string $note = '', string $changedBy = 'web_app'): array
    {
        return $this->saveAcknowledgement($companyId, $accountingPeriodId, 'transaction_tail_review', $acknowledged, $note, $changedBy);
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

        if ($acknowledged && $checkCode === 'prepayment_approvals') {
            $prepaymentApprovalGate = $this->prepaymentApprovalGate($companyId, $accountingPeriodId);
            if (empty($prepaymentApprovalGate['success'])) {
                return $prepaymentApprovalGate;
            }
        }

        return $this->saveAcknowledgement($companyId, $accountingPeriodId, $checkCode, $acknowledged, $note, $changedBy);
    }

    public function fetchReviewAcknowledgement(int $companyId, int $accountingPeriodId, string $checkCode): ?array
    {
        $checkCode = trim($checkCode);
        if ($checkCode === '') {
            return null;
        }

        $checklist = $this->fetchChecklist($companyId, $accountingPeriodId);
        $check = is_array($checklist) ? $this->findChecklistCheck($checklist, $checkCode) : null;
        if (!is_array($check)) {
            return null;
        }

        $acknowledgement = $check['review_acknowledgement'] ?? $check['previous_acknowledgement'] ?? null;
        if (!is_array($acknowledgement)) {
            return null;
        }

        $acknowledgement['state'] = (string)($check['acknowledgement_state'] ?? 'absent');
        $acknowledgement['current'] = !empty($check['acknowledgement_current']);
        return $acknowledgement;
    }

    private function saveAcknowledgement(
        int $companyId,
        int $accountingPeriodId,
        string $checkCode,
        bool $acknowledged,
        string $note,
        string $changedBy
    ): array {
        ($this->lockService ?? new \eel_accounts\Service\YearEndLockService())
            ->assertUnlocked($companyId, $accountingPeriodId, 'change the year-end review confirmation for this period');

        if (!in_array($checkCode, self::ACKNOWLEDGEMENT_CHECKS, true)) {
            return ['success' => false, 'errors' => ['This year-end check cannot be acknowledged.']];
        }

        $checklistResult = $this->fetchChecklistResult($companyId, $accountingPeriodId);
        if (empty($checklistResult['success'])) {
            return $checklistResult;
        }

        $currentCheck = $this->findChecklistCheck((array)$checklistResult['checklist'], $checkCode);
        if ($currentCheck === null) {
            return ['success' => false, 'errors' => ['The selected year-end check could not be found.']];
        }
        if ($acknowledged
            && (string)($currentCheck['status'] ?? '') === 'fail'
            && $checkCode !== 'retained_earnings_close_confirmation') {
            return ['success' => false, 'errors' => ['Resolve the blocking year-end check instead of acknowledging it.']];
        }

        $basis = $currentCheck['basis_data'] ?? null;
        if ($acknowledged && !is_array($basis)) {
            return ['success' => false, 'errors' => ['The current live review basis could not be verified. Refresh the related workflow and try again.']];
        }

        $service = $this->acknowledgementService ?? new \eel_accounts\Service\YearEndAcknowledgementService();
        $existing = $service->fetch($companyId, $accountingPeriodId, $checkCode);
        $result = $acknowledged
            ? $service->save($companyId, $accountingPeriodId, $checkCode, $basis, $changedBy, $note)
            : $service->revoke($companyId, $accountingPeriodId, $checkCode);
        if (empty($result['success'])) {
            return $result;
        }

        ($this->lockService ?? new \eel_accounts\Service\YearEndLockService())->writeAuditLog(
            $companyId,
            $accountingPeriodId,
            $acknowledged ? 'review_check_acknowledged' : 'review_check_reopened',
            $changedBy,
            $existing,
            $acknowledged ? (array)($result['acknowledgement'] ?? []) : ['check_code' => $checkCode, 'acknowledged' => false],
            trim($note) !== '' ? trim($note) : null
        );

        return $result + ['checklist' => $this->fetchChecklist($companyId, $accountingPeriodId)];
    }

    public function unlockPeriod(int $companyId, int $accountingPeriodId, string $changedBy = 'web_app', ?string $notes = null): array {
        $checklistResult = $this->fetchChecklistResult($companyId, $accountingPeriodId);
        if (empty($checklistResult['success'])) {
            return $checklistResult;
        }

        $lock = $this->lockService ?? new \eel_accounts\Service\YearEndLockService();
        $result = $lock->unlockPeriod($companyId, $accountingPeriodId, $changedBy, $notes);
        if (empty($result['success'])) {
            return $result;
        }

        return $result + [
            'checklist' => $this->fetchChecklist($companyId, $accountingPeriodId),
        ];
    }

    public function recalculateChecklist(int $companyId, int $accountingPeriodId, string $changedBy = 'web_app'): array {
        ($this->lockService ?? new \eel_accounts\Service\YearEndLockService())->assertUnlocked($companyId, $accountingPeriodId, 'refresh the year-end checklist for this period');
        $checklistResult = $this->fetchChecklistResult($companyId, $accountingPeriodId);
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

    public function fetchChecklist(int $companyId, int $accountingPeriodId): ?array {
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
        $transactionTail = (new \eel_accounts\Service\YearEndTransactionTailService($metrics))->fetchContext($companyId, $accountingPeriodId);
        $prepaymentReview = (new \eel_accounts\Service\PrepaymentReviewService($metrics, $lock))->fetchContext($companyId, $accountingPeriodId);
        $duplicateRepayments = $metrics->duplicateRepaymentRiskSummary($companyId, $periodStart, $periodEnd);
        $financialStatements = $metrics->financialStatementsSummary($companyId, $accountingPeriodId, $periodStart, $periodEnd, $trialBalance);
        $retainedEarningsClose = ($this->retainedEarningsCloseService ?? new \eel_accounts\Service\RetainedEarningsCloseService())
            ->fetchContext($companyId, $accountingPeriodId);
        $incorporationShares = (new \eel_accounts\Service\IncorporationShareCapitalService())->fetchSummary($companyId);
        $potentialAssetThreshold = \eel_accounts\Service\AssetService::normalisePotentialAssetThreshold($settings['potential_asset_threshold'] ?? 250);
        $potentialAssetCandidates = ($this->assetService ?? new \eel_accounts\Service\AssetService())->fetchNonAssetCandidates(
            $companyId,
            $accountingPeriodId,
            (int)($settings['tools_small_equipment_nominal_id'] ?? 0),
            $potentialAssetThreshold
        );
        $potentialAssetCandidateCount = (int)($potentialAssetCandidates['count'] ?? 0);
        $taxReadiness = $tax->fetchAccountingPeriodCtSummary($companyId, $accountingPeriodId);
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
            '?page=companies'
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
            '?page=uploads'
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
            '?page=transactions&show_card=year_end_empty_month_confirmations'
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
            '?page=transactions&category_filter=uncategorised'
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
            '?page=journal'
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
            $this->trialBalanceMetric($trialBalance),
            '?page=journal'
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
            '?page=journal'
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
            '?page=journal'
        );
        array_push($sections['ledger_integrity'], ...$this->postedSourceWorkChecks($postedSourceWork));

        $continuityWarningCount = $this->statementContinuityIssueCount($statementContinuity);
        $sections['bank_source_completeness'][] = $this->makeCheck(
            'statement_continuity',
            'Statement continuity',
            'warning',
            $continuityWarningCount > 0 ? 'warning' : 'pass',
            $continuityWarningCount > 0
                ? $this->statementContinuityDetail($statementContinuity, $settings)
                : 'Statement continuity checks passed where statement balance data exists.',
            $this->statementContinuityMetric($continuityWarningCount),
            '?page=source_accounts'
        );
        $sections['bank_source_completeness'][] = $this->makeCheck(
            'duplicate_import_audit',
            'Duplicate import audit',
            'warning',
            ((int)$duplicateAudit['duplicate_rows'] > 0 || (int)$duplicateAudit['duplicate_files'] > 0) ? 'warning' : 'pass',
            'Duplicate files blocked and duplicate rows skipped are informational checks for import quality.',
            (int)$duplicateAudit['duplicate_files'] . ' file(s), ' . (int)$duplicateAudit['duplicate_rows'] . ' row(s)',
            '?page=uploads'
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
            '?page=uploads'
        );

        $dlaClosing = (float)($directorLoan['closing_balance'] ?? 0);
        $sections['director_loan_expenses'][] = $this->applyReviewAcknowledgement($this->makeCheck(
            'director_loan_closing_balance',
            'Director loan closing balance',
            'warning',
            empty($directorLoan['available']) ? 'not_applicable' : (abs($dlaClosing) >= 0.005 ? 'warning' : 'pass'),
            empty($directorLoan['available'])
                ? (string)($directorLoan['error'] ?? 'Director loan summary unavailable.')
                : 'Review whether the period-end director loan balance is expected before filing.',
            empty($directorLoan['available']) ? '' : $this->money($settings, $dlaClosing),
            '?page=director_loans&show_card=year_end_director_loan_offset',
            empty($directorLoan['available']) ? null : $this->acknowledgementBasis('director_loan_closing_balance', [
                'closing_balance' => number_format($dlaClosing, 2, '.', ''),
            ])
        ), $reviewAcknowledgements);
        $directorLoanTaxReviewRequired = !empty($directorLoanTaxReview['available']) && !empty($directorLoanTaxReview['review_required']);
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
            '?page=director_loans&show_card=year_end_director_loan_offset',
            empty($directorLoanTaxReview['available']) ? null : $this->acknowledgementBasis('director_loan_tax_review', $directorLoanTaxReview)
        ), $reviewAcknowledgements);
        $expensePositionBalance = (float)((($expensePosition['totals'] ?? [])['carried_forward'] ?? 0));
        $sections['director_loan_expenses'][] = $this->applyReviewAcknowledgement($this->makeCheck(
            'expense_position_acknowledgement',
            'Expense position acknowledgement',
            'warning',
            empty($expensePosition['available']) ? 'not_applicable' : 'warning',
            empty($expensePosition['available'])
                ? (string)(($expensePosition['errors'] ?? [])[0] ?? 'Expense claim register is not available yet.')
                : 'Review the expense claim balance brought forward, claims, payments, and carried-forward position before closing this accounting period.',
            empty($expensePosition['available'])
                ? ''
                : $this->expensePositionMetric($settings, $expensePositionBalance),
            '?page=expense_claims&show_card=year_end_expenses_confirmation',
            empty($expensePosition['available']) ? null : $this->acknowledgementBasis('expense_position_acknowledgement', $expensePosition)
        ), $reviewAcknowledgements);
        $sections['director_loan_expenses'][] = $this->makeCheck(
            'duplicate_repayment_protection',
            'Duplicate repayment protection',
            'warning',
            !empty($duplicateRepayments['available']) && (int)$duplicateRepayments['risk_count'] > 0 ? 'warning' : (!empty($duplicateRepayments['available']) ? 'pass' : 'not_applicable'),
            !empty($duplicateRepayments['available'])
                ? 'Potentially duplicated repayment recognition should be checked where the same bank transaction is linked more than once.'
                : 'Expense repayment links are not available yet.',
            !empty($duplicateRepayments['available']) ? (string)$duplicateRepayments['risk_count'] : '',
            '?page=expense_claims'
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
            '?page=journal'
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
            '?page=companies_house#companies-house-comparison'
        );
        $equityMovement = abs((float)($financialStatements['retained_earnings']['unexplained_movement'] ?? 0));
        $retainedEarningsCloseAvailable = !empty($retainedEarningsClose['available']);
        $retainedEarningsConfirmationCheck = $this->applyReviewAcknowledgement($this->makeCheck(
            'retained_earnings_close_confirmation',
            'Retained earnings close confirmation',
            'fail',
            $retainedEarningsCloseAvailable ? 'fail' : 'fail',
            !$retainedEarningsCloseAvailable
                ? (string)(($retainedEarningsClose['errors'] ?? [])[0] ?? 'Retained earnings close preview is not available.')
                : 'Review and agree how current profit/loss will be carried into retained earnings before locking.',
            $retainedEarningsCloseAvailable ? 'Pending' : '',
            '?page=profit_loss&show_card=year_end_retained_earnings',
            !$retainedEarningsCloseAvailable ? null : $this->acknowledgementBasis('retained_earnings_close_confirmation', [
                'summary' => (array)($retainedEarningsClose['summary'] ?? []),
                'journal_lines' => (array)($retainedEarningsClose['journal_lines'] ?? []),
            ])
        ), $reviewAcknowledgements);
        $retainedEarningsCloseCurrent = !empty($retainedEarningsConfirmationCheck['acknowledgement_current']);
        $retainedEarningsMovementCheck = $this->makeCheck(
            'retained_earnings_movement',
            'Retained earnings movement',
            'warning',
            $equityMovement > 0.99 && !$retainedEarningsCloseCurrent ? 'warning' : 'pass',
            $equityMovement > 0.99
                ? 'Current profit/loss has not yet been carried into retained earnings for this period.'
                : 'Opening equity, profit, and closing equity look internally consistent.',
            $this->money($settings, $financialStatements['retained_earnings']['unexplained_movement'] ?? 0),
            '?page=profit_loss&show_card=year_end_retained_earnings'
        );
        $retainedEarningsMovementCheck['formula_text'] = $this->balanceEquationText(
            $settings,
            (array)((($retainedEarningsClose['summary'] ?? []) ?: []))
        );
        $sections['year_end_accounts_review'][] = $retainedEarningsMovementCheck;
        $sections['year_end_accounts_review'][] = $retainedEarningsConfirmationCheck;
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
            '?page=incorporation'
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
            '?page=assets&show_card=not_an_asset',
            $this->acknowledgementBasis('fixed_asset_review_placeholder', [
                'candidate_count' => $potentialAssetCandidateCount,
                'threshold' => number_format($potentialAssetThreshold, 2, '.', ''),
                'tools_nominal_id' => (int)($settings['tools_small_equipment_nominal_id'] ?? 0),
                'candidates' => array_map(static fn(array $candidate): array => [
                    'source' => (string)($candidate['source'] ?? ''),
                    'source_id' => (int)($candidate['source_id'] ?? 0),
                    'date' => (string)($candidate['date'] ?? ''),
                    'amount' => number_format((float)($candidate['amount'] ?? 0), 2, '.', ''),
                    'nominal_account_id' => (int)($candidate['nominal_account_id'] ?? 0),
                ], (array)($potentialAssetCandidates['rows'] ?? [])),
            ])
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
            '?page=vehicles'
        );
        $transactionTailStatus = empty($transactionTail['available'])
            ? 'not_applicable'
            : 'warning';
        $sections['year_end_accounts_review'][] = $this->applyReviewAcknowledgement($this->makeCheck(
            'transaction_tail_review',
            'Bank transaction cut-off review',
            'warning',
            $transactionTailStatus,
            empty($transactionTail['available'])
                ? (string)(($transactionTail['errors'] ?? [])[0] ?? 'Transaction cut-off review is not available.')
                : 'Review the latest transaction line on each company account before closing this accounting period.',
            empty($transactionTail['available'])
                ? ''
                : ((int)($transactionTail['accounts_with_transactions'] ?? 0) . ' of ' . (int)($transactionTail['account_count'] ?? 0)),
            '?page=transactions&show_card=year_end_transaction_tail',
            empty($transactionTail['available']) ? null : $this->acknowledgementBasis('transaction_tail_review', $transactionTail)
        ), $reviewAcknowledgements);

        $prepaymentPendingCount = (int)($prepaymentReview['pending_count'] ?? 0);
        $prepaymentTotalCount = (int)($prepaymentReview['total_count'] ?? 0);
        $sections['year_end_accounts_review'][] = $this->makeCheck(
            'prepayments_review',
            'Prepayments review',
            'warning',
            empty($prepaymentReview['available']) ? 'not_applicable' : ($prepaymentPendingCount > 0 ? 'warning' : 'pass'),
            empty($prepaymentReview['available'])
                ? (string)(($prepaymentReview['errors'] ?? [])[0] ?? 'Prepayment review is not available.')
                : ($prepaymentPendingCount > 0
                    ? 'Review all source items posted to nominals marked as prepayment candidates before filing.'
                    : 'All potential prepayment source items have been reviewed for this accounting period.'),
            empty($prepaymentReview['available'])
                ? ''
                : ((int)($prepaymentReview['reviewed_count'] ?? 0) . ' of ' . $prepaymentTotalCount),
            '?page=prepayments'
        );

        $sections['year_end_accounts_review'][] = $this->applyReviewAcknowledgement($this->makeCheck(
            'prepayment_approvals',
            'Prepayment approvals',
            'warning',
            'warning',
            'Approve the prepayment review before closing this accounting period.',
            'Pending',
            '?page=prepayments&show_card=year_end_prepayment_approvals',
            empty($prepaymentReview['available']) ? null : $this->acknowledgementBasis('prepayment_approvals', $prepaymentReview)
        ), $reviewAcknowledgements);

        $sections['year_end_accounts_review'][] = $this->applyReviewAcknowledgement($this->makeCheck(
            'cut_off_journals_review',
            'Cut-off journals review',
            'warning',
            'warning',
            'Review whether any accruals, deferred income, prepayments, or other year-end cut-off journals are required.',
            'Pending',
            '?page=journal&show_card=journal_cut_off_confirmation',
            $this->acknowledgementBasis('cut_off_journals_review', [
                'trial_balance' => $trialBalance,
                'posted_source_work' => $postedSourceWork,
                'prepayment_review' => $prepaymentReview,
            ])
        ), $reviewAcknowledgements);

        $taxPeriodDisplay = $this->taxPeriodDisplay($taxReadiness);

        $sections['corporation_tax_readiness'][] = $this->makeCheck(
            'tax_adjusted_profit_basis_available',
            'Tax-adjusted profit basis available',
            'warning',
            !empty($taxReadiness['available']) && ((int)($taxReadiness['unknown_treatment_count'] ?? 0) > 0 || (int)($taxReadiness['other_treatment_count'] ?? 0) > 0) ? 'warning' : (!empty($taxReadiness['available']) ? 'pass' : 'fail'),
            !empty($taxReadiness['available'])
                ? 'Nominal tax treatments are being used to estimate the tax-adjusted result across all CT periods in this accounting period.'
                : 'Tax readiness could not be calculated for this period.',
            !empty($taxReadiness['available']) ? $this->money($settings, $taxReadiness['taxable_profit'] ?? 0) : '',
            '?page=nominals'
        );
        $taxEstimateCheck = $this->makeCheck(
            'corporation_tax_estimate_generated',
            'Corporation tax estimate generated',
            'info',
            !empty($taxReadiness['available']) ? 'pass' : 'fail',
            !empty($taxReadiness['available'])
                ? 'Estimated taxable profit/loss and corporation tax have been generated for every CT period in this accounting period. This is not final filing-grade tax computation.'
                : 'No tax estimate could be generated for this period.',
            !empty($taxReadiness['available'])
                ? ('Tax ' . $this->money($settings, $taxReadiness['estimated_corporation_tax'] ?? 0))
                : '',
            '?page=tax'
        );
        if ($taxPeriodDisplay !== '') {
            $taxEstimateCheck['formula_text'] = 'CT periods: ' . $taxPeriodDisplay;
        }
        $sections['corporation_tax_readiness'][] = $taxEstimateCheck;
        $taxProvision = (array)($taxReadiness['provision'] ?? []);
        $taxProvisionStatus = (string)($taxProvision['status'] ?? '');
        $taxProvisionAvailable = !empty($taxReadiness['available']) && !empty($taxProvision['available']);
        $taxProvisionCurrent = $taxProvisionAvailable && in_array($taxProvisionStatus, ['posted', 'not_required'], true);
        $taxProvisionCheckStatus = empty($taxReadiness['available'])
            ? 'not_applicable'
            : ($taxProvisionAvailable ? 'pass' : 'fail');
        $taxProvisionDetail = empty($taxReadiness['available'])
            ? 'A Corporation Tax estimate is needed before the provision can be prepared.'
            : ($taxProvisionAvailable
                ? ($taxProvisionCurrent
                    ? 'The 8500 Corporation Tax Expense / 2200 Corporation Tax provision matches the current CT estimate.'
                    : 'The final Year End close will post or refresh the CT provision before retained earnings are closed.')
                : 'The CT provision position could not be prepared for the final Year End close.');
        $sections['corporation_tax_readiness'][] = $this->makeCheck(
            'corporation_tax_provision_posted',
            'Corporation tax provision close task',
            'fail',
            $taxProvisionCheckStatus,
            $taxProvisionDetail,
            empty($taxReadiness['available'])
                ? ''
                : ('Posted ' . $this->money($settings, $taxProvision['posted_corporation_tax_charge'] ?? 0)
                    . ' / estimate ' . $this->money($settings, $taxProvision['estimated_corporation_tax'] ?? 0)),
            '?page=year_end&show_card=year_end_tax_readiness#tax-readiness'
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
                    ? 'No scope warnings are currently attached to the corporation tax estimates for any CT period.'
                    : 'Review the estimate warnings before relying on the corporation tax numbers.'),
            empty($taxReadiness['available']) ? '' : ($taxWarningCount . ' warning' . ($taxWarningCount === 1 ? '' : 's')),
            '?page=tax'
        );
        $sections['corporation_tax_readiness'][] = $this->makeCheck(
            'losses_carried_forward',
            'Losses carried forward',
            'info',
            !empty($taxReadiness['available']) ? 'pass' : 'not_applicable',
            'Losses brought forward, used, and carried forward are shown across all CT periods in this accounting period.',
            !empty($taxReadiness['available']) ? $this->money($settings, $taxReadiness['losses_carried_forward'] ?? 0) : '',
            '?page=tax'
        );
        $sections['corporation_tax_readiness'][] = $this->applyReviewAcknowledgement($this->makeCheck(
            'tax_readiness_acknowledgement',
            'Tax readiness acknowledgement',
            'warning',
            empty($taxReadiness['available']) ? 'not_applicable' : 'warning',
            empty($taxReadiness['available'])
                ? 'Tax readiness must be available before this review can be acknowledged.'
                : 'Review the corporation tax workings for every CT period before closing this accounting period.',
            empty($taxReadiness['available']) ? '' : 'Pending',
            '?page=year_end&show_card=year_end_tax_readiness#tax-readiness',
            empty($taxReadiness['available']) ? null : $this->acknowledgementBasis('tax_readiness_acknowledgement', $taxReadiness)
        ), $reviewAcknowledgements);
        $sections['corporation_tax_readiness'][] = $this->applyReviewAcknowledgement($this->makeCheck(
            'filing_basis_reminder',
            'Filing basis reminder',
            'info',
            'info',
            'Year-end lock finalises the app ledger. Statutory accounts, iXBRL, and tax filing outputs should still be reviewed separately before submission.',
            '',
            '?page=year_end&show_card=year_end_tax_readiness'
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
            '?page=companies'
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
            '?page=companies_house&show_card=year_end_companies_house_comparison#companies-house-comparison'
        );
        $sections['companies_house_comparison'][] = $this->applyReviewAcknowledgement($this->makeCheck(
            'companies_house_mismatch_acknowledgement',
            'Accounts comparison metrics',
            'warning',
            !empty($chComparison['available']) && $comparisonFailures > 0 ? 'warning' : (!empty($chComparison['available']) ? 'pass' : 'not_applicable'),
            !empty($chComparison['available'])
                ? ($comparisonFailures > 0
                    ? 'Stored Companies House filing values differ from the reviewed app figures. Acknowledge here only when this is a known filing error to be corrected before HMRC submission.'
                    : 'App-computed balance sheet values match the stored filed accounts.')
                : 'No comparison metrics are available.',
            !empty($chComparison['available']) ? (string)$comparisonFailures : '',
            '?page=companies_house&show_card=year_end_companies_house_comparison#companies-house-mismatch-acknowledgement',
            empty($chComparison['available']) ? null : $this->acknowledgementBasis('companies_house_mismatch_acknowledgement', $chComparison)
        ), $reviewAcknowledgements);

        $blockingChecksPass = $uncategorisedCount === 0
            && abs((float)$suspenseSummary['closing_balance']) < 0.005
            && !empty($trialBalance['balances'])
            && !empty($trialBalance['exists'])
            && $journalIntegrityIssues === 0
            && $unpostedSourceWorkCount === 0
            && $retainedEarningsCloseCurrent
            && $taxProvisionCurrent
            && $this->acknowledgementCurrentInSections($sections, 'prepayment_approvals')
            && (!$directorLoanTaxReviewRequired || $this->acknowledgementCurrentInSections($sections, 'director_loan_tax_review'));
        $sections['final_review_lock'][] = $this->makeCheck(
            'lock_readiness_checklist',
            'Lock readiness checklist',
            'fail',
            $blockingChecksPass ? 'pass' : 'fail',
            $blockingChecksPass
                ? 'All blocking year-end checks currently pass.'
                : 'One or more blocking checks still fail, so this period cannot be locked yet.',
            $blockingChecksPass ? 'Ready to lock' : 'Not ready',
            '?page=year_end&show_card=year_end_state'
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
            '?page=year_end&show_card=year_end_notes'
        );

        foreach ($sections as $sectionChecks) {
            foreach ($sectionChecks as $check) {
                $checks[] = $check;
            }
        }

        $overallStatus = $this->determineOverallStatus($checks, $hasSourceData, !empty($review['is_locked']));
        return [
            'company_id' => $companyId,
            'accounting_period' => $accountingPeriod,
            'overall_status' => $overallStatus,
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

    private function taxPeriodDisplay(array $taxReadiness): string
    {
        $periods = array_values(array_filter(
            (array)($taxReadiness['periods'] ?? []),
            static fn(mixed $period): bool => is_array($period)
        ));
        if ($periods === []) {
            return '';
        }

        $labels = [];
        foreach ($periods as $period) {
            $label = trim((string)($period['period_label'] ?? ''));
            if ($label === '') {
                $start = trim((string)($period['period_start'] ?? ''));
                $end = trim((string)($period['period_end'] ?? ''));
                $label = trim($start . ' to ' . $end);
            }
            if ($label !== '' && $label !== 'to') {
                $labels[] = $label;
            }
        }

        return implode('; ', $labels);
    }

    private function money(array $settings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($settings, $value);
    }

    private function statementContinuityIssueCount(array $summary): int
    {
        if (array_key_exists('issue_count', $summary)) {
            return (int)$summary['issue_count'];
        }

        $issues = array_values(array_filter((array)($summary['issues'] ?? []), static fn(mixed $issue): bool => is_array($issue)));
        if ($issues !== []) {
            return count($issues);
        }

        return (int)($summary['continuity_warnings'] ?? 0)
            + (int)($summary['running_balance_warnings'] ?? 0)
            + (int)($summary['ledger_warnings'] ?? 0);
    }

    private function statementContinuityMetric(int $issueCount): string
    {
        return $issueCount . ' statement continuity ' . ($issueCount === 1 ? 'issue' : 'issues');
    }

    private function statementContinuityDetail(array $summary, array $settings): string
    {
        $issues = array_values(array_filter((array)($summary['issues'] ?? []), static fn(mixed $issue): bool => is_array($issue)));
        if ($issues === []) {
            return 'At least one bank account has running-balance, statement-boundary, or ledger reconciliation issues.';
        }

        $parts = [];
        foreach (array_slice($issues, 0, 3) as $issue) {
            $text = $this->statementContinuityIssueText($issue, $settings);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        $remaining = count($issues) - count($parts);
        if ($remaining > 0) {
            $parts[] = $remaining . ' more bank/source ' . ($remaining === 1 ? 'issue' : 'issues') . ' need review on the Source Accounts workflow.';
        }

        return $parts !== []
            ? implode(' ', $parts)
            : 'At least one bank account has running-balance, statement-boundary, or ledger reconciliation issues.';
    }

    private function statementContinuityIssueText(array $issue, array $settings): string
    {
        $accountName = trim((string)($issue['account_name'] ?? ''));
        if ($accountName === '') {
            $accountId = (int)($issue['account_id'] ?? 0);
            $accountName = $accountId > 0 ? 'Account #' . $accountId : 'Bank account';
        }

        return match ((string)($issue['type'] ?? '')) {
            'statement_continuity' => $this->statementContinuityBoundaryText($accountName, $issue, $settings),
            'running_balance' => $this->statementContinuityRunningBalanceText($accountName, $issue),
            'ledger_reconciliation' => $this->statementContinuityLedgerText($accountName, $issue, $settings),
            default => $accountName . ': source-account review issue.',
        };
    }

    private function statementContinuityBoundaryText(string $accountName, array $issue, array $settings): string
    {
        $uploadLabel = $this->statementContinuityUploadLabel($issue);
        $range = $this->statementContinuityDateRangeText($issue, $settings);
        $openingBalance = $this->statementContinuityMoney($settings, $issue['opening_balance'] ?? null);
        $previousBalance = $issue['previous_statement_closing_balance'] ?? null;
        $note = $this->statementContinuityNoteFragment((string)($issue['note'] ?? ''));
        $openingText = $range !== '' ? ' and opens at ' : ' opens at ';

        if ($previousBalance === null || $previousBalance === '') {
            return $accountName . ': first statement ' . $uploadLabel . $range . $openingText . $openingBalance . '; '
                . ($note !== '' ? $note : 'no previous statement exists to compare against') . '.';
        }

        return $accountName . ': statement ' . $uploadLabel . $range . $openingText . $openingBalance
            . ', but the previous statement closed at ' . $this->statementContinuityMoney($settings, $previousBalance) . '; '
            . ($note !== '' ? $note : 'opening and previous closing balances do not match') . '.';
    }

    private function statementContinuityRunningBalanceText(string $accountName, array $issue): string
    {
        $uploadLabel = $this->statementContinuityUploadLabel($issue);
        $failed = (int)($issue['balance_check_rows_failed'] ?? 0);
        $tested = (int)($issue['balance_check_rows_tested'] ?? 0);
        $rowNumbers = array_values(array_filter(
            array_map('intval', (array)($issue['failed_row_numbers'] ?? [])),
            static fn(int $rowNumber): bool => $rowNumber > 0
        ));
        $rowText = $rowNumbers !== [] ? '; first failed row(s): ' . implode(', ', $rowNumbers) : '';

        return $accountName . ': statement ' . $uploadLabel . ' has ' . $failed . ' running-balance '
            . ($failed === 1 ? 'break' : 'breaks') . ' across ' . $tested . ' checked row(s)' . $rowText . '.';
    }

    private function statementContinuityLedgerText(string $accountName, array $issue, array $settings): string
    {
        $date = $this->statementContinuityDate((string)($issue['statement_closing_date'] ?? ''), $settings);
        $statementBalance = $this->statementContinuityMoney($settings, $issue['statement_closing_balance'] ?? null);
        $ledgerBalance = $this->statementContinuityMoney($settings, $issue['ledger_balance'] ?? null);
        $difference = $this->statementContinuityMoney($settings, $issue['difference'] ?? null);
        $note = $this->statementContinuityNoteFragment((string)($issue['note'] ?? ''));

        $text = $accountName . ': ledger reconciliation differs';
        if ($date !== '') {
            $text .= ' at ' . $date;
        }

        $text .= '; statement closes at ' . $statementBalance . ', ledger is ' . $ledgerBalance . ', difference ' . $difference;
        if ($note !== '') {
            $text .= '; ' . $note;
        }

        return $text . '.';
    }

    private function statementContinuityUploadLabel(array $issue): string
    {
        $filename = trim((string)($issue['upload_filename'] ?? ''));
        if ($filename !== '') {
            return $filename;
        }

        $uploadId = (int)($issue['upload_id'] ?? 0);
        return $uploadId > 0 ? 'upload #' . $uploadId : 'statement upload';
    }

    private function statementContinuityDateRangeText(array $issue, array $settings): string
    {
        $start = trim((string)($issue['date_range_start'] ?? ''));
        if ($start === '') {
            $start = trim((string)($issue['statement_month'] ?? ''));
        }

        $end = trim((string)($issue['date_range_end'] ?? ''));
        if ($end === '') {
            $end = trim((string)($issue['closing_date'] ?? ''));
        }

        $displayStart = $this->statementContinuityDate($start, $settings);
        $displayEnd = $this->statementContinuityDate($end, $settings);

        if ($displayStart !== '' && $displayEnd !== '' && $displayStart !== $displayEnd) {
            return ' covers ' . $displayStart . ' to ' . $displayEnd;
        }

        if ($displayStart !== '') {
            return ' covers ' . $displayStart;
        }

        if ($displayEnd !== '') {
            return ' closes ' . $displayEnd;
        }

        return '';
    }

    private function statementContinuityDate(string $date, array $settings): string
    {
        $date = trim($date);
        if ($date === '') {
            return '';
        }

        $format = trim((string)($settings['date_format'] ?? 'd/m/Y'));
        if ($format === '') {
            $format = 'd/m/Y';
        }

        try {
            return (new \DateTimeImmutable($date))->format($format);
        } catch (\Throwable) {
            return $date;
        }
    }

    private function statementContinuityMoney(array $settings, mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'unknown balance';
        }

        return $this->money($settings, $value);
    }

    private function statementContinuityNoteFragment(string $note): string
    {
        $note = rtrim(trim($note), '.');
        if ($note === '') {
            return '';
        }

        return strtolower(substr($note, 0, 1)) . substr($note, 1);
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

    private function trialBalanceMetric(array $trialBalance): string
    {
        return (int)($trialBalance['line_count'] ?? 0) . ' trial balance line(s)';
    }

    private function postedSourceWorkChecks(array $postedSourceWork): array
    {
        return [
            $this->postedSourceWorkTypeCheck(
                $postedSourceWork,
                'posted_transactions_integrity',
                'Posted transactions',
                'unposted_transactions',
                'transaction(s)',
                'All postable transactions have posted journals for this period.',
                'Post or confirm the remaining transactions before locking this period.',
                '?page=transactions&show_card=transactions_imported&category_filter=not_posted'
            ),
            $this->postedSourceWorkTypeCheck(
                $postedSourceWork,
                'posted_expense_claims_integrity',
                'Posted expense claims',
                'unposted_expense_claims',
                'expense claim(s)',
                'All postable expense claims have posted journals for this period.',
                'Post or confirm the remaining expense claims before locking this period.',
                '?page=expense_claims'
            ),
            $this->postedSourceWorkTypeCheck(
                $postedSourceWork,
                'posted_assets_integrity',
                'Posted assets',
                'unposted_assets',
                'asset(s)',
                'All fixed assets have posted journals for this period.',
                'Post or confirm the remaining fixed assets before locking this period.',
                '?page=assets'
            ),
        ];
    }

    private function postedSourceWorkTypeCheck(
        array $postedSourceWork,
        string $code,
        string $title,
        string $countKey,
        string $unit,
        string $passDetail,
        string $failDetail,
        string $actionUrl
    ): array {
        $count = (int)($postedSourceWork[$countKey] ?? 0);

        return $this->makeCheck(
            $code,
            $title,
            'fail',
            $count > 0 ? 'fail' : 'pass',
            $count > 0 ? $failDetail : $passDetail,
            $count . ' ' . $unit,
            $actionUrl
        );
    }

    private function makeCheck(
        string $code,
        string $title,
        string $severity,
        string $status,
        string $detail,
        string $metricValue = '',
        ?string $actionUrl = null,
        ?array $basisData = null
    ): array {
        $workflowUrl = $this->workflowActionUrl($actionUrl);

        return [
            'check_code' => $code,
            'title' => $title,
            'severity' => $severity,
            'status' => $status,
            'detail_text' => $detail,
            'metric_value' => $metricValue,
            'action_url' => $workflowUrl,
            'workflow_page' => $this->workflowPage($workflowUrl),
            'workflow_label' => 'Open Related Workflow',
            'workflow_fields' => $this->workflowFields($workflowUrl),
            'basis_data' => $basisData,
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

        $rebuiltQuery = $this->buildQuery($params);
        return ($rebuiltQuery !== '' ? '?' . $rebuiltQuery : '') . $fragment;
    }

    private function workflowPage(?string $actionUrl): string
    {
        $params = $this->workflowParams($actionUrl);
        return (string)($params['page'] ?? '');
    }

    private function workflowFields(?string $actionUrl): array
    {
        $params = $this->workflowParams($actionUrl);
        unset($params['page']);
        return $params;
    }

    private function workflowParams(?string $actionUrl): array
    {
        $actionUrl = trim((string)$actionUrl);
        if ($actionUrl === '') {
            return [];
        }

        $hashPosition = strpos($actionUrl, '#');
        if ($hashPosition !== false) {
            $actionUrl = substr($actionUrl, 0, $hashPosition);
        }

        $query = str_starts_with($actionUrl, '?') ? substr($actionUrl, 1) : $actionUrl;
        parse_str($query, $params);
        return $params;
    }

    private function buildQuery(array $params): string
    {
        $parts = [];
        foreach ($params as $name => $value) {
            if (is_array($value)) {
                continue;
            }
            $parts[] = rawurlencode((string)$name) . '=' . rawurlencode((string)$value);
        }

        return implode('&', $parts);
    }

    private function applyReviewAcknowledgement(array $check, array $acknowledgements): array
    {
        $checkCode = (string)($check['check_code'] ?? '');
        if (!in_array($checkCode, self::ACKNOWLEDGEMENT_CHECKS, true)) {
            return $check;
        }

        $check['review_clearable'] = in_array($checkCode, self::REVIEW_ACKNOWLEDGEABLE_CHECKS, true);
        $acknowledgement = $acknowledgements[$checkCode] ?? null;
        $evaluation = ($this->acknowledgementService ?? new \eel_accounts\Service\YearEndAcknowledgementService())
            ->evaluate(
                is_array($acknowledgement) ? $acknowledgement : null,
                is_array($check['basis_data'] ?? null) ? $check['basis_data'] : null,
                !empty($acknowledgement['_period_locked'])
            );
        $check['acknowledgement_state'] = (string)($evaluation['state'] ?? 'absent');
        $check['acknowledgement_current'] = !empty($evaluation['current']);

        if (!is_array($acknowledgement)) {
            return $check;
        }

        if (empty($evaluation['current'])) {
            $check['previous_acknowledgement'] = $acknowledgement;
            $check['detail_text'] = ((string)($evaluation['state'] ?? '') === 'unverifiable'
                ? 'Review required — the current live basis could not be verified. '
                : 'Review required — underlying data changed. ')
                . (string)($check['detail_text'] ?? '');
            return $check;
        }

        $check['review_acknowledgement'] = $acknowledgement;
        if (in_array((string)($check['status'] ?? ''), ['warning', 'fail'], true)) {
            $check['status'] = 'pass';
            $check['metric_value'] = $checkCode === 'companies_house_mismatch_acknowledgement'
                ? 'Acknowledged'
                : (trim((string)($check['metric_value'] ?? '')) !== ''
                ? (string)$check['metric_value']
                : 'Reviewed');
            $check['detail_text'] = 'Review acknowledged for this period. ' . (string)($check['detail_text'] ?? '');
        }

        return $check;
    }

    private function acknowledgementBasis(string $checkCode, array $facts): array
    {
        return ($this->acknowledgementService ?? new \eel_accounts\Service\YearEndAcknowledgementService())
            ->buildBasis($checkCode, $facts);
    }

    private function prepaymentApprovalGate(int $companyId, int $accountingPeriodId): array
    {
        $review = (new \eel_accounts\Service\PrepaymentReviewService(
            $this->metricsService ?? new \eel_accounts\Service\YearEndMetricsService(),
            $this->lockService ?? new \eel_accounts\Service\YearEndLockService()
        ))->fetchContext($companyId, $accountingPeriodId);

        if (empty($review['available'])) {
            return [
                'success' => false,
                'errors' => [(string)(($review['errors'] ?? [])[0] ?? 'Prepayment review is not available.')],
            ];
        }

        $incompleteCount = (int)($review['pending_count'] ?? 0);
        if ($incompleteCount > 0) {
            return [
                'success' => false,
                'errors' => ['Complete all pre-paid service dates before saving this approval. Incomplete: ' . $incompleteCount . '.'],
            ];
        }

        return ['success' => true];
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

    private function acknowledgementCurrentInSections(array $sections, string $checkCode): bool
    {
        foreach ($sections as $checks) {
            foreach ((array)$checks as $check) {
                if (is_array($check)
                    && (string)($check['check_code'] ?? '') === $checkCode
                    && !empty($check['acknowledgement_current'])) {
                    return true;
                }
            }
        }
        return false;
    }

    private function fetchReviewAcknowledgements(int $companyId, int $accountingPeriodId): array
    {
        $acknowledgements = ($this->acknowledgementService ?? new \eel_accounts\Service\YearEndAcknowledgementService())
            ->fetchAll($companyId, $accountingPeriodId);
        if (($this->lockService ?? new \eel_accounts\Service\YearEndLockService())->isLocked($companyId, $accountingPeriodId)) {
            foreach ($acknowledgements as $checkCode => $acknowledgement) {
                if (is_array($acknowledgement)) {
                    $acknowledgements[$checkCode]['_period_locked'] = true;
                }
            }
        }
        return $acknowledgements;
    }

    private function actorValue(string $value): string
    {
        $value = trim($value);
        return $value !== '' ? $value : 'web_app';
    }

    private function fetchChecklistResult(int $companyId, int $accountingPeriodId): array {
        $checklist = $this->fetchChecklist($companyId, $accountingPeriodId);
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
