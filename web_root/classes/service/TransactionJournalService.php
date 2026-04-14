<?php
declare(strict_types=1);

final class TransactionJournalService
{
    public function postCategorisedTransactions(
        int $companyId,
        int $taxYearId,
        int $bankNominalId,
        ?string $monthKey = null,
        string $changedBy = 'system'
    ): array {
        if ($companyId <= 0 || $taxYearId <= 0) {
            return [
                'success' => false,
                'errors' => ['Select a company and accounting period before posting transactions.'],
            ];
        }

        if ($bankNominalId <= 0) {
            return [
                'success' => false,
                'errors' => ['Set the default bank nominal before posting categorised transactions.'],
            ];
        }

        $transactionIds = $this->fetchPostableTransactionIds($companyId, $taxYearId, $monthKey);
        (new YearEndLockService())->assertUnlocked($companyId, $taxYearId, 'post categorised transactions');
        $summary = [
            'success' => true,
            'errors' => [],
            'created' => 0,
            'rebuilt' => 0,
            'unchanged' => 0,
            'removed' => 0,
            'processed' => 0,
        ];

        foreach ($transactionIds as $transactionId) {
            $result = $this->syncJournalForTransaction($transactionId, $bankNominalId, $changedBy, true);

            if (!empty($result['errors'])) {
                foreach ($result['errors'] as $error) {
                    $summary['errors'][] = (string)$error;
                }

                continue;
            }

            $summary['processed']++;

            if (!empty($result['created'])) {
                $summary['created']++;
            } elseif (!empty($result['rebuilt'])) {
                $summary['rebuilt']++;
            } elseif (!empty($result['removed'])) {
                $summary['removed']++;
            } else {
                $summary['unchanged']++;
            }
        }

        $summary['success'] = $summary['errors'] === [];

        return $summary;
    }

    public function syncJournalForTransaction(
        int $transactionId,
        int $bankNominalId,
        string $changedBy = 'system',
        bool $allowRebuild = false
    ): array {
        if ($transactionId <= 0) {
            return [
                'success' => false,
                'errors' => ['A valid transaction is required before a journal can be posted.'],
            ];
        }

        $transaction = $this->fetchTransactionForPosting($transactionId);

        if ($transaction === null) {
            return [
                'success' => false,
                'errors' => ['The selected transaction could not be found.'],
            ];
        }

        (new YearEndLockService())->assertUnlocked(
            (int)($transaction['company_id'] ?? 0),
            (int)($transaction['tax_year_id'] ?? 0),
            'post journals in this period'
        );

        $sourceRef = $this->sourceRefForTransaction((int)$transaction['id']);
        $existingJournal = $this->fetchJournalBySourceRef((int)$transaction['company_id'], $sourceRef);
        $desiredJournal = $this->buildDesiredJournal($transaction, $bankNominalId);

        if ($desiredJournal !== null && $bankNominalId <= 0) {
            return [
                'success' => false,
                'errors' => ['Set the default bank nominal before posting categorised transactions.'],
            ];
        }

        if ($desiredJournal === null) {
            if ($existingJournal === null) {
                return [
                    'success' => true,
                    'created' => false,
                    'rebuilt' => false,
                    'removed' => false,
                ];
            }

            if (!$allowRebuild) {
                return [
                    'success' => true,
                    'created' => false,
                    'rebuilt' => false,
                    'removed' => false,
                    'requires_confirmation' => true,
                ];
            }

            $this->deleteJournal((int)$existingJournal['id']);

            return [
                'success' => true,
                'created' => false,
                'rebuilt' => false,
                'removed' => true,
            ];
        }

        if ($existingJournal !== null && $this->journalMatches($existingJournal, $desiredJournal)) {
            return [
                'success' => true,
                'created' => false,
                'rebuilt' => false,
                'removed' => false,
            ];
        }

        if ($existingJournal !== null && !$allowRebuild) {
            return [
                'success' => true,
                'created' => false,
                'rebuilt' => false,
                'removed' => false,
                'requires_confirmation' => true,
            ];
        }

        $ownsTransaction = !InterfaceDB::inTransaction();

        if ($ownsTransaction) {
            InterfaceDB::beginTransaction();
        }

        try {
            if ($existingJournal !== null) {
                $this->deleteJournal((int)$existingJournal['id']);
            }

            $journalId = $this->insertJournal($desiredJournal, $changedBy);

            foreach ($desiredJournal['lines'] as $line) {
                $this->insertJournalLine($journalId, $line);
            }

            if ($ownsTransaction) {
                InterfaceDB::commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }

            return [
                'success' => false,
                'errors' => ['The derived journal could not be written: ' . $exception->getMessage()],
            ];
        }

        return [
            'success' => true,
            'created' => $existingJournal === null,
            'rebuilt' => $existingJournal !== null,
            'removed' => false,
            'journal_id' => $journalId,
        ];
    }

    public function transactionHasDerivedJournal(int $transactionId): bool {
        if ($transactionId <= 0) {
            return false;
        }

        $stmt = InterfaceDB::prepare(
            'SELECT COUNT(*)
             FROM journals
             WHERE source_type = :source_type
               AND source_ref = :source_ref'
        );
        $stmt->execute([
            'source_type' => 'bank_csv',
            'source_ref' => $this->sourceRefForTransaction($transactionId),
        ]);

        return (int)$stmt->fetchColumn() > 0;
    }

    public function fetchJournals(int $companyId, int $taxYearId, int $limit = 200): array {
        if ($companyId <= 0 || $taxYearId <= 0) {
            return [];
        }

        $limit = max(1, min($limit, 500));
        $stmt = InterfaceDB::prepare(
            "SELECT j.id,
                    j.company_id,
                    j.tax_year_id,
                    j.source_type,
                    COALESCE(j.source_ref, '') AS source_ref,
                    j.journal_date,
                    j.description,
                    j.is_posted,
                    COUNT(jl.id) AS line_count,
                    COALESCE(SUM(jl.debit), 0.00) AS total_debit
             FROM journals j
             LEFT JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE j.company_id = :company_id
               AND j.tax_year_id = :tax_year_id
             GROUP BY j.id, j.company_id, j.tax_year_id, j.source_type, j.source_ref, j.journal_date, j.description, j.is_posted
             ORDER BY j.journal_date DESC, j.id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
        ]);

        $journals = $stmt->fetchAll();

        foreach ($journals as &$journal) {
            $journal['lines'] = $this->fetchJournalLines((int)$journal['id']);
        }
        unset($journal);

        return $journals;
    }

    private function fetchTransactionForPosting(int $transactionId): ?array {
        $stmt = InterfaceDB::prepare(
            'SELECT t.id,
                    t.company_id,
                    t.tax_year_id,
                    t.account_id,
                    t.txn_date,
                    t.txn_type,
                    t.description,
                    t.amount,
                    t.nominal_account_id,
                    t.transfer_account_id,
                    t.is_internal_transfer,
                    t.category_status,
                    COALESCE(ca.internal_transfer_marker, \'\') AS internal_transfer_marker,
                    COALESCE(ca.account_name, \'\') AS source_account_name,
                    COALESCE(ta.account_name, \'\') AS transfer_account_name
             FROM transactions t
             LEFT JOIN company_accounts ca ON ca.id = t.account_id
             LEFT JOIN company_accounts ta ON ta.id = t.transfer_account_id
             WHERE t.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $transactionId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function fetchPostableTransactionIds(int $companyId, int $taxYearId, ?string $monthKey): array {
        $where = [
            't.company_id = :company_id',
            't.tax_year_id = :tax_year_id',
            '(
                (t.nominal_account_id IS NOT NULL AND t.category_status IN (\'auto\', \'manual\'))
                OR (t.transfer_account_id IS NOT NULL AND t.is_internal_transfer = 1 AND t.category_status = \'manual\')
            )',
        ];
        $params = [
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
        ];

        $monthKey = trim((string)$monthKey);

        if (preg_match('/^\d{4}-\d{2}-01$/', $monthKey) === 1) {
            $monthStart = new DateTimeImmutable($monthKey);
            $monthEnd = $monthStart->modify('last day of this month');
            $where[] = 't.txn_date BETWEEN :month_start AND :month_end';
            $params['month_start'] = $monthStart->format('Y-m-d');
            $params['month_end'] = $monthEnd->format('Y-m-d');
        }

        $stmt = InterfaceDB::prepare(
            'SELECT t.id
             FROM transactions t
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY t.txn_date ASC, t.id ASC'
        );
        $stmt->execute($params);

        return array_map(
            static fn(array $row): int => (int)$row['id'],
            $stmt->fetchAll()
        );
    }

    private function buildDesiredJournal(array $transaction, int $bankNominalId): ?array {
        if ($this->isTransferTransaction($transaction)) {
            return $this->buildTransferJournal($transaction, $bankNominalId);
        }

        $nominalAccountId = (int)($transaction['nominal_account_id'] ?? 0);
        $categoryStatus = trim((string)($transaction['category_status'] ?? ''));

        if ($nominalAccountId <= 0 || !in_array($categoryStatus, ['auto', 'manual'], true)) {
            return null;
        }

        $amount = round(abs((float)($transaction['amount'] ?? 0)), 2);

        if ($amount <= 0.0) {
            return null;
        }

        $description = trim((string)($transaction['description'] ?? ''));
        $journalDate = trim((string)($transaction['txn_date'] ?? ''));

        $lines = (float)$transaction['amount'] < 0
            ? [
                [
                    'nominal_account_id' => $nominalAccountId,
                    'debit' => number_format($amount, 2, '.', ''),
                    'credit' => '0.00',
                    'line_description' => $description,
                ],
                [
                    'nominal_account_id' => $bankNominalId,
                    'debit' => '0.00',
                    'credit' => number_format($amount, 2, '.', ''),
                    'line_description' => $description,
                ],
            ]
            : [
                [
                    'nominal_account_id' => $bankNominalId,
                    'debit' => number_format($amount, 2, '.', ''),
                    'credit' => '0.00',
                    'line_description' => $description,
                ],
                [
                    'nominal_account_id' => $nominalAccountId,
                    'debit' => '0.00',
                    'credit' => number_format($amount, 2, '.', ''),
                    'line_description' => $description,
                ],
            ];

        return [
            'company_id' => (int)$transaction['company_id'],
            'tax_year_id' => (int)$transaction['tax_year_id'],
            'source_type' => 'bank_csv',
            'source_ref' => $this->sourceRefForTransaction((int)$transaction['id']),
            'journal_date' => $journalDate,
            'description' => $description !== '' ? $description : 'Imported transaction',
            'is_posted' => 1,
            'lines' => $lines,
        ];
    }

    private function fetchJournalBySourceRef(int $companyId, string $sourceRef): ?array {
        $stmt = InterfaceDB::prepare(
            'SELECT id,
                    company_id,
                    tax_year_id,
                    source_type,
                    source_ref,
                    journal_date,
                    description,
                    is_posted
             FROM journals
             WHERE company_id = :company_id
               AND source_type = :source_type
               AND source_ref = :source_ref
             LIMIT 1'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'source_type' => 'bank_csv',
            'source_ref' => $sourceRef,
        ]);
        $journal = $stmt->fetch();

        if (!is_array($journal)) {
            return null;
        }

        $journal['lines'] = $this->fetchJournalLines((int)$journal['id']);

        return $journal;
    }

    private function fetchJournalLines(int $journalId): array {
        $stmt = InterfaceDB::prepare(
            'SELECT jl.id,
                    jl.nominal_account_id,
                    jl.company_account_id,
                    jl.debit,
                    jl.credit,
                    COALESCE(jl.line_description, \'\') AS line_description,
                    COALESCE(na.code, \'\') AS nominal_code,
                    COALESCE(na.name, \'\') AS nominal_name,
                    COALESCE(ca.account_name, \'\') AS company_account_name
             FROM journal_lines jl
             LEFT JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             LEFT JOIN company_accounts ca ON ca.id = jl.company_account_id
             WHERE jl.journal_id = :journal_id
             ORDER BY jl.id ASC'
        );
        $stmt->execute(['journal_id' => $journalId]);

        return $stmt->fetchAll();
    }

    private function journalMatches(array $existingJournal, array $desiredJournal): bool {
        if (trim((string)$existingJournal['journal_date']) !== trim((string)$desiredJournal['journal_date'])) {
            return false;
        }

        if (trim((string)$existingJournal['description']) !== trim((string)$desiredJournal['description'])) {
            return false;
        }

        $existingLines = array_map([$this, 'normaliseLineForComparison'], $existingJournal['lines'] ?? []);
        $desiredLines = array_map([$this, 'normaliseLineForComparison'], $desiredJournal['lines'] ?? []);

        usort($existingLines, [$this, 'sortComparableLines']);
        usort($desiredLines, [$this, 'sortComparableLines']);

        return $existingLines === $desiredLines;
    }

    private function normaliseLineForComparison(array $line): array {
        return [
            'nominal_account_id' => (int)($line['nominal_account_id'] ?? 0),
            'company_account_id' => (int)($line['company_account_id'] ?? 0),
            'debit' => number_format((float)($line['debit'] ?? 0), 2, '.', ''),
            'credit' => number_format((float)($line['credit'] ?? 0), 2, '.', ''),
            'line_description' => trim((string)($line['line_description'] ?? '')),
        ];
    }

    private function sortComparableLines(array $left, array $right): int {
        return [$left['nominal_account_id'], $left['company_account_id'], $left['debit'], $left['credit'], $left['line_description']]
            <=> [$right['nominal_account_id'], $right['company_account_id'], $right['debit'], $right['credit'], $right['line_description']];
    }

    private function insertJournal(array $journal, string $changedBy): int {
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $stmt = InterfaceDB::prepare(
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
                :is_posted,
                :created_at,
                :updated_at
            )'
        );
        $stmt->execute([
            'company_id' => $journal['company_id'],
            'tax_year_id' => $journal['tax_year_id'],
            'source_type' => $journal['source_type'],
            'source_ref' => $journal['source_ref'],
            'journal_date' => $journal['journal_date'],
            'description' => $journal['description'],
            'is_posted' => $journal['is_posted'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $reloadedId = $this->findJournalId(
            (int)$journal['company_id'],
            (string)$journal['source_type'],
            (string)$journal['source_ref']
        );

        if ($reloadedId === null) {
            throw new RuntimeException('The derived journal could not be reloaded after insert.');
        }

        return $reloadedId;
    }

    private function insertJournalLine(int $journalId, array $line): void {
        $stmt = InterfaceDB::prepare(
            'INSERT INTO journal_lines (
                journal_id,
                nominal_account_id,
                company_account_id,
                debit,
                credit,
                line_description
            ) VALUES (
                :journal_id,
                :nominal_account_id,
                :company_account_id,
                :debit,
                :credit,
                :line_description
            )'
        );
        $stmt->execute([
            'journal_id' => $journalId,
            'nominal_account_id' => (int)$line['nominal_account_id'],
            'company_account_id' => isset($line['company_account_id']) && (int)$line['company_account_id'] > 0
                ? (int)$line['company_account_id']
                : null,
            'debit' => $line['debit'],
            'credit' => $line['credit'],
            'line_description' => trim((string)($line['line_description'] ?? '')) !== ''
                ? trim((string)$line['line_description'])
                : null,
        ]);
    }

    private function buildTransferJournal(array $transaction, int $bankNominalId): ?array {
        $sourceAccountId = (int)($transaction['account_id'] ?? 0);
        $transferAccountId = (int)($transaction['transfer_account_id'] ?? 0);
        $categoryStatus = trim((string)($transaction['category_status'] ?? ''));
        $amount = round(abs((float)($transaction['amount'] ?? 0)), 2);

        if ($sourceAccountId <= 0 || $transferAccountId <= 0 || $categoryStatus !== 'manual' || $amount <= 0.0) {
            return null;
        }

        $description = trim((string)($transaction['description'] ?? ''));
        $journalDate = trim((string)($transaction['txn_date'] ?? ''));
        $sourceLineDescription = trim((string)($transaction['source_account_name'] ?? '')) !== ''
            ? trim((string)$transaction['source_account_name'])
            : $description;
        $transferLineDescription = trim((string)($transaction['transfer_account_name'] ?? '')) !== ''
            ? trim((string)$transaction['transfer_account_name'])
            : $description;

        $lines = (float)$transaction['amount'] < 0
            ? [
                [
                    'nominal_account_id' => $bankNominalId,
                    'company_account_id' => $transferAccountId,
                    'debit' => number_format($amount, 2, '.', ''),
                    'credit' => '0.00',
                    'line_description' => $transferLineDescription,
                ],
                [
                    'nominal_account_id' => $bankNominalId,
                    'company_account_id' => $sourceAccountId,
                    'debit' => '0.00',
                    'credit' => number_format($amount, 2, '.', ''),
                    'line_description' => $sourceLineDescription,
                ],
            ]
            : [
                [
                    'nominal_account_id' => $bankNominalId,
                    'company_account_id' => $sourceAccountId,
                    'debit' => number_format($amount, 2, '.', ''),
                    'credit' => '0.00',
                    'line_description' => $sourceLineDescription,
                ],
                [
                    'nominal_account_id' => $bankNominalId,
                    'company_account_id' => $transferAccountId,
                    'debit' => '0.00',
                    'credit' => number_format($amount, 2, '.', ''),
                    'line_description' => $transferLineDescription,
                ],
            ];

        return [
            'company_id' => (int)$transaction['company_id'],
            'tax_year_id' => (int)$transaction['tax_year_id'],
            'source_type' => 'bank_csv',
            'source_ref' => $this->sourceRefForTransaction((int)$transaction['id']),
            'journal_date' => $journalDate,
            'description' => $description !== '' ? $description : 'Internal transfer',
            'is_posted' => 1,
            'lines' => $lines,
        ];
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

    private function findJournalId(int $companyId, string $sourceType, string $sourceRef): ?int {
        $stmt = InterfaceDB::prepare(
            'SELECT id
             FROM journals
             WHERE company_id = :company_id
               AND source_type = :source_type
               AND source_ref = :source_ref
             LIMIT 1'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'source_type' => $sourceType,
            'source_ref' => $sourceRef,
        ]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int)$id : null;
    }

    private function deleteJournal(int $journalId): void {
        $stmt = InterfaceDB::prepare(
            'DELETE FROM journals
             WHERE id = :id'
        );
        $stmt->execute(['id' => $journalId]);
    }

    private function sourceRefForTransaction(int $transactionId): string {
        return 'transaction:' . $transactionId;
    }
}
