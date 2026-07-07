<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class TransactionInterAccountMarkerService
{
    public function hasSchema(): bool
    {
        static $hasSchema = null;
        if ($hasSchema !== null) {
            return $hasSchema;
        }

        try {
            $hasSchema = \InterfaceDB::tableExists('transaction_inter_ac_marker');
        } catch (\Throwable) {
            $hasSchema = false;
        }

        return $hasSchema;
    }

    public function fetchMarkerForTransaction(int $transactionId): ?array
    {
        if ($transactionId <= 0 || !$this->hasSchema()) {
            return null;
        }

        $stmt = \InterfaceDB::prepare(
            "SELECT tiam.id,
                    tiam.company_id,
                    tiam.accounting_period_id,
                    tiam.transaction_id,
                    tiam.matched_transaction_id,
                    tiam.created_at,
                    tiam.created_by,
                    CASE
                        WHEN tiam.transaction_id = :transaction_id THEN 'source'
                        ELSE 'matched'
                    END AS role
             FROM transaction_inter_ac_marker tiam
             WHERE tiam.transaction_id = :transaction_id
                OR tiam.matched_transaction_id = :transaction_id
             LIMIT 1"
        );
        $stmt->execute(['transaction_id' => $transactionId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function isMatchedNoPostTransaction(int $transactionId): bool
    {
        if ($transactionId <= 0 || !$this->hasSchema()) {
            return false;
        }

        return \InterfaceDB::countWhere('transaction_inter_ac_marker', [
            'matched_transaction_id' => $transactionId,
        ]) > 0;
    }

    public function fetchCandidates(int $transactionId, int $limit = 50): array
    {
        if ($transactionId <= 0 || !$this->hasSchema()) {
            return [];
        }

        $transaction = $this->fetchTransaction($transactionId);
        if ($transaction === null) {
            return [];
        }

        $amount = round(abs((float)($transaction['amount'] ?? 0)), 2);
        $accountId = (int)($transaction['account_id'] ?? 0);
        $txnDate = trim((string)($transaction['txn_date'] ?? ''));
        if ($amount <= 0.0 || $accountId <= 0 || $txnDate === '') {
            return [];
        }

        $limit = max(1, min($limit, 100));
        $stmt = \InterfaceDB::prepare(
            "SELECT candidate.id,
                    candidate.account_id,
                    candidate.txn_date,
                    candidate.description,
                    candidate.amount,
                    COALESCE(ca.account_name, '') AS account_name,
                    ABS(DATEDIFF(candidate.txn_date, :distance_txn_date)) AS date_distance
             FROM transactions candidate
             INNER JOIN company_accounts ca ON ca.id = candidate.account_id
             WHERE candidate.company_id = :company_id
               AND candidate.accounting_period_id = :accounting_period_id
               AND candidate.id <> :transaction_id
               AND candidate.account_id <> :account_id
               AND ABS(ROUND(candidate.amount, 2)) = :amount
               AND candidate.txn_date BETWEEN DATE_SUB(:window_txn_date_start, INTERVAL 4 DAY) AND DATE_ADD(:window_txn_date_end, INTERVAL 4 DAY)
               AND NOT EXISTS (
                   SELECT 1
                   FROM transaction_inter_ac_marker existing_marker
                   WHERE existing_marker.transaction_id IN (:source_transaction_id, candidate.id)
                      OR existing_marker.matched_transaction_id IN (:matched_transaction_id, candidate.id)
               )
             ORDER BY date_distance ASC, candidate.txn_date ASC, candidate.id ASC
             LIMIT {$limit}"
        );
        $stmt->execute([
            'transaction_id' => $transactionId,
            'company_id' => (int)$transaction['company_id'],
            'accounting_period_id' => (int)$transaction['accounting_period_id'],
            'account_id' => $accountId,
            'amount' => number_format($amount, 2, '.', ''),
            'distance_txn_date' => $txnDate,
            'window_txn_date_start' => $txnDate,
            'window_txn_date_end' => $txnDate,
            'source_transaction_id' => $transactionId,
            'matched_transaction_id' => $transactionId,
        ]);

        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function saveMarker(int $transactionId, int $matchedTransactionId, string $createdBy = 'web_app'): array
    {
        if (!$this->hasSchema()) {
            return [
                'success' => false,
                'errors' => ['The inter-account marker table has not been installed yet.'],
            ];
        }

        $errors = $this->validateMarker($transactionId, $matchedTransactionId);
        if ($errors !== []) {
            return [
                'success' => false,
                'errors' => $errors,
            ];
        }

        $transaction = $this->fetchTransaction($transactionId);
        if ($transaction === null) {
            return [
                'success' => false,
                'errors' => ['The source transaction could not be found.'],
            ];
        }

        (new \eel_accounts\Service\YearEndLockService())->assertUnlocked(
            (int)$transaction['company_id'],
            (int)$transaction['accounting_period_id'],
            'mark an inter-account transaction'
        );

        \InterfaceDB::prepareExecute(
            'INSERT INTO transaction_inter_ac_marker
                (company_id, accounting_period_id, transaction_id, matched_transaction_id, created_by)
             VALUES
                (:company_id, :accounting_period_id, :transaction_id, :matched_transaction_id, :created_by)',
            [
                'company_id' => (int)$transaction['company_id'],
                'accounting_period_id' => (int)$transaction['accounting_period_id'],
                'transaction_id' => $transactionId,
                'matched_transaction_id' => $matchedTransactionId,
                'created_by' => $createdBy !== '' ? substr($createdBy, 0, 100) : 'web_app',
            ]
        );

        return [
            'success' => true,
            'errors' => [],
            'marker' => $this->fetchMarkerForTransaction($transactionId),
        ];
    }

    public function clearMarkerForTransaction(int $transactionId): array
    {
        if ($transactionId <= 0 || !$this->hasSchema()) {
            return [
                'success' => true,
                'removed' => false,
                'errors' => [],
            ];
        }

        $marker = $this->fetchMarkerForTransaction($transactionId);
        if ($marker === null) {
            return [
                'success' => true,
                'removed' => false,
                'errors' => [],
            ];
        }

        (new \eel_accounts\Service\YearEndLockService())->assertUnlocked(
            (int)$marker['company_id'],
            (int)$marker['accounting_period_id'],
            'remove an inter-account transaction marker'
        );

        \InterfaceDB::prepareExecute(
            'DELETE FROM transaction_inter_ac_marker WHERE id = :id',
            ['id' => (int)$marker['id']]
        );

        return [
            'success' => true,
            'removed' => true,
            'errors' => [],
            'marker' => $marker,
        ];
    }

    private function validateMarker(int $transactionId, int $matchedTransactionId): array
    {
        $errors = [];
        if ($transactionId <= 0 || $matchedTransactionId <= 0) {
            return ['Choose a valid source transaction and matching transaction.'];
        }

        if ($transactionId === $matchedTransactionId) {
            return ['The source and matching transactions must be different rows.'];
        }

        if ($this->fetchMarkerForTransaction($transactionId) !== null) {
            $errors[] = 'The source transaction already has an inter-account marker.';
        }

        if ($this->fetchMarkerForTransaction($matchedTransactionId) !== null) {
            $errors[] = 'The matching transaction already has an inter-account marker.';
        }

        $transaction = $this->fetchTransaction($transactionId);
        $matchedTransaction = $this->fetchTransaction($matchedTransactionId);
        if ($transaction === null || $matchedTransaction === null) {
            $errors[] = 'Both transactions must exist before they can be matched.';
            return $errors;
        }

        if ((int)$transaction['company_id'] !== (int)$matchedTransaction['company_id']) {
            $errors[] = 'Inter-account matches must belong to the same company.';
        }

        if ((int)$transaction['accounting_period_id'] !== (int)$matchedTransaction['accounting_period_id']) {
            $errors[] = 'Inter-account matches must belong to the same accounting period.';
        }

        if ((int)($transaction['account_id'] ?? 0) <= 0 || (int)($matchedTransaction['account_id'] ?? 0) <= 0) {
            $errors[] = 'Both transactions must have a source bank or trade account.';
        } elseif ((int)$transaction['account_id'] === (int)$matchedTransaction['account_id']) {
            $errors[] = 'Inter-account matches must come from different source accounts.';
        }

        $sourceAmount = round(abs((float)($transaction['amount'] ?? 0)), 2);
        $matchedAmount = round(abs((float)($matchedTransaction['amount'] ?? 0)), 2);
        if ($sourceAmount <= 0.0 || abs($sourceAmount - $matchedAmount) >= 0.005) {
            $errors[] = 'Inter-account matches must have the same absolute amount.';
        }

        $dateDistance = $this->dateDistanceDays(
            (string)($transaction['txn_date'] ?? ''),
            (string)($matchedTransaction['txn_date'] ?? '')
        );
        if ($dateDistance === null || $dateDistance > 4) {
            $errors[] = 'Inter-account matches must be dated within four days of each other.';
        }

        return $errors;
    }

    private function fetchTransaction(int $transactionId): ?array
    {
        $stmt = \InterfaceDB::prepare(
            'SELECT id,
                    company_id,
                    accounting_period_id,
                    account_id,
                    txn_date,
                    description,
                    amount
             FROM transactions
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $transactionId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function dateDistanceDays(string $left, string $right): ?int
    {
        try {
            $leftDate = new \DateTimeImmutable($left);
            $rightDate = new \DateTimeImmutable($right);
        } catch (\Throwable) {
            return null;
        }

        return (int)abs($leftDate->diff($rightDate)->days);
    }
}
