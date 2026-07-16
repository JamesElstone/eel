<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class ProfitLossService
{
    private readonly PreTaxProfitLossService $preTaxService;

    public function __construct(?PreTaxProfitLossService $preTaxService = null)
    {
        $this->preTaxService = $preTaxService ?? new PreTaxProfitLossService();
    }

    public function getProfitLossSummary(int $companyId, int $accountingPeriodId, ?string $asAtDate = null): array
    {
        $accountingPeriod = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null) {
            return $this->emptySummary('Select a company and accounting period before reviewing Profit & Loss.');
        }

        try {
            $preTaxService = $this->preTaxService;
            $totals = $preTaxService->calculate($companyId, $accountingPeriodId, $asAtDate);
        } catch (\Throwable $exception) {
            return $this->emptySummary($exception->getMessage());
        }
        $incomeTotal = round((float)$totals['income_total'], 2);
        $costOfSalesTotal = round((float)$totals['cost_of_sales_total'], 2);
        $operatingExpenseTotal = round((float)$totals['operating_expense_total'], 2);
        $postedCorporationTaxCharge = round((float)$totals['posted_corporation_tax_charge'], 2);
        $expenseTotal = round($operatingExpenseTotal + $postedCorporationTaxCharge, 2);
        $grossProfit = round((float)$totals['gross_profit'], 2);
        $profitBeforeTax = round((float)$totals['profit_before_tax'], 2);
        $netProfit = round($profitBeforeTax - $postedCorporationTaxCharge, 2);
        $scope = $totals['scope'] ?? null;
        $effectiveEnd = $scope instanceof PeriodLedgerScope ? $scope->asAtDate : (string)$accountingPeriod['period_end'];
        $provision = $this->corporationTaxProvisionPosition($companyId, $accountingPeriodId, $preTaxService, $accountingPeriod, $effectiveEnd, $totals);
        $estimatedCorporationTax = !empty($provision['available'])
            ? round((float)($provision['estimated_corporation_tax'] ?? 0), 2)
            : $postedCorporationTaxCharge;
        $unpostedCorporationTaxAdjustment = !empty($provision['available'])
            ? round((float)($provision['unposted_corporation_tax_adjustment'] ?? 0), 2)
            : 0.0;
        $profitAfterEstimatedTax = round($profitBeforeTax - $estimatedCorporationTax, 2);

        $journalCount = (int)($totals['journal_count'] ?? 0);
        $transactionCount = $this->transactionCount($companyId, $accountingPeriodId, (string)$accountingPeriod['period_start'], $effectiveEnd);

        return [
            'available' => true,
            'errors' => [],
            'period_label' => (string)($accountingPeriod['label'] ?? ''),
            'period_start' => (string)($accountingPeriod['period_start'] ?? ''),
            'period_end' => $effectiveEnd,
            'as_at_date' => $effectiveEnd,
            'journal_count' => $journalCount,
            'transaction_count' => $transactionCount,
            'income_total' => $incomeTotal,
            'cost_of_sales_total' => $costOfSalesTotal,
            'gross_profit' => $grossProfit,
            'operating_expense_total' => $operatingExpenseTotal,
            'posted_operating_expense_total' => round((float)($totals['posted_operating_expense_total'] ?? $operatingExpenseTotal), 2),
            'depreciation_expense' => round((float)($totals['depreciation_expense'] ?? 0), 2),
            'prepayment_expense_adjustment' => round((float)($totals['prepayment_expense_adjustment'] ?? 0), 2),
            'prepayment_preview_reliable' => !empty($totals['prepayment_preview_reliable']),
            'prepayment_preview_warnings' => array_values(array_unique(array_map(
                'strval',
                (array)($totals['prepayment_preview_warnings'] ?? [])
            ))),
            'depreciation_preview' => (array)($totals['depreciation_preview'] ?? []),
            'accounting_basis' => 'posted_journals_plus_year_end_close_preview',
            'expense_total' => $expenseTotal,
            'profit_before_tax' => $profitBeforeTax,
            'corporation_tax_expense_total' => $postedCorporationTaxCharge,
            'posted_corporation_tax_charge' => $postedCorporationTaxCharge,
            'estimated_corporation_tax' => $estimatedCorporationTax,
            'unposted_corporation_tax_adjustment' => $unpostedCorporationTaxAdjustment,
            'profit_after_posted_tax' => $netProfit,
            'profit_after_estimated_tax' => $profitAfterEstimatedTax,
            'corporation_tax_provision' => $provision,
            'net_profit' => $netProfit,
            'profit_margin_percent' => $incomeTotal > 0 ? round(($profitAfterEstimatedTax / $incomeTotal) * 100, 1) : 0.0,
            'has_loss' => $profitAfterEstimatedTax < 0,
            'has_accounting_loss' => $profitAfterEstimatedTax < 0,
            'has_journals' => $journalCount > 0,
            'has_transactions' => $transactionCount > 0,
        ];
    }

    public function getProfitLossBreakdown(int $companyId, int $accountingPeriodId): array
    {
        $accountingPeriod = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null) {
            return [
                'income' => [],
                'cost_of_sales' => [],
                'expense' => [],
                'tax_charge' => [],
                'positive_non_income_receipts' => [],
            ];
        }

        $preTax = $this->preTaxService->calculate($companyId, $accountingPeriodId);
        $dataset = $preTax['dataset'] ?? null;
        $monthlyRows = $dataset instanceof PeriodLedgerDataset ? $dataset->rows : [];
        $rowsByNominal = [];
        foreach ($monthlyRows as $row) {
            $id = (int)($row['nominal_account_id'] ?? 0);
            if (!isset($rowsByNominal[$id])) {
                $rowsByNominal[$id] = $row;
                $rowsByNominal[$id]['total_debit'] = 0.0;
                $rowsByNominal[$id]['total_credit'] = 0.0;
            }
            $rowsByNominal[$id]['total_debit'] += (float)($row['total_debit'] ?? 0);
            $rowsByNominal[$id]['total_credit'] += (float)($row['total_credit'] ?? 0);
        }
        $rows = array_values($rowsByNominal);

        $breakdown = [
            'income' => [],
            'cost_of_sales' => [],
            'expense' => [],
            'tax_charge' => [],
            'positive_non_income_receipts' => [],
        ];

        foreach ($rows as $row) {
            $accountType = (string)($row['account_type'] ?? '');
            if (!isset($breakdown[$accountType])) {
                continue;
            }

            $debit = (float)($row['total_debit'] ?? 0);
            $credit = (float)($row['total_credit'] ?? 0);
            $amount = $accountType === 'income'
                ? round($credit - $debit, 2)
                : round($debit - $credit, 2);

            if (abs($amount) < 0.005) {
                continue;
            }

            $groupKey = $accountType === 'expense' && $this->isCorporationTaxExpenseRow((array)$row, $companyId)
                ? 'tax_charge'
                : $accountType;

            $breakdown[$groupKey][] = [
                'nominal_account_id' => (int)($row['nominal_account_id'] ?? 0),
                'code' => (string)($row['code'] ?? ''),
                'name' => (string)($row['name'] ?? ''),
                'account_type' => $groupKey,
                'account_subtype_code' => (string)($row['account_subtype_code'] ?? ''),
                'account_subtype_name' => (string)($row['account_subtype_name'] ?? ''),
                'amount' => $amount,
            ];
        }

        foreach ($breakdown as &$groupRows) {
            usort($groupRows, static fn(array $left, array $right): int => abs((float)$right['amount']) <=> abs((float)$left['amount']));
        }
        unset($groupRows);

        $breakdown = $this->appendDepreciationExpenseBreakdown(
            $breakdown,
            $companyId,
            $accountingPeriodId,
            (string)$accountingPeriod['period_start'],
            (string)$accountingPeriod['period_end'],
            (float)($preTax['depreciation_expense'] ?? 0)
        );
        $breakdown = $this->mergePendingPrepaymentBreakdown(
            $breakdown,
            $companyId,
            $accountingPeriodId,
            (string)$accountingPeriod['period_start'],
            (string)$accountingPeriod['period_end']
        );

        $breakdown['positive_non_income_receipts'] = $this->positiveNonIncomeReceipts(
            $companyId,
            $accountingPeriodId,
            (string)$accountingPeriod['period_start'],
            (string)$accountingPeriod['period_end']
        );

        return $breakdown;
    }

    /**
     * Merge preview-only prepayment effects into the same original nominal
     * rows that ordinary posted journals populate. Consequently callers see
     * an identical nominal breakdown immediately before and after Year End
     * posts the schedule journals.
     */
    private function mergePendingPrepaymentBreakdown(
        array $breakdown,
        int $companyId,
        int $accountingPeriodId,
        string $periodStart,
        string $periodEnd
    ): array {
        foreach ((new YearEndClosePreviewService())->prepaymentExpenseRowsForPeriod(
            $companyId,
            $accountingPeriodId,
            $periodStart,
            $periodEnd
        ) as $pending) {
            $group = (string)($pending['account_type'] ?? '') === 'cost_of_sales'
                ? 'cost_of_sales'
                : 'expense';
            $nominalId = (int)($pending['nominal_account_id'] ?? 0);
            $amount = round((float)($pending['amount'] ?? 0), 2);
            if ($nominalId <= 0 || abs($amount) < 0.005) {
                continue;
            }

            $merged = false;
            foreach ($breakdown[$group] as $index => $existing) {
                if ((int)($existing['nominal_account_id'] ?? 0) !== $nominalId) {
                    continue;
                }
                $breakdown[$group][$index]['amount'] = round((float)($existing['amount'] ?? 0) + $amount, 2);
                if (abs((float)$breakdown[$group][$index]['amount']) < 0.005) {
                    unset($breakdown[$group][$index]);
                    $breakdown[$group] = array_values($breakdown[$group]);
                }
                $merged = true;
                break;
            }
            if ($merged) {
                continue;
            }

            $breakdown[$group][] = [
                'nominal_account_id' => $nominalId,
                'code' => (string)($pending['code'] ?? ''),
                'name' => (string)($pending['name'] ?? ''),
                'account_type' => $group,
                'account_subtype_code' => (string)($pending['subtype_code'] ?? ''),
                'account_subtype_name' => (string)($pending['subtype_name'] ?? ''),
                'amount' => $amount,
            ];
        }

        foreach (['cost_of_sales', 'expense'] as $group) {
            usort(
                $breakdown[$group],
                static fn(array $left, array $right): int => abs((float)$right['amount']) <=> abs((float)$left['amount'])
            );
        }
        return $breakdown;
    }

    public function getMonthlyProfitLossTrend(int $companyId, int $accountingPeriodId): array
    {
        $accountingPeriod = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null) {
            return [];
        }

        $months = $this->periodMonths((string)$accountingPeriod['period_start'], (string)$accountingPeriod['period_end']);
        $preTax = $this->preTaxService->calculate($companyId, $accountingPeriodId);
        $dataset = $preTax['dataset'] ?? null;
        $rows = $dataset instanceof PeriodLedgerDataset ? $dataset->rows : [];

        foreach ($rows as $row) {
            $month = (string)($row['month_start'] ?? '');
            if (!isset($months[$month])) {
                continue;
            }

            $accountType = (string)($row['account_type'] ?? '');
            $debit = (float)($row['total_debit'] ?? 0);
            $credit = (float)($row['total_credit'] ?? 0);
            if ($accountType === 'income') {
                $months[$month]['income_total'] += round($credit - $debit, 2);
            } elseif ($accountType === 'cost_of_sales') {
                $months[$month]['cost_of_sales_total'] += round($debit - $credit, 2);
            } elseif ($accountType === 'expense') {
                $amount = round($debit - $credit, 2);
                if ($this->isCorporationTaxExpenseRow((array)$row, $companyId)) {
                    $months[$month]['posted_corporation_tax_charge'] += $amount;
                } else {
                    $months[$month]['operating_expense_total'] += $amount;
                }
            }
        }

        $estimatedTaxAdjustments = $this->monthlyEstimatedCorporationTaxAdjustments(
            $companyId,
            $accountingPeriodId,
            $accountingPeriod,
            $preTax
        );

        $depreciationByMonth = (new YearEndClosePreviewService())->monthlyDepreciationExpenseForPeriod(
            $companyId,
            $accountingPeriodId,
            (string)$accountingPeriod['period_start'],
            (string)$accountingPeriod['period_end']
        );
        $prepaymentOperatingByMonth = [];
        $prepaymentCostByMonth = [];
        foreach ((new YearEndClosePreviewService())->prepaymentExpenseRowsForPeriod(
            $companyId,
            $accountingPeriodId,
            (string)$accountingPeriod['period_start'],
            (string)$accountingPeriod['period_end']
        ) as $prepaymentRow) {
            $monthStart = substr((string)$prepaymentRow['journal_date'], 0, 7) . '-01';
            $target = (string)$prepaymentRow['account_type'] === 'cost_of_sales'
                ? $prepaymentCostByMonth
                : $prepaymentOperatingByMonth;
            $target[$monthStart] = round((float)($target[$monthStart] ?? 0) + (float)$prepaymentRow['amount'], 2);
            if ((string)$prepaymentRow['account_type'] === 'cost_of_sales') {
                $prepaymentCostByMonth = $target;
            } else {
                $prepaymentOperatingByMonth = $target;
            }
        }
        foreach ($months as $monthStart => &$month) {
            $month['posted_operating_expense_total'] = round((float)$month['operating_expense_total'], 2);
            $month['depreciation_expense'] = round((float)($depreciationByMonth[$monthStart] ?? 0), 2);
            $month['prepayment_expense_adjustment'] = round((float)($prepaymentOperatingByMonth[$monthStart] ?? 0), 2);
            $month['prepayment_cost_of_sales_adjustment'] = round((float)($prepaymentCostByMonth[$monthStart] ?? 0), 2);
            $month['cost_of_sales_total'] = round(
                (float)$month['cost_of_sales_total'] + (float)$month['prepayment_cost_of_sales_adjustment'],
                2
            );
            $month['operating_expense_total'] = round(
                (float)$month['posted_operating_expense_total']
                    + (float)$month['prepayment_expense_adjustment']
                    + (float)$month['depreciation_expense'],
                2
            );
            $month['accounting_basis'] = 'posted_journals_plus_year_end_close_preview';
            $month['income_total'] = round((float)$month['income_total'], 2);
            $month['cost_of_sales_total'] = round((float)$month['cost_of_sales_total'], 2);
            $month['operating_expense_total'] = round((float)$month['operating_expense_total'], 2);
            $month['posted_corporation_tax_charge'] = round((float)$month['posted_corporation_tax_charge'], 2);
            $month['estimated_corporation_tax_adjustment'] = round(
                (float)($estimatedTaxAdjustments[$monthStart] ?? 0),
                2
            );
            $month['corporation_tax_expense_total'] = round(
                $month['posted_corporation_tax_charge'] + $month['estimated_corporation_tax_adjustment'],
                2
            );
            $month['expense_total'] = round($month['operating_expense_total'] + $month['corporation_tax_expense_total'], 2);
            $month['profit_before_tax'] = round($month['income_total'] - $month['cost_of_sales_total'] - $month['operating_expense_total'], 2);
            $month['profit_after_tax'] = round($month['profit_before_tax'] - $month['corporation_tax_expense_total'], 2);
            $month['profit_after_estimated_tax'] = $month['profit_after_tax'];
            $month['net_profit'] = $month['profit_after_tax'];
        }
        unset($month);

        return array_values($months);
    }

    public function getProfitLossHealth(int $companyId, int $accountingPeriodId): array
    {
        $accountingPeriod = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null) {
            return [
                'available' => false,
                'errors' => ['Select a company and accounting period before reviewing data health.'],
                'books_health_score' => 0,
            ];
        }

        $periodStart = (string)$accountingPeriod['period_start'];
        $periodEnd = (string)$accountingPeriod['period_end'];
        $totalTransactions = $this->transactionCount($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $uncategorisedTransactions = $this->uncategorisedTransactionCount($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $categorisedTransactions = max(0, $totalTransactions - $uncategorisedTransactions);
        $categorisedPercent = $totalTransactions > 0 ? round(($categorisedTransactions / $totalTransactions) * 100, 1) : 0.0;
        $monthGrid = $this->getMonthStatusGrid($companyId, $accountingPeriodId);
        $missingMonthCount = count(array_filter($monthGrid, static fn(array $row): bool => (string)($row['status'] ?? '') === 'no_data'));
        $uploadedMonthCount = count(array_filter($monthGrid, static fn(array $row): bool => (int)($row['upload_count'] ?? 0) > 0));
        $committedMonthCount = count(array_filter($monthGrid, static fn(array $row): bool => (int)($row['committed_count'] ?? 0) > 0));
        $uploadInProgressCount = count(array_filter($monthGrid, static fn(array $row): bool => (string)($row['status'] ?? '') === 'upload_in_progress'));
        $journalCount = $this->journalCount($companyId, $accountingPeriodId, $periodStart, $periodEnd);

        $score = 100.0;
        if ($journalCount <= 0) {
            $score -= 30;
        }
        if ($totalTransactions > 0) {
            $score -= min(30, ($uncategorisedTransactions / $totalTransactions) * 30);
        }
        $score -= $missingMonthCount * 5;
        if ($uploadInProgressCount > 0) {
            $score -= 10;
        }

        return [
            'available' => true,
            'errors' => [],
            'total_transactions' => $totalTransactions,
            'categorised_transactions' => $categorisedTransactions,
            'uncategorised_transactions' => $uncategorisedTransactions,
            'categorised_percent' => $categorisedPercent,
            'missing_month_count' => $missingMonthCount,
            'uploaded_month_count' => $uploadedMonthCount,
            'committed_month_count' => $committedMonthCount,
            'upload_in_progress_count' => $uploadInProgressCount,
            'journal_count' => $journalCount,
            'books_health_score' => max(0, min(100, (int)round($score))),
        ];
    }

    public function getUncategorisedWatch(int $companyId, int $accountingPeriodId, int $limit = 10): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $resolvedWithoutNominalPredicate = $this->resolvedWithoutNominalPredicate('t');
        $stmt = \InterfaceDB::prepare(
            'SELECT t.id,
                    t.txn_date,
                    COALESCE(t.description, \'\') AS description,
                    t.amount,
                    COALESCE(t.category_status, \'\') AS category_status,
                    COALESCE(t.source_account_label, \'\') AS source_account_label,
                    COALESCE(t.counterparty_name, \'\') AS counterparty_name
             FROM transactions t
             WHERE t.company_id = ?
               AND t.accounting_period_id = ?
               AND (
                    t.category_status = ?
                    OR (t.nominal_account_id IS NULL AND NOT (' . $resolvedWithoutNominalPredicate . '))
               )
             ORDER BY ABS(t.amount) DESC, t.txn_date DESC, t.id DESC
             LIMIT ' . $limit
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->execute([$companyId, $accountingPeriodId, 'uncategorised']);

        return $stmt->fetchAll() ?: [];
    }

    public function getMonthStatusGrid(int $companyId, int $accountingPeriodId): array
    {
        $accountingPeriod = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null) {
            return [];
        }

        $months = $this->periodMonths((string)$accountingPeriod['period_start'], (string)$accountingPeriod['period_end']);
        $periodStart = (string)$accountingPeriod['period_start'];
        $periodEnd = (string)$accountingPeriod['period_end'];
        $emptyMonthConfirmations = $this->emptyMonthConfirmationMonths($companyId, $accountingPeriodId);
        foreach ($this->transactionMonths($companyId, $accountingPeriodId, $periodStart, $periodEnd) as $monthKey => $row) {
            if (!isset($months[$monthKey])) {
                continue;
            }
            $months[$monthKey]['transaction_count'] = (int)($row['transaction_count'] ?? 0);
            $months[$monthKey]['uncategorised_count'] = (int)($row['uncategorised_count'] ?? 0);
        }
        foreach ($this->journalMonths($companyId, $accountingPeriodId, $periodStart, $periodEnd) as $monthKey => $count) {
            if (isset($months[$monthKey])) {
                $months[$monthKey]['journal_count'] = (int)$count;
            }
        }
        foreach ($this->uploadMonths($companyId, $accountingPeriodId, $periodStart, $periodEnd) as $monthKey => $row) {
            if (!isset($months[$monthKey])) {
                continue;
            }
            $months[$monthKey]['upload_count'] = (int)($row['upload_count'] ?? 0);
            $months[$monthKey]['committed_count'] = (int)($row['committed_count'] ?? 0);
            $months[$monthKey]['in_progress_count'] = (int)($row['in_progress_count'] ?? 0);
        }

        foreach ($months as &$month) {
            if ((int)$month['in_progress_count'] > 0) {
                $month['status'] = 'upload_in_progress';
            } elseif ((int)$month['transaction_count'] > 0 && (int)$month['uncategorised_count'] > 0) {
                $month['status'] = 'needs_categorisation';
            } elseif ((int)$month['transaction_count'] === 0 && (int)$month['journal_count'] === 0) {
                $month['status'] = 'no_data';
            } else {
                $month['status'] = 'ready';
            }

            $monthStart = (string)($month['month_start'] ?? '');
            $emptyMonth = (array)($emptyMonthConfirmations[$monthStart] ?? []);
            if ($emptyMonth === []) {
                continue;
            }

            $month['empty_month_confirmation_status'] = (string)($emptyMonth['status'] ?? '');
            $month['empty_month_confirmation_reason'] = (string)($emptyMonth['reason'] ?? '');
            $month['empty_month_confirmation'] = (array)($emptyMonth['confirmation'] ?? []);

            if ((string)$month['status'] === 'no_data' && (string)($emptyMonth['status'] ?? '') === 'confirmed') {
                $month['status'] = 'confirmed_empty';
            } elseif ((string)$month['status'] === 'no_data' && !empty($emptyMonth['can_confirm'])) {
                $month['can_confirm_empty_month'] = true;
            }
        }
        unset($month);

        return array_values($months);
    }

    public function getSourceCoverage(int $companyId, int $accountingPeriodId): array
    {
        $accountingPeriod = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        $periodStart = (string)($accountingPeriod['period_start'] ?? '');
        $periodEnd = (string)($accountingPeriod['period_end'] ?? '');
        $sources = [
            'bank_csv' => ['label' => 'Bank CSV journals'],
            'director_loan_offset' => ['label' => 'Director loan control reclassification journals'],
            'expense_register' => ['label' => 'Expense register journals'],
            'asset_depreciation' => ['label' => 'Asset depreciation journals'],
            'asset_disposal' => ['label' => 'Asset disposal journals'],
            'manual' => ['label' => 'Manual journals'],
            'other' => ['label' => 'Other posted journals'],
        ];

        foreach ($sources as $sourceType => &$source) {
            $source['source_type'] = $sourceType;
            $source['journal_count'] = 0;
            $source['debit_total'] = 0.0;
            $source['credit_total'] = 0.0;
            $source['present'] = false;
            $source['verified_journal_count'] = 0;
            $source['unverified_journal_count'] = 0;
            $source['evidence_failures'] = [];
        }
        unset($source);

        if ($companyId <= 0 || $accountingPeriodId <= 0 || $periodStart === '' || $periodEnd === '') {
            $sources['coverage_summary'] = $this->sourceCoverageSummary([], $sources);
            return $sources;
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT j.id,
                    COALESCE(j.source_type, \'\') AS source_type,
                    COALESCE(j.source_ref, \'\') AS source_ref,
                    j.journal_date,
                    COALESCE(j.description, \'\') AS description,
                    COALESCE(SUM(jl.debit), 0) AS debit_total,
                    COALESCE(SUM(jl.credit), 0) AS credit_total
             FROM journals j
             LEFT JOIN journal_lines jl ON jl.journal_id = j.id
             LEFT JOIN journal_entry_metadata jem_close
               ON jem_close.journal_id = j.id
              AND jem_close.journal_tag = :close_journal_tag
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
               AND j.is_posted = 1
               AND j.journal_date BETWEEN :period_start AND :period_end
               AND jem_close.id IS NULL
             GROUP BY j.id, j.source_type, j.source_ref, j.journal_date, j.description',
            [
                'close_journal_tag' => \eel_accounts\Service\RetainedEarningsCloseService::JOURNAL_TAG,
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]
        );
        $evidenceByJournal = (new JournalSourceEvidenceService())
            ->verify($rows, $companyId, $accountingPeriodId);

        foreach ($rows as $row) {
            $journalId = (int)($row['id'] ?? 0);
            $evidence = (array)($evidenceByJournal[$journalId] ?? []);
            $sourceRef = (string)($row['source_ref'] ?? '');
            $description = strtolower((string)($row['description'] ?? ''));
            $sourceType = str_starts_with($sourceRef, 'meta:director_loan') || str_contains($description, 'director loan')
                ? 'director_loan_offset'
                : (string)($row['source_type'] ?? '');
            if (!isset($sources[$sourceType])) {
                $sourceType = 'other';
            }
            $sources[$sourceType]['journal_count']++;
            $sources[$sourceType]['debit_total'] = round((float)$sources[$sourceType]['debit_total'] + (float)($row['debit_total'] ?? 0), 2);
            $sources[$sourceType]['credit_total'] = round((float)$sources[$sourceType]['credit_total'] + (float)($row['credit_total'] ?? 0), 2);
            $sources[$sourceType]['present'] = true;
            if (!empty($evidence['verified'])) {
                $sources[$sourceType]['verified_journal_count']++;
            } else {
                $sources[$sourceType]['unverified_journal_count']++;
                $sources[$sourceType]['evidence_failures'][] = [
                    'journal_id' => $journalId,
                    'source_ref' => $sourceRef,
                    'reason' => (string)($evidence['reason'] ?? 'Source evidence could not be verified.'),
                ];
            }
        }

        $sources['coverage_summary'] = $this->sourceCoverageSummary($rows, $sources, $evidenceByJournal);
        return $sources;
    }

    private function sourceCoverageSummary(array $rows, array $sources, array $evidenceByJournal = []): array
    {
        $coveredJournalCount = 0;
        $coveredDebitTotal = 0.0;
        $coveredCreditTotal = 0.0;

        $postedDebitTotal = 0.0;
        $postedCreditTotal = 0.0;
        $evidenceFailures = [];
        foreach ($rows as $row) {
            $postedDebitTotal += (float)($row['debit_total'] ?? 0);
            $postedCreditTotal += (float)($row['credit_total'] ?? 0);
            $journalId = (int)($row['id'] ?? 0);
            $evidence = (array)($evidenceByJournal[$journalId] ?? []);
            if (!empty($evidence['verified'])) {
                $coveredJournalCount++;
                $coveredDebitTotal += (float)($row['debit_total'] ?? 0);
                $coveredCreditTotal += (float)($row['credit_total'] ?? 0);
                continue;
            }
            $evidenceFailures[] = [
                'journal_id' => $journalId,
                'source_type' => (string)($row['source_type'] ?? ''),
                'source_ref' => (string)($row['source_ref'] ?? ''),
                'reason' => (string)($evidence['reason'] ?? 'Source evidence could not be verified.'),
            ];
        }

        $postedJournalCount = count($rows);
        $reconciled = $coveredJournalCount === $postedJournalCount
            && abs(round($coveredDebitTotal - $postedDebitTotal, 2)) < 0.005
            && abs(round($coveredCreditTotal - $postedCreditTotal, 2)) < 0.005;

        return [
            'is_summary' => true,
            'posted_journal_count' => $postedJournalCount,
            'covered_journal_count' => $coveredJournalCount,
            'uncovered_journal_count' => max(0, $postedJournalCount - $coveredJournalCount),
            'posted_debit_total' => round($postedDebitTotal, 2),
            'covered_debit_total' => round($coveredDebitTotal, 2),
            'posted_credit_total' => round($postedCreditTotal, 2),
            'covered_credit_total' => round($coveredCreditTotal, 2),
            'reconciled' => $reconciled,
            'status' => $reconciled ? 'pass' : 'warning',
            'evidence_failures' => $evidenceFailures,
        ];
    }

    public function getCtPeriodProfitReconciliation(int $companyId, int $accountingPeriodId): array
    {
        $accountingPeriod = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null) {
            return [
                'available' => false,
                'errors' => ['Select a company and accounting period before reviewing CT-period profit.'],
                'ct_periods' => [],
            ];
        }

        try {
            $periods = $this->activeCtPeriods($companyId, $accountingPeriodId, $accountingPeriod);
            $rows = [];
            $ctPeriodProfitTotal = 0.0;
            foreach ($periods as $period) {
                $totals = $this->preTaxService->calculate(
                    $companyId,
                    $accountingPeriodId,
                    (string)$period['period_end'],
                    (string)$period['period_start']
                );
                $profitBeforeTax = round((float)($totals['profit_before_tax'] ?? 0), 2);
                $ctPeriodProfitTotal = round($ctPeriodProfitTotal + $profitBeforeTax, 2);
                $rows[] = [
                    'ct_period_id' => (int)($period['id'] ?? 0),
                    'sequence_no' => (int)($period['sequence_no'] ?? 0),
                    'display_sequence_no' => (int)($period['display_sequence_no'] ?? ($period['sequence_no'] ?? 0)),
                    'display_label' => (string)($period['display_label'] ?? ('CT Period ' . (int)($period['sequence_no'] ?? 0))),
                    'period_start' => (string)$period['period_start'],
                    'period_end' => (string)$period['period_end'],
                    'profit_before_tax' => $profitBeforeTax,
                ];
            }

            $accountingPeriodTotals = $this->preTaxService->calculate(
                $companyId,
                $accountingPeriodId,
                (string)$accountingPeriod['period_end'],
                (string)$accountingPeriod['period_start']
            );
            $accountingPeriodProfit = round((float)($accountingPeriodTotals['profit_before_tax'] ?? 0), 2);

            return [
                'available' => true,
                'errors' => [],
                'accounting_period_id' => $accountingPeriodId,
                'accounting_period_start' => (string)$accountingPeriod['period_start'],
                'accounting_period_end' => (string)$accountingPeriod['period_end'],
                'accounting_period_profit_before_tax' => $accountingPeriodProfit,
                'ct_period_profit_total' => $ctPeriodProfitTotal,
                'reconciliation_difference' => round($accountingPeriodProfit - $ctPeriodProfitTotal, 2),
                'ct_periods' => $rows,
            ];
        } catch (\Throwable $exception) {
            return [
                'available' => false,
                'errors' => [$exception->getMessage()],
                'ct_periods' => [],
            ];
        }
    }

    private function emptySummary(string $error): array
    {
        return [
            'available' => false,
            'errors' => [$error],
            'period_label' => '',
            'income_total' => 0.0,
            'cost_of_sales_total' => 0.0,
            'gross_profit' => 0.0,
            'operating_expense_total' => 0.0,
            'expense_total' => 0.0,
            'profit_before_tax' => 0.0,
            'corporation_tax_expense_total' => 0.0,
            'posted_corporation_tax_charge' => 0.0,
            'estimated_corporation_tax' => 0.0,
            'unposted_corporation_tax_adjustment' => 0.0,
            'profit_after_posted_tax' => 0.0,
            'profit_after_estimated_tax' => 0.0,
            'corporation_tax_provision' => ['available' => false, 'errors' => []],
            'net_profit' => 0.0,
            'profit_margin_percent' => 0.0,
            'has_loss' => false,
            'has_journals' => false,
            'has_transactions' => false,
            'journal_count' => 0,
            'transaction_count' => 0,
        ];
    }

    private function fetchAccountingPeriod(int $companyId, int $accountingPeriodId): ?array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return null;
        }

        return (new \eel_accounts\Repository\AccountingPeriodRepository())->fetchAccountingPeriod($companyId, $accountingPeriodId);
    }

    private function activeCtPeriods(int $companyId, int $accountingPeriodId, array $accountingPeriod): array
    {
        $periods = [];
        $hasCtPeriodTable = \InterfaceDB::tableExists('corporation_tax_periods');
        if ($hasCtPeriodTable) {
            $periods = \InterfaceDB::fetchAll(
                'SELECT id, accounting_period_id, sequence_no, period_start, period_end, status
                 FROM corporation_tax_periods
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                   AND status <> :superseded_status
                 ORDER BY sequence_no ASC, id ASC',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'superseded_status' => 'superseded',
                ]
            ) ?: [];
        }

        if ($periods === []) {
            $derivedPeriods = (new TaxPeriodService())->derive(
                (string)$accountingPeriod['period_start'],
                (string)$accountingPeriod['period_end'],
                $companyId
            );
            $periods = array_map(
                static fn(array $period, int $index): array => [
                    'id' => 0,
                    'accounting_period_id' => $accountingPeriodId,
                    'sequence_no' => $index + 1,
                    'period_start' => (string)$period['start'],
                    'period_end' => (string)$period['end'],
                    'status' => 'derived',
                ],
                $derivedPeriods,
                array_keys($derivedPeriods)
            );
        }

        $ctPeriodService = $hasCtPeriodTable ? new CorporationTaxPeriodService() : null;
        foreach ($periods as &$period) {
            $sequenceNo = (int)($period['sequence_no'] ?? 0);
            $displaySequenceNo = $ctPeriodService !== null
                ? $ctPeriodService->displaySequenceNo($companyId, $accountingPeriodId, $sequenceNo)
                : $sequenceNo;
            $period['display_sequence_no'] = $displaySequenceNo;
            $period['display_label'] = 'CT Period ' . $displaySequenceNo;
        }
        unset($period);

        return $periods;
    }

    private function corporationTaxProvisionPosition(int $companyId, int $accountingPeriodId, PreTaxProfitLossService $preTaxService, array $accountingPeriod, string $effectiveEnd, array $preTax): array
    {
        try {
            $metrics = new YearEndMetricsService(null, null, null, null, $preTaxService);
            $computation = new CorporationTaxComputationService($metrics);
            if ($effectiveEnd < (string)($accountingPeriod['period_end'] ?? $effectiveEnd)) {
                $asAtPeriod = $accountingPeriod;
                $asAtPeriod['period_end'] = $effectiveEnd;
                $estimate = $computation->fetchCurrentPeriodEstimate($companyId, $accountingPeriodId, $asAtPeriod, [
                    'profit_before_tax' => (float)($preTax['profit_before_tax'] ?? 0),
                    'disallowable_add_backs' => (float)($preTax['disallowable_add_backs'] ?? 0),
                    'capital_add_backs' => (float)($preTax['capital_add_backs'] ?? 0),
                    'other_treatment_count' => (int)($preTax['other_treatment_count'] ?? 0),
                    'unknown_treatment_count' => (int)($preTax['unknown_treatment_count'] ?? 0),
                    'prepayment_preview_reliable' => !array_key_exists('prepayment_preview_reliable', $preTax)
                        || !empty($preTax['prepayment_preview_reliable']),
                    'prepayment_preview_warnings' => array_values(array_unique(array_map(
                        'strval',
                        (array)($preTax['prepayment_preview_warnings'] ?? [])
                    ))),
                ]);
                $posted = (float)($preTax['posted_corporation_tax_charge'] ?? 0);
                $estimated = (float)($estimate['estimated_corporation_tax'] ?? 0);
                return [
                    'available' => !empty($estimate['available']),
                    'errors' => (array)($estimate['errors'] ?? []),
                    'estimated_corporation_tax' => $estimated,
                    'posted_corporation_tax_charge' => $posted,
                    'unposted_corporation_tax_adjustment' => max(0.0, round($estimated - $posted, 2)),
                    'status' => 'as_at_estimate',
                ];
            }
            return (new CorporationTaxProvisionService($computation))->fetchAccountingPeriodPosition($companyId, $accountingPeriodId);
        } catch (\Throwable $exception) {
            return [
                'available' => false,
                'errors' => [$exception->getMessage()],
                'estimated_corporation_tax' => 0.0,
                'posted_corporation_tax_charge' => 0.0,
                'unposted_corporation_tax_adjustment' => 0.0,
                'status' => 'unavailable',
            ];
        }
    }

    private function isCorporationTaxExpenseRow(array $row, int $companyId): bool
    {
        if ((string)($row['account_type'] ?? '') !== 'expense') {
            return false;
        }

        $settings = (new \eel_accounts\Store\CompanySettingsStore($companyId))->all();
        $expenseNominalId = (int)($settings['corporation_tax_expense_nominal_id'] ?? 0);

        return $expenseNominalId > 0 && (int)($row['nominal_account_id'] ?? $row['id'] ?? 0) === $expenseNominalId;
    }

    private function appendDepreciationExpenseBreakdown(array $breakdown, int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd, ?float $depreciationAmount = null): array
    {
        $preview = new \eel_accounts\Service\YearEndClosePreviewService();
        $amount = $depreciationAmount ?? $preview->depreciationExpenseForPeriod($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        if ($amount <= 0) {
            return $breakdown;
        }

        $nominal = $preview->depreciationExpenseNominal();
        if ($nominal === null) {
            return $breakdown;
        }

        $row = [
            'nominal_account_id' => (int)($nominal['nominal_account_id'] ?? 0),
            'code' => (string)($nominal['code'] ?? '6200'),
            'name' => (string)($nominal['name'] ?? 'Depreciation Expense'),
            'account_type' => 'expense',
            'account_subtype_code' => (string)($nominal['subtype_code'] ?? ''),
            'account_subtype_name' => 'Depreciation Expense',
            'amount' => $amount,
        ];

        foreach ($breakdown['expense'] as $index => $existing) {
            if ((int)($existing['nominal_account_id'] ?? 0) !== (int)$row['nominal_account_id']) {
                continue;
            }

            $breakdown['expense'][$index]['amount'] = round((float)($existing['amount'] ?? 0) + $amount, 2);
            return $breakdown;
        }

        $breakdown['expense'][] = $row;
        usort($breakdown['expense'], static fn(array $left, array $right): int => abs((float)$right['amount']) <=> abs((float)$left['amount']));

        return $breakdown;
    }

    private function positiveNonIncomeReceipts(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [];
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT na.id AS nominal_account_id,
                    COALESCE(na.code, \'\') AS code,
                    COALESCE(na.name, \'\') AS name,
                    na.account_type,
                    COALESCE(nas.code, \'\') AS account_subtype_code,
                    COALESCE(nas.name, \'\') AS account_subtype_name,
                    COUNT(*) AS transaction_count,
                    COALESCE(SUM(t.amount), 0) AS amount
             FROM transactions t
             INNER JOIN nominal_accounts na ON na.id = t.nominal_account_id
             LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             LEFT JOIN company_accounts ca ON ca.id = t.account_id
             WHERE t.company_id = :company_id
               AND t.accounting_period_id = :accounting_period_id
               AND t.txn_date BETWEEN :period_start AND :period_end
               AND t.amount > 0
               AND t.category_status IN (:auto_status, :manual_status)
               AND COALESCE(t.is_internal_transfer, 0) = 0
               AND COALESCE(ca.account_type, \'\') = :bank_account_type
               AND na.account_type <> :income_type
             GROUP BY na.id, na.code, na.name, na.account_type, nas.code, nas.name
             HAVING COALESCE(SUM(t.amount), 0) > 0
             ORDER BY COALESCE(SUM(t.amount), 0) DESC, na.code ASC',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'auto_status' => 'auto',
                'manual_status' => 'manual',
                'bank_account_type' => CompanyAccountService::TYPE_BANK,
                'income_type' => 'income',
            ]
        );

        return array_map(
            static fn(array $row): array => [
                'nominal_account_id' => (int)($row['nominal_account_id'] ?? 0),
                'code' => (string)($row['code'] ?? ''),
                'name' => (string)($row['name'] ?? ''),
                'account_type' => (string)($row['account_type'] ?? ''),
                'account_subtype_code' => (string)($row['account_subtype_code'] ?? ''),
                'account_subtype_name' => (string)($row['account_subtype_name'] ?? ''),
                'transaction_count' => (int)($row['transaction_count'] ?? 0),
                'amount' => round((float)($row['amount'] ?? 0), 2),
            ],
            $rows
        );
    }

    private function periodMonths(string $periodStart, string $periodEnd): array
    {
        $months = [];
        try {
            $cursor = (new \DateTimeImmutable($periodStart))->modify('first day of this month');
            $end = (new \DateTimeImmutable($periodEnd))->modify('first day of this month');
        } catch (\Throwable) {
            return [];
        }

        while ($cursor <= $end) {
            $monthStart = $cursor->format('Y-m-01');
            $months[$monthStart] = [
                'month_start' => $monthStart,
                'month_label' => \HelperFramework::displayMonthYear($cursor),
                'income_total' => 0.0,
                'cost_of_sales_total' => 0.0,
                'operating_expense_total' => 0.0,
                'posted_operating_expense_total' => 0.0,
                'depreciation_expense' => 0.0,
                'posted_corporation_tax_charge' => 0.0,
                'estimated_corporation_tax_adjustment' => 0.0,
                'corporation_tax_expense_total' => 0.0,
                'expense_total' => 0.0,
                'profit_before_tax' => 0.0,
                'profit_after_tax' => 0.0,
                'net_profit' => 0.0,
                'transaction_count' => 0,
                'uncategorised_count' => 0,
                'upload_count' => 0,
                'committed_count' => 0,
                'in_progress_count' => 0,
                'journal_count' => 0,
                'can_confirm_empty_month' => false,
                'empty_month_confirmation_status' => '',
                'empty_month_confirmation_reason' => '',
                'empty_month_confirmation' => [],
                'status' => 'no_data',
            ];
            $cursor = $cursor->modify('+1 month');
        }

        return $months;
    }

    /** @return array<string, float> */
    private function monthlyEstimatedCorporationTaxAdjustments(
        int $companyId,
        int $accountingPeriodId,
        array $accountingPeriod,
        array $preTax
    ): array {
        $periods = $this->activeCtPeriods($companyId, $accountingPeriodId, $accountingPeriod);
        $adjustments = [];
        $allPeriodEstimatesAvailable = $periods !== [];
        $computation = new CorporationTaxComputationService();

        foreach ($periods as $period) {
            $ctPeriodId = (int)($period['id'] ?? 0);
            if ($ctPeriodId <= 0) {
                $allPeriodEstimatesAvailable = false;
                break;
            }

            $summary = $computation->fetchSummaryForCtPeriodId($companyId, $ctPeriodId);
            if (empty($summary['available'])) {
                $allPeriodEstimatesAvailable = false;
                break;
            }

            $periodStart = (string)($period['period_start'] ?? '');
            $periodEnd = (string)($period['period_end'] ?? '');
            $monthStart = substr($periodEnd, 0, 7) . '-01';
            $posted = $this->postedCorporationTaxChargeForDateRange(
                $companyId,
                $accountingPeriodId,
                $periodStart,
                $periodEnd
            );
            $adjustments[$monthStart] = round(
                (float)($adjustments[$monthStart] ?? 0)
                    + (float)($summary['estimated_corporation_tax'] ?? 0)
                    - $posted,
                2
            );
        }

        if ($allPeriodEstimatesAvailable) {
            return $adjustments;
        }

        $position = $this->corporationTaxProvisionPosition(
            $companyId,
            $accountingPeriodId,
            $this->preTaxService,
            $accountingPeriod,
            (string)($accountingPeriod['period_end'] ?? ''),
            $preTax
        );
        if (empty($position['available'])) {
            return [];
        }

        $monthStart = substr((string)($accountingPeriod['period_end'] ?? ''), 0, 7) . '-01';
        return [
            $monthStart => round(
                (float)($position['estimated_corporation_tax'] ?? 0)
                    - (float)($preTax['posted_corporation_tax_charge'] ?? 0),
                2
            ),
        ];
    }

    private function postedCorporationTaxChargeForDateRange(
        int $companyId,
        int $accountingPeriodId,
        string $periodStart,
        string $periodEnd
    ): float {
        $settings = (new \eel_accounts\Store\CompanySettingsStore($companyId))->all();
        $nominalId = (int)($settings['corporation_tax_expense_nominal_id'] ?? 0);
        if ($nominalId <= 0 || $periodStart === '' || $periodEnd === '') {
            return 0.0;
        }

        return round((float)\InterfaceDB::fetchColumn(
            'SELECT COALESCE(SUM(COALESCE(jl.debit, 0) - COALESCE(jl.credit, 0)), 0)
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
               AND j.is_posted = 1
               AND j.journal_date BETWEEN :period_start AND :period_end
               AND jl.nominal_account_id = :nominal_account_id',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'nominal_account_id' => $nominalId,
            ]
        ), 2);
    }

    private function emptyMonthConfirmationMonths(int $companyId, int $accountingPeriodId): array
    {
        try {
            $context = (new EmptyMonthConfirmationService())->fetchContext($companyId, $accountingPeriodId);
        } catch (\Throwable) {
            return [];
        }

        if (empty($context['available'])) {
            return [];
        }

        $months = [];
        foreach ((array)($context['months'] ?? []) as $month) {
            if (!is_array($month)) {
                continue;
            }

            $monthStart = (string)($month['month_start'] ?? '');
            if ($monthStart !== '') {
                $months[$monthStart] = $month;
            }
        }

        return $months;
    }

    private function transactionMonths(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): array
    {
        $result = [];
        $resolvedWithoutNominalPredicate = $this->resolvedWithoutNominalPredicate('transactions');
        foreach (\InterfaceDB::fetchAll(
            'SELECT DATE_FORMAT(txn_date, \'%Y-%m-01\') AS month_start,
                    COUNT(*) AS transaction_count,
                    SUM(
                        CASE
                            WHEN category_status = :uncategorised THEN 1
                            WHEN nominal_account_id IS NULL AND NOT (' . $resolvedWithoutNominalPredicate . ') THEN 1
                            ELSE 0
                        END
                    ) AS uncategorised_count
             FROM transactions
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND txn_date BETWEEN :period_start AND :period_end
             GROUP BY DATE_FORMAT(txn_date, \'%Y-%m-01\')',
            [
                'uncategorised' => 'uncategorised',
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]
        ) as $row) {
            $result[(string)$row['month_start']] = $row;
        }

        return $result;
    }

    private function journalMonths(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): array
    {
        $result = [];
        foreach (\InterfaceDB::fetchAll(
            'SELECT DATE_FORMAT(journal_date, \'%Y-%m-01\') AS month_start,
                    COUNT(*) AS journal_count
             FROM journals j
             LEFT JOIN journal_entry_metadata jem_close
               ON jem_close.journal_id = j.id
              AND jem_close.journal_tag = :close_journal_tag
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
               AND j.is_posted = 1
               AND j.journal_date BETWEEN :period_start AND :period_end
               AND jem_close.id IS NULL
             GROUP BY DATE_FORMAT(j.journal_date, \'%Y-%m-01\')',
            [
                'close_journal_tag' => \eel_accounts\Service\RetainedEarningsCloseService::JOURNAL_TAG,
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]
        ) as $row) {
            $result[(string)$row['month_start']] = (int)($row['journal_count'] ?? 0);
        }

        return $result;
    }

    private function uploadMonths(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): array
    {
        $result = [];
        foreach (\InterfaceDB::fetchAll(
            'SELECT DATE_FORMAT(COALESCE(date_range_start, statement_month), \'%Y-%m-01\') AS month_start,
                    COUNT(*) AS upload_count,
                    SUM(CASE WHEN workflow_status IN (:committed, :completed) OR rows_committed > 0 THEN 1 ELSE 0 END) AS committed_count,
                    SUM(CASE WHEN workflow_status NOT IN (:committed, :completed) AND rows_committed = 0 THEN 1 ELSE 0 END) AS in_progress_count
             FROM statement_uploads
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND COALESCE(date_range_start, statement_month) BETWEEN :period_start AND :period_end
             GROUP BY DATE_FORMAT(COALESCE(date_range_start, statement_month), \'%Y-%m-01\')',
            [
                'committed' => 'committed',
                'completed' => 'completed',
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]
        ) as $row) {
            $result[(string)$row['month_start']] = $row;
        }

        return $result;
    }

    private function journalCount(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): int
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return 0;
        }

        return (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM journals j
             LEFT JOIN journal_entry_metadata jem_close
               ON jem_close.journal_id = j.id
              AND jem_close.journal_tag = :close_journal_tag
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
               AND j.is_posted = 1
               AND j.journal_date BETWEEN :period_start AND :period_end
               AND jem_close.id IS NULL',
            [
                'close_journal_tag' => \eel_accounts\Service\RetainedEarningsCloseService::JOURNAL_TAG,
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]
        );
    }

    private function transactionCount(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): int
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return 0;
        }

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

    private function uncategorisedTransactionCount(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): int
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return 0;
        }

        $resolvedWithoutNominalPredicate = $this->resolvedWithoutNominalPredicate('transactions');

        return (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM transactions
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND txn_date BETWEEN :period_start AND :period_end
               AND (
                    category_status = :uncategorised
                    OR (nominal_account_id IS NULL AND NOT (' . $resolvedWithoutNominalPredicate . '))
               )',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'uncategorised' => 'uncategorised',
            ]
        );
    }

    private function interAccountMarkerPredicate(string $transactionAlias): string
    {
        try {
            if (!\InterfaceDB::tableExists('transaction_inter_ac_marker')) {
                return '0 = 1';
            }
        } catch (\Throwable) {
            return '0 = 1';
        }

        return 'EXISTS (
                    SELECT 1
                    FROM transaction_inter_ac_marker health_tiam
                    WHERE health_tiam.transaction_id = ' . $transactionAlias . '.id
                       OR health_tiam.matched_transaction_id = ' . $transactionAlias . '.id
                )';
    }

    private function resolvedWithoutNominalPredicate(string $transactionAlias): string
    {
        $predicates = [
            $this->interAccountMarkerPredicate($transactionAlias),
            '(COALESCE(' . $transactionAlias . '.is_internal_transfer, 0) = 1
              AND COALESCE(' . $transactionAlias . '.transfer_account_id, 0) > 0
              AND ' . $transactionAlias . '.category_status = \'manual\')',
        ];

        try {
            $hasSplits = \InterfaceDB::tableExists('transaction_splits')
                && \InterfaceDB::tableExists('transaction_split_lines');
        } catch (\Throwable) {
            $hasSplits = false;
        }

        if ($hasSplits) {
            $predicates[] = 'EXISTS (
                SELECT 1
                FROM transaction_splits health_ts
                INNER JOIN transaction_split_lines health_tsl
                    ON health_tsl.split_id = health_ts.id
                WHERE health_ts.transaction_id = ' . $transactionAlias . '.id
                GROUP BY health_ts.id, health_ts.transaction_id
                HAVING SUM(CASE WHEN COALESCE(health_tsl.is_deferred, 0) = 0 THEN 1 ELSE 0 END) >= 2
                   AND SUM(CASE WHEN COALESCE(health_tsl.is_deferred, 0) = 1 THEN 1 ELSE 0 END) = 0
                   AND SUM(
                       CASE
                           WHEN health_tsl.amount IS NULL
                             OR health_tsl.amount <= 0
                             OR health_tsl.nominal_account_id IS NULL
                           THEN 1
                           ELSE 0
                       END
                   ) = 0
                   AND ABS(
                       ROUND(
                           ABS(' . $transactionAlias . '.amount) - COALESCE(SUM(health_tsl.amount), 0),
                           2
                       )
                   ) < 0.005
            )';
        }

        return '(' . implode(' OR ', $predicates) . ')';
    }
}
