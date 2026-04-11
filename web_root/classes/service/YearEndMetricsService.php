<?php
declare(strict_types=1);

final class YearEndMetricsService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?BankingReconciliationService $bankingReconciliationService = null,
        private readonly ?DirectorLoanService $directorLoanService = null,
        private readonly ?ExpenseClaimService $expenseClaimService = null,
    ) {
    }

    public function fetchTaxYear(int $companyId, int $taxYearId): ?array {
        if ($companyId <= 0 || $taxYearId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, company_id, label, period_start, period_end
             FROM tax_years
             WHERE company_id = :company_id
               AND id = :id
             LIMIT 1'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'id' => $taxYearId,
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function fetchTaxYears(int $companyId): array {
        if ($companyId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, label, period_start, period_end
             FROM tax_years
             WHERE company_id = :company_id
             ORDER BY period_start DESC, id DESC'
        );
        $stmt->execute(['company_id' => $companyId]);

        return $stmt->fetchAll() ?: [];
    }

    public function resolveLatestOpenTaxYearId(int $companyId): int {
        $stmt = $this->pdo->prepare(
            'SELECT ty.id
             FROM tax_years ty
             LEFT JOIN year_end_reviews yer
               ON yer.company_id = ty.company_id
              AND yer.tax_year_id = ty.id
             WHERE ty.company_id = :company_id
               AND COALESCE(yer.is_locked, 0) = 0
             ORDER BY ty.period_start DESC, ty.id DESC
             LIMIT 1'
        );

        try {
            $stmt->execute(['company_id' => $companyId]);
            $value = $stmt->fetchColumn();
            if ($value !== false) {
                return (int)$value;
            }
        } catch (Throwable) {
        }

        $stmt = $this->pdo->prepare(
            'SELECT id
             FROM tax_years
             WHERE company_id = :company_id
             ORDER BY period_start DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute(['company_id' => $companyId]);

        return (int)($stmt->fetchColumn() ?: 0);
    }

    public function fetchCompanySummary(int $companyId): ?array {
        if ($companyId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, company_name, company_number
             FROM companies
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $companyId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function fetchCompanySettings(int $companyId): array {
        if ($companyId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT setting, value
             FROM company_settings
             WHERE company_id = :company_id'
        );
        $stmt->execute(['company_id' => $companyId]);

        $settings = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $settings[(string)$row['setting']] = (string)($row['value'] ?? '');
        }

        return $settings;
    }

    public function buildMonthTiles(int $companyId, int $taxYearId, string $periodStart, string $periodEnd): array {
        $coverageService = new AccountingPeriodCoverageService();
        $coverage = $coverageService->summarise($this->pdo, $companyId, $taxYearId, $periodStart, $periodEnd);
        $uploadsByMonth = $this->fetchUploadCountsByMonth($companyId, $taxYearId, $periodStart, $periodEnd);
        $transactionByMonth = $this->fetchTransactionCountsByMonth($companyId, $taxYearId, $periodStart, $periodEnd);
        $suspenseNominalId = $this->findSuspenseNominalId($companyId);
        $suspenseByMonth = $suspenseNominalId > 0
            ? $this->fetchSuspenseCountsByMonth($companyId, $taxYearId, $suspenseNominalId)
            : [];

        $tiles = [];
        foreach (($coverage['months'] ?? []) as $month) {
            $monthKey = (string)($month['month_key'] ?? '');
            $uploadCount = (int)($uploadsByMonth[$monthKey] ?? 0);
            $txnSummary = $transactionByMonth[$monthKey] ?? ['transactions' => 0, 'uncategorised' => 0];
            $txnCount = (int)($txnSummary['transactions'] ?? 0);
            $uncategorisedCount = (int)($txnSummary['uncategorised'] ?? 0);
            $suspenseCount = (int)($suspenseByMonth[$monthKey] ?? 0);

            if ($txnCount === 0 && $uploadCount === 0) {
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
                'uncategorised_count' => $uncategorisedCount,
                'suspense_count' => $suspenseCount,
            ];
        }

        return $tiles;
    }

    public function sourceDataSummary(int $companyId, int $taxYearId, string $periodStart, string $periodEnd): array {
        return [
            'bank_transactions' => $this->countTransactions($companyId, $taxYearId, $periodStart, $periodEnd),
            'manual_journals' => $this->countJournalsBySource($companyId, $taxYearId, ['manual']),
            'director_loan_journals' => $this->countJournalsBySource($companyId, $taxYearId, ['director_loan_register']),
            'expense_journals' => $this->countJournalsBySource($companyId, $taxYearId, ['expense_register', 'expense_claim_post', 'expense_claim_payment_link']),
        ];
    }

    public function uncategorisedTransactionsCount(int $companyId, int $taxYearId, string $periodStart, string $periodEnd): int {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM transactions
             WHERE company_id = :company_id
               AND tax_year_id = :tax_year_id
               AND txn_date BETWEEN :period_start AND :period_end
               AND (category_status = :category_status OR nominal_account_id IS NULL)'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'category_status' => 'uncategorised',
        ]);

        return (int)$stmt->fetchColumn();
    }

    public function autoCategorisedPendingReviewCount(int $companyId, int $taxYearId, string $periodStart, string $periodEnd): int {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM transactions
             WHERE company_id = :company_id
               AND tax_year_id = :tax_year_id
               AND txn_date BETWEEN :period_start AND :period_end
               AND category_status = :category_status'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'category_status' => 'auto',
        ]);

        return (int)$stmt->fetchColumn();
    }

    public function suspenseSummary(int $companyId, int $taxYearId, string $periodEnd): array {
        $nominalId = $this->findSuspenseNominalId($companyId);
        if ($nominalId <= 0) {
            return [
                'has_nominal' => false,
                'nominal_account_id' => 0,
                'closing_balance' => 0.0,
                'entry_count' => 0,
            ];
        }

        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(COALESCE(jl.debit, 0) - COALESCE(jl.credit, 0)), 0) AS closing_balance,
                    COUNT(*) AS entry_count
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE j.company_id = :company_id
               AND j.is_posted = 1
               AND j.journal_date <= :period_end
               AND jl.nominal_account_id = :nominal_account_id'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'period_end' => $periodEnd,
            'nominal_account_id' => $nominalId,
        ]);
        $row = $stmt->fetch() ?: [];

        return [
            'has_nominal' => true,
            'nominal_account_id' => $nominalId,
            'closing_balance' => round((float)($row['closing_balance'] ?? 0), 2),
            'entry_count' => (int)($row['entry_count'] ?? 0),
        ];
    }

    public function trialBalanceSummary(int $companyId, int $taxYearId, string $periodStart, string $periodEnd): array {
        $lines = $this->fetchTrialBalanceLines($companyId, $taxYearId, $periodStart, $periodEnd);
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

    public function journalIntegritySummary(int $companyId, int $taxYearId): array {
        $summary = [
            'line_count_failures' => 0,
            'unbalanced_journals' => 0,
            'missing_nominal_lines' => 0,
            'issues' => [],
        ];

        $stmt = $this->pdo->prepare(
            'SELECT j.id,
                    j.description,
                    COUNT(jl.id) AS line_count,
                    COALESCE(SUM(jl.debit), 0) AS total_debit,
                    COALESCE(SUM(jl.credit), 0) AS total_credit,
                    SUM(CASE WHEN na.id IS NULL OR COALESCE(na.is_active, 0) <> 1 THEN 1 ELSE 0 END) AS missing_nominal_lines
             FROM journals j
             LEFT JOIN journal_lines jl ON jl.journal_id = j.id
             LEFT JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             WHERE j.company_id = :company_id
               AND j.tax_year_id = :tax_year_id
             GROUP BY j.id, j.description
             ORDER BY j.journal_date ASC, j.id ASC'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
        ]);

        foreach ($stmt->fetchAll() ?: [] as $row) {
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

    public function statementContinuitySummary(int $companyId, int $taxYearId, int $bankNominalId): array {
        $service = $this->bankingReconciliationService ?? new BankingReconciliationService($this->pdo);
        $panels = $service->fetchBankAccountPanels($companyId, $taxYearId, $bankNominalId);

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

    public function duplicateImportAudit(int $companyId, int $taxYearId): array {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(COALESCE(rows_duplicate, 0)), 0) AS duplicate_rows,
                    COALESCE(SUM(CASE WHEN COALESCE(rows_duplicate, 0) > 0 THEN 1 ELSE 0 END), 0) AS duplicate_files,
                    COALESCE(SUM(COALESCE(rows_duplicate_within_upload, 0)), 0) AS duplicate_within_upload,
                    COALESCE(SUM(COALESCE(rows_duplicate_existing, 0)), 0) AS duplicate_existing
             FROM statement_uploads
             WHERE company_id = :company_id
               AND tax_year_id = :tax_year_id'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
        ]);
        $row = $stmt->fetch() ?: [];

        return [
            'duplicate_rows' => (int)($row['duplicate_rows'] ?? 0),
            'duplicate_files' => (int)($row['duplicate_files'] ?? 0),
            'duplicate_within_upload' => (int)($row['duplicate_within_upload'] ?? 0),
            'duplicate_existing' => (int)($row['duplicate_existing'] ?? 0),
        ];
    }

    public function strandedCommittedSourceRowsCount(int $companyId, int $taxYearId): int {
        if (!$this->tableExists('statement_import_rows')) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM statement_import_rows sir
             INNER JOIN statement_uploads su ON su.id = sir.upload_id
             LEFT JOIN transactions t ON t.id = sir.committed_transaction_id
             LEFT JOIN journals j
               ON j.source_type = :source_type
              AND j.source_ref = CONCAT(:source_prefix, sir.committed_transaction_id)
             WHERE su.company_id = :company_id
               AND su.tax_year_id = :tax_year_id
               AND sir.committed_transaction_id IS NOT NULL
               AND (
                    t.id IS NULL
                    OR (
                        t.nominal_account_id IS NOT NULL
                        AND (t.category_status = :auto_status OR t.category_status = :manual_status)
                        AND j.id IS NULL
                    )
               )'
        );
        $stmt->execute([
            'source_type' => 'bank_csv',
            'source_prefix' => 'transaction:',
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
            'auto_status' => 'auto',
            'manual_status' => 'manual',
        ]);

        return (int)$stmt->fetchColumn();
    }

    public function directorLoanSummary(int $companyId, int $taxYearId): array {
        $service = $this->directorLoanService ?? new DirectorLoanService($this->pdo);
        $result = $service->fetchStatement($companyId, $taxYearId);
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

    public function unpaidExpenseSummary(int $companyId, string $periodEnd): array {
        if (!$this->tableExists('expense_claims')) {
            return [
                'available' => false,
                'unpaid_count' => 0,
                'outstanding_amount' => 0.0,
            ];
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS unpaid_count,
                    COALESCE(SUM(carried_forward_amount), 0) AS outstanding_amount
             FROM expense_claims
             WHERE company_id = :company_id
               AND period_end <= :period_end
               AND COALESCE(carried_forward_amount, 0) > 0.004'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'period_end' => $periodEnd,
        ]);
        $row = $stmt->fetch() ?: [];

        return [
            'available' => true,
            'unpaid_count' => (int)($row['unpaid_count'] ?? 0),
            'outstanding_amount' => round((float)($row['outstanding_amount'] ?? 0), 2),
        ];
    }

    public function duplicateRepaymentRiskSummary(int $companyId, string $periodStart, string $periodEnd): array {
        if (!$this->tableExists('expense_claim_payment_links')) {
            return [
                'available' => false,
                'risk_count' => 0,
            ];
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM (
                SELECT t.id
                FROM expense_claim_payment_links l
                INNER JOIN transactions t ON t.id = l.transaction_id
                WHERE t.company_id = :company_id
                  AND t.txn_date BETWEEN :period_start AND :period_end
                GROUP BY t.id
                HAVING COUNT(*) > 1 OR SUM(l.linked_amount) > ABS(MAX(t.amount))
             ) duplicate_risk'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);

        return [
            'available' => true,
            'risk_count' => (int)$stmt->fetchColumn(),
        ];
    }

    public function financialStatementsSummary(int $companyId, int $taxYearId, string $periodStart, string $periodEnd): array {
        $trialBalance = $this->trialBalanceSummary($companyId, $taxYearId, $periodStart, $periodEnd);
        $balances = $this->fetchBalanceSheetMetricValues($companyId, $taxYearId, $periodStart, $periodEnd);
        $profitAndLoss = $this->profitAndLossSummary($companyId, $taxYearId, $periodStart, $periodEnd);
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
            'fixed_asset_hint_count' => $this->likelyCapitalPurchaseCount($companyId, $taxYearId, $periodStart, $periodEnd),
        ];
    }

    public function fetchBalanceSheetMetricValues(int $companyId, int $taxYearId, string $periodStart, string $periodEnd): array {
        $stmt = $this->pdo->prepare(
            'SELECT na.account_type,
                    COALESCE(nas.code, \'\') AS subtype_code,
                    COALESCE(SUM(COALESCE(jl.debit, 0) - COALESCE(jl.credit, 0)), 0) AS movement
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             INNER JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             WHERE j.company_id = :company_id
               AND j.tax_year_id = :tax_year_id
               AND j.is_posted = 1
               AND j.journal_date BETWEEN :period_start AND :period_end
             GROUP BY na.account_type, nas.code'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);

        $fixedAssets = 0.0;
        $currentAssets = 0.0;
        $currentLiabilities = 0.0;
        $nonCurrentLiabilities = 0.0;
        $equity = 0.0;

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $accountType = (string)($row['account_type'] ?? '');
            $subtypeCode = (string)($row['subtype_code'] ?? '');
            $movement = round((float)($row['movement'] ?? 0), 2);

            if ($accountType === 'asset') {
                if (in_array($subtypeCode, ['bank', 'trade_debtor', 'director_loan_asset'], true) || $subtypeCode === '') {
                    $currentAssets += $movement;
                } else {
                    $fixedAssets += $movement;
                }
            } elseif ($accountType === 'liability') {
                if ($subtypeCode === 'corp_tax' || $subtypeCode === 'vat_control' || $subtypeCode === 'director_loan_liability' || $subtypeCode === '') {
                    $currentLiabilities += abs($movement);
                } else {
                    $nonCurrentLiabilities += abs($movement);
                }
            } elseif ($accountType === 'equity') {
                $equity += abs($movement);
            }
        }

        $totalAssets = round($fixedAssets + $currentAssets, 2);
        $netCurrentAssets = round($currentAssets - $currentLiabilities, 2);
        $totalAssetsLessCurrentLiabilities = round($totalAssets - $currentLiabilities, 2);
        $netAssets = round($totalAssets - $currentLiabilities - $nonCurrentLiabilities, 2);

        return [
            'fixed_assets' => round($fixedAssets, 2),
            'current_assets' => round($currentAssets, 2),
            'creditors_within_one_year' => round($currentLiabilities, 2),
            'creditors_after_more_than_one_year' => round($nonCurrentLiabilities, 2),
            'net_current_assets_liabilities' => $netCurrentAssets,
            'total_assets_less_current_liabilities' => $totalAssetsLessCurrentLiabilities,
            'net_assets_liabilities' => $netAssets,
            'equity_capital_reserves' => round($equity !== 0.0 ? $equity : $netAssets, 2),
        ];
    }

    public function profitAndLossSummary(int $companyId, int $taxYearId, string $periodStart, string $periodEnd): array {
        $stmt = $this->pdo->prepare(
            'SELECT na.account_type,
                    COALESCE(na.tax_treatment, \'allowable\') AS tax_treatment,
                    SUM(COALESCE(jl.debit, 0)) AS total_debit,
                    SUM(COALESCE(jl.credit, 0)) AS total_credit
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             INNER JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             WHERE j.company_id = :company_id
               AND j.tax_year_id = :tax_year_id
               AND j.is_posted = 1
               AND j.journal_date BETWEEN :period_start AND :period_end
               AND (na.account_type = :income_type OR na.account_type = :cost_type OR na.account_type = :expense_type)
             GROUP BY na.account_type, na.tax_treatment'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
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

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $accountType = (string)($row['account_type'] ?? '');
            $taxTreatment = trim((string)($row['tax_treatment'] ?? ''));
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

        return [
            'income' => round($income, 2),
            'expenses' => round($expenses, 2),
            'profit_before_tax' => round($income - $expenses, 2),
            'disallowable_add_backs' => round($disallowableAddBacks, 2),
            'capital_add_backs' => round($capitalAddBacks, 2),
            'other_treatment_count' => $otherTreatmentCount,
            'unknown_treatment_count' => $unknownTreatmentCount,
        ];
    }

    private function countTransactions(int $companyId, int $taxYearId, string $periodStart, string $periodEnd): int {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM transactions
             WHERE company_id = :company_id
               AND tax_year_id = :tax_year_id
               AND txn_date BETWEEN :period_start AND :period_end'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);

        return (int)$stmt->fetchColumn();
    }

    private function countJournalsBySource(int $companyId, int $taxYearId, array $sourceTypes): int {
        if ($sourceTypes === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($sourceTypes), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM journals
             WHERE company_id = ?
               AND tax_year_id = ?
               AND is_posted = 1
               AND source_type IN (' . $placeholders . ')'
        );
        $stmt->execute(array_merge([$companyId, $taxYearId], $sourceTypes));

        return (int)$stmt->fetchColumn();
    }

    private function fetchUploadCountsByMonth(int $companyId, int $taxYearId, string $periodStart, string $periodEnd): array {
        $result = [];
        $stmt = $this->pdo->prepare(
            'SELECT DATE_FORMAT(COALESCE(date_range_start, statement_month), \'%Y-%m-01\') AS month_key,
                    COUNT(*) AS upload_count
             FROM statement_uploads
             WHERE company_id = :company_id
               AND tax_year_id = :tax_year_id
               AND COALESCE(date_range_start, statement_month, date_range_end) BETWEEN :period_start AND :period_end
             GROUP BY DATE_FORMAT(COALESCE(date_range_start, statement_month), \'%Y-%m-01\')'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $result[(string)$row['month_key']] = (int)($row['upload_count'] ?? 0);
        }

        return $result;
    }

    private function fetchTransactionCountsByMonth(int $companyId, int $taxYearId, string $periodStart, string $periodEnd): array {
        $result = [];
        $stmt = $this->pdo->prepare(
            'SELECT DATE_FORMAT(txn_date, \'%Y-%m-01\') AS month_key,
                    COUNT(*) AS transaction_count,
                    SUM(CASE WHEN category_status = :category_status OR nominal_account_id IS NULL THEN 1 ELSE 0 END) AS uncategorised_count
             FROM transactions
             WHERE company_id = :company_id
               AND tax_year_id = :tax_year_id
               AND txn_date BETWEEN :period_start AND :period_end
             GROUP BY DATE_FORMAT(txn_date, \'%Y-%m-01\')'
        );
        $stmt->execute([
            'category_status' => 'uncategorised',
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $result[(string)$row['month_key']] = [
                'transactions' => (int)($row['transaction_count'] ?? 0),
                'uncategorised' => (int)($row['uncategorised_count'] ?? 0),
            ];
        }

        return $result;
    }

    private function fetchSuspenseCountsByMonth(int $companyId, int $taxYearId, int $nominalAccountId): array {
        $result = [];
        $stmt = $this->pdo->prepare(
            'SELECT DATE_FORMAT(j.journal_date, \'%Y-%m-01\') AS month_key,
                    COUNT(*) AS suspense_count
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE j.company_id = :company_id
               AND j.tax_year_id = :tax_year_id
               AND j.is_posted = 1
               AND jl.nominal_account_id = :nominal_account_id
             GROUP BY DATE_FORMAT(j.journal_date, \'%Y-%m-01\')'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
            'nominal_account_id' => $nominalAccountId,
        ]);

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $result[(string)$row['month_key']] = (int)($row['suspense_count'] ?? 0);
        }

        return $result;
    }

    private function fetchTrialBalanceLines(int $companyId, int $taxYearId, string $periodStart, string $periodEnd): array {
        $stmt = $this->pdo->prepare(
            'SELECT jl.nominal_account_id,
                    COALESCE(na.code, \'\') AS nominal_code,
                    COALESCE(na.name, \'\') AS nominal_name,
                    COALESCE(SUM(jl.debit), 0) AS debit,
                    COALESCE(SUM(jl.credit), 0) AS credit
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             LEFT JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             WHERE j.company_id = :company_id
               AND j.tax_year_id = :tax_year_id
               AND j.is_posted = 1
               AND j.journal_date BETWEEN :period_start AND :period_end
             GROUP BY jl.nominal_account_id, na.code, na.name
             ORDER BY na.code ASC, na.name ASC, jl.nominal_account_id ASC'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);

        return $stmt->fetchAll() ?: [];
    }

    private function findSuspenseNominalId(int $companyId): int {
        $settings = $this->fetchCompanySettings($companyId);
        $uncategorisedNominalId = (int)($settings['uncategorised_nominal_id'] ?? 0);

        $stmt = $this->pdo->prepare(
            'SELECT id
             FROM nominal_accounts
             WHERE is_active = 1
               AND (
                    LOWER(name) LIKE :suspense_name
                    OR code = :suspense_code
                    OR id = :uncategorised_nominal_id
               )
             ORDER BY CASE WHEN LOWER(name) LIKE :prefer_suspense THEN 0 ELSE 1 END, sort_order ASC, id ASC
             LIMIT 1'
        );
        $stmt->execute([
            'suspense_name' => '%suspense%',
            'suspense_code' => '9990',
            'uncategorised_nominal_id' => $uncategorisedNominalId,
            'prefer_suspense' => '%suspense%',
        ]);

        return (int)($stmt->fetchColumn() ?: 0);
    }

    private function equityBalanceUntilDate(int $companyId, string $date, bool $exclusive): float {
        $operator = $exclusive ? '<' : '<=';
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(COALESCE(jl.credit, 0) - COALESCE(jl.debit, 0)), 0)
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             INNER JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             WHERE j.company_id = :company_id
               AND j.is_posted = 1
               AND j.journal_date ' . $operator . ' :date
               AND na.account_type = :account_type'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'date' => $date,
            'account_type' => 'equity',
        ]);

        return round((float)$stmt->fetchColumn(), 2);
    }

    private function likelyCapitalPurchaseCount(int $companyId, int $taxYearId, string $periodStart, string $periodEnd): int {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM transactions t
             LEFT JOIN nominal_accounts na ON na.id = t.nominal_account_id
             WHERE t.company_id = :company_id
               AND t.tax_year_id = :tax_year_id
               AND t.txn_date BETWEEN :period_start AND :period_end
               AND (
                    COALESCE(na.tax_treatment, \'\') = :capital_treatment
                    OR LOWER(COALESCE(t.description, \'\')) LIKE :capital_hint_one
                    OR LOWER(COALESCE(t.description, \'\')) LIKE :capital_hint_two
                    OR LOWER(COALESCE(t.description, \'\')) LIKE :capital_hint_three
               )'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'capital_treatment' => 'capital',
            'capital_hint_one' => '%tool%',
            'capital_hint_two' => '%equipment%',
            'capital_hint_three' => '%vehicle%',
        ]);

        return (int)$stmt->fetchColumn();
    }

    private function tableExists(string $table): bool {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        try {
            $stmt = $this->pdo->query('SELECT 1 FROM ' . $table . ' WHERE 1 = 0');
            $cache[$table] = $stmt !== false;
        } catch (Throwable) {
            $cache[$table] = false;
        }

        return $cache[$table];
    }
}
