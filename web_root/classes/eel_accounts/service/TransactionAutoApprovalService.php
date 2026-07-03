<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class TransactionAutoApprovalService
{
    public const STATE_PENDING = 'pending';
    public const STATE_CHECKED = 'checked';
    public const STATE_CONFIRMED = 'confirmed';

    public static function currentApprovalSql(string $alias = 'taa', string $transactionAlias = 't'): string
    {
        return 'COALESCE(' . $alias . ".state = 'confirmed'
            AND " . $alias . '.confirmed_at IS NOT NULL
            AND ' . $alias . '.confirmed_transaction_updated_at = ' . $transactionAlias . '.updated_at, 0)';
    }

    public static function currentCheckedSql(string $alias = 'taa', string $transactionAlias = 't'): string
    {
        return 'COALESCE((' . $alias . ".state = 'checked'
                AND " . $alias . '.state_change_transaction_updated_at = ' . $transactionAlias . '.updated_at)
            OR (' . self::currentApprovalSql($alias, $transactionAlias) . '), 0)';
    }

    public function setTransactionApprovalState(
        int $companyId,
        int $accountingPeriodId,
        int $transactionId,
        bool $checked,
        ?int $userId = null
    ): array {
        $result = $this->setTransactionApprovalStates(
            $companyId,
            $accountingPeriodId,
            [$transactionId => $checked],
            $userId
        );

        if (empty($result['success'])) {
            return $result;
        }

        return [
            'success' => true,
            'state' => $checked ? self::STATE_CHECKED : self::STATE_PENDING,
            'updated' => (int)($result['updated'] ?? 0),
            'errors' => [],
        ];
    }

    public function toggleTransactionApproval(
        int $companyId,
        int $accountingPeriodId,
        int $transactionId,
        bool $checked,
        ?int $userId = null
    ): array {
        return $this->setTransactionApprovalState($companyId, $accountingPeriodId, $transactionId, $checked, $userId);
    }

    public function setTransactionApprovalStates(
        int $companyId,
        int $accountingPeriodId,
        array $states,
        ?int $userId = null
    ): array {
        $normalisedStates = [];
        foreach ($states as $transactionId => $checked) {
            $transactionId = (int)$transactionId;
            if ($transactionId > 0) {
                $normalisedStates[$transactionId] = filter_var($checked, FILTER_VALIDATE_BOOL);
            }
        }

        if ($normalisedStates === []) {
            return [
                'success' => false,
                'errors' => ['Select an auto-categorised rule-based transaction before changing auto approval.'],
            ];
        }

        (new \eel_accounts\Service\YearEndLockService())->assertUnlocked($companyId, $accountingPeriodId, 'change auto categorisation approval');

        $transactions = $this->fetchAutoRuleTransactions($companyId, $accountingPeriodId, array_keys($normalisedStates));
        if (count($transactions) !== count($normalisedStates)) {
            return [
                'success' => false,
                'errors' => ['One or more selected transactions are no longer rule-based auto categorisations. Refresh the page and try again.'],
            ];
        }

        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $actorUserId = $userId !== null && $userId > 0 ? $userId : null;
        $statement = \InterfaceDB::prepare(
            'INSERT INTO transaction_auto_approvals (
                transaction_id,
                state,
                state_change_user_id,
                state_change_at,
                state_change_transaction_updated_at,
                confirmed_by_user_id,
                confirmed_at,
                confirmed_transaction_updated_at,
                created_at,
                updated_at
            ) VALUES (
                :transaction_id,
                :state,
                :state_change_user_id,
                :state_change_at,
                :state_change_transaction_updated_at,
                NULL,
                NULL,
                NULL,
                :created_at,
                :updated_at
            )
            ON DUPLICATE KEY UPDATE
                state = VALUES(state),
                state_change_user_id = VALUES(state_change_user_id),
                state_change_at = VALUES(state_change_at),
                state_change_transaction_updated_at = VALUES(state_change_transaction_updated_at),
                confirmed_by_user_id = NULL,
                confirmed_at = NULL,
                confirmed_transaction_updated_at = NULL,
                updated_at = VALUES(updated_at)'
        );

        $updated = 0;
        foreach ($transactions as $transaction) {
            $transactionId = (int)($transaction['id'] ?? 0);
            $checked = (bool)($normalisedStates[$transactionId] ?? false);
            $statement->execute([
                'transaction_id' => $transactionId,
                'state' => $checked ? self::STATE_CHECKED : self::STATE_PENDING,
                'state_change_user_id' => $actorUserId,
                'state_change_at' => $now,
                'state_change_transaction_updated_at' => $checked ? (string)$transaction['updated_at'] : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $updated++;
        }

        return [
            'success' => true,
            'updated' => $updated,
            'errors' => [],
        ];
    }

    public function pendingPostConfirmationCount(int $companyId, int $accountingPeriodId, ?string $monthKey = null): int
    {
        return count($this->fetchPostableAutoTransactionsNeedingConfirmation($companyId, $accountingPeriodId, $monthKey));
    }

    public function confirmPostableAutoTransactions(
        int $companyId,
        int $accountingPeriodId,
        ?string $monthKey,
        ?int $userId = null
    ): array {
        $transactions = $this->fetchPostableAutoTransactionsNeedingConfirmation($companyId, $accountingPeriodId, $monthKey);
        if ($transactions === []) {
            return [
                'success' => true,
                'confirmed' => 0,
                'errors' => [],
            ];
        }

        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $actorUserId = $userId !== null && $userId > 0 ? $userId : null;
        $statement = \InterfaceDB::prepare(
            'INSERT INTO transaction_auto_approvals (
                transaction_id,
                state,
                state_change_user_id,
                state_change_at,
                state_change_transaction_updated_at,
                confirmed_by_user_id,
                confirmed_at,
                confirmed_transaction_updated_at,
                created_at,
                updated_at
            ) VALUES (
                :transaction_id,
                :state,
                :state_change_user_id,
                :state_change_at,
                :state_change_transaction_updated_at,
                :confirmed_by_user_id,
                :confirmed_at,
                :confirmed_transaction_updated_at,
                :created_at,
                :updated_at
            )
            ON DUPLICATE KEY UPDATE
                state = VALUES(state),
                state_change_user_id = VALUES(state_change_user_id),
                state_change_at = VALUES(state_change_at),
                state_change_transaction_updated_at = VALUES(state_change_transaction_updated_at),
                confirmed_by_user_id = VALUES(confirmed_by_user_id),
                confirmed_at = VALUES(confirmed_at),
                confirmed_transaction_updated_at = VALUES(confirmed_transaction_updated_at),
                updated_at = VALUES(updated_at)'
        );

        foreach ($transactions as $transaction) {
            $statement->execute([
                'transaction_id' => (int)$transaction['id'],
                'state' => self::STATE_CONFIRMED,
                'state_change_user_id' => $actorUserId,
                'state_change_at' => $now,
                'state_change_transaction_updated_at' => (string)$transaction['updated_at'],
                'confirmed_by_user_id' => $actorUserId,
                'confirmed_at' => $now,
                'confirmed_transaction_updated_at' => (string)$transaction['updated_at'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return [
            'success' => true,
            'confirmed' => count($transactions),
            'errors' => [],
        ];
    }

    private function fetchAutoRuleTransaction(int $companyId, int $accountingPeriodId, int $transactionId): ?array
    {
        $rows = $this->fetchAutoRuleTransactions($companyId, $accountingPeriodId, [$transactionId]);

        return $rows[$transactionId] ?? null;
    }

    private function fetchAutoRuleTransactions(int $companyId, int $accountingPeriodId, array $transactionIds): array
    {
        $transactionIds = array_values(array_unique(array_filter(
            array_map(static fn(mixed $id): int => (int)$id, $transactionIds),
            static fn(int $id): bool => $id > 0
        )));

        if ($companyId <= 0 || $accountingPeriodId <= 0 || $transactionIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'category_status' => 'auto',
        ];
        foreach ($transactionIds as $index => $transactionId) {
            $key = 'transaction_id_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $transactionId;
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT id, company_id, accounting_period_id, category_status, auto_rule_id, updated_at
             FROM transactions
             WHERE id IN (' . implode(', ', $placeholders) . ')
               AND company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND category_status = :category_status
               AND auto_rule_id IS NOT NULL
               AND auto_rule_id > 0',
            $params
        );

        $indexed = [];
        foreach ($rows as $row) {
            if (is_array($row) && (int)($row['id'] ?? 0) > 0) {
                $indexed[(int)$row['id']] = $row;
            }
        }

        return $indexed;
    }

    private function fetchPostableAutoTransactionsNeedingConfirmation(int $companyId, int $accountingPeriodId, ?string $monthKey): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [];
        }

        $where = [
            't.company_id = :company_id',
            't.accounting_period_id = :accounting_period_id',
            't.category_status = :category_status',
            't.auto_rule_id IS NOT NULL',
            't.auto_rule_id > 0',
            't.nominal_account_id IS NOT NULL',
            'taa.state = :checked_state',
            'taa.state_change_transaction_updated_at = t.updated_at',
        ];
        $params = [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'category_status' => 'auto',
            'checked_state' => self::STATE_CHECKED,
        ];

        $monthKey = trim((string)$monthKey);
        if (preg_match('/^\d{4}-\d{2}-01$/', $monthKey) === 1) {
            $monthStart = new \DateTimeImmutable($monthKey);
            $monthEnd = $monthStart->modify('last day of this month');
            $where[] = 't.txn_date BETWEEN :month_start AND :month_end';
            $params['month_start'] = $monthStart->format('Y-m-d');
            $params['month_end'] = $monthEnd->format('Y-m-d');
        }

        $stmt = \InterfaceDB::prepare(
            'SELECT t.id, t.updated_at
             FROM transactions t
             LEFT JOIN transaction_auto_approvals taa ON taa.transaction_id = t.id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY t.txn_date ASC, t.id ASC'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }
}
