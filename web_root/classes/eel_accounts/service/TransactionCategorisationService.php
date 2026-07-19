<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class TransactionCategorisationService
{
    public function applyAutoCategoryToTransaction(
        int $transactionId,
        string $changedBy = 'system',
        bool $reapplyExistingAuto = false
    ): array {
        if ($transactionId <= 0) {
            return [
                'success' => false,
                'changed' => false,
                'reason' => 'invalid_transaction',
            ];
        }

        $transaction = $this->fetchTransaction($transactionId);

        if ($transaction === null) {
            return [
                'success' => false,
                'changed' => false,
                'reason' => 'not_found',
            ];
        }

        if (!$this->isEligibleForAuto($transaction, $reapplyExistingAuto)) {
            return [
                'success' => true,
                'changed' => false,
                'reason' => 'not_eligible',
                'transaction' => $transaction,
            ];
        }

        $this->assertPeriodUnlocked($transaction, 'change transaction categorisation');

        $rule = $this->findMatchingRule((int)$transaction['company_id'], $transaction);
        $nextState = $this->buildAutoTargetState($transaction, $rule, $reapplyExistingAuto);

        if ($nextState === null) {
            return [
                'success' => true,
                'changed' => false,
                'reason' => 'no_match',
                'transaction' => $transaction,
            ];
        }

        if (!$this->categorisationFieldsChanged($transaction, $nextState)) {
            return [
                'success' => true,
                'changed' => false,
                'reason' => 'unchanged',
                'transaction' => $transaction,
            ];
        }

        $this->persistCategorisation($transaction, $nextState, $changedBy, $nextState['reason']);

        return [
            'success' => true,
            'changed' => true,
            'rule' => $rule,
            'transaction' => $this->fetchTransaction($transactionId),
            'reason' => $nextState['reason_code'],
        ];
    }

    public function applyAutoCategoryBatch(
        int $companyId,
        ?int $accountingPeriodId = null,
        string $mode = 'uncategorised',
        ?string $monthKey = null,
        string $changedBy = 'system'
    ): array {
        $mode = $this->normaliseBatchMode($mode);
        $transactions = $this->fetchBatchTransactions($companyId, $accountingPeriodId, $mode, $monthKey);
        $rules = $this->fetchActiveRules($companyId);
        $summary = [
            'success' => true,
            'mode' => $mode,
            'processed' => 0,
            'changed' => 0,
            'unchanged' => 0,
            'changed_transaction_ids' => [],
        ];

        $ownsTransaction = !\InterfaceDB::inTransaction();

        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            $statements = $this->prepareCategorisationStatements();

            foreach ($transactions as $transaction) {
                $summary['processed']++;

                if (!$this->isEligibleForAuto($transaction, $mode === 'auto')) {
                    $summary['unchanged']++;
                    continue;
                }

                $this->assertPeriodUnlocked($transaction, 'run auto categorisation');

                $rule = $this->findMatchingRuleInSet($rules, $transaction);
                $nextState = $this->buildAutoTargetState($transaction, $rule, $mode === 'auto');

                if ($nextState === null || !$this->categorisationFieldsChanged($transaction, $nextState)) {
                    $summary['unchanged']++;
                    continue;
                }

                $this->persistCategorisationWithStatements(
                    $transaction,
                    $nextState,
                    $changedBy,
                    $nextState['reason'],
                    $statements['update'],
                    $statements['audit']
                );

                $summary['changed']++;
                $summary['changed_transaction_ids'][] = (int)$transaction['id'];
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

        return $summary;
    }

    public function saveManualCategorisation(
        int $transactionId,
        ?int $nominalAccountId,
        ?int $transferAccountId,
        bool $isAutoExcluded,
        string $changedBy,
        bool $confirmedJournalRebuild = false
    ): array {
        if ($transactionId <= 0) {
            return [
                'success' => false,
                'changed' => false,
                'errors' => ['A valid transaction is required.'],
            ];
        }

        $transaction = $this->fetchTransaction($transactionId);

        if ($transaction === null) {
            return [
                'success' => false,
                'changed' => false,
                'errors' => ['The selected transaction could not be found.'],
            ];
        }

        $this->assertPeriodUnlocked($transaction, 'save transaction categorisation');

        if ($this->isTransferTransaction($transaction)) {
            $transferValidationErrors = $this->validateTransferAccountId($transaction, $transferAccountId, $isAutoExcluded);

            if ($transferValidationErrors !== []) {
                return [
                    'success' => false,
                    'changed' => false,
                    'errors' => $transferValidationErrors,
                ];
            }

            $nextState = $this->buildTransferTargetState($transaction, $transferAccountId, $isAutoExcluded);
        } else {
            $nextState = $this->buildManualTargetState($transaction, $nominalAccountId, $isAutoExcluded);
            $selfNominalErrors = $this->validateDestinationNominal($transaction, $nextState['nominal_account_id']);
            if ($selfNominalErrors !== []) {
                return [
                    'success' => false,
                    'changed' => false,
                    'errors' => $selfNominalErrors,
                ];
            }
        }

        if (!$this->categorisationFieldsChanged($transaction, $nextState)) {
            return [
                'success' => true,
                'changed' => false,
                'transaction' => $transaction,
                'errors' => [],
            ];
        }

        $requiresJournalRebuild = $this->manualChangeAffectsDerivedJournal($transaction, $nextState);

        if ($requiresJournalRebuild && !$confirmedJournalRebuild) {
            return [
                'success' => true,
                'changed' => false,
                'requires_confirmation' => true,
                'transaction' => $transaction,
                'errors' => [],
            ];
        }

        $this->persistCategorisation($transaction, $nextState, $changedBy, $nextState['reason']);

        return [
            'success' => true,
            'changed' => true,
            'requires_journal_rebuild' => $requiresJournalRebuild,
            'transaction' => $this->fetchTransaction($transactionId),
            'errors' => [],
        ];
    }

    public function applyInterAccountMatchState(int $sourceTransactionId, int $matchedTransactionId, string $changedBy): array
    {
        $sourceTransaction = $this->fetchTransaction($sourceTransactionId);
        $matchedTransaction = $this->fetchTransaction($matchedTransactionId);

        if ($sourceTransaction === null || $matchedTransaction === null) {
            return [
                'success' => false,
                'changed' => false,
                'errors' => ['Both inter-account transactions must exist before the match can be saved.'],
            ];
        }

        $this->assertPeriodUnlocked($sourceTransaction, 'save inter-account transaction match');
        $this->assertPeriodUnlocked($matchedTransaction, 'save inter-account transaction match');

        $errors = $this->validateInterAccountStatePair($sourceTransaction, $matchedTransaction);
        if ($errors !== []) {
            return [
                'success' => false,
                'changed' => false,
                'errors' => $errors,
            ];
        }

        $sourceState = $this->buildInterAccountSourceState($sourceTransaction, $matchedTransaction);
        $matchedState = $this->buildInterAccountEvidenceState($matchedTransaction, $sourceTransaction);

        return $this->persistInterAccountStates(
            $sourceTransaction,
            $sourceState,
            $matchedTransaction,
            $matchedState,
            $changedBy
        );
    }

    public function clearInterAccountMatchState(int $sourceTransactionId, int $matchedTransactionId, string $changedBy): array
    {
        $sourceTransaction = $this->fetchTransaction($sourceTransactionId);
        $matchedTransaction = $this->fetchTransaction($matchedTransactionId);

        if ($sourceTransaction === null || $matchedTransaction === null) {
            return [
                'success' => false,
                'changed' => false,
                'errors' => ['Both inter-account transactions must exist before the match can be cancelled.'],
            ];
        }

        $this->assertPeriodUnlocked($sourceTransaction, 'cancel inter-account transaction match');
        $this->assertPeriodUnlocked($matchedTransaction, 'cancel inter-account transaction match');

        $sourceState = $this->buildUncategorisedReviewState($sourceTransaction, 'inter-account match cancelled');
        $matchedState = $this->buildUncategorisedReviewState($matchedTransaction, 'inter-account match cancelled');

        return $this->persistInterAccountStates(
            $sourceTransaction,
            $sourceState,
            $matchedTransaction,
            $matchedState,
            $changedBy
        );
    }

    public function approveAutoCategorisationsBatch(
        int $companyId,
        ?int $accountingPeriodId = null,
        ?string $monthKey = null,
        string $changedBy = 'transactions_page_review'
    ): array {
        $transactions = $this->fetchAutoReviewTransactions($companyId, $accountingPeriodId, $monthKey);
        $summary = [
            'success' => true,
            'processed' => 0,
            'changed' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            foreach ($transactions as $transaction) {
                $summary['processed']++;
                $nominalAccountId = (int)($transaction['nominal_account_id'] ?? 0);
                if ($nominalAccountId <= 0 || $this->isTransferTransaction($transaction)) {
                    $summary['skipped']++;
                    continue;
                }

                $result = $this->saveManualCategorisation(
                    (int)$transaction['id'],
                    $nominalAccountId,
                    null,
                    (int)($transaction['is_auto_excluded'] ?? 0) === 1,
                    $changedBy,
                    true
                );

                if (!empty($result['errors'])) {
                    $summary['errors'] = array_merge($summary['errors'], array_map('strval', (array)$result['errors']));
                    continue;
                }

                if (!empty($result['changed'])) {
                    $summary['changed']++;
                }
            }

            if ($summary['errors'] !== []) {
                $summary['success'] = false;
                if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                    \InterfaceDB::rollBack();
                }

                return $summary;
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

        return $summary;
    }

    private function assertPeriodUnlocked(array $transaction, string $actionLabel): void {
        (new \eel_accounts\Service\YearEndLockService())->assertUnlocked(
            (int)($transaction['company_id'] ?? 0),
            (int)($transaction['accounting_period_id'] ?? 0),
            $actionLabel
        );
    }

    public function findMatchingRule(int $companyId, array $transactionPayload): ?array {
        if ($companyId <= 0) {
            return null;
        }

        return $this->findMatchingRuleInSet($this->fetchActiveRules($companyId), $transactionPayload);
    }

    public function fetchTransaction(int $transactionId): ?array {
        $internalTransferMarkerExpression = $this->internalTransferMarkerExpression('ca.');
        $stmt = \InterfaceDB::prepare(
            'SELECT t.id,
                    t.company_id,
                    t.accounting_period_id,
                    t.account_id,
                    t.txn_date,
                    t.txn_type,
                    t.description,
                    t.reference,
                    t.amount,
                    t.currency,
                    t.source_type,
                    t.source_account_label,
                    t.source_created_at,
                    t.source_processed_at,
                    t.source_category,
                    t.source_document_url,
                    t.counterparty_name,
                    t.card,
                    t.nominal_account_id,
                    t.director_id,
                    t.party_id,
                    t.transfer_account_id,
                    t.is_internal_transfer,
                    t.category_status,
                    t.auto_rule_id,
                    t.is_auto_excluded,
                    ' . $internalTransferMarkerExpression . ' AS internal_transfer_marker,
                    COALESCE(ca.nominal_account_id, 0) AS source_account_nominal_id
             FROM transactions t
             LEFT JOIN company_accounts ca ON ca.id = t.account_id
             WHERE t.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $transactionId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        $row['has_derived_journal'] = $this->transactionHasDerivedJournal((int)$row['id']);

        return $row;
    }

    private function fetchActiveRules(int $companyId): array {
        if ($companyId <= 0) {
            return [];
        }

        $stmt = \InterfaceDB::prepare(
            'SELECT id,
                    company_id,
                    priority,
                    match_field,
                    desc_match_type,
                    desc_match_value,
                    ref_match_type,
                    ref_match_value,
                    source_category_value,
                    source_account_value,
                    nominal_account_id,
                    director_id,
                    is_active
             FROM categorisation_rules
             WHERE company_id = :company_id
               AND is_active = 1
             ORDER BY priority ASC, id ASC'
        );
        $stmt->execute(['company_id' => $companyId]);

        return $stmt->fetchAll();
    }

    private function fetchAutoReviewTransactions(int $companyId, ?int $accountingPeriodId, ?string $monthKey): array
    {
        if ($companyId <= 0) {
            return [];
        }

        $where = [
            't.company_id = :company_id',
            't.category_status = :category_status',
        ];
        $params = [
            'company_id' => $companyId,
            'category_status' => 'auto',
        ];

        if ($accountingPeriodId !== null && $accountingPeriodId > 0) {
            $where[] = 't.accounting_period_id = :accounting_period_id';
            $params['accounting_period_id'] = $accountingPeriodId;
        }

        $monthKey = trim((string)$monthKey);
        if ($monthKey !== '') {
            try {
                $monthStart = (new \DateTimeImmutable($monthKey))->modify('first day of this month')->format('Y-m-d');
                $monthEnd = (new \DateTimeImmutable($monthStart))->modify('+1 month')->format('Y-m-d');
                $where[] = 't.txn_date >= :month_start';
                $where[] = 't.txn_date < :month_end';
                $params['month_start'] = $monthStart;
                $params['month_end'] = $monthEnd;
            } catch (\Throwable) {
                $monthKey = '';
            }
        }

        $internalTransferMarkerExpression = $this->internalTransferMarkerExpression('ca.');
        $stmt = \InterfaceDB::prepare(
            'SELECT t.id,
                    t.company_id,
                    t.accounting_period_id,
                    t.account_id,
                    t.nominal_account_id,
                    t.director_id,
                    t.transfer_account_id,
                    t.is_internal_transfer,
                    t.category_status,
                    t.auto_rule_id,
                    t.is_auto_excluded,
                    ' . $internalTransferMarkerExpression . ' AS internal_transfer_marker,
                    COALESCE(ca.nominal_account_id, 0) AS source_account_nominal_id
             FROM transactions t
             LEFT JOIN company_accounts ca ON ca.id = t.account_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY t.txn_date ASC, t.id ASC'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    private function findMatchingRuleInSet(array $rules, array $transactionPayload): ?array {
        foreach ($rules as $rule) {
            if ($this->ruleMatches($rule, $transactionPayload)) {
                return $rule;
            }
        }

        return null;
    }

    private function buildAutoTargetState(array $transaction, ?array $rule, bool $reapplyExistingAuto): ?array {
        if ($this->isTransferTransaction($transaction)) {
            return null;
        }

        if ($rule !== null) {
            if ($this->validateDestinationNominal($transaction, (int)$rule['nominal_account_id']) !== []) {
                return null;
            }

            return [
                'nominal_account_id' => (int)$rule['nominal_account_id'],
                'director_id' => (int)($rule['director_id'] ?? 0) ?: null,
                'party_id' => null,
                'transfer_account_id' => null,
                'is_internal_transfer' => (int)($transaction['is_internal_transfer'] ?? 0),
                'category_status' => 'auto',
                'auto_rule_id' => (int)$rule['id'],
                'is_auto_excluded' => (int)($transaction['is_auto_excluded'] ?? 0),
                'reason' => 'auto categorisation by rule #' . (int)$rule['id'],
                'reason_code' => 'matched_rule',
            ];
        }

        if ($reapplyExistingAuto && (string)($transaction['category_status'] ?? '') === 'auto') {
            return [
                'nominal_account_id' => null,
                'director_id' => null,
                'party_id' => null,
                'transfer_account_id' => null,
                'is_internal_transfer' => (int)($transaction['is_internal_transfer'] ?? 0),
                'category_status' => 'uncategorised',
                'auto_rule_id' => null,
                'is_auto_excluded' => (int)($transaction['is_auto_excluded'] ?? 0),
                'reason' => 'auto categorisation cleared after rule re-run',
                'reason_code' => 'cleared_after_rerun',
            ];
        }

        return null;
    }

    private function buildManualTargetState(array $transaction, ?int $nominalAccountId, bool $isAutoExcluded): array {
        $newNominalAccountId = $nominalAccountId !== null && $nominalAccountId > 0 ? $nominalAccountId : null;
        $newStatus = $newNominalAccountId !== null ? 'manual' : 'uncategorised';

        return [
            'nominal_account_id' => $newNominalAccountId,
            'director_id' => (new DirectorLoanAttributionService())->isDirectorLoanNominal(
                (int)$transaction['company_id'],
                $newNominalAccountId
            ) ? ((int)($transaction['director_id'] ?? 0) ?: null) : null,
            'party_id' => in_array(
                $newNominalAccountId,
                (new ParticipatorLoanService())->controlNominalIds((int)$transaction['company_id'])['all'],
                true
            ) ? ((int)($transaction['party_id'] ?? 0) ?: null) : null,
            'transfer_account_id' => null,
            'is_internal_transfer' => (int)($transaction['is_internal_transfer'] ?? 0),
            'category_status' => $newStatus,
            'auto_rule_id' => null,
            'is_auto_excluded' => $isAutoExcluded ? 1 : 0,
            'reason' => $this->manualAuditReason($transaction, $newNominalAccountId, $isAutoExcluded),
            'reason_code' => 'manual_save',
        ];
    }

    private function buildTransferTargetState(array $transaction, ?int $transferAccountId, bool $isAutoExcluded): array {
        $newTransferAccountId = $transferAccountId !== null && $transferAccountId > 0 ? $transferAccountId : null;

        return [
            'nominal_account_id' => null,
            'director_id' => null,
            'party_id' => null,
            'transfer_account_id' => $newTransferAccountId,
            'is_internal_transfer' => 1,
            'category_status' => $newTransferAccountId !== null ? 'manual' : 'uncategorised',
            'auto_rule_id' => null,
            'is_auto_excluded' => $isAutoExcluded ? 1 : 0,
            'reason' => $newTransferAccountId !== null ? 'manual transfer account selection' : 'transfer reset to account needed',
            'reason_code' => 'manual_transfer_save',
        ];
    }

    private function buildInterAccountSourceState(array $sourceTransaction, array $matchedTransaction): array
    {
        return [
            'nominal_account_id' => null,
            'transfer_account_id' => (int)$matchedTransaction['account_id'],
            'is_internal_transfer' => 1,
            'category_status' => 'manual',
            'auto_rule_id' => null,
            'is_auto_excluded' => 0,
            'reason' => 'inter-account source linked to transaction #' . (int)$matchedTransaction['id'],
            'reason_code' => 'inter_account_source',
        ];
    }

    private function buildInterAccountEvidenceState(array $matchedTransaction, array $sourceTransaction): array
    {
        return [
            'nominal_account_id' => null,
            'transfer_account_id' => (int)$sourceTransaction['account_id'],
            'is_internal_transfer' => 1,
            'category_status' => 'manual',
            'auto_rule_id' => null,
            'is_auto_excluded' => 0,
            'reason' => 'inter-account destination linked to transaction #' . (int)$sourceTransaction['id'],
            'reason_code' => 'inter_account_destination',
        ];
    }

    private function buildUncategorisedReviewState(array $transaction, string $reason): array
    {
        return [
            'nominal_account_id' => null,
            'transfer_account_id' => null,
            'is_internal_transfer' => 0,
            'category_status' => 'uncategorised',
            'auto_rule_id' => null,
            'is_auto_excluded' => 0,
            'reason' => $reason,
            'reason_code' => 'inter_account_clear',
        ];
    }

    private function validateInterAccountStatePair(array $sourceTransaction, array $matchedTransaction): array
    {
        $errors = [];

        if ((int)$sourceTransaction['id'] === (int)$matchedTransaction['id']) {
            $errors[] = 'An inter-account match needs two different transactions.';
        }
        if ((int)$sourceTransaction['company_id'] !== (int)$matchedTransaction['company_id']) {
            $errors[] = 'Inter-account transactions must belong to the same company.';
        }
        if ((int)$sourceTransaction['accounting_period_id'] !== (int)$matchedTransaction['accounting_period_id']) {
            $errors[] = 'Inter-account transactions must belong to the same accounting period.';
        }
        if ((int)$matchedTransaction['account_id'] <= 0) {
            $errors[] = 'The matched transaction is missing its account.';
        }
        if ((int)$sourceTransaction['account_id'] === (int)$matchedTransaction['account_id']) {
            $errors[] = 'Inter-account transactions must be between different accounts.';
        }

        return $errors;
    }

    private function persistInterAccountStates(
        array $sourceTransaction,
        array $sourceState,
        array $matchedTransaction,
        array $matchedState,
        string $changedBy
    ): array {
        $changed = $this->categorisationFieldsChanged($sourceTransaction, $sourceState)
            || $this->categorisationFieldsChanged($matchedTransaction, $matchedState);
        $ownsTransaction = !\InterfaceDB::inTransaction();

        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            $statements = $this->prepareCategorisationStatements();
            $this->persistCategorisationWithStatements(
                $sourceTransaction,
                $sourceState,
                $changedBy,
                $sourceState['reason'],
                $statements['update'],
                $statements['audit']
            );
            $this->persistCategorisationWithStatements(
                $matchedTransaction,
                $matchedState,
                $changedBy,
                $matchedState['reason'],
                $statements['update'],
                $statements['audit']
            );
            $this->deleteAutoApprovalStates([(int)$sourceTransaction['id'], (int)$matchedTransaction['id']]);

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
            'changed' => $changed,
            'source_transaction' => $this->fetchTransaction((int)$sourceTransaction['id']),
            'matched_transaction' => $this->fetchTransaction((int)$matchedTransaction['id']),
            'errors' => [],
        ];
    }

    private function deleteAutoApprovalStates(array $transactionIds): void
    {
        $transactionIds = array_values(array_unique(array_filter(
            array_map(static fn(mixed $id): int => (int)$id, $transactionIds),
            static fn(int $id): bool => $id > 0
        )));

        if ($transactionIds === [] || !\InterfaceDB::tableExists('transaction_auto_approvals')) {
            return;
        }

        $placeholders = [];
        $params = [];
        foreach ($transactionIds as $index => $transactionId) {
            $key = 'transaction_id_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $transactionId;
        }

        \InterfaceDB::prepareExecute(
            'DELETE FROM transaction_auto_approvals
             WHERE transaction_id IN (' . implode(', ', $placeholders) . ')',
            $params
        );
    }

    private function manualAuditReason(array $transaction, ?int $nominalAccountId, bool $isAutoExcluded): string {
        $oldNominalAccountId = $transaction['nominal_account_id'] !== null ? (int)$transaction['nominal_account_id'] : null;
        $oldStatus = trim((string)($transaction['category_status'] ?? 'uncategorised'));
        $oldExcluded = (int)($transaction['is_auto_excluded'] ?? 0) === 1;

        if ($nominalAccountId === null && $isAutoExcluded) {
            return 'deferred for manual review';
        }

        if ($oldExcluded !== $isAutoExcluded && $oldNominalAccountId === $nominalAccountId && $oldStatus === ($nominalAccountId !== null ? 'manual' : 'uncategorised')) {
            return 'auto exclusion updated';
        }

        if ($nominalAccountId === null) {
            return 'manual reset to uncategorised';
        }

        if ($oldStatus === 'auto' && $oldNominalAccountId === $nominalAccountId) {
            return 'manual categorisation';
        }

        if ($oldNominalAccountId !== null && $oldNominalAccountId !== $nominalAccountId) {
            return 'manual recategorisation';
        }

        if ($oldStatus === 'manual' && $oldNominalAccountId === $nominalAccountId) {
            return 'manual categorisation';
        }

        return 'manual categorisation';
    }

    private function persistCategorisation(array $transaction, array $nextState, string $changedBy, ?string $reason): void {
        $ownsTransaction = !\InterfaceDB::inTransaction();

        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            $statements = $this->prepareCategorisationStatements();
            $this->persistCategorisationWithStatements(
                $transaction,
                $nextState,
                $changedBy,
                $reason,
                $statements['update'],
                $statements['audit']
            );

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            throw $exception;
        }
    }

    private function prepareCategorisationStatements(): array {
        return [
            'update' => \InterfaceDB::prepare(
                'UPDATE transactions
                 SET nominal_account_id = :nominal_account_id,
                     director_id = :director_id,
                     party_id = :party_id,
                     transfer_account_id = :transfer_account_id,
                     is_internal_transfer = :is_internal_transfer,
                     category_status = :category_status,
                     auto_rule_id = :auto_rule_id,
                     is_auto_excluded = :is_auto_excluded
                 WHERE id = :id'
            ),
            'audit' => \InterfaceDB::prepare(
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
                )'
            ),
        ];
    }

    private function persistCategorisationWithStatements(
        array $transaction,
        array $nextState,
        string $changedBy,
        ?string $reason,
        \PDOStatement $update,
        \PDOStatement $audit
    ): void {
        $update->execute([
            'nominal_account_id' => $nextState['nominal_account_id'],
            'director_id' => $nextState['director_id'] ?? null,
            'party_id' => $nextState['party_id'] ?? null,
            'transfer_account_id' => $nextState['transfer_account_id'],
            'is_internal_transfer' => $nextState['is_internal_transfer'],
            'category_status' => $nextState['category_status'],
            'auto_rule_id' => $nextState['auto_rule_id'],
            'is_auto_excluded' => $nextState['is_auto_excluded'],
            'id' => (int)$transaction['id'],
        ]);

        $audit->execute([
            'transaction_id' => (int)$transaction['id'],
            'old_nominal_account_id' => $transaction['nominal_account_id'] !== null ? (int)$transaction['nominal_account_id'] : null,
            'new_nominal_account_id' => $nextState['nominal_account_id'],
            'old_category_status' => (string)$transaction['category_status'],
            'new_category_status' => (string)$nextState['category_status'],
            'old_auto_rule_id' => $transaction['auto_rule_id'] !== null ? (int)$transaction['auto_rule_id'] : null,
            'new_auto_rule_id' => $nextState['auto_rule_id'],
            'old_is_auto_excluded' => (int)($transaction['is_auto_excluded'] ?? 0),
            'new_is_auto_excluded' => (int)$nextState['is_auto_excluded'],
            'changed_by' => $this->changedByValue($changedBy),
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
        ]);

        (new DirectorLoanAttributionService())->recordChange(
            (int)$transaction['company_id'],
            'transaction',
            (int)$transaction['id'],
            (int)($transaction['director_id'] ?? 0) ?: null,
            (int)($nextState['director_id'] ?? 0) ?: null,
            $changedBy,
            (string)($reason ?? 'Transaction categorisation changed.')
        );

        if (class_exists(\eel_accounts\Service\VehicleService::class)) {
            (new \eel_accounts\Service\VehicleService())->cleanupVehicleDetailsForTransaction((int)$transaction['id']);
        }
    }

    private function isEligibleForAuto(array $transaction, bool $reapplyExistingAuto): bool {
        if ((new \eel_accounts\Service\TransactionInterAccountMarkerService())->fetchMarkerForTransaction((int)($transaction['id'] ?? 0)) !== null) {
            return false;
        }

        if ($this->isTransferTransaction($transaction)) {
            return false;
        }

        if ((int)($transaction['is_auto_excluded'] ?? 0) === 1) {
            return false;
        }

        $categoryStatus = trim((string)($transaction['category_status'] ?? 'uncategorised'));

        if ($categoryStatus === 'manual') {
            return false;
        }

        if ($reapplyExistingAuto) {
            return $categoryStatus === 'auto';
        }

        return $categoryStatus === 'uncategorised' && $transaction['nominal_account_id'] === null;
    }

    private function manualChangeAffectsDerivedJournal(array $transaction, array $nextState): bool {
        if ((int)($transaction['has_derived_journal'] ?? 0) !== 1) {
            return false;
        }

        if ($this->isTransferTransaction($transaction) || (int)($nextState['is_internal_transfer'] ?? 0) === 1) {
            $oldTransferAccountId = isset($transaction['transfer_account_id']) && $transaction['transfer_account_id'] !== null
                ? (int)$transaction['transfer_account_id']
                : null;
            $newTransferAccountId = isset($nextState['transfer_account_id']) && $nextState['transfer_account_id'] !== null
                ? (int)$nextState['transfer_account_id']
                : null;

            $oldPosted = $oldTransferAccountId !== null && trim((string)($transaction['category_status'] ?? '')) === 'manual';
            $newPosted = $newTransferAccountId !== null && trim((string)($nextState['category_status'] ?? '')) === 'manual';

            return $oldPosted !== $newPosted || $oldTransferAccountId !== $newTransferAccountId;
        }

        $oldNominalAccountId = $transaction['nominal_account_id'] !== null ? (int)$transaction['nominal_account_id'] : null;
        $newNominalAccountId = $nextState['nominal_account_id'] !== null ? (int)$nextState['nominal_account_id'] : null;
        $oldDirectorId = (int)($transaction['director_id'] ?? 0) ?: null;
        $newDirectorId = (int)($nextState['director_id'] ?? 0) ?: null;
        $oldPartyId = (int)($transaction['party_id'] ?? 0) ?: null;
        $newPartyId = (int)($nextState['party_id'] ?? 0) ?: null;
        $oldStatus = trim((string)($transaction['category_status'] ?? 'uncategorised'));
        $newStatus = trim((string)($nextState['category_status'] ?? 'uncategorised'));

        $oldPosted = in_array($oldStatus, ['auto', 'manual'], true) && $oldNominalAccountId !== null;
        $newPosted = in_array($newStatus, ['auto', 'manual'], true) && $newNominalAccountId !== null;

        if ($oldPosted !== $newPosted) {
            return true;
        }

        return $oldNominalAccountId !== $newNominalAccountId
            || $oldDirectorId !== $newDirectorId
            || $oldPartyId !== $newPartyId;
    }

    private function categorisationFieldsChanged(array $transaction, array $nextState): bool {
        $oldNominalAccountId = $transaction['nominal_account_id'] !== null ? (int)$transaction['nominal_account_id'] : null;
        $newNominalAccountId = $nextState['nominal_account_id'] !== null ? (int)$nextState['nominal_account_id'] : null;
        $oldTransferAccountId = $transaction['transfer_account_id'] !== null ? (int)$transaction['transfer_account_id'] : null;
        $newTransferAccountId = $nextState['transfer_account_id'] !== null ? (int)$nextState['transfer_account_id'] : null;
        $oldAutoRuleId = $transaction['auto_rule_id'] !== null ? (int)$transaction['auto_rule_id'] : null;
        $newAutoRuleId = $nextState['auto_rule_id'] !== null ? (int)$nextState['auto_rule_id'] : null;
        $oldDirectorId = (int)($transaction['director_id'] ?? 0) ?: null;
        $newDirectorId = (int)($nextState['director_id'] ?? 0) ?: null;
        $oldPartyId = (int)($transaction['party_id'] ?? 0) ?: null;
        $newPartyId = (int)($nextState['party_id'] ?? 0) ?: null;

        return $oldNominalAccountId !== $newNominalAccountId
            || $oldDirectorId !== $newDirectorId
            || $oldPartyId !== $newPartyId
            || $oldTransferAccountId !== $newTransferAccountId
            || (int)($transaction['is_internal_transfer'] ?? 0) !== (int)($nextState['is_internal_transfer'] ?? 0)
            || trim((string)($transaction['category_status'] ?? '')) !== trim((string)($nextState['category_status'] ?? ''))
            || $oldAutoRuleId !== $newAutoRuleId
            || (int)($transaction['is_auto_excluded'] ?? 0) !== (int)($nextState['is_auto_excluded'] ?? 0);
    }

    private function fetchBatchTransactions(int $companyId, ?int $accountingPeriodId, string $mode, ?string $monthKey): array {
        if ($companyId <= 0) {
            return [];
        }

        $where = [
            't.company_id = :company_id',
        ];
        $params = [
            'company_id' => $companyId,
        ];

        if ($accountingPeriodId !== null && $accountingPeriodId > 0) {
            $where[] = 't.accounting_period_id = :accounting_period_id';
            $params['accounting_period_id'] = $accountingPeriodId;
        }

        if ($mode === 'auto') {
            $where[] = 't.category_status = :category_status';
            $params['category_status'] = 'auto';
        } else {
            $where[] = 't.category_status = :category_status';
            $where[] = 't.nominal_account_id IS NULL';
            $params['category_status'] = 'uncategorised';
        }

        $monthKey = trim((string)$monthKey);

        if (preg_match('/^\d{4}-\d{2}-01$/', $monthKey) === 1) {
            $monthStart = new \DateTimeImmutable($monthKey);
            $monthEnd = $monthStart->modify('last day of this month');
            $where[] = 't.txn_date BETWEEN :month_start AND :month_end';
            $params['month_start'] = $monthStart->format('Y-m-d');
            $params['month_end'] = $monthEnd->format('Y-m-d');
        }

        $internalTransferMarkerExpression = $this->internalTransferMarkerExpression('ca.');
        $stmt = \InterfaceDB::prepare(
            'SELECT t.id,
                    t.company_id,
                    t.accounting_period_id,
                    t.account_id,
                    t.txn_date,
                    t.txn_type,
                    t.description,
                    t.reference,
                    t.amount,
                    t.currency,
                    t.source_type,
                    t.source_account_label,
                    t.source_created_at,
                    t.source_processed_at,
                    t.source_category,
                    t.source_document_url,
                    t.counterparty_name,
                    t.card,
                    t.nominal_account_id,
                    t.director_id,
                    t.transfer_account_id,
                    t.is_internal_transfer,
                    t.category_status,
                    t.auto_rule_id,
                    t.is_auto_excluded,
                    ' . $internalTransferMarkerExpression . ' AS internal_transfer_marker,
                    COALESCE(ca.nominal_account_id, 0) AS source_account_nominal_id
             FROM transactions t
             LEFT JOIN company_accounts ca ON ca.id = t.account_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY t.txn_date ASC, t.id ASC'
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function isTransferTransaction(array $transaction): bool {
        $txnType = trim((string)($transaction['txn_type'] ?? ''));
        $marker = trim((string)($transaction['internal_transfer_marker'] ?? ''));

        if ($txnType !== '' && $marker !== '' && strcasecmp($txnType, $marker) === 0) {
            return true;
        }

        return (int)($transaction['is_internal_transfer'] ?? 0) === 1
            || (int)($transaction['transfer_account_id'] ?? 0) > 0;
    }

    private function internalTransferMarkerExpression(string $prefix = ''): string
    {
        return \InterfaceDB::columnExists('company_accounts', 'internal_transfer_marker')
            ? 'COALESCE(' . $prefix . 'internal_transfer_marker, \'\')'
            : '\'\'';
    }

    private function validateTransferAccountId(array $transaction, ?int $transferAccountId, bool $isAutoExcluded): array {
        if ($isAutoExcluded) {
            return [];
        }

        if ($transferAccountId === null || $transferAccountId <= 0) {
            return ['Choose the destination owned account before saving this transfer.'];
        }

        if ((int)($transaction['company_id'] ?? 0) <= 0) {
            return ['The transfer transaction is missing its company context.'];
        }

        if ($transferAccountId === (int)($transaction['account_id'] ?? 0)) {
            return ['The transfer account must be different from the source account.'];
        }

        $stmt = \InterfaceDB::prepare(
            'SELECT company_id,
                    account_type,
                    is_active
             FROM company_accounts
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $transferAccountId]);
        $account = $stmt->fetch();

        if (!is_array($account)) {
            return ['The selected transfer account could not be found.'];
        }

        if ((int)($account['company_id'] ?? 0) !== (int)($transaction['company_id'] ?? 0)) {
            return ['The selected transfer account must belong to the same company.'];
        }

        if (!in_array((string)($account['account_type'] ?? ''), [\eel_accounts\Service\CompanyAccountService::TYPE_BANK, \eel_accounts\Service\CompanyAccountService::TYPE_TRADE], true)) {
            return ['Transfers must point to another active bank or trade account.'];
        }

        if ((int)($account['is_active'] ?? 0) !== 1) {
            return ['Transfers must point to an active bank or trade account.'];
        }

        return [];
    }

    private function validateDestinationNominal(array $transaction, ?int $nominalAccountId): array
    {
        if ($nominalAccountId === null || $nominalAccountId <= 0) {
            return [];
        }

        $sourceNominalAccountId = (int)($transaction['source_account_nominal_id'] ?? 0);
        if ($sourceNominalAccountId <= 0 || $sourceNominalAccountId !== $nominalAccountId) {
            return [];
        }

        return ['The destination nominal cannot be the same nominal used by the source account.'];
    }

    private function transactionHasDerivedJournal(int $transactionId): bool {
        if ((new \eel_accounts\Service\TransactionInterAccountMarkerService())->isMatchedNoPostTransaction($transactionId)) {
            return false;
        }

        return (int)\InterfaceDB::fetchColumn(
            'SELECT EXISTS(
                SELECT 1
                FROM journals j
                WHERE j.source_type = :source_type
                  AND j.source_ref = :source_ref
            )',
            [
                'source_type' => 'bank_csv',
                'source_ref' => 'transaction:' . $transactionId,
            ]
        ) === 1;
    }

    private function ruleMatches(array $rule, array $transactionPayload): bool {
        $matchValue = trim((string)($rule['desc_match_value'] ?? $rule['match_value'] ?? ''));

        if ($matchValue === '') {
            return false;
        }

        $candidate = $this->ruleCandidateText((string)($rule['match_field'] ?? 'description'), $transactionPayload);

        if (!$this->textMatches($candidate, $matchValue, (string)($rule['desc_match_type'] ?? $rule['match_type'] ?? 'contains'))) {
            return false;
        }

        $refMatchType = (string)($rule['ref_match_type'] ?? 'none');
        if ($refMatchType !== 'none' && !$this->textMatches(
            $this->stringValue($transactionPayload['reference'] ?? null),
            trim((string)($rule['ref_match_value'] ?? '')),
            $refMatchType
        )) {
            return false;
        }

        $sourceCategoryRule = trim((string)($rule['source_category_value'] ?? ''));

        if ($sourceCategoryRule !== '' && !$this->textMatches(
            $this->stringValue($transactionPayload['source_category'] ?? null),
            $sourceCategoryRule,
            (string)($rule['desc_match_type'] ?? $rule['match_type'] ?? 'contains')
        )) {
            return false;
        }

        $sourceAccountRule = trim((string)($rule['source_account_value'] ?? ''));

        if ($sourceAccountRule !== '' && !$this->textMatches(
            $this->stringValue($transactionPayload['source_account_label'] ?? null),
            $sourceAccountRule,
            (string)($rule['desc_match_type'] ?? $rule['match_type'] ?? 'contains')
        )) {
            return false;
        }

        return true;
    }

    private function ruleCandidateText(string $field, array $transactionPayload): string {
        return match ($field) {
            'description' => $this->stringValue($transactionPayload['description'] ?? null),
            'reference' => $this->stringValue($transactionPayload['reference'] ?? null),
            'name' => $this->stringValue($transactionPayload['counterparty_name'] ?? null),
            'type' => $this->stringValue($transactionPayload['txn_type'] ?? null),
            'card' => $this->stringValue($transactionPayload['card'] ?? null),
            'source_category' => $this->stringValue($transactionPayload['source_category'] ?? null),
            'source_account' => $this->stringValue($transactionPayload['source_account_label'] ?? null),
            default => implode("\n", array_filter([
                $this->stringValue($transactionPayload['description'] ?? null),
                $this->stringValue($transactionPayload['reference'] ?? null),
                $this->stringValue($transactionPayload['counterparty_name'] ?? null),
                $this->stringValue($transactionPayload['txn_type'] ?? null),
                $this->stringValue($transactionPayload['card'] ?? null),
                $this->stringValue($transactionPayload['source_account_label'] ?? null),
                $this->stringValue($transactionPayload['source_category'] ?? null),
            ])),
        };
    }

    private function textMatches(string $candidate, string $matchValue, string $matchType): bool {
        $candidate = trim($candidate);
        $matchValue = trim($matchValue);

        if ($candidate === '' || $matchValue === '') {
            return false;
        }

        $candidateLower = $this->lowercase($candidate);
        $matchLower = $this->lowercase($matchValue);

        return match ($matchType) {
            'equals' => $candidateLower === $matchLower,
            'starts_with' => str_starts_with($candidateLower, $matchLower),
            'regex' => $this->safeRegexMatch($matchValue, $candidate),
            default => str_contains($candidateLower, $matchLower),
        };
    }

    private function safeRegexMatch(string $pattern, string $candidate): bool {
        $delimited = '/' . str_replace('/', '\/', $pattern) . '/i';

        try {
            return @preg_match($delimited, $candidate) === 1;
        } catch (\Throwable $exception) {
            return false;
        }
    }

    private function normaliseBatchMode(string $mode): string {
        return trim($mode) === 'auto' ? 'auto' : 'uncategorised';
    }

    private function changedByValue(string $changedBy): string {
        $changedBy = trim($changedBy);

        return $changedBy !== '' ? $changedBy : 'system';
    }

    private function stringValue(mixed $value): string {
        return trim((string)$value);
    }

    private function lowercase(string $value): string {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }
}
