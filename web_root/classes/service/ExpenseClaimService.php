<?php
declare(strict_types=1);

final class ExpenseClaimService
{
    private TransactionCategorisationService $categorisationService;
    private TransactionJournalService $journalService;

    public function __construct(
        ?TransactionCategorisationService $categorisationService = null,
        ?TransactionJournalService $journalService = null
    ) {
        $this->categorisationService = $categorisationService ?? new TransactionCategorisationService();
        $this->journalService = $journalService ?? new TransactionJournalService();
    }

    public function fetchPageData(int $companyId, array $filters = []): array {
        $selectedClaim = null;
        if (isset($filters['claim_reference_code']) && trim((string)$filters['claim_reference_code']) !== '') {
            $selectedClaim = $this->fetchClaimByReferenceCode($companyId, (string)$filters['claim_reference_code']);
        } elseif (isset($filters['claim_id'])) {
            $selectedClaim = $this->fetchClaim($companyId, (int)$filters['claim_id']);
        }

        return [
            'claimants' => $this->fetchClaimants($companyId, false),
            'active_claimant_count' => count($this->fetchClaimants($companyId, true)),
            'nominal_accounts' => $this->fetchExpenseNominals(),
            'claims' => $this->listClaims($companyId, $filters),
            'selected_claim' => $selectedClaim,
            'filters' => [
                'query' => trim((string)($filters['query'] ?? '')),
                'status' => $this->normaliseStatusFilter((string)($filters['status'] ?? 'all')),
            ],
        ];
    }

    public function fetchClaimants(int $companyId, bool $activeOnly = true): array {
        if ($companyId <= 0) {
            return [];
        }

        $sql = 'SELECT id, company_id, claimant_name, is_active, created_at, updated_at
                FROM expense_claimants
                WHERE company_id = :company_id';

        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }

        $sql .= ' ORDER BY claimant_name ASC, id ASC';

        return InterfaceDB::fetchAll( $sql, ['company_id' => $companyId]);
    }

    public function createClaimant(int $companyId, string $claimantName): array {
        $claimantName = trim($claimantName);

        if ($companyId <= 0) {
            return [
                'success' => false,
                'errors' => ['Select a company before adding a claimant.'],
            ];
        }

        if ($claimantName === '') {
            return [
                'success' => false,
                'errors' => ['Enter a claimant name.'],
            ];
        }

        $existing = $this->findClaimantByName($companyId, $claimantName);
        if ($existing !== null) {
            if ((int)($existing['is_active'] ?? 0) !== 1) {
                InterfaceDB::prepare(
                    'UPDATE expense_claimants
                     SET is_active = 1,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                )->execute(['id' => (int)$existing['id']]);
                $existing = $this->fetchClaimantById($companyId, (int)$existing['id']);
            }

            return [
                'success' => true,
                'claimant' => $existing,
                'claimants' => $this->fetchClaimants($companyId, false),
                'messages' => ['That claimant already existed, so it has been selected.'],
            ];
        }

        InterfaceDB::prepare(
            'INSERT INTO expense_claimants (
                company_id,
                claimant_name,
                is_active,
                created_at,
                updated_at
             ) VALUES (
                :company_id,
                :claimant_name,
                1,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
             )'
        )->execute([
            'company_id' => $companyId,
            'claimant_name' => $claimantName,
        ]);

        $claimant = $this->findClaimantByName($companyId, $claimantName);

        return [
            'success' => $claimant !== null,
            'claimant' => $claimant,
            'claimants' => $this->fetchClaimants($companyId, false),
            'active_claimant_count' => count($this->fetchClaimants($companyId, true)),
            'errors' => $claimant === null ? ['The claimant could not be saved.'] : [],
        ];
    }

    public function setClaimantActive(int $companyId, int $claimantId, bool $isActive): array {
        if ($companyId <= 0 || $claimantId <= 0) {
            return [
                'success' => false,
                'errors' => ['Select a valid claimant first.'],
            ];
        }

        $claimant = $this->fetchClaimantById($companyId, $claimantId);
        if ($claimant === null) {
            return [
                'success' => false,
                'errors' => ['The selected claimant could not be found.'],
            ];
        }

        InterfaceDB::prepare(
            'UPDATE expense_claimants
             SET is_active = :is_active,
                 updated_at = CURRENT_TIMESTAMP
             WHERE company_id = :company_id
               AND id = :id'
        )->execute([
            'is_active' => $isActive ? 1 : 0,
            'company_id' => $companyId,
            'id' => $claimantId,
        ]);

        return [
            'success' => true,
            'claimant' => $this->fetchClaimantById($companyId, $claimantId),
            'claimants' => $this->fetchClaimants($companyId, false),
            'active_claimant_count' => count($this->fetchClaimants($companyId, true)),
            'messages' => [$isActive ? 'Claimant activated.' : 'Claimant deactivated.'],
        ];
    }

    public function listClaims(int $companyId, array $filters = []): array {
        if ($companyId <= 0) {
            return [];
        }

        $conditions = ['ec.company_id = :company_id'];
        $params = ['company_id' => $companyId];
        $query = trim((string)($filters['query'] ?? ''));
        $status = $this->normaliseStatusFilter((string)($filters['status'] ?? 'all'));

        if ($query !== '') {
            $conditions[] = '(ec.claim_reference_code LIKE :query_reference OR ec.notes LIKE :query_notes OR c.claimant_name LIKE :query_claimant)';
            $params['query_reference'] = '%' . $query . '%';
            $params['query_notes'] = '%' . $query . '%';
            $params['query_claimant'] = '%' . $query . '%';
        }

        if ($status !== 'all') {
            $conditions[] = 'ec.status = :status';
            $params['status'] = $status;
        }

        return array_map([$this, 'formatClaimSummary'], InterfaceDB::fetchAll( 'SELECT ec.id,
                    ec.company_id,
                    ec.tax_year_id,
                    ec.claimant_id,
                    ec.claim_year,
                    ec.claim_month,
                    ec.period_start,
                    ec.period_end,
                    ec.claim_reference_code,
                    ec.brought_forward_amount,
                    ec.claimed_amount,
                    ec.payments_amount,
                    ec.carried_forward_amount,
                    ec.status,
                    ec.posted_journal_id,
                    ec.notes,
                    ec.created_at,
                    ec.updated_at,
                    c.claimant_name
             FROM expense_claims ec
             INNER JOIN expense_claimants c ON c.id = ec.claimant_id
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY ec.claim_year DESC, ec.claim_month DESC, ec.updated_at DESC, ec.id DESC', $params));
    }

    public function createClaim(int $companyId, array $payload): array {
        $claimantId = isset($payload['claimant_id']) ? (int)$payload['claimant_id'] : 0;
        $period = $this->normaliseClaimPeriodFromPayload($payload);

        if ($companyId <= 0) {
            return ['success' => false, 'errors' => ['Select a company before creating a claim.']];
        }

        if ($claimantId <= 0) {
            return ['success' => false, 'errors' => ['Choose a claimant before creating a claim.']];
        }

        if ($period === null) {
            return ['success' => false, 'errors' => ['Choose a valid claim month.']];
        }

        $incorporationDate = trim((string)($payload['incorporation_date'] ?? ''));
        if ($incorporationDate !== '' && !$this->periodIsOnOrAfterIncorporation($period['year'], $period['month'], $incorporationDate)) {
            return ['success' => false, 'errors' => ['Claim month cannot be earlier than the company incorporation date.']];
        }

        $existing = $this->findClaimByUniqueMonth($companyId, $claimantId, $period['year'], $period['month']);
        if ($existing !== null) {
            return [
                'success' => true,
                'claim' => $this->fetchClaim($companyId, (int)$existing['id']),
                'claims' => $this->listClaims($companyId),
                'messages' => ['That claimant already has a claim for the selected month, so the existing claim was opened.'],
            ];
        }

        $claimant = $this->fetchClaimantById($companyId, $claimantId);
        if ($claimant === null) {
            return ['success' => false, 'errors' => ['The selected claimant could not be found.']];
        }

        $derivedPeriod = $this->deriveMonthlyPeriod($period['year'], $period['month']);
        $resolvedTaxYearId = $this->resolveTaxYearIdForDate($companyId, $derivedPeriod['period_end']);
        if ($resolvedTaxYearId > 0) {
            (new YearEndLockService())->assertUnlocked($companyId, $resolvedTaxYearId, 'create expense claims in this period');
        }
        if ((int)($claimant['is_active'] ?? 0) !== 1) {
            return ['success' => false, 'errors' => ['Only active claimants can be used for new claims.']];
        }

        $taxYearId = $this->deriveTaxYearId($companyId, $derivedPeriod['period_start'], $derivedPeriod['period_end']);

        if ($taxYearId <= 0) {
            return ['success' => false, 'errors' => ['No accounting period overlaps the selected claim month.']];
        }

        $referenceCode = $this->generateUniqueReferenceCode($companyId, $period['year'], $period['month']);

        InterfaceDB::prepare(
            'INSERT INTO expense_claims (
                company_id,
                tax_year_id,
                claimant_id,
                claim_year,
                claim_month,
                period_start,
                period_end,
                claim_reference_code,
                brought_forward_amount,
                claimed_amount,
                payments_amount,
                carried_forward_amount,
                status,
                posted_journal_id,
                notes,
                created_at,
                updated_at
             ) VALUES (
                :company_id,
                :tax_year_id,
                :claimant_id,
                :claim_year,
                :claim_month,
                :period_start,
                :period_end,
                :claim_reference_code,
                0,
                0,
                0,
                0,
                :status,
                NULL,
                :notes,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
             )'
        )->execute([
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
            'claimant_id' => $claimantId,
            'claim_year' => $period['year'],
            'claim_month' => $period['month'],
            'period_start' => $derivedPeriod['period_start'],
            'period_end' => $derivedPeriod['period_end'],
            'claim_reference_code' => $referenceCode,
            'status' => 'draft',
            'notes' => trim((string)($payload['notes'] ?? '')),
        ]);

        $claim = $this->findClaimByUniqueMonth($companyId, $claimantId, $period['year'], $period['month']);
        if ($claim === null) {
            return ['success' => false, 'errors' => ['The claim could not be created.']];
        }

        $this->recalculateClaimSeries($companyId, $claimantId);

        return [
            'success' => true,
            'claim' => $this->fetchClaim($companyId, (int)$claim['id']),
            'claims' => $this->listClaims($companyId),
        ];
    }

    public function fetchClaim(int $companyId, int $claimId): ?array {
        if ($companyId <= 0 || $claimId <= 0) {
            return null;
        }

        $claim = InterfaceDB::fetchOne( 'SELECT ec.*,
                    c.claimant_name
             FROM expense_claims ec
             INNER JOIN expense_claimants c ON c.id = ec.claimant_id
             WHERE ec.company_id = :company_id
               AND ec.id = :id
             LIMIT 1', [
            'company_id' => $companyId,
            'id' => $claimId,
        ]);

        if (!is_array($claim)) {
            return null;
        }

        $claim['lines'] = $this->fetchClaimLines($claimId);
        $claim['payment_links'] = $this->fetchPaymentLinks($claimId);
        $claim['control_totals'] = [
            'A' => (float)$claim['brought_forward_amount'],
            'B' => (float)$claim['claimed_amount'],
            'C' => (float)$claim['payments_amount'],
            'D' => (float)$claim['carried_forward_amount'],
        ];
        $claim['claim_period'] = sprintf('%04d-%02d', (int)$claim['claim_year'], (int)$claim['claim_month']);
        $claim['is_posted'] = (string)$claim['status'] === 'posted';
        $claim['status_label'] = ucfirst((string)$claim['status']);

        return $claim;
    }

    public function fetchClaimByReferenceCode(int $companyId, string $referenceCode): ?array {
        $referenceCode = trim($referenceCode);
        if ($companyId <= 0 || $referenceCode === '') {
            return null;
        }

        $claimId = (int)InterfaceDB::fetchColumn( 'SELECT id
             FROM expense_claims
             WHERE company_id = :company_id
               AND claim_reference_code = :claim_reference_code
             LIMIT 1', [
            'company_id' => $companyId,
            'claim_reference_code' => $referenceCode,
        ]);
        return $claimId > 0 ? $this->fetchClaim($companyId, $claimId) : null;
    }

    public function updateClaim(int $companyId, int $claimId, array $payload): array {
        $claim = $this->fetchClaim($companyId, $claimId);
        if ($claim === null) {
            return ['success' => false, 'errors' => ['The selected claim could not be found.']];
        }

        if ((string)$claim['status'] === 'posted') {
            return ['success' => false, 'errors' => ['Posted claims are locked.']];
        }

        $previousClaimantId = (int)$claim['claimant_id'];
        $nextClaimantId = array_key_exists('claimant_id', $payload)
            ? (int)$payload['claimant_id']
            : $previousClaimantId;
        $nextPeriod = (array_key_exists('claim_period', $payload) || array_key_exists('claim_year', $payload) || array_key_exists('claim_month', $payload))
            ? $this->normaliseClaimPeriodFromPayload($payload)
            : [
                'year' => (int)$claim['claim_year'],
                'month' => (int)$claim['claim_month'],
            ];
        $nextNotes = array_key_exists('notes', $payload)
            ? trim((string)$payload['notes'])
            : (string)($claim['notes'] ?? '');

        if ($nextClaimantId <= 0) {
            return ['success' => false, 'errors' => ['Choose a claimant.']];
        }

        if ($nextPeriod === null) {
            return ['success' => false, 'errors' => ['Choose a valid claim month.']];
        }

        $incorporationDate = trim((string)($payload['incorporation_date'] ?? ''));
        if ($incorporationDate !== '' && !$this->periodIsOnOrAfterIncorporation($nextPeriod['year'], $nextPeriod['month'], $incorporationDate)) {
            return ['success' => false, 'errors' => ['Claim month cannot be earlier than the company incorporation date.']];
        }

        $nextClaimant = $this->fetchClaimantById($companyId, $nextClaimantId);
        if ($nextClaimant === null) {
            return ['success' => false, 'errors' => ['The selected claimant could not be found.']];
        }
        if ((int)($nextClaimant['is_active'] ?? 0) !== 1) {
            return ['success' => false, 'errors' => ['Only active claimants can be used for claims.']];
        }

        $duplicate = $this->findClaimByUniqueMonth($companyId, $nextClaimantId, $nextPeriod['year'], $nextPeriod['month']);
        if ($duplicate !== null && (int)$duplicate['id'] !== $claimId) {
            return ['success' => false, 'errors' => ['That claimant already has a claim for the selected month.']];
        }

        $derivedPeriod = $this->deriveMonthlyPeriod($nextPeriod['year'], $nextPeriod['month']);
        $taxYearId = $this->deriveTaxYearId($companyId, $derivedPeriod['period_start'], $derivedPeriod['period_end']);

        if ($taxYearId <= 0) {
            return ['success' => false, 'errors' => ['No accounting period overlaps the selected claim month.']];
        }

        InterfaceDB::prepare(
            'UPDATE expense_claims
             SET claimant_id = :claimant_id,
                 tax_year_id = :tax_year_id,
                 claim_year = :claim_year,
                 claim_month = :claim_month,
                 period_start = :period_start,
                 period_end = :period_end,
                 notes = :notes,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND company_id = :company_id'
        )->execute([
            'claimant_id' => $nextClaimantId,
            'tax_year_id' => $taxYearId,
            'claim_year' => $nextPeriod['year'],
            'claim_month' => $nextPeriod['month'],
            'period_start' => $derivedPeriod['period_start'],
            'period_end' => $derivedPeriod['period_end'],
            'notes' => $nextNotes,
            'id' => $claimId,
            'company_id' => $companyId,
        ]);

        $this->recalculateClaimSeries($companyId, $previousClaimantId);
        if ($nextClaimantId !== $previousClaimantId) {
            $this->recalculateClaimSeries($companyId, $nextClaimantId);
        }

        return [
            'success' => true,
            'claim' => $this->fetchClaim($companyId, $claimId),
            'claims' => $this->listClaims($companyId),
        ];
    }

    public function saveLine(int $companyId, int $claimId, array $payload): array {
        $claim = $this->fetchClaim($companyId, $claimId);
        if ($claim === null) {
            return ['success' => false, 'errors' => ['The selected claim could not be found.']];
        }

        if ((string)$claim['status'] === 'posted') {
            return ['success' => false, 'errors' => ['Posted claims are locked.']];
        }

        $lineId = isset($payload['id']) ? (int)$payload['id'] : 0;
        $errors = $this->validateLinePayload($payload);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        $expenseDate = trim((string)$payload['expense_date']);
        $description = trim((string)$payload['description']);
        $amount = round((float)$payload['amount'], 2);
        $nominalAccountId = isset($payload['nominal_account_id']) && (int)$payload['nominal_account_id'] > 0
            ? (int)$payload['nominal_account_id']
            : (isset($payload['default_expense_nominal_id']) && (int)$payload['default_expense_nominal_id'] > 0
                ? (int)$payload['default_expense_nominal_id']
                : null);
        $receiptReference = trim((string)($payload['receipt_reference'] ?? ''));
        $notes = trim((string)($payload['notes'] ?? ''));

        if ($lineId > 0) {
            InterfaceDB::prepare(
                'UPDATE expense_claim_lines
                 SET expense_date = :expense_date,
                     description = :description,
                     amount = :amount,
                     nominal_account_id = :nominal_account_id,
                     receipt_reference = :receipt_reference,
                     notes = :notes,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id
                   AND expense_claim_id = :expense_claim_id'
            )->execute([
                'expense_date' => $expenseDate,
                'description' => $description,
                'amount' => $amount,
                'nominal_account_id' => $nominalAccountId,
                'receipt_reference' => $receiptReference !== '' ? $receiptReference : null,
                'notes' => $notes !== '' ? $notes : null,
                'id' => $lineId,
                'expense_claim_id' => $claimId,
            ]);
        } else {
            $lineNumber = $this->nextLineNumber($claimId);
            InterfaceDB::prepare(
                'INSERT INTO expense_claim_lines (
                    expense_claim_id,
                    line_number,
                    expense_date,
                    description,
                    amount,
                    nominal_account_id,
                    receipt_reference,
                    notes,
                    created_at,
                    updated_at
                 ) VALUES (
                    :expense_claim_id,
                    :line_number,
                    :expense_date,
                    :description,
                    :amount,
                    :nominal_account_id,
                    :receipt_reference,
                    :notes,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                 )'
            )->execute([
                'expense_claim_id' => $claimId,
                'line_number' => $lineNumber,
                'expense_date' => $expenseDate,
                'description' => $description,
                'amount' => $amount,
                'nominal_account_id' => $nominalAccountId,
                'receipt_reference' => $receiptReference !== '' ? $receiptReference : null,
                'notes' => $notes !== '' ? $notes : null,
            ]);
        }

        $this->recalculateClaimSeries($companyId, (int)$claim['claimant_id']);

        return [
            'success' => true,
            'claim' => $this->fetchClaim($companyId, $claimId),
            'claims' => $this->listClaims($companyId),
        ];
    }

    public function deleteLine(int $companyId, int $claimId, int $lineId): array {
        $claim = $this->fetchClaim($companyId, $claimId);
        if ($claim === null) {
            return ['success' => false, 'errors' => ['The selected claim could not be found.']];
        }

        if ((string)$claim['status'] === 'posted') {
            return ['success' => false, 'errors' => ['Posted claims are locked.']];
        }

        InterfaceDB::prepare(
            'DELETE FROM expense_claim_lines
             WHERE id = :id
               AND expense_claim_id = :expense_claim_id'
        )->execute([
            'id' => $lineId,
            'expense_claim_id' => $claimId,
        ]);

        $this->renumberLines($claimId);
        $this->recalculateClaimSeries($companyId, (int)$claim['claimant_id']);

        return [
            'success' => true,
            'claim' => $this->fetchClaim($companyId, $claimId),
            'claims' => $this->listClaims($companyId),
        ];
    }

    public function linkPayment(int $companyId, int $claimId, array $payload): array {
        $claim = $this->fetchClaim($companyId, $claimId);
        if ($claim === null) {
            return ['success' => false, 'errors' => ['The selected claim could not be found.']];
        }
        (new YearEndLockService())->assertUnlocked($companyId, (int)($claim['tax_year_id'] ?? 0), 'link expense repayments in this period');

        if ((string)$claim['status'] === 'posted') {
            return ['success' => false, 'errors' => ['Posted claims are locked.']];
        }

        $transactionId = isset($payload['transaction_id']) ? (int)$payload['transaction_id'] : 0;
        $linkedAmount = isset($payload['linked_amount']) ? round((float)$payload['linked_amount'], 2) : 0.0;
        $directorLoanNominalId = isset($payload['director_loan_nominal_id']) ? (int)$payload['director_loan_nominal_id'] : 0;
        $defaultBankNominalId = isset($payload['default_bank_nominal_id']) ? (int)$payload['default_bank_nominal_id'] : 0;

        if ($transactionId <= 0) {
            return ['success' => false, 'errors' => ['Select a repayment transaction.']];
        }

        if ($linkedAmount <= 0) {
            return ['success' => false, 'errors' => ['Linked repayment amount must be greater than zero.']];
        }

        if ($directorLoanNominalId <= 0) {
            return ['success' => false, 'errors' => ['Set the director loan nominal before linking repayments.']];
        }

        $transaction = $this->categorisationService->fetchTransaction($transactionId);
        if ($transaction === null || (int)$transaction['company_id'] !== $companyId) {
            return ['success' => false, 'errors' => ['The selected transaction could not be found.']];
        }

        $transactionAmount = round(abs((float)$transaction['amount']), 2);
        if ($linkedAmount > $transactionAmount) {
            return ['success' => false, 'errors' => ['Linked repayment amount cannot exceed the bank transaction amount.']];
        }

        $allocatedElsewhere = $this->sumLinkedAmountForTransaction($transactionId, $claimId);
        if (($allocatedElsewhere + $linkedAmount) - $transactionAmount > 0.0001) {
            return ['success' => false, 'errors' => ['That transaction is already linked elsewhere for most of its value.']];
        }

        $ownsTransaction = !InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            InterfaceDB::beginTransaction();
        }

        try {
            $existingLink = $this->findPaymentLink($claimId, $transactionId);
            if ($existingLink !== null) {
                InterfaceDB::prepare(
                    'UPDATE expense_claim_payment_links
                     SET linked_amount = :linked_amount
                     WHERE id = :id'
                )->execute([
                    'linked_amount' => $linkedAmount,
                    'id' => (int)$existingLink['id'],
                ]);
            } else {
                InterfaceDB::prepare(
                    'INSERT INTO expense_claim_payment_links (
                        expense_claim_id,
                        transaction_id,
                        linked_amount,
                        created_at
                     ) VALUES (
                        :expense_claim_id,
                        :transaction_id,
                        :linked_amount,
                        CURRENT_TIMESTAMP
                     )'
                )->execute([
                    'expense_claim_id' => $claimId,
                    'transaction_id' => $transactionId,
                    'linked_amount' => $linkedAmount,
                ]);
            }

            $saveResult = $this->categorisationService->saveManualCategorisation(
                $transactionId,
                $directorLoanNominalId,
                null,
                false,
                'expense_claim_payment_link',
                true
            );

            if (!empty($saveResult['errors'])) {
                throw new RuntimeException(implode(' ', array_map('strval', $saveResult['errors'])));
            }

            if (!empty($saveResult['requires_journal_rebuild'])) {
                if ($defaultBankNominalId <= 0) {
                    throw new RuntimeException('Set the default bank nominal before linking a posted repayment transaction.');
                }

                $journalResult = $this->journalService->syncJournalForTransaction(
                    $transactionId,
                    $defaultBankNominalId,
                    'expense_claim_payment_link',
                    true
                );

                if (!empty($journalResult['errors'])) {
                    throw new RuntimeException(implode(' ', array_map('strval', $journalResult['errors'])));
                }
            }

            $this->recalculateClaimSeries($companyId, (int)$claim['claimant_id']);

            if ($ownsTransaction) {
                InterfaceDB::commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }

            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }

        return [
            'success' => true,
            'claim' => $this->fetchClaim($companyId, $claimId),
            'claims' => $this->listClaims($companyId),
        ];
    }

    public function unlinkPayment(int $companyId, int $claimId, int $paymentLinkId): array {
        $claim = $this->fetchClaim($companyId, $claimId);
        if ($claim === null) {
            return ['success' => false, 'errors' => ['The selected claim could not be found.']];
        }

        if ((string)$claim['status'] === 'posted') {
            return ['success' => false, 'errors' => ['Posted claims are locked.']];
        }

        InterfaceDB::prepare(
            'DELETE FROM expense_claim_payment_links
             WHERE id = :id
               AND expense_claim_id = :expense_claim_id'
        )->execute([
            'id' => $paymentLinkId,
            'expense_claim_id' => $claimId,
        ]);

        $this->recalculateClaimSeries($companyId, (int)$claim['claimant_id']);

        return [
            'success' => true,
            'claim' => $this->fetchClaim($companyId, $claimId),
            'claims' => $this->listClaims($companyId),
        ];
    }

    public function searchTransactions(int $companyId, array $filters = []): array {
        if ($companyId <= 0) {
            return [];
        }

        $claimId = isset($filters['claim_id']) ? (int)$filters['claim_id'] : 0;
        $query = trim((string)($filters['query'] ?? ''));
        $currentMonthOnly = !array_key_exists('current_month_only', $filters) || (bool)$filters['current_month_only'];
        $claim = $claimId > 0 ? $this->fetchClaim($companyId, $claimId) : null;

        $conditions = ['t.company_id = ?', 't.amount < 0'];
        $params = [$companyId];

        if ($query !== '') {
            $conditions[] = '(t.description LIKE ? OR COALESCE(t.reference, \'\') LIKE ? OR COALESCE(t.counterparty_name, \'\') LIKE ?)';
            $params[] = '%' . $query . '%';
            $params[] = '%' . $query . '%';
            $params[] = '%' . $query . '%';
        }

        if ($currentMonthOnly && $claim !== null) {
            $conditions[] = 't.txn_date BETWEEN ? AND ?';
            $params[] = (string)$claim['period_start'];
            $params[] = (string)$claim['period_end'];
        }

        $stmt = InterfaceDB::prepare(
            'SELECT t.id,
                    t.txn_date,
                    t.description,
                    t.reference,
                    t.amount,
                    t.nominal_account_id,
                    t.category_status,
                    n.code AS nominal_code,
                    n.name AS nominal_name,
                    COALESCE(SUM(CASE WHEN l.expense_claim_id <> ? THEN l.linked_amount ELSE 0 END), 0) AS allocated_elsewhere,
                    MAX(CASE WHEN l.expense_claim_id = ? THEN l.id ELSE 0 END) AS current_link_id,
                    COALESCE(MAX(CASE WHEN l.expense_claim_id = ? THEN l.linked_amount ELSE 0 END), 0) AS current_link_amount
             FROM transactions t
             LEFT JOIN nominal_accounts n ON n.id = t.nominal_account_id
             LEFT JOIN expense_claim_payment_links l ON l.transaction_id = t.id
             WHERE ' . implode(' AND ', $conditions) . '
             GROUP BY t.id, t.txn_date, t.description, t.reference, t.amount, t.nominal_account_id, t.category_status, n.code, n.name
             ORDER BY t.txn_date DESC, t.id DESC
             LIMIT 80'
        );
        $stmt->execute(array_merge([$claimId, $claimId, $claimId], $params));

        return array_map(
            static function (array $row): array {
                $amount = round(abs((float)$row['amount']), 2);
                $allocatedElsewhere = round((float)$row['allocated_elsewhere'], 2);
                $availableAmount = max(0.0, round($amount - $allocatedElsewhere, 2));

                return [
                    'id' => (int)$row['id'],
                    'txn_date' => (string)$row['txn_date'],
                    'description' => (string)$row['description'],
                    'reference' => (string)($row['reference'] ?? ''),
                    'amount' => $amount,
                    'available_amount' => $availableAmount,
                    'nominal_label' => trim((string)($row['nominal_code'] ?? '')) !== ''
                        ? (string)$row['nominal_code'] . ' - ' . (string)($row['nominal_name'] ?? '')
                        : (string)($row['nominal_name'] ?? ''),
                    'category_status' => (string)$row['category_status'],
                    'current_link_id' => (int)$row['current_link_id'],
                    'current_link_amount' => round((float)$row['current_link_amount'], 2),
                ];
            },
            $stmt->fetchAll() ?: []
        );
    }

    public function postClaim(int $companyId, int $claimId, array $payload = []): array {
        $claim = $this->fetchClaim($companyId, $claimId);
        if ($claim === null) {
            return ['success' => false, 'errors' => ['The selected claim could not be found.']];
        }
        (new YearEndLockService())->assertUnlocked($companyId, (int)($claim['tax_year_id'] ?? 0), 'post expense claims in this period');

        if ((string)$claim['status'] === 'posted') {
            return ['success' => false, 'errors' => ['This claim has already been posted.']];
        }

        if ((string)$claim['claim_reference_code'] === '') {
            return ['success' => false, 'errors' => ['Claim reference code is missing.']];
        }

        $directorLoanNominalId = isset($payload['director_loan_nominal_id']) ? (int)$payload['director_loan_nominal_id'] : 0;
        if ($directorLoanNominalId <= 0) {
            return ['success' => false, 'errors' => ['Set the director loan nominal before posting a claim.']];
        }

        $lines = $this->fetchClaimLines($claimId);
        if ($lines === []) {
            return ['success' => false, 'errors' => ['Add at least one expense line before posting a claim.']];
        }

        foreach ($lines as $line) {
            $lineErrors = $this->validateLineForPosting($line);
            if ($lineErrors !== []) {
                return ['success' => false, 'errors' => $lineErrors];
            }
        }

        $totalClaimed = round((float)$claim['claimed_amount'], 2);
        if ($totalClaimed <= 0) {
            return ['success' => false, 'errors' => ['Claim total must be greater than zero before posting.']];
        }

        $existingJournal = $this->fetchExistingExpenseJournal($companyId, (string)$claim['claim_reference_code']);
        if ($existingJournal !== null) {
            return ['success' => false, 'errors' => ['An expense journal already exists for this claim reference.']];
        }

        $ownsTransaction = !InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            InterfaceDB::beginTransaction();
        }

        try {
            InterfaceDB::prepare(
                'INSERT INTO journals (
                    company_id,
                    tax_year_id,
                    source_type,
                    source_ref,
                    journal_date,
                    description,
                    is_posted,
                    created_at,
                    updated_at
                 ) VALUES (
                    :company_id,
                    :tax_year_id,
                    :source_type,
                    :source_ref,
                    :journal_date,
                    :description,
                    1,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                 )'
            )->execute([
                'company_id' => $companyId,
                'tax_year_id' => (int)$claim['tax_year_id'],
                'source_type' => 'expense_register',
                'source_ref' => (string)$claim['claim_reference_code'],
                'journal_date' => (string)$claim['period_end'],
                'description' => 'Expense claim ' . (string)$claim['claim_reference_code'],
            ]);

            $journal = $this->fetchExistingExpenseJournal($companyId, (string)$claim['claim_reference_code']);
            if ($journal === null) {
                throw new RuntimeException('The expense journal could not be created.');
            }

            foreach ($lines as $line) {
                $this->insertJournalLine(
                    (int)$journal['id'],
                    (int)$line['nominal_account_id'],
                    round((float)$line['amount'], 2),
                    0.0,
                    (string)$line['description']
                );
            }

            $this->insertJournalLine(
                (int)$journal['id'],
                $directorLoanNominalId,
                0.0,
                $totalClaimed,
                'Director loan liability'
            );

            InterfaceDB::prepare(
                'UPDATE expense_claims
                 SET status = :status,
                     posted_journal_id = :posted_journal_id,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id
                   AND company_id = :company_id'
            )->execute([
                'status' => 'posted',
                'posted_journal_id' => (int)$journal['id'],
                'id' => $claimId,
                'company_id' => $companyId,
            ]);

            if ($ownsTransaction) {
                InterfaceDB::commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }

            return ['success' => false, 'errors' => ['The claim could not be posted: ' . $exception->getMessage()]];
        }

        return [
            'success' => true,
            'claim' => $this->fetchClaim($companyId, $claimId),
            'claims' => $this->listClaims($companyId),
        ];
    }

    public function fetchExpenseNominals(): array {
        $stmt = InterfaceDB::query(
            "SELECT id, code, name, account_type
             FROM nominal_accounts
             WHERE is_active = 1
               AND account_type IN ('expense', 'cost_of_sales')
             ORDER BY sort_order ASC, code ASC, id ASC"
        );

        return $stmt->fetchAll() ?: [];
    }

    public function recalculateClaim(int $claimId): void {
        $claimRow = $this->fetchClaimRow($claimId);
        if ($claimRow === null) {
            return;
        }

        $broughtForward = $this->previousCarryForward(
            (int)$claimRow['company_id'],
            (int)$claimRow['claimant_id'],
            $claimId,
            (string)$claimRow['period_start']
        );

        $claimed = $this->sumClaimLines($claimId);
        $payments = $this->sumPayments($claimId);
        $carriedForward = round($broughtForward + $claimed - $payments, 2);

        InterfaceDB::prepare(
            'UPDATE expense_claims
             SET brought_forward_amount = :brought_forward_amount,
                 claimed_amount = :claimed_amount,
                 payments_amount = :payments_amount,
                 carried_forward_amount = :carried_forward_amount,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        )->execute([
            'brought_forward_amount' => $broughtForward,
            'claimed_amount' => $claimed,
            'payments_amount' => $payments,
            'carried_forward_amount' => $carriedForward,
            'id' => $claimId,
        ]);
    }

    public function recalculateClaimSeries(int $companyId, int $claimantId): void {
        if ($companyId <= 0 || $claimantId <= 0) {
            return;
        }

        $claimIds = array_map('intval', InterfaceDB::fetchAll( 'SELECT id
             FROM expense_claims
             WHERE company_id = :company_id
               AND claimant_id = :claimant_id
             ORDER BY period_start ASC, id ASC', [
            'company_id' => $companyId,
            'claimant_id' => $claimantId,
        ]));
        $broughtForward = 0.0;

        foreach ($claimIds as $seriesClaimId) {
            $claimed = $this->sumClaimLines($seriesClaimId);
            $payments = $this->sumPayments($seriesClaimId);
            $carriedForward = round($broughtForward + $claimed - $payments, 2);

            InterfaceDB::prepare(
                'UPDATE expense_claims
                 SET brought_forward_amount = :brought_forward_amount,
                     claimed_amount = :claimed_amount,
                     payments_amount = :payments_amount,
                     carried_forward_amount = :carried_forward_amount,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            )->execute([
                'brought_forward_amount' => $broughtForward,
                'claimed_amount' => $claimed,
                'payments_amount' => $payments,
                'carried_forward_amount' => $carriedForward,
                'id' => $seriesClaimId,
            ]);

            $broughtForward = $carriedForward;
        }
    }

    private function fetchClaimLines(int $claimId): array {
        return array_map(
            static function (array $row): array {
                return [
                    'id' => (int)$row['id'],
                    'expense_claim_id' => (int)$row['expense_claim_id'],
                    'line_number' => (int)$row['line_number'],
                    'expense_date' => (string)$row['expense_date'],
                    'description' => (string)$row['description'],
                    'amount' => round((float)$row['amount'], 2),
                    'nominal_account_id' => isset($row['nominal_account_id']) ? (int)$row['nominal_account_id'] : null,
                    'receipt_reference' => (string)($row['receipt_reference'] ?? ''),
                    'notes' => (string)($row['notes'] ?? ''),
                    'nominal_label' => trim((string)($row['nominal_code'] ?? '')) !== ''
                        ? (string)$row['nominal_code'] . ' - ' . (string)($row['nominal_name'] ?? '')
                        : (string)($row['nominal_name'] ?? ''),
                ];
            },
            InterfaceDB::fetchAll( 'SELECT l.id,
                    l.expense_claim_id,
                    l.line_number,
                    l.expense_date,
                    l.description,
                    l.amount,
                    l.nominal_account_id,
                    l.receipt_reference,
                    l.notes,
                    l.created_at,
                    l.updated_at,
                    n.code AS nominal_code,
                    n.name AS nominal_name
             FROM expense_claim_lines l
             LEFT JOIN nominal_accounts n ON n.id = l.nominal_account_id
             WHERE l.expense_claim_id = :expense_claim_id
             ORDER BY l.line_number ASC, l.id ASC', ['expense_claim_id' => $claimId])
        );
    }

    private function fetchPaymentLinks(int $claimId): array {
        return array_map(
            static function (array $row): array {
                return [
                    'id' => (int)$row['id'],
                    'expense_claim_id' => (int)$row['expense_claim_id'],
                    'transaction_id' => (int)$row['transaction_id'],
                    'linked_amount' => round((float)$row['linked_amount'], 2),
                    'txn_date' => (string)$row['txn_date'],
                    'description' => (string)$row['description'],
                    'reference' => (string)($row['reference'] ?? ''),
                    'transaction_amount' => round(abs((float)$row['amount']), 2),
                ];
            },
            InterfaceDB::fetchAll( 'SELECT l.id,
                    l.expense_claim_id,
                    l.transaction_id,
                    l.linked_amount,
                    l.created_at,
                    t.txn_date,
                    t.description,
                    t.reference,
                    t.amount
             FROM expense_claim_payment_links l
             INNER JOIN transactions t ON t.id = l.transaction_id
             WHERE l.expense_claim_id = :expense_claim_id
             ORDER BY t.txn_date DESC, l.id DESC', ['expense_claim_id' => $claimId])
        );
    }

    private function fetchClaimantById(int $companyId, int $claimantId): ?array {
        $row = InterfaceDB::fetchOne( 'SELECT id, company_id, claimant_name, is_active, created_at, updated_at
             FROM expense_claimants
             WHERE company_id = :company_id
               AND id = :id
             LIMIT 1', [
            'company_id' => $companyId,
            'id' => $claimantId,
        ]);
        return is_array($row) ? $row : null;
    }

    private function findClaimantByName(int $companyId, string $claimantName): ?array {
        $row = InterfaceDB::fetchOne( 'SELECT id, company_id, claimant_name, is_active, created_at, updated_at
             FROM expense_claimants
             WHERE company_id = :company_id
               AND claimant_name = :claimant_name
             LIMIT 1', [
            'company_id' => $companyId,
            'claimant_name' => $claimantName,
        ]);
        return is_array($row) ? $row : null;
    }

    private function findClaimByUniqueMonth(int $companyId, int $claimantId, int $claimYear, int $claimMonth): ?array {
        $row = InterfaceDB::fetchOne( 'SELECT id
             FROM expense_claims
             WHERE company_id = :company_id
               AND claimant_id = :claimant_id
               AND claim_year = :claim_year
               AND claim_month = :claim_month
             LIMIT 1', [
            'company_id' => $companyId,
            'claimant_id' => $claimantId,
            'claim_year' => $claimYear,
            'claim_month' => $claimMonth,
        ]);
        return is_array($row) ? $row : null;
    }

    private function fetchClaimRow(int $claimId): ?array {
        $row = InterfaceDB::fetchOne( 'SELECT id, company_id, claimant_id, period_start
             FROM expense_claims
             WHERE id = :id
             LIMIT 1', ['id' => $claimId]);
        return is_array($row) ? $row : null;
    }

    private function previousCarryForward(int $companyId, int $claimantId, int $claimId, string $periodStart): float {
        return round((float)InterfaceDB::fetchColumn( 'SELECT carried_forward_amount
             FROM expense_claims
             WHERE company_id = :company_id
               AND claimant_id = :claimant_id
               AND id <> :id
               AND period_start < :period_start
             ORDER BY period_start DESC, id DESC
             LIMIT 1', [
            'company_id' => $companyId,
            'claimant_id' => $claimantId,
            'id' => $claimId,
            'period_start' => $periodStart,
        ]), 2);
    }

    private function sumClaimLines(int $claimId): float {
        return round((float)InterfaceDB::fetchColumn( 'SELECT COALESCE(SUM(amount), 0)
             FROM expense_claim_lines
             WHERE expense_claim_id = :expense_claim_id', ['expense_claim_id' => $claimId]), 2);
    }

    private function sumPayments(int $claimId): float {
        return round((float)InterfaceDB::fetchColumn( 'SELECT COALESCE(SUM(linked_amount), 0)
             FROM expense_claim_payment_links
             WHERE expense_claim_id = :expense_claim_id', ['expense_claim_id' => $claimId]), 2);
    }

    private function sumLinkedAmountForTransaction(int $transactionId, int $excludingClaimId = 0): float {
        $sql = 'SELECT COALESCE(SUM(linked_amount), 0)
                FROM expense_claim_payment_links
                WHERE transaction_id = :transaction_id';
        $params = ['transaction_id' => $transactionId];

        if ($excludingClaimId > 0) {
            $sql .= ' AND expense_claim_id <> :expense_claim_id';
            $params['expense_claim_id'] = $excludingClaimId;
        }

        $stmt = InterfaceDB::prepare($sql);
        $stmt->execute($params);

        return round((float)$stmt->fetchColumn(), 2);
    }

    private function findPaymentLink(int $claimId, int $transactionId): ?array {
        $stmt = InterfaceDB::prepare(
            'SELECT id, expense_claim_id, transaction_id, linked_amount
             FROM expense_claim_payment_links
             WHERE expense_claim_id = :expense_claim_id
               AND transaction_id = :transaction_id
             LIMIT 1'
        );
        $stmt->execute([
            'expense_claim_id' => $claimId,
            'transaction_id' => $transactionId,
        ]);

        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function nextLineNumber(int $claimId): int {
        $stmt = InterfaceDB::prepare(
            'SELECT COALESCE(MAX(line_number), 0)
             FROM expense_claim_lines
             WHERE expense_claim_id = :expense_claim_id'
        );
        $stmt->execute(['expense_claim_id' => $claimId]);

        return ((int)$stmt->fetchColumn()) + 1;
    }

    private function resolveTaxYearIdForDate(int $companyId, string $date): int {
        $stmt = InterfaceDB::prepare(
            'SELECT id
             FROM tax_years
             WHERE company_id = :company_id
               AND period_start <= :date
               AND period_end >= :date
             ORDER BY period_start DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'date' => $date,
        ]);

        return (int)($stmt->fetchColumn() ?: 0);
    }

    private function renumberLines(int $claimId): void {
        $stmt = InterfaceDB::prepare(
            'SELECT id
             FROM expense_claim_lines
             WHERE expense_claim_id = :expense_claim_id
             ORDER BY line_number ASC, id ASC'
        );
        $stmt->execute(['expense_claim_id' => $claimId]);

        $lineNumber = 1;
        foreach ($stmt->fetchAll() ?: [] as $row) {
            InterfaceDB::prepare(
                'UPDATE expense_claim_lines
                 SET line_number = :line_number,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            )->execute([
                'line_number' => $lineNumber,
                'id' => (int)$row['id'],
            ]);
            $lineNumber++;
        }
    }

    private function validateLinePayload(array $payload): array {
        $errors = [];
        $expenseDate = trim((string)($payload['expense_date'] ?? ''));
        $description = trim((string)($payload['description'] ?? ''));
        $amount = isset($payload['amount']) ? (float)$payload['amount'] : 0.0;

        if ($expenseDate === '' || !$this->isValidDate($expenseDate)) {
            $errors[] = 'Expense date is required.';
        }

        if ($description === '') {
            $errors[] = 'Description is required.';
        }

        if ($amount <= 0) {
            $errors[] = 'Amount must be greater than zero.';
        }

        return $errors;
    }

    private function validateLineForPosting(array $line): array {
        $errors = $this->validateLinePayload([
            'expense_date' => (string)($line['expense_date'] ?? ''),
            'description' => (string)($line['description'] ?? ''),
            'amount' => (float)($line['amount'] ?? 0),
        ]);

        if ((int)($line['nominal_account_id'] ?? 0) <= 0) {
            $errors[] = 'Every expense line needs a nominal account before posting.';
        }

        return $errors;
    }

    private function deriveMonthlyPeriod(int $year, int $month): array {
        $periodStart = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $periodEnd = $periodStart->modify('last day of this month');

        return [
            'period_start' => $periodStart->format('Y-m-d'),
            'period_end' => $periodEnd->format('Y-m-d'),
        ];
    }

    private function deriveTaxYearId(int $companyId, string $periodStart, string $periodEnd): int {
        $stmt = InterfaceDB::prepare(
            'SELECT id, period_start, period_end
             FROM tax_years
             WHERE company_id = :company_id
               AND period_end >= :period_start
               AND period_start <= :period_end
             ORDER BY period_start ASC, period_end ASC'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);

        $bestId = 0;
        $bestOverlapDays = -1;
        $targetStart = new DateTimeImmutable($periodStart);
        $targetEnd = new DateTimeImmutable($periodEnd);

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $rowStart = new DateTimeImmutable((string)$row['period_start']);
            $rowEnd = new DateTimeImmutable((string)$row['period_end']);
            $overlapStart = $rowStart > $targetStart ? $rowStart : $targetStart;
            $overlapEnd = $rowEnd < $targetEnd ? $rowEnd : $targetEnd;

            if ($overlapEnd < $overlapStart) {
                continue;
            }

            $days = (int)$overlapStart->diff($overlapEnd)->format('%a');
            if ($days > $bestOverlapDays) {
                $bestOverlapDays = $days;
                $bestId = (int)$row['id'];
            }
        }

        return $bestId;
    }

    private function generateUniqueReferenceCode(int $companyId, int $claimYear, int $claimMonth): string {
        $prefix = 'EXP-' . substr((string)$claimYear, -2) . sprintf('%02d', $claimMonth);

        for ($attempt = 0; $attempt < 25; $attempt++) {
            $candidate = $prefix . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
            if (!$this->referenceCodeExists($companyId, $candidate)) {
                return $candidate;
            }
        }

        return $prefix . '-' . strtoupper(substr(hash('sha256', uniqid((string)$companyId, true)), 0, 4));
    }

    private function referenceCodeExists(int $companyId, string $referenceCode): bool {
        return InterfaceDB::countWhere('expense_claims', [
            'company_id' => $companyId,
            'claim_reference_code' => $referenceCode,
        ]) > 0;
    }

    private function fetchExistingExpenseJournal(int $companyId, string $sourceRef): ?array {
        $stmt = InterfaceDB::prepare(
            'SELECT id, company_id, source_ref
             FROM journals
             WHERE company_id = :company_id
               AND source_type = :source_type
               AND source_ref = :source_ref
             LIMIT 1'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'source_type' => 'expense_register',
            'source_ref' => $sourceRef,
        ]);

        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function insertJournalLine(int $journalId, int $nominalAccountId, float $debit, float $credit, string $description): void {
        InterfaceDB::prepare(
            'INSERT INTO journal_lines (
                journal_id,
                nominal_account_id,
                debit,
                credit,
                line_description
             ) VALUES (
                :journal_id,
                :nominal_account_id,
                :debit,
                :credit,
                :line_description
             )'
        )->execute([
            'journal_id' => $journalId,
            'nominal_account_id' => $nominalAccountId,
            'debit' => round($debit, 2),
            'credit' => round($credit, 2),
            'line_description' => trim($description) !== '' ? $description : null,
        ]);
    }

    private function normaliseClaimPeriod(string $claimPeriod): ?array {
        $claimPeriod = trim($claimPeriod);
        if (!preg_match('/^\d{4}\-\d{2}$/', $claimPeriod)) {
            return null;
        }

        [$year, $month] = array_map('intval', explode('-', $claimPeriod, 2));
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            return null;
        }

        return ['year' => $year, 'month' => $month];
    }

    private function normaliseClaimPeriodFromPayload(array $payload): ?array {
        $claimYear = isset($payload['claim_year']) ? (int)$payload['claim_year'] : 0;
        $claimMonth = isset($payload['claim_month']) ? (int)$payload['claim_month'] : 0;

        if ($claimYear > 0 || $claimMonth > 0) {
            if ($claimYear < 2000 || $claimYear > 2100 || $claimMonth < 1 || $claimMonth > 12) {
                return null;
            }

            return ['year' => $claimYear, 'month' => $claimMonth];
        }

        return $this->normaliseClaimPeriod((string)($payload['claim_period'] ?? ''));
    }

    private function periodIsOnOrAfterIncorporation(int $claimYear, int $claimMonth, string $incorporationDate): bool {
        if (!$this->isValidDate($incorporationDate)) {
            return true;
        }

        $incorporatedAt = new DateTimeImmutable($incorporationDate);
        $incorporationYear = (int)$incorporatedAt->format('Y');
        $incorporationMonth = (int)$incorporatedAt->format('m');

        if ($claimYear > $incorporationYear) {
            return true;
        }

        if ($claimYear < $incorporationYear) {
            return false;
        }

        return $claimMonth >= $incorporationMonth;
    }

    private function normaliseStatusFilter(string $status): string {
        $status = strtolower(trim($status));
        return in_array($status, ['all', 'draft', 'posted'], true) ? $status : 'all';
    }

    private function formatClaimSummary(array $claim): array {
        return [
            'id' => (int)$claim['id'],
            'claimant_name' => (string)$claim['claimant_name'],
            'claim_year' => (int)$claim['claim_year'],
            'claim_month' => (int)$claim['claim_month'],
            'claim_period' => sprintf('%04d-%02d', (int)$claim['claim_year'], (int)$claim['claim_month']),
            'claim_reference_code' => (string)$claim['claim_reference_code'],
            'A' => round((float)$claim['brought_forward_amount'], 2),
            'B' => round((float)$claim['claimed_amount'], 2),
            'C' => round((float)$claim['payments_amount'], 2),
            'D' => round((float)$claim['carried_forward_amount'], 2),
            'status' => (string)$claim['status'],
            'last_updated' => (string)$claim['updated_at'],
        ];
    }

    private function isValidDate(string $value): bool {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
    }
}


