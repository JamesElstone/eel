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
    private const TRANSFER_MARKER_CREATED_BY = 'transfer_marker:auto';

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

    public function autoMatchTransferMarkerTransaction(int $transactionId, string $changedBy = 'transfer_marker_auto'): array
    {
        if ($transactionId <= 0 || !$this->hasSchema()) {
            return [
                'success' => true,
                'matched' => false,
                'skipped_reason' => 'schema_unavailable',
                'errors' => [],
            ];
        }

        if ($this->fetchMarkerForTransaction($transactionId) !== null) {
            return [
                'success' => true,
                'matched' => false,
                'skipped_reason' => 'already_matched',
                'errors' => [],
            ];
        }

        $transaction = $this->fetchTransferMarkerTransaction($transactionId);
        if ($transaction === null || !$this->transactionCanAutoMatch($transaction)) {
            return [
                'success' => true,
                'matched' => false,
                'skipped_reason' => 'not_eligible',
                'errors' => [],
            ];
        }

        $candidates = $this->fetchTransferMarkerAutoMatchCandidates($transaction);
        if (count($candidates) !== 1) {
            return [
                'success' => true,
                'matched' => false,
                'skipped_reason' => count($candidates) > 1 ? 'ambiguous' : 'no_candidate',
                'candidate_count' => count($candidates),
                'errors' => [],
            ];
        }

        $candidate = $candidates[0];
        $sourceTransactionId = (float)($transaction['amount'] ?? 0) < 0
            ? (int)$transaction['id']
            : (int)$candidate['id'];
        $matchedTransactionId = $sourceTransactionId === (int)$transaction['id']
            ? (int)$candidate['id']
            : (int)$transaction['id'];

        $result = $this->saveMarker($sourceTransactionId, $matchedTransactionId, self::TRANSFER_MARKER_CREATED_BY, $changedBy);

        return [
            'success' => empty($result['errors']),
            'matched' => empty($result['errors']),
            'source_transaction_id' => $sourceTransactionId,
            'matched_transaction_id' => $matchedTransactionId,
            'marker' => $result['marker'] ?? null,
            'errors' => array_map('strval', (array)($result['errors'] ?? [])),
        ];
    }

    public function isTransferMarkerCreatedBy(?string $createdBy): bool
    {
        return str_starts_with(trim((string)$createdBy), 'transfer_marker:');
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
        $dateDistanceExpression = \InterfaceDB::driverName() === 'sqlite'
            ? 'ABS(julianday(candidate.txn_date) - julianday(:distance_txn_date))'
            : 'ABS(DATEDIFF(candidate.txn_date, :distance_txn_date))';
        $dateWindowPredicate = \InterfaceDB::driverName() === 'sqlite'
            ? "candidate.txn_date BETWEEN date(:window_txn_date_start, '-4 days') AND date(:window_txn_date_end, '+4 days')"
            : 'candidate.txn_date BETWEEN DATE_SUB(:window_txn_date_start, INTERVAL 4 DAY) AND DATE_ADD(:window_txn_date_end, INTERVAL 4 DAY)';
        $stmt = \InterfaceDB::prepare(
            "SELECT candidate.id,
                    candidate.account_id,
                    candidate.txn_date,
                    candidate.description,
                    candidate.amount,
                    COALESCE(ca.account_name, '') AS account_name,
                    {$dateDistanceExpression} AS date_distance
             FROM transactions candidate
             INNER JOIN company_accounts ca ON ca.id = candidate.account_id
             WHERE candidate.company_id = :company_id
               AND candidate.accounting_period_id = :accounting_period_id
               AND candidate.id <> :transaction_id
               AND candidate.account_id <> :account_id
               AND CAST(ABS(ROUND(candidate.amount, 2)) AS DECIMAL(18, 2)) = CAST(:amount AS DECIMAL(18, 2))
               AND {$dateWindowPredicate}
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

    public function saveMarker(
        int $transactionId,
        int $matchedTransactionId,
        string $createdBy = 'web_app',
        string $changedBy = 'inter_ac_marker'
    ): array
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

        $ownsTransaction = !\InterfaceDB::inTransaction();

        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
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

            $stateResult = (new \eel_accounts\Service\TransactionCategorisationService())->applyInterAccountMatchState(
                $transactionId,
                $matchedTransactionId,
                $changedBy
            );
            if (!empty($stateResult['errors'])) {
                if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                    \InterfaceDB::rollBack();
                }

                return [
                    'success' => false,
                    'errors' => array_map('strval', (array)$stateResult['errors']),
                ];
            }

            $journalService = new \eel_accounts\Service\TransactionJournalService();
            $defaultBankNominalId = $this->defaultBankNominalId((int)$transaction['company_id']);
            foreach ([$transactionId, $matchedTransactionId] as $journalTransactionId) {
                $journalResult = $journalService->syncJournalForTransaction(
                    (int)$journalTransactionId,
                    $defaultBankNominalId,
                    $changedBy,
                    true
                );
                if (!empty($journalResult['errors'])) {
                    if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                        \InterfaceDB::rollBack();
                    }

                    return [
                        'success' => false,
                        'errors' => array_map('strval', (array)$journalResult['errors']),
                    ];
                }
            }

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            throw $exception;
        }

        return [
            'success' => true,
            'errors' => [],
            'marker' => $this->fetchMarkerForTransaction($transactionId),
        ];
    }

    public function clearMarkerForTransaction(int $transactionId, string $changedBy = 'inter_ac_cancel'): array
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

        $sourceTransactionId = (int)$marker['transaction_id'];
        $matchedTransactionId = (int)$marker['matched_transaction_id'];
        $ownsTransaction = !\InterfaceDB::inTransaction();

        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            $journalService = new \eel_accounts\Service\TransactionJournalService();
            foreach ([$sourceTransactionId, $matchedTransactionId] as $journalTransactionId) {
                $journalResult = $journalService->removeJournalForTransaction((int)$journalTransactionId);
                if (!empty($journalResult['errors'])) {
                    if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                        \InterfaceDB::rollBack();
                    }

                    return [
                        'success' => false,
                        'removed' => false,
                        'errors' => array_map('strval', (array)$journalResult['errors']),
                    ];
                }
            }

            \InterfaceDB::prepareExecute(
                'DELETE FROM transaction_inter_ac_marker WHERE id = :id',
                ['id' => (int)$marker['id']]
            );

            $stateResult = (new \eel_accounts\Service\TransactionCategorisationService())->clearInterAccountMatchState(
                $sourceTransactionId,
                $matchedTransactionId,
                $changedBy
            );
            if (!empty($stateResult['errors'])) {
                if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                    \InterfaceDB::rollBack();
                }

                return [
                    'success' => false,
                    'removed' => false,
                    'errors' => array_map('strval', (array)$stateResult['errors']),
                ];
            }

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            throw $exception;
        }

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

    private function fetchTransferMarkerTransaction(int $transactionId): ?array
    {
        $splitSelect = \InterfaceDB::tableExists('transaction_splits')
            ? "EXISTS (SELECT 1 FROM transaction_splits ts WHERE ts.transaction_id = t.id)"
            : '0';
        $journalSelect = "EXISTS (
                SELECT 1
                FROM journals j
                WHERE j.company_id = t.company_id
                  AND j.source_type = 'bank_csv'
                  AND j.source_ref = CONCAT('transaction:', t.id)
            )";

        $stmt = \InterfaceDB::prepare(
            'SELECT t.id,
                    t.company_id,
                    t.accounting_period_id,
                    t.account_id,
                    t.txn_date,
                    t.txn_type,
                    t.description,
                    t.amount,
                    t.nominal_account_id,
                    t.transfer_account_id,
                    t.is_internal_transfer,
                    t.category_status,
                    t.is_auto_excluded,
                    ca.account_type,
                    COALESCE(ca.institution_name, \'\') AS institution_name,
                    COALESCE(ca.internal_transfer_marker, \'\') AS internal_transfer_marker,
                    ' . $splitSelect . ' AS has_split,
                    ' . $journalSelect . ' AS has_journal
             FROM transactions t
             INNER JOIN company_accounts ca ON ca.id = t.account_id
             WHERE t.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $transactionId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function fetchTransferMarkerAutoMatchCandidates(array $transaction): array
    {
        $splitPredicate = \InterfaceDB::tableExists('transaction_splits')
            ? 'AND NOT EXISTS (SELECT 1 FROM transaction_splits candidate_ts WHERE candidate_ts.transaction_id = candidate.id)'
            : '';
        $institutionName = $this->normalisedText((string)($transaction['institution_name'] ?? ''));

        $stmt = \InterfaceDB::prepare(
            "SELECT candidate.id,
                    candidate.account_id,
                    candidate.txn_date,
                    candidate.description,
                    candidate.amount,
                    COALESCE(candidate_ca.account_name, '') AS account_name
             FROM transactions candidate
             INNER JOIN company_accounts candidate_ca ON candidate_ca.id = candidate.account_id
             WHERE candidate.company_id = :company_id
               AND candidate.accounting_period_id = :accounting_period_id
               AND candidate.id <> :transaction_id
               AND candidate.account_id <> :account_id
               AND candidate.txn_date = :txn_date
               AND ROUND(candidate.amount + :signed_amount, 2) = 0.00
               AND candidate.nominal_account_id IS NULL
               AND candidate.transfer_account_id IS NULL
               AND COALESCE(candidate.is_internal_transfer, 0) = 1
               AND candidate.category_status = 'uncategorised'
               AND COALESCE(candidate.is_auto_excluded, 0) = 0
               AND candidate_ca.account_type = :bank_account_type
               AND LOWER(TRIM(COALESCE(candidate_ca.institution_name, ''))) = :institution_name
               AND LOWER(TRIM(COALESCE(candidate.txn_type, ''))) = LOWER(TRIM(COALESCE(candidate_ca.internal_transfer_marker, '')))
               AND TRIM(COALESCE(candidate_ca.internal_transfer_marker, '')) <> ''
               AND NOT EXISTS (
                   SELECT 1
                   FROM transaction_inter_ac_marker existing_marker
                   WHERE existing_marker.transaction_id IN (:source_transaction_id, candidate.id)
                      OR existing_marker.matched_transaction_id IN (:matched_transaction_id, candidate.id)
               )
               AND NOT EXISTS (
                   SELECT 1
                   FROM journals candidate_j
                   WHERE candidate_j.company_id = candidate.company_id
                     AND candidate_j.source_type = 'bank_csv'
                     AND candidate_j.source_ref = CONCAT('transaction:', candidate.id)
               )
               {$splitPredicate}
             ORDER BY candidate.id ASC"
        );
        $stmt->execute([
            'company_id' => (int)$transaction['company_id'],
            'accounting_period_id' => (int)$transaction['accounting_period_id'],
            'transaction_id' => (int)$transaction['id'],
            'account_id' => (int)$transaction['account_id'],
            'txn_date' => (string)$transaction['txn_date'],
            'signed_amount' => number_format((float)$transaction['amount'], 2, '.', ''),
            'bank_account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
            'institution_name' => $institutionName,
            'source_transaction_id' => (int)$transaction['id'],
            'matched_transaction_id' => (int)$transaction['id'],
        ]);

        return $stmt->fetchAll();
    }

    private function transactionCanAutoMatch(array $transaction): bool
    {
        if ((int)($transaction['company_id'] ?? 0) <= 0
            || (int)($transaction['accounting_period_id'] ?? 0) <= 0
            || (int)($transaction['account_id'] ?? 0) <= 0
        ) {
            return false;
        }

        if ((string)($transaction['account_type'] ?? '') !== \eel_accounts\Service\CompanyAccountService::TYPE_BANK) {
            return false;
        }

        if ($this->normalisedText((string)($transaction['institution_name'] ?? '')) === '') {
            return false;
        }

        if (!$this->transactionMatchesOwnTransferMarker($transaction)) {
            return false;
        }

        if (round(abs((float)($transaction['amount'] ?? 0)), 2) <= 0.0) {
            return false;
        }

        if ((int)($transaction['has_split'] ?? 0) === 1 || (int)($transaction['has_journal'] ?? 0) === 1) {
            return false;
        }

        return ($transaction['nominal_account_id'] ?? null) === null
            && ($transaction['transfer_account_id'] ?? null) === null
            && (int)($transaction['is_internal_transfer'] ?? 0) === 1
            && (string)($transaction['category_status'] ?? '') === 'uncategorised'
            && (int)($transaction['is_auto_excluded'] ?? 0) === 0;
    }

    private function transactionMatchesOwnTransferMarker(array $transaction): bool
    {
        $txnType = $this->normalisedText((string)($transaction['txn_type'] ?? ''));
        $marker = $this->normalisedText((string)($transaction['internal_transfer_marker'] ?? ''));

        return $txnType !== '' && $marker !== '' && $txnType === $marker;
    }

    private function normalisedText(string $value): string
    {
        return strtolower(trim($value));
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

    private function defaultBankNominalId(int $companyId): int
    {
        if ($companyId <= 0) {
            return 0;
        }

        return (int)((new \eel_accounts\Store\CompanySettingsStore($companyId))->all()['default_bank_nominal_id'] ?? 0);
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
