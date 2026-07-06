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
                    is_active
             FROM company_accounts
             WHERE company_id = :company_id
             ORDER BY is_active DESC, account_type ASC, account_name ASC, id ASC',
            ['company_id' => $companyId]
        );

        $rows = [];
        foreach ((array)$accounts as $account) {
            if (!is_array($account)) {
                continue;
            }

            $lastTransaction = $this->fetchLastTransaction($companyId, $accountingPeriodId, (int)($account['id'] ?? 0));
            $rows[] = [
                'account_id' => (int)($account['id'] ?? 0),
                'account' => (string)($account['account_name'] ?? ''),
                'account_type' => (string)($account['account_type'] ?? ''),
                'is_active' => (int)($account['is_active'] ?? 0),
                'last_transaction_date' => (string)($lastTransaction['txn_date'] ?? ''),
                'last_transaction_desc' => (string)($lastTransaction['description'] ?? ''),
                'last_transaction_amount' => $lastTransaction['amount'] ?? null,
            ];
        }

        return [
            'available' => true,
            'accounting_period' => $accountingPeriod,
            'rows' => $rows,
            'account_count' => count($rows),
            'accounts_with_transactions' => count(array_filter($rows, static fn(array $row): bool => (string)($row['last_transaction_date'] ?? '') !== '')),
        ];
    }

    private function fetchLastTransaction(int $companyId, int $accountingPeriodId, int $accountId): ?array
    {
        if ($accountId <= 0) {
            return null;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT txn_date,
                    COALESCE(description, counterparty_name, \'\') AS description,
                    amount
             FROM transactions
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND account_id = :account_id
             ORDER BY txn_date DESC, id DESC
             LIMIT 1',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'account_id' => $accountId,
            ]
        );

        return is_array($row) ? $row : null;
    }

    private function tableExists(string $table): bool
    {
        try {
            return \InterfaceDB::tableExists($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
