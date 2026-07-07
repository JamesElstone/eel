<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class TransactionSplitService
{
    private ?bool $schemaReady = null;

    public function hasSchema(): bool
    {
        if ($this->schemaReady !== null) {
            return $this->schemaReady;
        }

        return $this->schemaReady = \InterfaceDB::tableExists('transaction_splits')
            && \InterfaceDB::tableExists('transaction_split_lines');
    }

    public function startSplit(int $companyId, int $transactionId, int $lineCount = 2, string $changedBy = 'transactions_page_split'): array
    {
        if (!$this->hasSchema()) {
            return ['success' => false, 'errors' => ['Run the transaction split migration before splitting transactions.']];
        }

        $transaction = $this->fetchTransaction($companyId, $transactionId);
        if ($transaction === null) {
            return ['success' => false, 'errors' => ['The selected transaction could not be found for this company.']];
        }

        if ($this->transactionIsTransfer($transaction)) {
            return ['success' => false, 'errors' => ['Transfer transactions cannot be split into nominal lines.']];
        }

        (new \eel_accounts\Service\YearEndLockService())->assertUnlocked(
            $companyId,
            (int)$transaction['accounting_period_id'],
            'split transactions in this period'
        );

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            $split = $this->fetchSplitForTransaction($transactionId);
            if ($split === null) {
                \InterfaceDB::prepareExecute(
                    'INSERT INTO transaction_splits (transaction_id, created_at, updated_at)
                     VALUES (:transaction_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
                    ['transaction_id' => $transactionId]
                );
                $splitId = $this->splitIdForTransaction($transactionId);
                if ($splitId <= 0) {
                    throw new \RuntimeException('The split shell could not be reloaded after save.');
                }

                $lineCount = max(2, min($lineCount, 20));
                for ($lineNumber = 1; $lineNumber <= $lineCount; $lineNumber++) {
                    $this->insertLine($splitId, $lineNumber);
                }
            }

            $this->refreshTransactionState($transaction, $changedBy);

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }

            return [
                'success' => true,
                'changed' => $split === null,
                'split' => $this->fetchSplitForTransaction($transactionId),
                'messages' => [$split === null ? 'Draft split created.' : 'Transaction already has a draft split.'],
            ];
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            return ['success' => false, 'errors' => ['The transaction split could not be created: ' . $exception->getMessage()]];
        }
    }

    public function addLine(int $companyId, int $transactionId): array
    {
        $split = $this->fetchSplitForTransaction($transactionId);
        if ($split === null) {
            $result = $this->startSplit($companyId, $transactionId);
            if (empty($result['success'])) {
                return $result;
            }
            $split = is_array($result['split'] ?? null) ? $result['split'] : $this->fetchSplitForTransaction($transactionId);
        }

        if ($split === null || (int)($split['company_id'] ?? 0) !== $companyId) {
            return ['success' => false, 'errors' => ['The selected split could not be found for this company.']];
        }

        (new \eel_accounts\Service\YearEndLockService())->assertUnlocked(
            $companyId,
            (int)$split['accounting_period_id'],
            'add transaction split lines in this period'
        );

        $lineNumber = 1;
        foreach ((array)($split['lines'] ?? []) as $line) {
            $lineNumber = max($lineNumber, (int)($line['line_number'] ?? 0) + 1);
        }

        $this->insertLine((int)$split['id'], $lineNumber);
        $this->refreshTransactionState($split, 'transactions_page_split');

        return [
            'success' => true,
            'messages' => ['Split line added.'],
            'split' => $this->fetchSplitForTransaction($transactionId),
        ];
    }

    public function saveLine(int $companyId, int $lineId, array $payload): array
    {
        if (!$this->hasSchema()) {
            return ['success' => false, 'errors' => ['Run the transaction split migration before editing split lines.']];
        }

        $line = $this->fetchLineWithTransaction($lineId);
        if ($line === null || (int)($line['company_id'] ?? 0) !== $companyId) {
            return ['success' => false, 'errors' => ['The selected split line could not be found for this company.']];
        }

        (new \eel_accounts\Service\YearEndLockService())->assertUnlocked(
            $companyId,
            (int)$line['accounting_period_id'],
            'edit transaction split lines in this period'
        );

        $description = $this->normaliseOptionalString($payload['split_line_description'] ?? $payload['description'] ?? null, 255);
        $amountValue = $payload['split_line_amount'] ?? $payload['amount'] ?? null;
        $amountFormatValid = $this->amountIsBlank($amountValue) || $this->amountHasTwoDecimalPlaces($amountValue);
        $amount = $amountFormatValid ? $this->normaliseAmount($amountValue) : null;
        $nominalAccountId = $this->normaliseNominalId($companyId, $payload['nominal_account_id'] ?? null);
        $notes = $this->normaliseOptionalString($payload['split_line_notes'] ?? $payload['notes'] ?? null, 10000);
        $errors = [];

        if (!$amountFormatValid) {
            $errors[] = 'Split line amounts must use exactly 2 decimal places, for example 56.37.';
        } elseif ($amount !== null && $amount <= 0.0) {
            $errors[] = 'Split line amounts must be positive.';
        }

        if (($payload['nominal_account_id'] ?? null) !== null && trim((string)($payload['nominal_account_id'] ?? '')) !== '' && $nominalAccountId <= 0) {
            $errors[] = 'Choose an active nominal account for the split line.';
        }

        if ($errors === [] && $this->splitLineHasAsset($lineId)) {
            $currentAmount = $line['amount'] === null ? null : round((float)$line['amount'], 2);
            $currentNominalAccountId = (int)($line['nominal_account_id'] ?? 0);
            if ($amount !== $currentAmount || $nominalAccountId !== $currentNominalAccountId) {
                $errors[] = 'This split line is linked to an asset, so its amount and nominal cannot be changed.';
            }
        }

        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        \InterfaceDB::prepareExecute(
            'UPDATE transaction_split_lines
             SET description = :description,
                 amount = :amount,
                 nominal_account_id = :nominal_account_id,
                 notes = :notes,
                 is_deferred = 0,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            [
                'description' => $description,
                'amount' => $amount !== null ? number_format($amount, 2, '.', '') : null,
                'nominal_account_id' => $nominalAccountId > 0 ? $nominalAccountId : null,
                'notes' => $notes,
                'id' => $lineId,
            ]
        );

        $this->refreshTransactionState($line, 'transactions_page_split');

        return [
            'success' => true,
            'messages' => ['Split line saved.'],
            'split' => $this->fetchSplitForTransaction((int)$line['transaction_id']),
        ];
    }

    public function deferLine(int $companyId, int $lineId): array
    {
        $line = $this->fetchLineWithTransaction($lineId);
        if ($line === null || (int)($line['company_id'] ?? 0) !== $companyId) {
            return ['success' => false, 'errors' => ['The selected split line could not be found for this company.']];
        }

        if ($this->splitLineHasAsset($lineId)) {
            return ['success' => false, 'errors' => ['This split line is linked to an asset, so it cannot be deferred.']];
        }

        (new \eel_accounts\Service\YearEndLockService())->assertUnlocked(
            $companyId,
            (int)$line['accounting_period_id'],
            'defer transaction split lines in this period'
        );

        \InterfaceDB::prepareExecute(
            'UPDATE transaction_split_lines
             SET is_deferred = 1,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            ['id' => $lineId]
        );
        $this->refreshTransactionState($line, 'transactions_page_split');

        return [
            'success' => true,
            'messages' => ['Split line deferred for manual review.'],
            'split' => $this->fetchSplitForTransaction((int)$line['transaction_id']),
        ];
    }

    public function removeLine(int $companyId, int $lineId): array
    {
        if (!$this->hasSchema()) {
            return ['success' => false, 'errors' => ['Run the transaction split migration before removing split lines.']];
        }

        $line = $this->fetchLineWithTransaction($lineId);
        if ($line === null || (int)($line['company_id'] ?? 0) !== $companyId) {
            return ['success' => false, 'errors' => ['The selected split line could not be found for this company.']];
        }

        if ($this->splitLineHasAsset($lineId)) {
            return ['success' => false, 'errors' => ['This split line is linked to an asset, so it cannot be removed.']];
        }

        (new \eel_accounts\Service\YearEndLockService())->assertUnlocked(
            $companyId,
            (int)$line['accounting_period_id'],
            'remove transaction split lines in this period'
        );

        $lineCount = (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*) FROM transaction_split_lines WHERE split_id = :split_id',
            ['split_id' => (int)$line['split_id']]
        );
        if ($lineCount <= 1) {
            return ['success' => false, 'errors' => ['A split must keep at least one line.']];
        }

        \InterfaceDB::prepareExecute(
            'DELETE FROM transaction_split_lines WHERE id = :id',
            ['id' => $lineId]
        );
        $this->refreshTransactionState($line, 'transactions_page_split');

        return [
            'success' => true,
            'messages' => ['Split line removed.'],
            'split' => $this->fetchSplitForTransaction((int)$line['transaction_id']),
        ];
    }

    public function mergeSplit(int $companyId, int $transactionId, bool $confirmedJournalRebuild = false): array
    {
        if (!$this->hasSchema()) {
            return ['success' => false, 'errors' => ['Run the transaction split migration before merging split transactions.']];
        }

        $transaction = $this->fetchTransaction($companyId, $transactionId);
        if ($transaction === null) {
            return ['success' => false, 'errors' => ['The selected transaction could not be found for this company.']];
        }

        (new \eel_accounts\Service\YearEndLockService())->assertUnlocked(
            $companyId,
            (int)$transaction['accounting_period_id'],
            'merge transaction splits in this period'
        );

        $hasJournal = (new \eel_accounts\Service\TransactionJournalService())->transactionHasDerivedJournal($transactionId);
        if ($this->splitHasLinkedAssets($transactionId)) {
            return ['success' => false, 'errors' => ['This split has asset-linked lines, so merge is blocked.']];
        }

        if ($hasJournal && !$confirmedJournalRebuild) {
            return [
                'success' => true,
                'changed' => false,
                'requires_confirmation' => true,
                'errors' => [],
            ];
        }

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            if ($hasJournal) {
                \InterfaceDB::prepareExecute(
                    'DELETE FROM journals
                     WHERE company_id = :company_id
                       AND source_type = :source_type
                       AND source_ref = :source_ref',
                    [
                        'company_id' => $companyId,
                        'source_type' => 'bank_csv',
                        'source_ref' => 'transaction:' . $transactionId,
                    ]
                );
            }

            \InterfaceDB::prepareExecute(
                'DELETE FROM transaction_splits WHERE transaction_id = :transaction_id',
                ['transaction_id' => $transactionId]
            );

            $nextState = [
                'nominal_account_id' => null,
                'transfer_account_id' => null,
                'is_internal_transfer' => 0,
                'category_status' => 'uncategorised',
                'auto_rule_id' => null,
                'is_auto_excluded' => 0,
            ];
            $this->persistTransactionState($transaction, $nextState, 'transactions_page_split', 'split_merged');

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }

            return [
                'success' => true,
                'changed' => true,
                'messages' => ['Split merged back to a normal transaction.'],
            ];
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            return ['success' => false, 'errors' => ['The transaction split could not be merged: ' . $exception->getMessage()]];
        }
    }

    public function fetchSplitForTransaction(int $transactionId): ?array
    {
        if ($transactionId <= 0 || !$this->hasSchema()) {
            return null;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT ts.id,
                    ts.transaction_id,
                    t.company_id,
                    t.accounting_period_id,
                    t.account_id,
                    t.txn_date,
                    t.description AS transaction_description,
                    t.amount AS transaction_amount,
                    t.category_status,
                    t.nominal_account_id,
                    t.transfer_account_id,
                    t.is_internal_transfer,
                    t.auto_rule_id,
                    t.is_auto_excluded
             FROM transaction_splits ts
             INNER JOIN transactions t ON t.id = ts.transaction_id
             WHERE ts.transaction_id = :transaction_id
             LIMIT 1',
            ['transaction_id' => $transactionId]
        );

        if (!is_array($row)) {
            return null;
        }

        $row['lines'] = $this->fetchLinesForSplit((int)$row['id']);

        return $this->decorateSplit($row);
    }

    public function fetchSplitsForTransactions(array $transactionIds): array
    {
        $ids = [];
        foreach ($transactionIds as $transactionId) {
            $id = (int)$transactionId;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        if ($ids === [] || !$this->hasSchema()) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach (array_values($ids) as $index => $id) {
            $key = 'transaction_id_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT ts.id,
                    ts.transaction_id,
                    t.company_id,
                    t.accounting_period_id,
                    t.account_id,
                    t.txn_date,
                    t.description AS transaction_description,
                    t.amount AS transaction_amount,
                    t.category_status,
                    t.nominal_account_id,
                    t.transfer_account_id,
                    t.is_internal_transfer,
                    t.auto_rule_id,
                    t.is_auto_excluded
             FROM transaction_splits ts
             INNER JOIN transactions t ON t.id = ts.transaction_id
             WHERE ts.transaction_id IN (' . implode(', ', $placeholders) . ')',
            $params
        ) ?: [];

        if ($rows === []) {
            return [];
        }

        $splitsById = [];
        $splitIds = [];
        foreach ($rows as $row) {
            $splitId = (int)($row['id'] ?? 0);
            if ($splitId <= 0) {
                continue;
            }
            $row['lines'] = [];
            $splitsById[$splitId] = $row;
            $splitIds[] = $splitId;
        }

        foreach ($this->fetchLinesForSplits($splitIds) as $line) {
            $splitId = (int)($line['split_id'] ?? 0);
            if (isset($splitsById[$splitId])) {
                $splitsById[$splitId]['lines'][] = $line;
            }
        }

        $byTransaction = [];
        foreach ($splitsById as $split) {
            $decorated = $this->decorateSplit($split);
            $byTransaction[(int)$decorated['transaction_id']] = $decorated;
        }

        return $byTransaction;
    }

    public function attachSplitsToTransactions(array $transactions): array
    {
        $ids = [];
        foreach ($transactions as $transaction) {
            if (is_array($transaction) && (int)($transaction['id'] ?? 0) > 0) {
                $ids[] = (int)$transaction['id'];
            }
        }

        $splits = $this->fetchSplitsForTransactions($ids);
        foreach ($transactions as $index => $transaction) {
            if (!is_array($transaction)) {
                continue;
            }
            $split = $splits[(int)($transaction['id'] ?? 0)] ?? null;
            $transactions[$index]['has_transaction_split'] = $split !== null ? 1 : 0;
            $transactions[$index]['transaction_split_id'] = (int)($split['id'] ?? 0);
            $transactions[$index]['transaction_split_lines'] = (array)($split['lines'] ?? []);
            $transactions[$index]['transaction_split_total'] = $split !== null ? (string)$split['line_total'] : '0.00';
            $transactions[$index]['transaction_split_difference'] = $split !== null ? (string)$split['difference'] : '';
            $transactions[$index]['transaction_split_ready'] = !empty($split['is_ready']) ? 1 : 0;
        }

        return $transactions;
    }

    public function fetchReadySplitForPosting(int $transactionId): ?array
    {
        $split = $this->fetchSplitForTransaction($transactionId);
        if ($split === null || empty($split['is_ready'])) {
            return null;
        }

        return $split;
    }

    public function fetchReadySplitTransactionIds(int $companyId, int $accountingPeriodId, ?string $monthKey = null): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || !$this->hasSchema()) {
            return [];
        }

        $where = [
            't.company_id = :company_id',
            't.accounting_period_id = :accounting_period_id',
        ];
        $params = [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
        ];

        $monthKey = trim((string)$monthKey);
        if (preg_match('/^\d{4}-\d{2}-01$/', $monthKey) === 1) {
            $monthStart = new \DateTimeImmutable($monthKey);
            $monthEnd = $monthStart->modify('last day of this month');
            $where[] = 't.txn_date BETWEEN :month_start AND :month_end';
            $params['month_start'] = $monthStart->format('Y-m-d');
            $params['month_end'] = $monthEnd->format('Y-m-d');
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT t.id
             FROM transactions t
             INNER JOIN transaction_splits ts ON ts.transaction_id = t.id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY t.txn_date ASC, t.id ASC',
            $params
        ) ?: [];

        $ids = [];
        foreach ($rows as $row) {
            $split = $this->fetchSplitForTransaction((int)($row['id'] ?? 0));
            if ($split !== null && !empty($split['is_ready'])) {
                $ids[] = (int)$row['id'];
            }
        }

        return $ids;
    }

    public function fetchSplitLineForAsset(int $companyId, int $lineId): ?array
    {
        $line = $this->fetchLineWithTransaction($lineId);
        if ($line === null || (int)($line['company_id'] ?? 0) !== $companyId) {
            return null;
        }

        $line['amount'] = number_format((float)($line['amount'] ?? 0), 2, '.', '');

        return $line;
    }

    public function splitLineHasAsset(int $lineId): bool
    {
        if ($lineId <= 0 || !\InterfaceDB::tableExists('asset_register') || !\InterfaceDB::columnExists('asset_register', 'linked_transaction_split_line_id')) {
            return false;
        }

        return (int)\InterfaceDB::fetchColumn(
            'SELECT EXISTS(
                SELECT 1
                FROM asset_register
                WHERE linked_transaction_split_line_id = :line_id
            )',
            ['line_id' => $lineId]
        ) === 1;
    }

    private function splitHasLinkedAssets(int $transactionId): bool
    {
        if ($transactionId <= 0 || !\InterfaceDB::tableExists('asset_register') || !\InterfaceDB::columnExists('asset_register', 'linked_transaction_split_line_id')) {
            return false;
        }

        return (int)\InterfaceDB::fetchColumn(
            'SELECT EXISTS(
                SELECT 1
                FROM transaction_splits ts
                INNER JOIN transaction_split_lines tsl ON tsl.split_id = ts.id
                INNER JOIN asset_register ar ON ar.linked_transaction_split_line_id = tsl.id
                WHERE ts.transaction_id = :transaction_id
            )',
            ['transaction_id' => $transactionId]
        ) === 1;
    }

    private function fetchTransaction(int $companyId, int $transactionId): ?array
    {
        if ($companyId <= 0 || $transactionId <= 0) {
            return null;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT *
             FROM transactions
             WHERE id = :id
               AND company_id = :company_id
             LIMIT 1',
            [
                'id' => $transactionId,
                'company_id' => $companyId,
            ]
        );

        return is_array($row) ? $row : null;
    }

    private function fetchLineWithTransaction(int $lineId): ?array
    {
        if ($lineId <= 0 || !$this->hasSchema()) {
            return null;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT tsl.id,
                    tsl.split_id,
                    tsl.line_number,
                    tsl.description,
                    tsl.amount,
                    tsl.nominal_account_id,
                    tsl.is_deferred,
                    tsl.notes,
                    ts.transaction_id,
                    t.company_id,
                    t.accounting_period_id,
                    t.account_id,
                    t.txn_date,
                    t.description AS transaction_description,
                    t.amount AS transaction_amount,
                    t.category_status,
                    t.nominal_account_id AS transaction_nominal_account_id,
                    t.transfer_account_id,
                    t.is_internal_transfer,
                    t.auto_rule_id,
                    t.is_auto_excluded,
                    COALESCE(na.code, \'\') AS nominal_code,
                    COALESCE(na.name, \'\') AS nominal_name
             FROM transaction_split_lines tsl
             INNER JOIN transaction_splits ts ON ts.id = tsl.split_id
             INNER JOIN transactions t ON t.id = ts.transaction_id
             LEFT JOIN nominal_accounts na ON na.id = tsl.nominal_account_id
             WHERE tsl.id = :id
             LIMIT 1',
            ['id' => $lineId]
        );

        return is_array($row) ? $row : null;
    }

    private function fetchLinesForSplit(int $splitId): array
    {
        if ($splitId <= 0) {
            return [];
        }

        return $this->fetchLinesForSplits([$splitId]);
    }

    private function fetchLinesForSplits(array $splitIds): array
    {
        $ids = [];
        foreach ($splitIds as $splitId) {
            $id = (int)$splitId;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach (array_values($ids) as $index => $id) {
            $key = 'split_id_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        return \InterfaceDB::fetchAll(
            'SELECT tsl.id,
                    tsl.split_id,
                    tsl.line_number,
                    COALESCE(tsl.description, \'\') AS description,
                    tsl.amount,
                    tsl.nominal_account_id,
                    tsl.is_deferred,
                    COALESCE(tsl.notes, \'\') AS notes,
                    COALESCE(na.code, \'\') AS nominal_code,
                    COALESCE(na.name, \'\') AS nominal_name
             FROM transaction_split_lines tsl
             LEFT JOIN nominal_accounts na ON na.id = tsl.nominal_account_id
             WHERE tsl.split_id IN (' . implode(', ', $placeholders) . ')
             ORDER BY tsl.split_id ASC, tsl.line_number ASC, tsl.id ASC',
            $params
        ) ?: [];
    }

    private function decorateSplit(array $split): array
    {
        $lineTotal = 0.0;
        $allComplete = true;
        $hasDeferred = false;
        $lineCount = 0;

        foreach ((array)($split['lines'] ?? []) as $index => $line) {
            $amount = $line['amount'] === null ? null : round((float)$line['amount'], 2);
            $nominalAccountId = (int)($line['nominal_account_id'] ?? 0);
            $isDeferred = (int)($line['is_deferred'] ?? 0) === 1;

            if ($isDeferred) {
                $hasDeferred = true;
                $allComplete = false;
            } else {
                $lineCount++;
                if ($amount === null || $amount <= 0.0 || $nominalAccountId <= 0) {
                    $allComplete = false;
                } else {
                    $lineTotal += $amount;
                }
            }

            $line['amount'] = $amount !== null ? number_format($amount, 2, '.', '') : '';
            $line['is_complete'] = !$isDeferred && $amount !== null && $amount > 0.0 && $nominalAccountId > 0 ? 1 : 0;
            $split['lines'][$index] = $line;
        }

        $targetAmount = round(abs((float)($split['transaction_amount'] ?? 0)), 2);
        $difference = round($targetAmount - $lineTotal, 2);

        $split['target_amount'] = number_format($targetAmount, 2, '.', '');
        $split['line_total'] = number_format($lineTotal, 2, '.', '');
        $split['difference'] = number_format($difference, 2, '.', '');
        $split['is_balanced'] = abs($difference) < 0.005 ? 1 : 0;
        $split['is_ready'] = $lineCount >= 2 && !$hasDeferred && $allComplete && abs($difference) < 0.005 ? 1 : 0;

        return $split;
    }

    private function refreshTransactionState(array $transactionOrSplit, string $changedBy): void
    {
        $transactionId = (int)($transactionOrSplit['transaction_id'] ?? $transactionOrSplit['id'] ?? 0);
        $companyId = (int)($transactionOrSplit['company_id'] ?? 0);
        if ($transactionId <= 0 || $companyId <= 0) {
            return;
        }

        $transaction = $this->fetchTransaction($companyId, $transactionId);
        if ($transaction === null) {
            return;
        }

        $split = $this->fetchSplitForTransaction($transactionId);
        $nextState = [
            'nominal_account_id' => null,
            'transfer_account_id' => null,
            'is_internal_transfer' => 0,
            'category_status' => $split !== null && !empty($split['is_ready']) ? 'manual' : 'uncategorised',
            'auto_rule_id' => null,
            'is_auto_excluded' => 0,
        ];

        $this->persistTransactionState($transaction, $nextState, $changedBy, $nextState['category_status'] === 'manual' ? 'split_ready' : 'split_draft');
    }

    private function persistTransactionState(array $transaction, array $nextState, string $changedBy, string $reason): void
    {
        $transactionId = (int)($transaction['id'] ?? $transaction['transaction_id'] ?? 0);
        if ($transactionId <= 0) {
            return;
        }

        $changed = (int)($transaction['nominal_account_id'] ?? 0) !== (int)($nextState['nominal_account_id'] ?? 0)
            || (int)($transaction['transfer_account_id'] ?? 0) !== (int)($nextState['transfer_account_id'] ?? 0)
            || (int)($transaction['is_internal_transfer'] ?? 0) !== (int)($nextState['is_internal_transfer'] ?? 0)
            || (string)($transaction['category_status'] ?? '') !== (string)$nextState['category_status']
            || (int)($transaction['auto_rule_id'] ?? 0) !== (int)($nextState['auto_rule_id'] ?? 0)
            || (int)($transaction['is_auto_excluded'] ?? 0) !== (int)($nextState['is_auto_excluded'] ?? 0);

        \InterfaceDB::prepareExecute(
            'UPDATE transactions
             SET nominal_account_id = :nominal_account_id,
                 transfer_account_id = :transfer_account_id,
                 is_internal_transfer = :is_internal_transfer,
                 category_status = :category_status,
                 auto_rule_id = :auto_rule_id,
                 is_auto_excluded = :is_auto_excluded,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            [
                'nominal_account_id' => $nextState['nominal_account_id'],
                'transfer_account_id' => $nextState['transfer_account_id'],
                'is_internal_transfer' => (int)$nextState['is_internal_transfer'],
                'category_status' => $nextState['category_status'],
                'auto_rule_id' => $nextState['auto_rule_id'],
                'is_auto_excluded' => (int)$nextState['is_auto_excluded'],
                'id' => $transactionId,
            ]
        );

        if (!$changed || !\InterfaceDB::tableExists('transaction_category_audit')) {
            return;
        }

        \InterfaceDB::prepareExecute(
            'INSERT INTO transaction_category_audit (
                transaction_id,
                old_nominal_account_id,
                new_nominal_account_id,
                old_category_status,
                new_category_status,
                old_auto_rule_id,
                new_auto_rule_id,
                old_is_auto_excluded,
                new_is_auto_excluded,
                changed_by,
                reason
             ) VALUES (
                :transaction_id,
                :old_nominal_account_id,
                :new_nominal_account_id,
                :old_category_status,
                :new_category_status,
                :old_auto_rule_id,
                :new_auto_rule_id,
                :old_is_auto_excluded,
                :new_is_auto_excluded,
                :changed_by,
                :reason
             )',
            [
                'transaction_id' => $transactionId,
                'old_nominal_account_id' => $transaction['nominal_account_id'] ?? null,
                'new_nominal_account_id' => $nextState['nominal_account_id'],
                'old_category_status' => $transaction['category_status'] ?? null,
                'new_category_status' => $nextState['category_status'],
                'old_auto_rule_id' => $transaction['auto_rule_id'] ?? null,
                'new_auto_rule_id' => $nextState['auto_rule_id'],
                'old_is_auto_excluded' => (int)($transaction['is_auto_excluded'] ?? 0),
                'new_is_auto_excluded' => (int)$nextState['is_auto_excluded'],
                'changed_by' => $changedBy,
                'reason' => $reason,
            ]
        );
    }

    private function splitIdForTransaction(int $transactionId): int
    {
        $value = \InterfaceDB::fetchColumn(
            'SELECT id FROM transaction_splits WHERE transaction_id = :transaction_id LIMIT 1',
            ['transaction_id' => $transactionId]
        );

        return $value !== false ? (int)$value : 0;
    }

    private function insertLine(int $splitId, int $lineNumber): void
    {
        \InterfaceDB::prepareExecute(
            'INSERT INTO transaction_split_lines (split_id, line_number, created_at, updated_at)
             VALUES (:split_id, :line_number, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
            [
                'split_id' => $splitId,
                'line_number' => $lineNumber,
            ]
        );
    }

    private function normaliseAmount(mixed $value): ?float
    {
        if ($this->amountIsBlank($value)) {
            return null;
        }

        return (float)trim((string)$value);
    }

    private function amountIsBlank(mixed $value): bool
    {
        return $value === null || (is_scalar($value) && trim((string)$value) === '');
    }

    private function amountHasTwoDecimalPlaces(mixed $value): bool
    {
        return is_scalar($value) && preg_match('/^[0-9]+\.[0-9]{2}$/', trim((string)$value)) === 1;
    }

    private function normaliseNominalId(int $companyId, mixed $value): int
    {
        $id = max(0, (int)$value);
        if ($id <= 0) {
            return 0;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT id
             FROM nominal_accounts
             WHERE id = :id
               AND is_active = 1
             LIMIT 1',
            ['id' => $id]
        );

        return is_array($row) ? $id : 0;
    }

    private function normaliseOptionalString(mixed $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, max(1, $limit));
    }

    private function transactionIsTransfer(array $transaction): bool
    {
        if ((int)($transaction['is_internal_transfer'] ?? 0) === 1 || (int)($transaction['transfer_account_id'] ?? 0) > 0) {
            return true;
        }

        $sourceCategory = strtolower(trim((string)($transaction['source_category'] ?? '')));

        return str_contains($sourceCategory, 'transfer');
    }
}
