<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class YearEndMetricsService
{
    public function __construct(
        private readonly ?\eel_accounts\Service\BankingReconciliationService $bankingReconciliationService = null,
        private readonly ?\eel_accounts\Service\DirectorLoanService $directorLoanService = null,
        private readonly ?\eel_accounts\Service\ExpenseClaimService $expenseClaimService = null,
        private readonly ?\eel_accounts\Service\EmptyMonthConfirmationService $emptyMonthConfirmationService = null,
    ) {
    }

    public function fetchAccountingPeriod(int $companyId, int $accountingPeriodId): ?array {
        return (new \eel_accounts\Repository\AccountingPeriodRepository())->fetchAccountingPeriod($companyId, $accountingPeriodId);
    }

    public function fetchAccountingPeriods(int $companyId): array {
        return (new \eel_accounts\Repository\AccountingPeriodRepository())->fetchAccountingPeriods($companyId);
    }

    public function resolveLatestOpenAccountingPeriodId(int $companyId): int {
        $sql = 'SELECT ty.id
             FROM accounting_periods ty
             LEFT JOIN year_end_reviews yer
               ON yer.company_id = ty.company_id
              AND yer.accounting_period_id = ty.id
             WHERE ty.company_id = :company_id
               AND COALESCE(yer.is_locked, 0) = 0
             ORDER BY ty.period_start DESC, ty.id DESC
             LIMIT 1';

        try {
            $value = \InterfaceDB::fetchColumn( $sql, ['company_id' => $companyId]);
            if ($value !== false) {
                return (int)$value;
            }
        } catch (\Throwable) {
        }

        return (int)(\InterfaceDB::fetchColumn( 'SELECT id
             FROM accounting_periods
             WHERE company_id = :company_id
             ORDER BY period_start DESC, id DESC
             LIMIT 1', ['company_id' => $companyId]) ?: 0);
    }

    public function fetchCompanySummary(int $companyId): ?array {
        if ($companyId <= 0) {
            return null;
        }

        $row = \InterfaceDB::fetchOne( 'SELECT id, company_name, company_number
             FROM companies
             WHERE id = :id
             LIMIT 1', ['id' => $companyId]);

        return is_array($row) ? $row : null;
    }

    public function fetchCompanySettings(int $companyId): array {
        if ($companyId <= 0) {
            return [];
        }

        $settings = [];
        foreach (\InterfaceDB::fetchAll( 'SELECT setting, value
             FROM company_settings
             WHERE company_id = :company_id', ['company_id' => $companyId]) as $row) {
            $settings[(string)$row['setting']] = (string)($row['value'] ?? '');
        }

        return $settings;
    }

    public function buildMonthTiles(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): array {
        $coverageService = new \eel_accounts\Service\AccountingPeriodCoverageService();
        $coverage = $coverageService->summarise($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $uploadsByMonth = $this->fetchUploadCountsByMonth($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $transactionByMonth = $this->fetchTransactionCountsByMonth($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $postedJournalsByMonth = $this->fetchPostedJournalCountsByMonth($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $confirmedEmptyMonths = ($this->emptyMonthConfirmationService ?? new \eel_accounts\Service\EmptyMonthConfirmationService())
            ->activeConfirmationMap($companyId, $accountingPeriodId);
        $suspenseNominalId = $this->findSuspenseNominalId($companyId);
        $suspenseByMonth = $suspenseNominalId > 0
            ? $this->fetchSuspenseCountsByMonth($companyId, $accountingPeriodId, $suspenseNominalId)
            : [];

        $tiles = [];
        foreach (($coverage['months'] ?? []) as $month) {
            $monthKey = (string)($month['month_key'] ?? '');
            $uploadCount = (int)($uploadsByMonth[$monthKey] ?? 0);
            $txnSummary = $transactionByMonth[$monthKey] ?? ['transactions' => 0, 'uncategorised' => 0];
            $txnCount = (int)($txnSummary['transactions'] ?? 0);
            $uncategorisedCount = (int)($txnSummary['uncategorised'] ?? 0);
            $postedJournalCount = (int)($postedJournalsByMonth[$monthKey] ?? 0);
            $suspenseCount = (int)($suspenseByMonth[$monthKey] ?? 0);
            $isConfirmedEmpty = isset($confirmedEmptyMonths[$monthKey])
                && $txnCount === 0
                && $uploadCount === 0
                && $postedJournalCount === 0;

            if ($isConfirmedEmpty) {
                $status = 'green';
            } elseif ($txnCount === 0 && $uploadCount === 0 && $postedJournalCount === 0) {
                $status = 'red';
            } elseif ($uncategorisedCount > 0 || $suspenseCount > 0) {
                $status = 'amber';
            } else {
                $status = 'green';
            }

            $tiles[] = [
                'month_key' => $monthKey,
                'month_short_name' => substr((string)($month['label'] ?? ''), 0, 3),
                'label' => (string)($month['label'] ?? ''),
                'status' => $status,
                'statement_upload_count' => $uploadCount,
                'transaction_count' => $txnCount,
                'posted_journal_count' => $postedJournalCount,
                'uncategorised_count' => $uncategorisedCount,
                'suspense_count' => $suspenseCount,
                'empty_month_confirmed' => $isConfirmedEmpty,
                'empty_month_confirmation' => $isConfirmedEmpty ? $confirmedEmptyMonths[$monthKey] : null,
            ];
        }

        return $tiles;
    }

    public function sourceDataSummary(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): array {
        return [
            'bank_transactions' => $this->countTransactions($companyId, $accountingPeriodId, $periodStart, $periodEnd),
            'manual_journals' => $this->countJournalsBySource($companyId, $accountingPeriodId, ['manual']),
            'director_loan_journals' => $this->countJournalsBySource($companyId, $accountingPeriodId, ['director_loan_register']),
            'expense_journals' => $this->countJournalsBySource($companyId, $accountingPeriodId, ['expense_register', 'expense_claim_post', 'expense_claim_payment_link']),
        ];
    }

    public function uncategorisedTransactionsCount(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): int {
        $noPostExclusionSql = $this->tableExists('transaction_inter_ac_marker')
            ? 'AND NOT EXISTS (
                   SELECT 1
                   FROM transaction_inter_ac_marker tiam
                   WHERE tiam.matched_transaction_id = transactions.id
               )'
            : '';

        return (int)\InterfaceDB::fetchColumn( 'SELECT COUNT(*)
             FROM transactions
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND txn_date BETWEEN :period_start AND :period_end
               AND (category_status = :category_status OR nominal_account_id IS NULL)
               ' . $noPostExclusionSql, [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'category_status' => 'uncategorised',
        ]);
    }

    public function autoCategorisedPendingReviewCount(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): int {
        $summary = $this->autoCategorisedDecisionSummary($companyId, $accountingPeriodId, $periodStart, $periodEnd);

        return (int)($summary['unreviewed_count'] ?? 0);
    }

    public function autoCategorisedDecisionSummary(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): array
    {
        $row = \InterfaceDB::fetchOne( 'SELECT COALESCE(SUM(CASE
                        WHEN NOT (' . \eel_accounts\Service\TransactionAutoApprovalService::currentCheckedSql('taa', 't') . ')
                        THEN 1
                        ELSE 0
                    END), 0) AS unreviewed_count,
                    COALESCE(SUM(CASE
                        WHEN ' . \eel_accounts\Service\TransactionAutoApprovalService::currentPostConfirmationPendingSql('taa', 't') . '
                        THEN 1
                        ELSE 0
                    END), 0) AS post_confirmation_pending_count
             FROM transactions t
             LEFT JOIN transaction_auto_approvals taa ON taa.transaction_id = t.id
             WHERE t.company_id = :company_id
               AND t.accounting_period_id = :accounting_period_id
               AND t.txn_date BETWEEN :period_start AND :period_end
               AND t.category_status = :category_status
               AND t.auto_rule_id IS NOT NULL
               AND t.auto_rule_id > 0', [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'category_status' => 'auto',
        ]) ?: [];

        $unreviewed = (int)($row['unreviewed_count'] ?? 0);
        $postConfirmationPending = (int)($row['post_confirmation_pending_count'] ?? 0);

        return [
            'unreviewed_count' => $unreviewed,
            'post_confirmation_pending_count' => $postConfirmationPending,
            'total_attention_count' => $unreviewed + $postConfirmationPending,
        ];
    }

    public function postedSourceWorkSummary(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): array
    {
        $transactions = $this->unpostedPostableTransactionsCount($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $expenseClaims = $this->unpostedExpenseClaimsCount($companyId, $accountingPeriodId);
        $assets = $this->unpostedAssetsCount($companyId, $accountingPeriodId, $periodStart, $periodEnd);

        return [
            'unposted_transactions' => $transactions,
            'unposted_expense_claims' => $expenseClaims,
            'unposted_assets' => $assets,
            'total_unposted' => $transactions + $expenseClaims + $assets,
        ];
    }

    public function suspenseSummary(int $companyId, int $accountingPeriodId, string $periodEnd): array {
        $nominalId = $this->findSuspenseNominalId($companyId);
        if ($nominalId <= 0) {
            return [
                'has_nominal' => false,
                'nominal_account_id' => 0,
                'closing_balance' => 0.0,
                'entry_count' => 0,
            ];
        }

        $row = \InterfaceDB::fetchOne( 'SELECT COALESCE(SUM(COALESCE(jl.debit, 0) - COALESCE(jl.credit, 0)), 0) AS closing_balance,
                    COUNT(*) AS entry_count
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE j.company_id = :company_id
               AND j.is_posted = 1
               AND j.journal_date <= :period_end
               AND jl.nominal_account_id = :nominal_account_id', [
            'company_id' => $companyId,
            'period_end' => $periodEnd,
            'nominal_account_id' => $nominalId,
        ]) ?: [];

        return [
            'has_nominal' => true,
            'nominal_account_id' => $nominalId,
            'closing_balance' => round((float)($row['closing_balance'] ?? 0), 2),
            'entry_count' => (int)($row['entry_count'] ?? 0),
        ];
    }

    public function trialBalanceSummary(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): array {
        $lines = $this->fetchTrialBalanceLines($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $totalDebits = 0.0;
        $totalCredits = 0.0;

        foreach ($lines as $line) {
            $totalDebits += (float)($line['debit'] ?? 0);
            $totalCredits += (float)($line['credit'] ?? 0);
        }

        return [
            'exists' => $lines !== [],
            'line_count' => count($lines),
            'lines' => $lines,
            'total_debits' => round($totalDebits, 2),
            'total_credits' => round($totalCredits, 2),
            'difference' => round($totalDebits - $totalCredits, 2),
            'balances' => abs(round($totalDebits - $totalCredits, 2)) < 0.005,
        ];
    }

    public function journalIntegritySummary(int $companyId, int $accountingPeriodId): array {
        $summary = [
            'line_count_failures' => 0,
            'unbalanced_journals' => 0,
            'missing_nominal_lines' => 0,
            'issues' => [],
        ];

        foreach (\InterfaceDB::fetchAll( 'SELECT j.id,
                    j.description,
                    COUNT(jl.id) AS line_count,
                    COALESCE(SUM(jl.debit), 0) AS total_debit,
                    COALESCE(SUM(jl.credit), 0) AS total_credit,
                    SUM(CASE WHEN na.id IS NULL OR COALESCE(na.is_active, 0) <> 1 THEN 1 ELSE 0 END) AS missing_nominal_lines
             FROM journals j
             LEFT JOIN journal_lines jl ON jl.journal_id = j.id
             LEFT JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
             GROUP BY j.id, j.description
             ORDER BY j.journal_date ASC, j.id ASC', [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
        ]) as $row) {
            $issues = [];
            if ((int)($row['line_count'] ?? 0) < 2) {
                $summary['line_count_failures']++;
                $issues[] = 'fewer than 2 lines';
            }
            if (abs(round((float)($row['total_debit'] ?? 0) - (float)($row['total_credit'] ?? 0), 2)) >= 0.005) {
                $summary['unbalanced_journals']++;
                $issues[] = 'debits and credits do not match';
            }
            if ((int)($row['missing_nominal_lines'] ?? 0) > 0) {
                $summary['missing_nominal_lines'] += (int)$row['missing_nominal_lines'];
                $issues[] = 'inactive or missing nominal accounts found';
            }

            if ($issues !== []) {
                $summary['issues'][] = [
                    'journal_id' => (int)$row['id'],
                    'description' => (string)($row['description'] ?? ''),
                    'issues' => $issues,
                ];
            }
        }

        return $summary;
    }

    public function statementContinuitySummary(int $companyId, int $accountingPeriodId, int $bankNominalId): array {
        $service = $this->bankingReconciliationService ?? new \eel_accounts\Service\BankingReconciliationService();
        $panels = $service->fetchBankAccountPanels($companyId, $accountingPeriodId, $bankNominalId);

        $continuityWarnings = 0;
        $ledgerWarnings = 0;
        foreach ($panels as $panel) {
            if (($panel['statement_continuity_status'] ?? '') !== 'pass' && ($panel['statement_continuity_status'] ?? '') !== 'not_available') {
                $continuityWarnings++;
            }
            if (($panel['ledger_reconciliation_status'] ?? '') !== 'pass' && ($panel['ledger_reconciliation_status'] ?? '') !== 'not_available') {
                $ledgerWarnings++;
            }
        }

        return [
            'account_count' => count($panels),
            'continuity_warnings' => $continuityWarnings,
            'ledger_warnings' => $ledgerWarnings,
            'panels' => $panels,
        ];
    }

    public function duplicateImportAudit(int $companyId, int $accountingPeriodId): array {
        $row = \InterfaceDB::fetchOne( 'SELECT COALESCE(SUM(COALESCE(rows_duplicate, 0)), 0) AS duplicate_rows,
                    COALESCE(SUM(CASE WHEN COALESCE(rows_duplicate, 0) > 0 THEN 1 ELSE 0 END), 0) AS duplicate_files,
                    COALESCE(SUM(COALESCE(rows_duplicate_within_upload, 0)), 0) AS duplicate_within_upload,
                    COALESCE(SUM(COALESCE(rows_duplicate_existing, 0)), 0) AS duplicate_existing
             FROM statement_uploads
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id', [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
        ]) ?: [];

        return [
            'duplicate_rows' => (int)($row['duplicate_rows'] ?? 0),
            'duplicate_files' => (int)($row['duplicate_files'] ?? 0),
            'duplicate_within_upload' => (int)($row['duplicate_within_upload'] ?? 0),
            'duplicate_existing' => (int)($row['duplicate_existing'] ?? 0),
        ];
    }

    public function strandedCommittedSourceRowsCount(int $companyId, int $accountingPeriodId): int {
        if (!$this->tableExists('statement_import_rows')) {
            return 0;
        }

        return (int)\InterfaceDB::fetchColumn( 'SELECT COUNT(*)
             FROM statement_import_rows sir
             INNER JOIN statement_uploads su ON su.id = sir.upload_id
             LEFT JOIN transactions t ON t.id = sir.committed_transaction_id
             LEFT JOIN journals j
               ON j.source_type = :source_type
              AND j.source_ref = CONCAT(:source_prefix, sir.committed_transaction_id)
             WHERE su.company_id = :company_id
               AND su.accounting_period_id = :accounting_period_id
               AND sir.committed_transaction_id IS NOT NULL
               AND (
                    t.id IS NULL
                    OR (
                    t.nominal_account_id IS NOT NULL
                        AND (t.category_status = :auto_status OR t.category_status = :manual_status)
                        AND j.id IS NULL
                    )
               )', [
            'source_type' => 'bank_csv',
            'source_prefix' => 'transaction:',
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'auto_status' => 'auto',
            'manual_status' => 'manual',
        ]);
    }

    public function directorLoanSummary(int $companyId, int $accountingPeriodId): array {
        $service = $this->directorLoanService ?? new \eel_accounts\Service\DirectorLoanService();
        $result = $service->fetchStatement($companyId, $accountingPeriodId);
        if (empty($result['success'])) {
            return [
                'available' => false,
                'error' => (string)($result['errors'][0] ?? $result['error_code'] ?? 'Director loan summary unavailable.'),
            ];
        }

        return [
            'available' => true,
            'opening_balance' => round((float)($result['opening_balance'] ?? 0), 2),
            'movement_in_period' => round((float)($result['movement_in_period'] ?? 0), 2),
            'closing_balance' => round((float)($result['closing_balance'] ?? 0), 2),
            'balance_direction' => (string)($result['balance_direction'] ?? ''),
            'balance_direction_label' => (string)($result['balance_direction_label'] ?? ''),
        ];
    }

    public function unpaidExpenseSummary(int $companyId, int $accountingPeriodId, string $periodEnd = ''): array {
        if (!$this->tableExists('expense_claims')) {
            return [
                'available' => false,
                'unpaid_count' => 0,
                'outstanding_amount' => 0.0,
            ];
        }

        $expenseClaims = $this->expenseClaimService ?? new \eel_accounts\Service\ExpenseClaimService();
        $filters = $accountingPeriodId > 0
            ? ['accounting_period_id' => $accountingPeriodId]
            : ['accounting_period_end' => $periodEnd];
        $unpaidCount = 0;
        $outstandingAmount = 0.0;

        foreach ($expenseClaims->fetchStatisticsClaimantBalances($companyId, $filters) as $claimant) {
            $carriedForward = round((float)($claimant['carried_forward'] ?? 0), 2);
            if ($carriedForward <= 0.004) {
                continue;
            }

            $unpaidCount++;
            $outstandingAmount += $carriedForward;
        }

        return [
            'available' => true,
            'unpaid_count' => $unpaidCount,
            'outstanding_amount' => round($outstandingAmount, 2),
        ];
    }

    public function duplicateRepaymentRiskSummary(int $companyId, string $periodStart, string $periodEnd): array {
        if (!$this->tableExists('expense_claim_payment_links')) {
            return [
                'available' => false,
                'risk_count' => 0,
            ];
        }

        return [
            'available' => true,
            'risk_count' => (int)\InterfaceDB::fetchColumn( 'SELECT COUNT(*)
             FROM (
                SELECT t.id
                FROM expense_claim_payment_links l
                INNER JOIN transactions t ON t.id = l.transaction_id
                WHERE t.company_id = :company_id
                  AND t.txn_date BETWEEN :period_start AND :period_end
                GROUP BY t.id
                HAVING COUNT(*) > 1 OR SUM(l.linked_amount) > ABS(MAX(t.amount))
             ) duplicate_risk', [
            'company_id' => $companyId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]),
        ];
    }

    public function financialStatementsSummary(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd, ?array $trialBalance = null): array {
        $trialBalance ??= $this->trialBalanceSummary($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $balances = $this->fetchBalanceSheetMetricValues($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $profitAndLoss = $this->profitAndLossSummary($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $openingEquity = $this->equityBalanceUntilDate($companyId, $periodStart, true);
        $closingEquity = $this->equityBalanceUntilDate($companyId, $periodEnd, false);
        $expectedClosingEquity = round($openingEquity + (float)($profitAndLoss['profit_before_tax'] ?? 0), 2);

        return [
            'trial_balance' => $trialBalance,
            'profit_and_loss' => $profitAndLoss,
            'balance_sheet' => [
                'generated' => $trialBalance['exists'],
                'metrics' => $balances,
            ],
            'retained_earnings' => [
                'opening_equity' => round($openingEquity, 2),
                'closing_equity' => round($closingEquity, 2),
                'expected_closing_equity' => $expectedClosingEquity,
                'unexplained_movement' => round($closingEquity - $expectedClosingEquity, 2),
            ],
            'fixed_asset_hint_count' => $this->likelyCapitalPurchaseCount($companyId, $accountingPeriodId, $periodStart, $periodEnd),
        ];
    }

    public function fetchBalanceSheetMetricValues(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): array {
        $metrics = (new \eel_accounts\Service\IxbrlBalanceSheetMetricsService())->fetchClosingMetricsForPeriod($companyId, $periodStart, $periodEnd, $accountingPeriodId);
        $buckets = (array)($metrics['buckets'] ?? []);

        return [
            'fixed_assets' => round((float)($buckets['fixed_assets'] ?? 0), 2),
            'current_assets' => round((float)($buckets['current_assets'] ?? 0), 2),
            'creditors_within_one_year' => round((float)($buckets['creditors_within_one_year'] ?? 0), 2),
            'creditors_after_more_than_one_year' => round((float)($buckets['creditors_after_more_than_one_year'] ?? 0), 2),
            'net_current_assets_liabilities' => round((float)($buckets['net_current_assets_liabilities'] ?? 0), 2),
            'total_assets_less_current_liabilities' => round((float)($buckets['total_assets_less_current_liabilities'] ?? 0), 2),
            'net_assets_liabilities' => round((float)($buckets['net_assets_liabilities'] ?? 0), 2),
            'equity_capital_reserves' => round((float)($buckets['equity_capital_reserves'] ?? 0), 2),
        ];
    }

    public function profitAndLossSummary(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): array {
        $rows = \InterfaceDB::fetchAll( 'SELECT na.id,
                    na.code,
                    na.name,
                    na.account_type,
                    COALESCE(na.tax_treatment, \'allowable\') AS tax_treatment,
                    SUM(COALESCE(jl.debit, 0)) AS total_debit,
                    SUM(COALESCE(jl.credit, 0)) AS total_credit
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             INNER JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             LEFT JOIN journal_entry_metadata jem_close
               ON jem_close.journal_id = j.id
              AND jem_close.journal_tag = :close_journal_tag
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
               AND j.is_posted = 1
               AND j.journal_date BETWEEN :period_start AND :period_end
               AND COALESCE(j.source_type, \'\') <> :asset_depreciation_source_type
               AND jem_close.id IS NULL
               AND (na.account_type = :income_type OR na.account_type = :cost_type OR na.account_type = :expense_type)
             GROUP BY na.id, na.code, na.name, na.account_type, na.tax_treatment', [
            'close_journal_tag' => \eel_accounts\Service\RetainedEarningsCloseService::JOURNAL_TAG,
            'asset_depreciation_source_type' => \eel_accounts\Service\YearEndClosePreviewService::ASSET_DEPRECIATION_SOURCE_TYPE,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'income_type' => 'income',
            'cost_type' => 'cost_of_sales',
            'expense_type' => 'expense',
        ]);

        $income = 0.0;
        $expenses = 0.0;
        $disallowableAddBacks = 0.0;
        $capitalAddBacks = 0.0;
        $otherTreatmentCount = 0;
        $unknownTreatmentCount = 0;
        $taxTreatmentRules = new \eel_accounts\Service\CorporationTaxTreatmentRuleService();

        foreach ($rows as $row) {
            $accountType = (string)($row['account_type'] ?? '');
            $treatmentResult = $taxTreatmentRules->resolveTaxTreatment($row, $periodStart, $periodEnd);
            $taxTreatment = trim((string)($treatmentResult['tax_treatment'] ?? ''));
            $debit = (float)($row['total_debit'] ?? 0);
            $credit = (float)($row['total_credit'] ?? 0);

            if ($accountType === 'income') {
                $income += round($credit - $debit, 2);
            } else {
                $amount = round($debit - $credit, 2);
                $expenses += $amount;

                if ($taxTreatment === 'disallowable') {
                    $disallowableAddBacks += abs($amount);
                } elseif ($taxTreatment === 'capital') {
                    $capitalAddBacks += abs($amount);
                } elseif ($taxTreatment === 'other') {
                    $otherTreatmentCount++;
                } elseif (!in_array($taxTreatment, ['allowable', 'disallowable', 'capital', 'other'], true)) {
                    $unknownTreatmentCount++;
                }
            }
        }
        $depreciationExpense = (new \eel_accounts\Service\YearEndClosePreviewService())->depreciationExpenseForPeriod($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $expenses = round($expenses + $depreciationExpense, 2);

        return [
            'income' => round($income, 2),
            'expenses' => round($expenses, 2),
            'profit_before_tax' => round($income - $expenses, 2),
            'disallowable_add_backs' => round($disallowableAddBacks, 2),
            'capital_add_backs' => round($capitalAddBacks, 2),
            'depreciation_expense' => round($depreciationExpense, 2),
            'other_treatment_count' => $otherTreatmentCount,
            'unknown_treatment_count' => $unknownTreatmentCount,
        ];
    }

    private function countTransactions(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): int {
        return (int)\InterfaceDB::fetchColumn( 'SELECT COUNT(*)
             FROM transactions
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND txn_date BETWEEN :period_start AND :period_end', [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);
    }

    private function unpostedPostableTransactionsCount(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): int
    {
        if (!$this->tableExists('transactions') || !$this->tableExists('journals')) {
            return 0;
        }

        $noPostExclusionSql = $this->tableExists('transaction_inter_ac_marker')
            ? 'AND NOT EXISTS (
                    SELECT 1
                    FROM transaction_inter_ac_marker tiam
                    WHERE tiam.matched_transaction_id = t.id
               )'
            : '';

        return (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM transactions t
             WHERE t.company_id = :company_id
               AND t.accounting_period_id = :accounting_period_id
               AND t.txn_date BETWEEN :period_start AND :period_end
               AND (
                    (t.nominal_account_id IS NOT NULL AND t.category_status IN (:auto_status, :manual_status))
                    OR (t.transfer_account_id IS NOT NULL AND t.is_internal_transfer = 1 AND t.category_status = :transfer_status)
               )
               AND NOT EXISTS (
                    SELECT 1
                    FROM journals j
                    WHERE j.company_id = t.company_id
                      AND j.accounting_period_id = t.accounting_period_id
                      AND j.source_type = :source_type
                      AND j.source_ref = CONCAT(:source_ref_prefix, t.id)
                      AND j.is_posted = 1
               )
               ' . $noPostExclusionSql,
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'auto_status' => 'auto',
                'manual_status' => 'manual',
                'transfer_status' => 'manual',
                'source_type' => 'bank_csv',
                'source_ref_prefix' => 'transaction:',
            ]
        );
    }

    private function unpostedExpenseClaimsCount(int $companyId, int $accountingPeriodId): int
    {
        if (!$this->tableExists('expense_claims') || !$this->tableExists('expense_claim_lines') || !$this->tableExists('journals')) {
            return 0;
        }

        return (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM expense_claims ec
             WHERE ec.company_id = :company_id
               AND ec.accounting_period_id = :accounting_period_id
               AND NOT (
                    ec.status = :posted_status
                    AND ec.posted_journal_id IS NOT NULL
                    AND EXISTS (
                        SELECT 1
                        FROM journals j
                        WHERE j.id = ec.posted_journal_id
                          AND j.company_id = ec.company_id
                          AND j.accounting_period_id = ec.accounting_period_id
                          AND j.is_posted = 1
                    )
               )
               AND (
                    ec.no_lines_confirmed_at IS NULL
                    OR EXISTS (
                        SELECT 1
                        FROM expense_claim_lines ecl
                        WHERE ecl.expense_claim_id = ec.id
                    )
               )',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'posted_status' => 'posted',
            ]
        );
    }

    private function unpostedAssetsCount(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): int
    {
        if (!$this->tableExists('asset_register') || !$this->tableExists('journals')) {
            return 0;
        }

        return (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM asset_register ar
             WHERE ar.company_id = :company_id
               AND ar.purchase_date BETWEEN :period_start AND :period_end
               AND NOT EXISTS (
                    SELECT 1
                    FROM journals j
                    WHERE j.id = ar.linked_journal_id
                      AND j.company_id = ar.company_id
                      AND j.accounting_period_id = :accounting_period_id
                      AND j.is_posted = 1
               )',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]
        );
    }

    private function countJournalsBySource(int $companyId, int $accountingPeriodId, array $sourceTypes): int {
        if ($sourceTypes === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($sourceTypes), '?'));
        $stmt = \InterfaceDB::prepare(
            'SELECT COUNT(*)
             FROM journals
             WHERE company_id = ?
               AND accounting_period_id = ?
               AND is_posted = 1
               AND source_type IN (' . $placeholders . ')'
        );
        $stmt->execute(array_merge([$companyId, $accountingPeriodId], $sourceTypes));

        return (int)$stmt->fetchColumn();
    }

    private function fetchUploadCountsByMonth(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): array {
        $result = [];
        foreach (\InterfaceDB::fetchAll( 'SELECT DATE_FORMAT(COALESCE(date_range_start, statement_month), \'%Y-%m-01\') AS month_key,
                    COUNT(*) AS upload_count
             FROM statement_uploads
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND COALESCE(date_range_start, statement_month, date_range_end) BETWEEN :period_start AND :period_end
             GROUP BY DATE_FORMAT(COALESCE(date_range_start, statement_month), \'%Y-%m-01\')', [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]) as $row) {
            $result[(string)$row['month_key']] = (int)($row['upload_count'] ?? 0);
        }

        return $result;
    }

    private function fetchTransactionCountsByMonth(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): array {
        $result = [];
        $noPostPredicate = $this->tableExists('transaction_inter_ac_marker')
            ? "EXISTS (
                   SELECT 1
                   FROM transaction_inter_ac_marker tiam
                   WHERE tiam.matched_transaction_id = transactions.id
               )"
            : '0 = 1';
        foreach (\InterfaceDB::fetchAll( 'SELECT DATE_FORMAT(txn_date, \'%Y-%m-01\') AS month_key,
                    COUNT(*) AS transaction_count,
                    SUM(CASE WHEN NOT (' . $noPostPredicate . ') AND (category_status = :category_status OR nominal_account_id IS NULL) THEN 1 ELSE 0 END) AS uncategorised_count
             FROM transactions
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND txn_date BETWEEN :period_start AND :period_end
             GROUP BY DATE_FORMAT(txn_date, \'%Y-%m-01\')', [
            'category_status' => 'uncategorised',
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]) as $row) {
            $result[(string)$row['month_key']] = [
                'transactions' => (int)($row['transaction_count'] ?? 0),
                'uncategorised' => (int)($row['uncategorised_count'] ?? 0),
            ];
        }

        return $result;
    }

    private function fetchPostedJournalCountsByMonth(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): array {
        $result = [];
        foreach (\InterfaceDB::fetchAll( 'SELECT DATE_FORMAT(journal_date, \'%Y-%m-01\') AS month_key,
                    COUNT(*) AS journal_count
             FROM journals
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND is_posted = 1
               AND journal_date BETWEEN :period_start AND :period_end
             GROUP BY DATE_FORMAT(journal_date, \'%Y-%m-01\')', [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]) as $row) {
            $result[(string)$row['month_key']] = (int)($row['journal_count'] ?? 0);
        }

        return $result;
    }

    private function fetchSuspenseCountsByMonth(int $companyId, int $accountingPeriodId, int $nominalAccountId): array {
        $result = [];
        foreach (\InterfaceDB::fetchAll( 'SELECT DATE_FORMAT(j.journal_date, \'%Y-%m-01\') AS month_key,
                    COUNT(*) AS suspense_count
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
               AND j.is_posted = 1
               AND jl.nominal_account_id = :nominal_account_id
             GROUP BY DATE_FORMAT(j.journal_date, \'%Y-%m-01\')', [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'nominal_account_id' => $nominalAccountId,
        ]) as $row) {
            $result[(string)$row['month_key']] = (int)($row['suspense_count'] ?? 0);
        }

        return $result;
    }

    private function fetchTrialBalanceLines(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): array {
        return \InterfaceDB::fetchAll( 'SELECT jl.nominal_account_id,
                    COALESCE(na.code, \'\') AS nominal_code,
                    COALESCE(na.name, \'\') AS nominal_name,
                    COALESCE(SUM(jl.debit), 0) AS debit,
                    COALESCE(SUM(jl.credit), 0) AS credit
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             LEFT JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
               AND j.is_posted = 1
               AND j.journal_date BETWEEN :period_start AND :period_end
             GROUP BY jl.nominal_account_id, na.code, na.name
             ORDER BY na.code ASC, na.name ASC, jl.nominal_account_id ASC', [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);
    }

    private function findSuspenseNominalId(int $companyId): int {
        $settings = $this->fetchCompanySettings($companyId);
        $uncategorisedNominalId = (int)($settings['uncategorised_nominal_id'] ?? 0);

        return (int)(\InterfaceDB::fetchColumn( 'SELECT id
             FROM nominal_accounts
             WHERE is_active = 1
               AND (
                    LOWER(name) LIKE :suspense_name
                    OR code = :suspense_code
                    OR id = :uncategorised_nominal_id
               )
             ORDER BY CASE WHEN LOWER(name) LIKE :prefer_suspense THEN 0 ELSE 1 END, sort_order ASC, id ASC
             LIMIT 1', [
            'suspense_name' => '%suspense%',
            'suspense_code' => '9990',
            'uncategorised_nominal_id' => $uncategorisedNominalId,
            'prefer_suspense' => '%suspense%',
        ]) ?: 0);
    }

    private function equityBalanceUntilDate(int $companyId, string $date, bool $exclusive): float {
        $operator = $exclusive ? '<' : '<=';
        return round((float)\InterfaceDB::fetchColumn( 'SELECT COALESCE(SUM(COALESCE(jl.credit, 0) - COALESCE(jl.debit, 0)), 0)
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             INNER JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             WHERE j.company_id = :company_id
               AND j.is_posted = 1
               AND j.journal_date ' . $operator . ' :date
               AND na.account_type = :account_type', [
            'company_id' => $companyId,
            'date' => $date,
            'account_type' => 'equity',
        ]), 2);
    }

    private function likelyCapitalPurchaseCount(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): int {
        return (int)\InterfaceDB::fetchColumn( 'SELECT COUNT(*)
             FROM transactions t
             LEFT JOIN nominal_accounts na ON na.id = t.nominal_account_id
             WHERE t.company_id = :company_id
               AND t.accounting_period_id = :accounting_period_id
               AND t.txn_date BETWEEN :period_start AND :period_end
               AND (
                    COALESCE(na.tax_treatment, \'\') = :capital_treatment
                    OR LOWER(COALESCE(t.description, \'\')) LIKE :capital_hint_one
                    OR LOWER(COALESCE(t.description, \'\')) LIKE :capital_hint_two
                    OR LOWER(COALESCE(t.description, \'\')) LIKE :capital_hint_three
               )', [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'capital_treatment' => 'capital',
            'capital_hint_one' => '%tool%',
            'capital_hint_two' => '%equipment%',
            'capital_hint_three' => '%vehicle%',
        ]);
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


