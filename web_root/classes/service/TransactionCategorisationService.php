<?php
declare(strict_types=1);

final class TransactionCategorisationService
{
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

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
        ?int $taxYearId = null,
        string $mode = 'uncategorised',
        ?string $monthKey = null,
        string $changedBy = 'system'
    ): array {
        $mode = $this->normaliseBatchMode($mode);
        $transactions = $this->fetchBatchTransactions($companyId, $taxYearId, $mode, $monthKey);
        $rules = $this->fetchActiveRules($companyId);
        $summary = [
            'success' => true,
            'mode' => $mode,
            'processed' => 0,
            'changed' => 0,
            'unchanged' => 0,
            'changed_transaction_ids' => [],
        ];

        $ownsTransaction = !$this->pdo->inTransaction();

        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
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
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
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

    private function assertPeriodUnlocked(array $transaction, string $actionLabel): void {
        (new YearEndLockService($this->pdo))->assertUnlocked(
            (int)($transaction['company_id'] ?? 0),
            (int)($transaction['tax_year_id'] ?? 0),
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
        $stmt = $this->pdo->prepare(
            'SELECT t.id,
                    t.company_id,
                    t.tax_year_id,
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
                    t.transfer_account_id,
                    t.is_internal_transfer,
                    t.category_status,
                    t.auto_rule_id,
                    t.is_auto_excluded,
                    COALESCE(ca.internal_transfer_marker, \'\') AS internal_transfer_marker
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

        $stmt = $this->pdo->prepare(
            'SELECT id,
                    company_id,
                    priority,
                    match_field,
                    match_type,
                    match_value,
                    source_category_value,
                    source_account_value,
                    nominal_account_id,
                    is_active
             FROM categorisation_rules
             WHERE company_id = :company_id
               AND is_active = 1
             ORDER BY priority ASC, id ASC'
        );
        $stmt->execute(['company_id' => $companyId]);

        return $stmt->fetchAll();
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
            return [
                'nominal_account_id' => (int)$rule['nominal_account_id'],
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
            'transfer_account_id' => $newTransferAccountId,
            'is_internal_transfer' => 1,
            'category_status' => $newTransferAccountId !== null ? 'manual' : 'uncategorised',
            'auto_rule_id' => null,
            'is_auto_excluded' => $isAutoExcluded ? 1 : 0,
            'reason' => $newTransferAccountId !== null ? 'manual transfer account selection' : 'transfer reset to account needed',
            'reason_code' => 'manual_transfer_save',
        ];
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
        $ownsTransaction = !$this->pdo->inTransaction();

        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
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
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function prepareCategorisationStatements(): array {
        return [
            'update' => $this->pdo->prepare(
                'UPDATE transactions
                 SET nominal_account_id = :nominal_account_id,
                     transfer_account_id = :transfer_account_id,
                     is_internal_transfer = :is_internal_transfer,
                     category_status = :category_status,
                     auto_rule_id = :auto_rule_id,
                     is_auto_excluded = :is_auto_excluded
                 WHERE id = :id'
            ),
            'audit' => $this->pdo->prepare(
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
        PDOStatement $update,
        PDOStatement $audit
    ): void {
        $update->execute([
            'nominal_account_id' => $nextState['nominal_account_id'],
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
    }

    private function isEligibleForAuto(array $transaction, bool $reapplyExistingAuto): bool {
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
        $oldStatus = trim((string)($transaction['category_status'] ?? 'uncategorised'));
        $newStatus = trim((string)($nextState['category_status'] ?? 'uncategorised'));

        $oldPosted = in_array($oldStatus, ['auto', 'manual'], true) && $oldNominalAccountId !== null;
        $newPosted = in_array($newStatus, ['auto', 'manual'], true) && $newNominalAccountId !== null;

        if ($oldPosted !== $newPosted) {
            return true;
        }

        return $oldNominalAccountId !== $newNominalAccountId;
    }

    private function categorisationFieldsChanged(array $transaction, array $nextState): bool {
        $oldNominalAccountId = $transaction['nominal_account_id'] !== null ? (int)$transaction['nominal_account_id'] : null;
        $newNominalAccountId = $nextState['nominal_account_id'] !== null ? (int)$nextState['nominal_account_id'] : null;
        $oldTransferAccountId = $transaction['transfer_account_id'] !== null ? (int)$transaction['transfer_account_id'] : null;
        $newTransferAccountId = $nextState['transfer_account_id'] !== null ? (int)$nextState['transfer_account_id'] : null;
        $oldAutoRuleId = $transaction['auto_rule_id'] !== null ? (int)$transaction['auto_rule_id'] : null;
        $newAutoRuleId = $nextState['auto_rule_id'] !== null ? (int)$nextState['auto_rule_id'] : null;

        return $oldNominalAccountId !== $newNominalAccountId
            || $oldTransferAccountId !== $newTransferAccountId
            || (int)($transaction['is_internal_transfer'] ?? 0) !== (int)($nextState['is_internal_transfer'] ?? 0)
            || trim((string)($transaction['category_status'] ?? '')) !== trim((string)($nextState['category_status'] ?? ''))
            || $oldAutoRuleId !== $newAutoRuleId
            || (int)($transaction['is_auto_excluded'] ?? 0) !== (int)($nextState['is_auto_excluded'] ?? 0);
    }

    private function fetchBatchTransactions(int $companyId, ?int $taxYearId, string $mode, ?string $monthKey): array {
        if ($companyId <= 0) {
            return [];
        }

        $where = [
            't.company_id = :company_id',
        ];
        $params = [
            'company_id' => $companyId,
        ];

        if ($taxYearId !== null && $taxYearId > 0) {
            $where[] = 't.tax_year_id = :tax_year_id';
            $params['tax_year_id'] = $taxYearId;
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
            $monthStart = new DateTimeImmutable($monthKey);
            $monthEnd = $monthStart->modify('last day of this month');
            $where[] = 't.txn_date BETWEEN :month_start AND :month_end';
            $params['month_start'] = $monthStart->format('Y-m-d');
            $params['month_end'] = $monthEnd->format('Y-m-d');
        }

        $stmt = $this->pdo->prepare(
            'SELECT t.id,
                    t.company_id,
                    t.tax_year_id,
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
                    t.transfer_account_id,
                    t.is_internal_transfer,
                    t.category_status,
                    t.auto_rule_id,
                    t.is_auto_excluded,
                    COALESCE(ca.internal_transfer_marker, \'\') AS internal_transfer_marker
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

        $stmt = $this->pdo->prepare(
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

        if ((string)($account['account_type'] ?? '') !== CompanyAccountService::TYPE_BANK) {
            return ['Internal transfers must point to another active bank account.'];
        }

        if ((int)($account['is_active'] ?? 0) !== 1) {
            return ['Internal transfers must point to an active bank account.'];
        }

        return [];
    }

    private function transactionHasDerivedJournal(int $transactionId): bool {
        $stmt = $this->pdo->prepare(
            'SELECT EXISTS(
                SELECT 1
                FROM journals j
                WHERE j.source_type = :source_type
                  AND j.source_ref = :source_ref
            )'
        );
        $stmt->execute([
            'source_type' => 'bank_csv',
            'source_ref' => 'transaction:' . $transactionId,
        ]);

        return (int)$stmt->fetchColumn() === 1;
    }

    private function ruleMatches(array $rule, array $transactionPayload): bool {
        $matchValue = trim((string)($rule['match_value'] ?? ''));

        if ($matchValue === '') {
            return false;
        }

        $candidate = $this->ruleCandidateText((string)($rule['match_field'] ?? 'description'), $transactionPayload);

        if (!$this->textMatches($candidate, $matchValue, (string)($rule['match_type'] ?? 'contains'))) {
            return false;
        }

        $sourceCategoryRule = trim((string)($rule['source_category_value'] ?? ''));

        if ($sourceCategoryRule !== '' && !$this->textMatches(
            $this->stringValue($transactionPayload['source_category'] ?? null),
            $sourceCategoryRule,
            (string)($rule['match_type'] ?? 'contains')
        )) {
            return false;
        }

        $sourceAccountRule = trim((string)($rule['source_account_value'] ?? ''));

        if ($sourceAccountRule !== '' && !$this->textMatches(
            $this->stringValue($transactionPayload['source_account_label'] ?? null),
            $sourceAccountRule,
            (string)($rule['match_type'] ?? 'contains')
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
        } catch (Throwable $exception) {
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
