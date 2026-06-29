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
    public function __construct(
        private readonly ?\eel_accounts\Service\YearEndMetricsService $metricsService = null,
        private readonly ?\eel_accounts\Service\YearEndTaxReadinessService $taxReadinessService = null,
        private readonly ?\eel_accounts\Service\YearEndCompaniesHouseComparisonService $companiesHouseComparisonService = null,
        private readonly ?\eel_accounts\Service\YearEndLockService $lockService = null,
    ) {
    }

    public function fetchDashboardSummary(int $companyId, ?int $accountingPeriodId = null): array {
        $metrics = $this->metricsService ?? new \eel_accounts\Service\YearEndMetricsService();
        $resolvedAccountingPeriodId = $accountingPeriodId !== null && $accountingPeriodId > 0
            ? $accountingPeriodId
            : $metrics->resolveLatestOpenAccountingPeriodId($companyId);

        $checklist = $resolvedAccountingPeriodId > 0
            ? $this->fetchChecklist($companyId, $resolvedAccountingPeriodId, false)
            : null;

        if (!is_array($checklist)) {
            return [
                'available' => false,
                'status' => 'not_started',
                'period_label' => 'No accounting period selected',
                'top_issues' => [],
                'action_url' => '?page=year-end&company_id=' . (int)$companyId,
            ];
        }

        $topIssues = $this->topIssuesFromChecks((array)($checklist['checks_flat'] ?? []));

        return [
            'available' => true,
            'status' => (string)$checklist['overall_status'],
            'period_label' => (string)($checklist['accounting_period']['label'] ?? ''),
            'accounting_period_id' => (int)($checklist['accounting_period']['id'] ?? 0),
            'top_issues' => $topIssues,
            'action_url' => '?page=year-end&company_id=' . (int)$companyId . '&accounting_period_id=' . (int)($checklist['accounting_period']['id'] ?? 0),
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

        $lock = $this->lockService ?? new \eel_accounts\Service\YearEndLockService();
        $result = $lock->lockPeriod($companyId, $accountingPeriodId, $lockedBy);
        if (empty($result['success'])) {
            return $result;
        }

        return $result + [
            'checklist' => $this->fetchChecklist($companyId, $accountingPeriodId, true),
        ];
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

        $monthTiles = $metrics->buildMonthTiles($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $sourceData = $metrics->sourceDataSummary($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $uncategorisedCount = $metrics->uncategorisedTransactionsCount($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $autoPendingCount = $metrics->autoCategorisedPendingReviewCount($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $suspenseSummary = $metrics->suspenseSummary($companyId, $accountingPeriodId, $periodEnd);
        $trialBalance = $metrics->trialBalanceSummary($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $journalIntegrity = $metrics->journalIntegritySummary($companyId, $accountingPeriodId);
        $statementContinuity = $metrics->statementContinuitySummary($companyId, $accountingPeriodId, $bankNominalId);
        $duplicateAudit = $metrics->duplicateImportAudit($companyId, $accountingPeriodId);
        $strandedRows = $metrics->strandedCommittedSourceRowsCount($companyId, $accountingPeriodId);
        $directorLoan = $metrics->directorLoanSummary($companyId, $accountingPeriodId);
        $unpaidExpenses = $metrics->unpaidExpenseSummary($companyId, $periodEnd);
        $duplicateRepayments = $metrics->duplicateRepaymentRiskSummary($companyId, $periodStart, $periodEnd);
        $financialStatements = $metrics->financialStatementsSummary($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $taxReadiness = $tax->fetchSummary($companyId, $accountingPeriodId);
        $chComparison = $comparison->fetchComparison($companyId, $accountingPeriodId);

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
            (string)number_format((float)$suspenseSummary['closing_balance'], 2, '.', ''),
            '?page=journals&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
        );
        $sections['categorisation_suspense'][] = $this->makeCheck(
            'auto_categorisations_pending_review',
            'Auto categorisations pending review',
            'warning',
            $autoPendingCount > 0 ? 'warning' : 'pass',
            $autoPendingCount > 0
                ? 'Auto-categorised transactions remain and may need user review before final accounts work.'
                : 'No auto-categorised transactions are waiting for review.',
            (string)$autoPendingCount,
            '?page=transactions&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId . '&category_filter=auto'
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
            '?page=journals&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
        );
        $sections['ledger_integrity'][] = $this->makeCheck(
            'trial_balance_balances',
            'Trial balance balances',
            'fail',
            !empty($trialBalance['balances']) ? 'pass' : 'fail',
            !empty($trialBalance['balances'])
                ? 'Total debits equal total credits.'
                : 'Total debits and credits do not match for the selected period.',
            (string)number_format((float)($trialBalance['difference'] ?? 0), 2, '.', ''),
            '?page=journals&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
        );
        $journalIntegrityIssues = (int)$journalIntegrity['line_count_failures'] + (int)$journalIntegrity['unbalanced_journals'] + (int)$journalIntegrity['missing_nominal_lines'];
        $sections['ledger_integrity'][] = $this->makeCheck(
            'journal_structural_integrity',
            'Journal structural integrity',
            'fail',
            $journalIntegrityIssues > 0 ? 'fail' : 'pass',
            $journalIntegrityIssues > 0
                ? 'Some journals have structural issues that must be resolved before year end is locked.'
                : 'Journal structures look valid for this accounting period.',
            (string)$journalIntegrityIssues,
            '?page=journals&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
        );
        $sections['ledger_integrity'][] = $this->makeCheck(
            'posted_only_period_integrity',
            'Posted-only period integrity',
            'info',
            !empty($review['is_locked']) ? 'pass' : 'warning',
            !empty($review['is_locked'])
                ? 'This period is locked and backend mutation guards are enabled.'
                : 'This period is still open for posting changes.',
            !empty($review['is_locked']) ? 'Locked' : 'Unlocked',
            '?page=year-end&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
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
        $sections['director_loan_expenses'][] = $this->makeCheck(
            'director_loan_closing_balance',
            'Director loan closing balance',
            'warning',
            empty($directorLoan['available']) ? 'not_applicable' : ($dlaClosing !== 0.0 ? 'warning' : 'pass'),
            empty($directorLoan['available'])
                ? (string)($directorLoan['error'] ?? 'Director loan summary unavailable.')
                : 'Review whether the period-end director loan balance is expected before filing.',
            empty($directorLoan['available']) ? '' : (string)number_format($dlaClosing, 2, '.', ''),
            '?page=director-loan&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
        );
        $sections['director_loan_expenses'][] = $this->makeCheck(
            'unpaid_expense_claims',
            'Unpaid expense claims',
            'warning',
            !empty($unpaidExpenses['available']) && (int)$unpaidExpenses['unpaid_count'] > 0 ? 'warning' : (!empty($unpaidExpenses['available']) ? 'pass' : 'not_applicable'),
            !empty($unpaidExpenses['available'])
                ? 'Expense claims carried forward at period end should be reviewed.'
                : 'Expense claim register is not available yet.',
            !empty($unpaidExpenses['available']) ? (string)number_format((float)$unpaidExpenses['outstanding_amount'], 2, '.', '') : '',
            '?page=expenses&company_id=' . $companyId
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
            '?page=expenses&company_id=' . $companyId
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
            (string)number_format($profitBeforeTax, 2, '.', ''),
            '?page=journals&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
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
            '?page=year-end&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId . '#companies-house-comparison'
        );
        $equityMovement = abs((float)($financialStatements['retained_earnings']['unexplained_movement'] ?? 0));
        $sections['year_end_accounts_review'][] = $this->makeCheck(
            'retained_earnings_movement',
            'Retained earnings movement',
            'warning',
            $equityMovement > 0.99 ? 'warning' : 'pass',
            $equityMovement > 0.99
                ? 'Opening equity, profit, and closing equity do not fully reconcile.'
                : 'Opening equity, profit, and closing equity look internally consistent.',
            (string)number_format((float)($financialStatements['retained_earnings']['unexplained_movement'] ?? 0), 2, '.', ''),
            '?page=journals&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
        );
        $sections['year_end_accounts_review'][] = $this->makeCheck(
            'fixed_asset_review_placeholder',
            'Fixed asset review',
            'warning',
            (int)($financialStatements['fixed_asset_hint_count'] ?? 0) > 0 ? 'warning' : 'pass',
            (int)($financialStatements['fixed_asset_hint_count'] ?? 0) > 0
                ? 'Transactions with capital-style signals were found and should be reviewed for fixed asset treatment.'
                : 'No obvious capital purchase hints were found in this period.',
            (string)($financialStatements['fixed_asset_hint_count'] ?? 0),
            '?page=assets&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
        );
        $sections['year_end_accounts_review'][] = $this->makeCheck(
            'prepayments_accruals_placeholder',
            'Prepayments and accruals review',
            'warning',
            'warning',
            'Manual review reminder: consider year-end accruals, prepayments, and other cut-off journals before filing.',
            '',
            '?page=journals&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
        );

        $sections['corporation_tax_readiness'][] = $this->makeCheck(
            'tax_adjusted_profit_basis_available',
            'Tax-adjusted profit basis available',
            'warning',
            !empty($taxReadiness['available']) && ((int)($taxReadiness['unknown_treatment_count'] ?? 0) > 0 || (int)($taxReadiness['other_treatment_count'] ?? 0) > 0) ? 'warning' : (!empty($taxReadiness['available']) ? 'pass' : 'fail'),
            !empty($taxReadiness['available'])
                ? 'Nominal tax treatments are being used to estimate the tax-adjusted result.'
                : 'Tax readiness could not be calculated for this period.',
            !empty($taxReadiness['available']) ? (string)number_format((float)($taxReadiness['taxable_profit'] ?? 0), 2, '.', '') : '',
            '?page=nominals&company_id=' . $companyId
        );
        $sections['corporation_tax_readiness'][] = $this->makeCheck(
            'corporation_tax_estimate_generated',
            'Corporation tax estimate generated',
            'info',
            !empty($taxReadiness['available']) ? 'pass' : 'fail',
            !empty($taxReadiness['available'])
                ? 'Estimated taxable profit/loss and corporation tax have been generated for review.'
                : 'No tax estimate could be generated for this period.',
            !empty($taxReadiness['available'])
                ? ('Tax ' . number_format((float)($taxReadiness['estimated_corporation_tax'] ?? 0), 2, '.', ''))
                : '',
            '?page=year-end&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId . '#tax-readiness'
        );
        $sections['corporation_tax_readiness'][] = $this->makeCheck(
            'losses_carried_forward',
            'Losses carried forward',
            'info',
            !empty($taxReadiness['available']) ? 'pass' : 'not_applicable',
            'Losses brought forward, used, and carried forward are shown on a simple basis ready for later CT engine refinement.',
            !empty($taxReadiness['available']) ? (string)number_format((float)($taxReadiness['losses_carried_forward'] ?? 0), 2, '.', '') : '',
            '?page=year-end&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId . '#tax-readiness'
        );
        $sections['corporation_tax_readiness'][] = $this->makeCheck(
            'filing_basis_reminder',
            'Filing basis reminder',
            'warning',
            'warning',
            'App numbers remain working figures until final adjustments and filing outputs are finalised.',
            '',
            '?page=year-end&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
        );

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
            '?page=year-end&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId . '#companies-house-comparison'
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
            '?page=year-end&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId . '#companies-house-comparison'
        );

        $blockingChecksPass = $uncategorisedCount === 0
            && abs((float)$suspenseSummary['closing_balance']) < 0.005
            && !empty($trialBalance['balances'])
            && !empty($trialBalance['exists'])
            && $journalIntegrityIssues === 0;
        $sections['final_review_lock'][] = $this->makeCheck(
            'lock_readiness_checklist',
            'Lock readiness checklist',
            'fail',
            $blockingChecksPass ? 'pass' : 'fail',
            $blockingChecksPass
                ? 'All blocking year-end checks currently pass.'
                : 'One or more blocking checks still fail, so this period cannot be locked yet.',
            $blockingChecksPass ? 'Ready to lock' : 'Not ready',
            '?page=year-end&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
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
            '?page=year-end&company_id=' . $companyId . '&accounting_period_id=' . $accountingPeriodId
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
            'month_tiles' => $monthTiles,
            'sections' => $sections,
            'checks_flat' => $checks,
            'tax_readiness' => $taxReadiness,
            'companies_house_comparison' => $chComparison,
        ];
    }

    private function makeCheck(string $code, string $title, string $severity, string $status, string $detail, string $metricValue = '', ?string $actionUrl = null): array {
        return [
            'check_code' => $code,
            'title' => $title,
            'severity' => $severity,
            'status' => $status,
            'detail_text' => $detail,
            'metric_value' => $metricValue,
            'action_url' => $actionUrl,
        ];
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
}
