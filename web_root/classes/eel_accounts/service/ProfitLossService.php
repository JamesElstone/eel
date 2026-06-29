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
    public function getProfitLossSummary(int $companyId, int $accountingPeriodId): array
    {
        $accountingPeriod = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null) {
            return $this->emptySummary('Select a company and accounting period before reviewing Profit & Loss.');
        }

        $totals = $this->profitLossTotals($companyId, $accountingPeriodId, (string)$accountingPeriod['period_start'], (string)$accountingPeriod['period_end']);
        $incomeTotal = round((float)$totals['income_total'], 2);
        $costOfSalesTotal = round((float)$totals['cost_of_sales_total'], 2);
        $expenseTotal = round((float)$totals['expense_total'], 2);
        $grossProfit = round($incomeTotal - $costOfSalesTotal, 2);
        $netProfit = round($grossProfit - $expenseTotal, 2);

        return [
            'available' => true,
            'errors' => [],
            'period_label' => (string)($accountingPeriod['label'] ?? ''),
            'period_start' => (string)($accountingPeriod['period_start'] ?? ''),
            'period_end' => (string)($accountingPeriod['period_end'] ?? ''),
            'journal_count' => $this->journalCount($companyId, $accountingPeriodId),
            'transaction_count' => $this->transactionCount($companyId, $accountingPeriodId),
            'income_total' => $incomeTotal,
            'cost_of_sales_total' => $costOfSalesTotal,
            'gross_profit' => $grossProfit,
            'expense_total' => $expenseTotal,
            'net_profit' => $netProfit,
            'profit_margin_percent' => $incomeTotal > 0 ? round(($netProfit / $incomeTotal) * 100, 1) : 0.0,
            'has_loss' => $netProfit < 0,
            'has_journals' => $this->journalCount($companyId, $accountingPeriodId) > 0,
            'has_transactions' => $this->transactionCount($companyId, $accountingPeriodId) > 0,
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
            ];
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT na.id AS nominal_account_id,
                    COALESCE(na.code, \'\') AS code,
                    COALESCE(na.name, \'\') AS name,
                    na.account_type,
                    COALESCE(SUM(jl.debit), 0) AS total_debit,
                    COALESCE(SUM(jl.credit), 0) AS total_credit
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             INNER JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
               AND j.is_posted = 1
               AND j.journal_date BETWEEN :period_start AND :period_end
               AND na.account_type IN (:income_type, :cost_type, :expense_type)
             GROUP BY na.id, na.code, na.name, na.account_type
             ORDER BY na.account_type ASC, ABS(COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.credit), 0)) DESC, na.code ASC',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => (string)$accountingPeriod['period_start'],
                'period_end' => (string)$accountingPeriod['period_end'],
                'income_type' => 'income',
                'cost_type' => 'cost_of_sales',
                'expense_type' => 'expense',
            ]
        );

        $breakdown = [
            'income' => [],
            'cost_of_sales' => [],
            'expense' => [],
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

            $breakdown[$accountType][] = [
                'nominal_account_id' => (int)($row['nominal_account_id'] ?? 0),
                'code' => (string)($row['code'] ?? ''),
                'name' => (string)($row['name'] ?? ''),
                'account_type' => $accountType,
                'amount' => $amount,
            ];
        }

        foreach ($breakdown as &$groupRows) {
            usort($groupRows, static fn(array $left, array $right): int => abs((float)$right['amount']) <=> abs((float)$left['amount']));
        }
        unset($groupRows);

        return $breakdown;
    }

    public function getMonthlyProfitLossTrend(int $companyId, int $accountingPeriodId): array
    {
        $accountingPeriod = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null) {
            return [];
        }

        $months = $this->periodMonths((string)$accountingPeriod['period_start'], (string)$accountingPeriod['period_end']);
        $rows = \InterfaceDB::fetchAll(
            'SELECT DATE_FORMAT(j.journal_date, \'%Y-%m-01\') AS month_start,
                    na.account_type,
                    COALESCE(SUM(jl.debit), 0) AS total_debit,
                    COALESCE(SUM(jl.credit), 0) AS total_credit
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             INNER JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
               AND j.is_posted = 1
               AND j.journal_date BETWEEN :period_start AND :period_end
               AND na.account_type IN (:income_type, :cost_type, :expense_type)
             GROUP BY DATE_FORMAT(j.journal_date, \'%Y-%m-01\'), na.account_type
             ORDER BY month_start ASC',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => (string)$accountingPeriod['period_start'],
                'period_end' => (string)$accountingPeriod['period_end'],
                'income_type' => 'income',
                'cost_type' => 'cost_of_sales',
                'expense_type' => 'expense',
            ]
        );

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
                $months[$month]['expense_total'] += round($debit - $credit, 2);
            }
        }

        foreach ($months as &$month) {
            $month['income_total'] = round((float)$month['income_total'], 2);
            $month['cost_of_sales_total'] = round((float)$month['cost_of_sales_total'], 2);
            $month['expense_total'] = round((float)$month['expense_total'], 2);
            $month['net_profit'] = round($month['income_total'] - $month['cost_of_sales_total'] - $month['expense_total'], 2);
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

        $totalTransactions = $this->transactionCount($companyId, $accountingPeriodId);
        $uncategorisedTransactions = $this->uncategorisedTransactionCount($companyId, $accountingPeriodId);
        $categorisedTransactions = max(0, $totalTransactions - $uncategorisedTransactions);
        $categorisedPercent = $totalTransactions > 0 ? round(($categorisedTransactions / $totalTransactions) * 100, 1) : 0.0;
        $monthGrid = $this->getMonthStatusGrid($companyId, $accountingPeriodId);
        $missingMonthCount = count(array_filter($monthGrid, static fn(array $row): bool => (string)($row['status'] ?? '') === 'no_data'));
        $uploadedMonthCount = count(array_filter($monthGrid, static fn(array $row): bool => (int)($row['upload_count'] ?? 0) > 0));
        $committedMonthCount = count(array_filter($monthGrid, static fn(array $row): bool => (int)($row['committed_count'] ?? 0) > 0));
        $uploadInProgressCount = count(array_filter($monthGrid, static fn(array $row): bool => (string)($row['status'] ?? '') === 'upload_in_progress'));
        $journalCount = $this->journalCount($companyId, $accountingPeriodId);

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
               AND (t.category_status = ? OR t.nominal_account_id IS NULL)
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
        foreach ($this->transactionMonths($companyId, $accountingPeriodId) as $monthKey => $row) {
            if (!isset($months[$monthKey])) {
                continue;
            }
            $months[$monthKey]['transaction_count'] = (int)($row['transaction_count'] ?? 0);
            $months[$monthKey]['uncategorised_count'] = (int)($row['uncategorised_count'] ?? 0);
        }
        foreach ($this->journalMonths($companyId, $accountingPeriodId) as $monthKey => $count) {
            if (isset($months[$monthKey])) {
                $months[$monthKey]['journal_count'] = (int)$count;
            }
        }
        foreach ($this->uploadMonths($companyId, $accountingPeriodId) as $monthKey => $row) {
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
        }
        unset($month);

        return array_values($months);
    }

    public function getSourceCoverage(int $companyId, int $accountingPeriodId): array
    {
        $sources = [
            'bank_csv' => ['label' => 'Bank CSV journals'],
            'director_loan_register' => ['label' => 'Director loan register journals'],
            'expense_register' => ['label' => 'Expense register journals'],
            'manual' => ['label' => 'Manual journals'],
        ];

        foreach ($sources as $sourceType => &$source) {
            $source['source_type'] = $sourceType;
            $source['journal_count'] = 0;
            $source['debit_total'] = 0.0;
            $source['credit_total'] = 0.0;
            $source['present'] = false;
        }
        unset($source);

        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return $sources;
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT j.source_type,
                    COUNT(DISTINCT j.id) AS journal_count,
                    COALESCE(SUM(jl.debit), 0) AS debit_total,
                    COALESCE(SUM(jl.credit), 0) AS credit_total
             FROM journals j
             LEFT JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
               AND j.is_posted = 1
             GROUP BY j.source_type',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        );

        foreach ($rows as $row) {
            $sourceType = (string)($row['source_type'] ?? '');
            if (!isset($sources[$sourceType])) {
                continue;
            }
            $sources[$sourceType]['journal_count'] = (int)($row['journal_count'] ?? 0);
            $sources[$sourceType]['debit_total'] = round((float)($row['debit_total'] ?? 0), 2);
            $sources[$sourceType]['credit_total'] = round((float)($row['credit_total'] ?? 0), 2);
            $sources[$sourceType]['present'] = (int)($row['journal_count'] ?? 0) > 0;
        }

        return $sources;
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
            'expense_total' => 0.0,
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

    private function profitLossTotals(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): array
    {
        $rows = \InterfaceDB::fetchAll(
            'SELECT na.account_type,
                    COALESCE(SUM(jl.debit), 0) AS total_debit,
                    COALESCE(SUM(jl.credit), 0) AS total_credit
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             INNER JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
               AND j.is_posted = 1
               AND j.journal_date BETWEEN :period_start AND :period_end
               AND na.account_type IN (:income_type, :cost_type, :expense_type)
             GROUP BY na.account_type',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'income_type' => 'income',
                'cost_type' => 'cost_of_sales',
                'expense_type' => 'expense',
            ]
        );

        $totals = [
            'income_total' => 0.0,
            'cost_of_sales_total' => 0.0,
            'expense_total' => 0.0,
        ];
        foreach ($rows as $row) {
            $accountType = (string)($row['account_type'] ?? '');
            $debit = (float)($row['total_debit'] ?? 0);
            $credit = (float)($row['total_credit'] ?? 0);

            if ($accountType === 'income') {
                $totals['income_total'] += round($credit - $debit, 2);
            } elseif ($accountType === 'cost_of_sales') {
                $totals['cost_of_sales_total'] += round($debit - $credit, 2);
            } elseif ($accountType === 'expense') {
                $totals['expense_total'] += round($debit - $credit, 2);
            }
        }

        return $totals;
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
                'expense_total' => 0.0,
                'net_profit' => 0.0,
                'transaction_count' => 0,
                'uncategorised_count' => 0,
                'upload_count' => 0,
                'committed_count' => 0,
                'in_progress_count' => 0,
                'journal_count' => 0,
                'status' => 'no_data',
            ];
            $cursor = $cursor->modify('+1 month');
        }

        return $months;
    }

    private function transactionMonths(int $companyId, int $accountingPeriodId): array
    {
        $result = [];
        foreach (\InterfaceDB::fetchAll(
            'SELECT DATE_FORMAT(txn_date, \'%Y-%m-01\') AS month_start,
                    COUNT(*) AS transaction_count,
                    SUM(CASE WHEN category_status = :uncategorised OR nominal_account_id IS NULL THEN 1 ELSE 0 END) AS uncategorised_count
             FROM transactions
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             GROUP BY DATE_FORMAT(txn_date, \'%Y-%m-01\')',
            [
                'uncategorised' => 'uncategorised',
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        ) as $row) {
            $result[(string)$row['month_start']] = $row;
        }

        return $result;
    }

    private function journalMonths(int $companyId, int $accountingPeriodId): array
    {
        $result = [];
        foreach (\InterfaceDB::fetchAll(
            'SELECT DATE_FORMAT(journal_date, \'%Y-%m-01\') AS month_start,
                    COUNT(*) AS journal_count
             FROM journals
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND is_posted = 1
             GROUP BY DATE_FORMAT(journal_date, \'%Y-%m-01\')',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        ) as $row) {
            $result[(string)$row['month_start']] = (int)($row['journal_count'] ?? 0);
        }

        return $result;
    }

    private function uploadMonths(int $companyId, int $accountingPeriodId): array
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
             GROUP BY DATE_FORMAT(COALESCE(date_range_start, statement_month), \'%Y-%m-01\')',
            [
                'committed' => 'committed',
                'completed' => 'completed',
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        ) as $row) {
            $result[(string)$row['month_start']] = $row;
        }

        return $result;
    }

    private function journalCount(int $companyId, int $accountingPeriodId): int
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return 0;
        }

        return (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM journals
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND is_posted = 1',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        );
    }

    private function transactionCount(int $companyId, int $accountingPeriodId): int
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return 0;
        }

        return (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM transactions
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        );
    }

    private function uncategorisedTransactionCount(int $companyId, int $accountingPeriodId): int
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return 0;
        }

        return (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM transactions
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND (category_status = :uncategorised OR nominal_account_id IS NULL)',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'uncategorised' => 'uncategorised',
            ]
        );
    }
}
