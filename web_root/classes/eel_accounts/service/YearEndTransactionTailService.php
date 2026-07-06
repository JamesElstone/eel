<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class YearEndTransactionTailService
{
    public function __construct(
        private readonly ?\eel_accounts\Service\YearEndMetricsService $metricsService = null,
    ) {
    }

    public function fetchContext(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [
                'available' => false,
                'errors' => ['Select a company and accounting period before reviewing transaction cut-off.'],
            ];
        }

        foreach (['company_accounts', 'transactions'] as $table) {
            if (!$this->tableExists($table)) {
                return [
                    'available' => false,
                    'errors' => ['Company account or transaction tables are not available.'],
                ];
            }
        }

        $metrics = $this->metricsService ?? new \eel_accounts\Service\YearEndMetricsService();
        $accountingPeriod = $metrics->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null) {
            return [
                'available' => false,
                'errors' => ['The selected accounting period could not be found.'],
            ];
        }

        $accounts = \InterfaceDB::fetchAll(
            'SELECT id,
                    account_name,
                    account_type,
                    nominal_account_id,
                    is_active
             FROM company_accounts
             WHERE company_id = :company_id
             ORDER BY is_active DESC, account_type ASC, account_name ASC, id ASC',
            ['company_id' => $companyId]
        );

        $rows = [];
        $periodEnd = (string)($accountingPeriod['period_end'] ?? '');
        foreach ((array)$accounts as $account) {
            if (!is_array($account)) {
                continue;
            }

            $accountId = (int)($account['id'] ?? 0);
            $lastTransaction = $this->fetchLastTransaction($companyId, $accountingPeriodId, $accountId);
            $rows[] = [
                'account_id' => $accountId,
                'account' => (string)($account['account_name'] ?? ''),
                'account_type' => (string)($account['account_type'] ?? ''),
                'is_active' => (int)($account['is_active'] ?? 0),
                'last_transaction_date' => (string)($lastTransaction['txn_date'] ?? ''),
                'last_transaction_desc' => (string)($lastTransaction['description'] ?? ''),
                'last_transaction_amount' => $lastTransaction['amount'] ?? null,
                'balance' => $this->fetchBalanceAtPeriodEnd(
                    $companyId,
                    $accountId,
                    (string)($account['account_type'] ?? ''),
                    (int)($account['nominal_account_id'] ?? 0),
                    $periodEnd
                ),
            ];
        }

        return [
            'available' => true,
            'accounting_period' => $accountingPeriod,
            'rows' => $rows,
            'account_count' => count($rows),
            'accounts_with_transactions' => count(array_filter($rows, static fn(array $row): bool => (string)($row['last_transaction_date'] ?? '') !== '')),
            'acknowledgement' => $this->fetchAcknowledgement($companyId, $accountingPeriodId),
        ];
    }

    private function fetchLastTransaction(int $companyId, int $accountingPeriodId, int $accountId): ?array
    {
        if ($accountId <= 0) {
            return null;
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT id,
                    txn_date,
                    COALESCE(description, counterparty_name, \'\') AS description,
                    amount,
                    balance
             FROM transactions
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND account_id = :account_id
               AND txn_date = (
                   SELECT MAX(t2.txn_date)
                   FROM transactions t2
                   WHERE t2.company_id = :max_company_id
                     AND t2.accounting_period_id = :max_accounting_period_id
                     AND t2.account_id = :max_account_id
               )
             ORDER BY id DESC',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'account_id' => $accountId,
                'max_company_id' => $companyId,
                'max_accounting_period_id' => $accountingPeriodId,
                'max_account_id' => $accountId,
            ]
        );

        return $this->selectLastTransactionForDate((array)$rows);
    }

    private function fetchBalanceAtPeriodEnd(int $companyId, int $accountId, string $accountType, int $nominalAccountId, string $periodEnd): ?string
    {
        if ($accountId <= 0 || trim($periodEnd) === '') {
            return null;
        }

        $statementBalance = $this->fetchStatementBalanceAtPeriodEnd($companyId, $accountId, $periodEnd);
        if ($statementBalance !== null) {
            return $statementBalance;
        }

        return $this->fetchLedgerBalanceAtPeriodEnd($companyId, $accountId, $accountType, $nominalAccountId, $periodEnd);
    }

    private function fetchStatementBalanceAtPeriodEnd(int $companyId, int $accountId, string $periodEnd): ?string
    {
        $rows = \InterfaceDB::fetchAll(
            'SELECT id,
                    txn_date,
                    amount,
                    balance
             FROM transactions
             WHERE company_id = :company_id
               AND account_id = :account_id
               AND txn_date <= :period_end
               AND balance IS NOT NULL
               AND txn_date = (
                   SELECT MAX(t2.txn_date)
                   FROM transactions t2
                   WHERE t2.company_id = :max_company_id
                     AND t2.account_id = :max_account_id
                     AND t2.txn_date <= :max_period_end
                     AND t2.balance IS NOT NULL
               )
             ORDER BY id DESC',
            [
                'company_id' => $companyId,
                'account_id' => $accountId,
                'period_end' => $periodEnd,
                'max_company_id' => $companyId,
                'max_account_id' => $accountId,
                'max_period_end' => $periodEnd,
            ]
        );

        $row = $this->selectLastTransactionForDate((array)$rows);
        if (!is_array($row) || ($row['balance'] ?? null) === null) {
            return null;
        }

        return (string)$row['balance'];
    }

    private function selectLastTransactionForDate(array $transactions): ?array
    {
        $transactions = array_values(array_filter($transactions, 'is_array'));
        if ($transactions === []) {
            return null;
        }

        if (count($transactions) === 1) {
            return $transactions[0];
        }

        $previousBalanceKeys = [];
        foreach ($transactions as $transaction) {
            if (!$this->isNumericMoney($transaction['amount'] ?? null) || !$this->isNumericMoney($transaction['balance'] ?? null)) {
                continue;
            }

            $previousBalanceKeys[$this->moneyKey((float)$transaction['balance'] - (float)$transaction['amount'])] = true;
        }

        $terminalTransactions = [];
        foreach ($transactions as $transaction) {
            if (!$this->isNumericMoney($transaction['balance'] ?? null)) {
                continue;
            }

            if (!isset($previousBalanceKeys[$this->moneyKey((float)$transaction['balance'])])) {
                $terminalTransactions[] = $transaction;
            }
        }

        return count($terminalTransactions) === 1 ? $terminalTransactions[0] : $transactions[0];
    }

    private function isNumericMoney(mixed $value): bool
    {
        return $value !== null && trim((string)$value) !== '' && is_numeric($value);
    }

    private function moneyKey(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }

    private function fetchLedgerBalanceAtPeriodEnd(int $companyId, int $accountId, string $accountType, int $nominalAccountId, string $periodEnd): ?string
    {
        if (!$this->tableExists('journals') || !$this->tableExists('journal_lines')) {
            return null;
        }

        $conditions = [
            'j.company_id = :company_id',
            'j.journal_date <= :period_end',
            'j.is_posted = 1',
        ];
        $params = [
            'company_id' => $companyId,
            'period_end' => $periodEnd,
        ];

        if ($accountType === \eel_accounts\Service\CompanyAccountService::TYPE_TRADE) {
            $conditions[] = 'jl.company_account_id = :company_account_id';
            $params['company_account_id'] = $accountId;
        } elseif ($nominalAccountId > 0) {
            $conditions[] = 'jl.nominal_account_id = :nominal_account_id';
            $params['nominal_account_id'] = $nominalAccountId;
        } else {
            return null;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT COUNT(jl.id) AS line_count,
                    COALESCE(SUM(COALESCE(jl.debit, 0) - COALESCE(jl.credit, 0)), 0.00) AS balance
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE ' . implode(' AND ', $conditions),
            $params
        );

        if (!is_array($row) || (int)($row['line_count'] ?? 0) <= 0) {
            return null;
        }

        return number_format(round((float)($row['balance'] ?? 0), 2), 2, '.', '');
    }

    private function tableExists(string $table): bool
    {
        try {
            return \InterfaceDB::tableExists($table);
        } catch (\Throwable) {
            return false;
        }
    }

    private function fetchAcknowledgement(int $companyId, int $accountingPeriodId): ?array
    {
        if (!$this->tableExists('year_end_review_acknowledgements')) {
            return null;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT acknowledged_at,
                    acknowledged_by,
                    note
             FROM year_end_review_acknowledgements
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND check_code = :check_code
             LIMIT 1',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'check_code' => 'transaction_tail_review',
            ]
        );

        return is_array($row) ? $row : null;
    }
}
