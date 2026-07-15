<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class PrepaymentSourceService
{
    public const SOURCE_TRANSACTION = 'transaction';
    public const SOURCE_TRANSACTION_SPLIT_LINE = 'transaction_split_line';
    public const SOURCE_EXPENSE_CLAIM_LINE = 'expense_claim_line';

    /** @var array<string, bool> */
    private array $tableExistence = [];

    /** @return list<array<string, mixed>> */
    public function listCandidates(int $companyId, int $accountingPeriodId): array
    {
        return $this->fetchCandidateContext($companyId, $accountingPeriodId)['eligible'];
    }

    /** @return array{eligible: list<array<string, mixed>>, excluded: list<array<string, mixed>>} */
    public function fetchCandidateContext(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return ['eligible' => [], 'excluded' => []];
        }

        $rows = array_merge(
            $this->transactionCandidates($companyId, $accountingPeriodId),
            $this->splitLineCandidates($companyId, $accountingPeriodId),
            $this->expenseClaimLineCandidates($companyId, $accountingPeriodId)
        );

        return $this->verifyCandidateRows($companyId, $accountingPeriodId, $rows);
    }

    /** @return array{success: bool, source?: array<string, mixed>, errors: list<string>} */
    public function verify(int $companyId, int $accountingPeriodId, string $sourceType, int $sourceId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || $sourceId <= 0) {
            return ['success' => false, 'errors' => ['Select a valid prepayment source item.']];
        }
        if (!in_array($sourceType, self::sourceTypes(), true)) {
            return ['success' => false, 'errors' => ['The prepayment source type is not supported.']];
        }

        $source = match ($sourceType) {
            self::SOURCE_TRANSACTION => $this->directTransaction($companyId, $accountingPeriodId, $sourceId),
            self::SOURCE_TRANSACTION_SPLIT_LINE => $this->splitLine($companyId, $accountingPeriodId, $sourceId),
            self::SOURCE_EXPENSE_CLAIM_LINE => $this->expenseClaimLine($companyId, $accountingPeriodId, $sourceId),
        };

        if (!is_array($source)) {
            return ['success' => false, 'errors' => ['The selected source is not an eligible prepayment candidate in this accounting period.']];
        }

        $journalResolution = $this->sourceJournal($sourceType, $source);
        if (empty($journalResolution['success']) || !is_array($journalResolution['journal'] ?? null)) {
            return [
                'success' => false,
                'errors' => (array)($journalResolution['errors'] ?? ['The source must have a posted journal before it can be treated as a prepayment.']),
            ];
        }
        $journal = (array)$journalResolution['journal'];
        if (empty($journal['is_posted'])) {
            return ['success' => false, 'errors' => ['The source journal is not posted.']];
        }

        $journalLines = \InterfaceDB::fetchAll(
            'SELECT id, journal_id, nominal_account_id, debit, credit, COALESCE(line_description, \'\') AS line_description
             FROM journal_lines
             WHERE journal_id = :journal_id
               AND nominal_account_id = :nominal_account_id
               AND debit = :amount
               AND debit > 0
               AND credit = 0
             ORDER BY id ASC',
            [
                'journal_id' => (int)$journal['id'],
                'nominal_account_id' => (int)$source['nominal_account_id'],
                'amount' => number_format(((int)$source['amount_pence']) / 100, 2, '.', ''),
            ]
        );
        if (count($journalLines) > 1) {
            $description = trim((string)($source['description'] ?? ''));
            $descriptionMatches = $description !== '' ? array_values(array_filter(
                $journalLines,
                static fn(array $line): bool => trim((string)($line['line_description'] ?? '')) === $description
            )) : [];
            if (count($descriptionMatches) === 1) {
                $journalLines = $descriptionMatches;
            }
        }
        if (count($journalLines) !== 1) {
            $message = $journalLines === []
                ? 'The posted source journal does not contain the expected positive debit to the candidate expense nominal.'
                : 'The posted source journal contains ambiguous matching debit lines; make the source line descriptions unique before treating it as a prepayment.';
            return ['success' => false, 'errors' => [$message]];
        }
        $journalLine = $journalLines[0];
        if (!is_array($journalLine)) {
            return ['success' => false, 'errors' => ['The posted source journal does not contain the expected positive debit to the candidate expense nominal.']];
        }

        $source['source_journal_id'] = (int)$journal['id'];
        $source['source_journal_line_id'] = (int)$journalLine['id'];
        $source['source_journal_date'] = (string)$journal['journal_date'];
        $source['source_valid'] = true;
        $source['source_errors'] = [];

        return ['success' => true, 'source' => $source, 'errors' => []];
    }

    /** @return list<string> */
    public static function sourceTypes(): array
    {
        return [self::SOURCE_TRANSACTION, self::SOURCE_TRANSACTION_SPLIT_LINE, self::SOURCE_EXPENSE_CLAIM_LINE];
    }

    /**
     * Lock the source identity and its production journal evidence before a
     * close/posting integrity decision. SQLite relies on the surrounding
     * transaction; MySQL receives explicit row locks.
     */
    public function lockEvidence(int $companyId, int $accountingPeriodId, string $sourceType, int $sourceId): void
    {
        if (!\InterfaceDB::inTransaction()) {
            throw new \RuntimeException('Prepayment source evidence can only be locked inside a transaction.');
        }
        if (!in_array($sourceType, self::sourceTypes(), true) || $sourceId <= 0) {
            throw new \RuntimeException('The prepayment source identity is invalid.');
        }

        $suffix = \InterfaceDB::driverName() === 'sqlite' ? '' : ' FOR UPDATE';
        if ($sourceType === self::SOURCE_TRANSACTION) {
            $this->requireLockedRow(
                'SELECT id FROM transactions WHERE id = :id AND company_id = :company_id AND accounting_period_id = :accounting_period_id' . $suffix,
                ['id' => $sourceId, 'company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
            );
        } elseif ($sourceType === self::SOURCE_TRANSACTION_SPLIT_LINE) {
            $this->requireLockedRow(
                'SELECT tsl.id
                 FROM transaction_split_lines tsl
                 INNER JOIN transaction_splits ts ON ts.id = tsl.split_id
                 INNER JOIN transactions t ON t.id = ts.transaction_id
                 WHERE tsl.id = :id AND t.company_id = :company_id AND t.accounting_period_id = :accounting_period_id' . $suffix,
                ['id' => $sourceId, 'company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
            );
        } else {
            $this->requireLockedRow(
                'SELECT ecl.id
                 FROM expense_claim_lines ecl
                 INNER JOIN expense_claims ec ON ec.id = ecl.expense_claim_id
                 WHERE ecl.id = :id AND ec.company_id = :company_id AND ec.accounting_period_id = :accounting_period_id' . $suffix,
                ['id' => $sourceId, 'company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
            );
        }

        $source = match ($sourceType) {
            self::SOURCE_TRANSACTION => $this->directTransaction($companyId, $accountingPeriodId, $sourceId),
            self::SOURCE_TRANSACTION_SPLIT_LINE => $this->splitLine($companyId, $accountingPeriodId, $sourceId),
            self::SOURCE_EXPENSE_CLAIM_LINE => $this->expenseClaimLine($companyId, $accountingPeriodId, $sourceId),
        };
        if (!is_array($source)) {
            throw new \RuntimeException('The prepayment source disappeared while its evidence was being locked.');
        }
        $resolution = $this->sourceJournal($sourceType, $source);
        if (empty($resolution['success']) || !is_array($resolution['journal'] ?? null)) {
            throw new \RuntimeException((string)(($resolution['errors'] ?? [])[0] ?? 'The production source journal could not be locked.'));
        }
        $journalId = (int)$resolution['journal']['id'];
        $this->requireLockedRow('SELECT id FROM journals WHERE id = :id' . $suffix, ['id' => $journalId]);
        \InterfaceDB::fetchAll('SELECT id FROM journal_lines WHERE journal_id = :journal_id' . $suffix, ['journal_id' => $journalId]);
    }

    private function directTransaction(int $companyId, int $accountingPeriodId, int $sourceId): ?array
    {
        if (!$this->tableExists('transactions')) {
            return null;
        }

        $splitExclusion = $this->tableExists('transaction_splits')
            ? 'AND NOT EXISTS (SELECT 1 FROM transaction_splits ts WHERE ts.transaction_id = t.id)'
            : '';
        $row = \InterfaceDB::fetchOne(
            'SELECT t.id AS source_id,
                    t.company_id,
                    t.accounting_period_id,
                    t.txn_date AS source_date,
                    COALESCE(t.description, t.counterparty_name, \'\') AS description,
                    ABS(t.amount) AS amount,
                    na.id AS nominal_account_id,
                    na.code AS nominal_code,
                    na.name AS nominal_name,
                    na.account_type AS nominal_account_type
             FROM transactions t
             INNER JOIN nominal_accounts na ON na.id = t.nominal_account_id
             WHERE t.company_id = :company_id
               AND t.accounting_period_id = :accounting_period_id
               AND t.id = :source_id
               AND COALESCE(na.prepayment_candidate, 0) = 1
               AND na.account_type IN (\'expense\', \'cost_of_sales\')
               ' . $splitExclusion . '
             LIMIT 1',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId, 'source_id' => $sourceId]
        );

        return is_array($row) ? $this->normaliseSource($row, self::SOURCE_TRANSACTION) : null;
    }

    private function splitLine(int $companyId, int $accountingPeriodId, int $sourceId): ?array
    {
        foreach (['transactions', 'transaction_splits', 'transaction_split_lines'] as $table) {
            if (!$this->tableExists($table)) {
                return null;
            }
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT tsl.id AS source_id,
                    t.company_id,
                    t.accounting_period_id,
                    t.id AS parent_transaction_id,
                    t.txn_date AS source_date,
                    COALESCE(tsl.description, t.description, \'\') AS description,
                    tsl.amount,
                    na.id AS nominal_account_id,
                    na.code AS nominal_code,
                    na.name AS nominal_name,
                    na.account_type AS nominal_account_type
             FROM transaction_split_lines tsl
             INNER JOIN transaction_splits ts ON ts.id = tsl.split_id
             INNER JOIN transactions t ON t.id = ts.transaction_id
             INNER JOIN nominal_accounts na ON na.id = tsl.nominal_account_id
             WHERE t.company_id = :company_id
               AND t.accounting_period_id = :accounting_period_id
               AND tsl.id = :source_id
               AND tsl.amount > 0
               AND COALESCE(na.prepayment_candidate, 0) = 1
               AND na.account_type IN (\'expense\', \'cost_of_sales\')
             LIMIT 1',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId, 'source_id' => $sourceId]
        );

        return is_array($row) ? $this->normaliseSource($row, self::SOURCE_TRANSACTION_SPLIT_LINE) : null;
    }

    private function expenseClaimLine(int $companyId, int $accountingPeriodId, int $sourceId): ?array
    {
        foreach (['expense_claims', 'expense_claim_lines'] as $table) {
            if (!$this->tableExists($table)) {
                return null;
            }
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT ecl.id AS source_id,
                    ec.company_id,
                    ec.accounting_period_id,
                    ec.id AS expense_claim_id,
                    ec.posted_journal_id,
                    ec.claim_reference_code,
                    ec.period_end AS claim_period_end,
                    ecl.expense_date AS source_date,
                    ecl.description,
                    ecl.amount,
                    na.id AS nominal_account_id,
                    na.code AS nominal_code,
                    na.name AS nominal_name,
                    na.account_type AS nominal_account_type
             FROM expense_claim_lines ecl
             INNER JOIN expense_claims ec ON ec.id = ecl.expense_claim_id
             INNER JOIN nominal_accounts na ON na.id = ecl.nominal_account_id
             WHERE ec.company_id = :company_id
               AND ec.accounting_period_id = :accounting_period_id
               AND ecl.id = :source_id
               AND ec.status = \'posted\'
               AND ec.posted_journal_id IS NOT NULL
               AND ecl.amount > 0
               AND COALESCE(na.prepayment_candidate, 0) = 1
               AND na.account_type IN (\'expense\', \'cost_of_sales\')
             LIMIT 1',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId, 'source_id' => $sourceId]
        );

        return is_array($row) ? $this->normaliseSource($row, self::SOURCE_EXPENSE_CLAIM_LINE) : null;
    }

    /** @return list<array<string, mixed>> */
    private function transactionCandidates(int $companyId, int $accountingPeriodId): array
    {
        if (!$this->tableExists('transactions')) {
            return [];
        }
        $splitExclusion = $this->tableExists('transaction_splits')
            ? 'AND NOT EXISTS (SELECT 1 FROM transaction_splits ts WHERE ts.transaction_id = t.id)'
            : '';

        return \InterfaceDB::fetchAll(
            'SELECT \'transaction\' AS source_type, t.id AS source_id,
                    t.company_id, t.accounting_period_id, t.txn_date AS source_date,
                    COALESCE(t.description, t.counterparty_name, \'\') AS description, ABS(t.amount) AS amount,
                    na.id AS nominal_account_id, na.code AS nominal_code, na.name AS nominal_name,
                    na.account_type AS nominal_account_type
             FROM transactions t
             INNER JOIN nominal_accounts na ON na.id = t.nominal_account_id
             WHERE t.company_id = :company_id AND t.accounting_period_id = :accounting_period_id
               AND COALESCE(na.prepayment_candidate, 0) = 1
               AND na.account_type IN (\'expense\', \'cost_of_sales\')
               ' . $splitExclusion,
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
    }

    /** @return list<array<string, mixed>> */
    private function splitLineCandidates(int $companyId, int $accountingPeriodId): array
    {
        foreach (['transactions', 'transaction_splits', 'transaction_split_lines'] as $table) {
            if (!$this->tableExists($table)) {
                return [];
            }
        }

        return \InterfaceDB::fetchAll(
            'SELECT \'transaction_split_line\' AS source_type, tsl.id AS source_id,
                    t.company_id, t.accounting_period_id, t.id AS parent_transaction_id, t.txn_date AS source_date,
                    COALESCE(tsl.description, t.description, \'\') AS description, tsl.amount,
                    na.id AS nominal_account_id, na.code AS nominal_code, na.name AS nominal_name,
                    na.account_type AS nominal_account_type
             FROM transaction_split_lines tsl
             INNER JOIN transaction_splits ts ON ts.id = tsl.split_id
             INNER JOIN transactions t ON t.id = ts.transaction_id
             INNER JOIN nominal_accounts na ON na.id = tsl.nominal_account_id
             WHERE t.company_id = :company_id AND t.accounting_period_id = :accounting_period_id
               AND tsl.amount > 0 AND COALESCE(na.prepayment_candidate, 0) = 1
               AND na.account_type IN (\'expense\', \'cost_of_sales\')',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
    }

    /** @return list<array<string, mixed>> */
    private function expenseClaimLineCandidates(int $companyId, int $accountingPeriodId): array
    {
        foreach (['expense_claims', 'expense_claim_lines'] as $table) {
            if (!$this->tableExists($table)) {
                return [];
            }
        }

        return \InterfaceDB::fetchAll(
            'SELECT \'expense_claim_line\' AS source_type, ecl.id AS source_id,
                    ec.company_id, ec.accounting_period_id, ec.id AS expense_claim_id,
                    ec.posted_journal_id, ec.claim_reference_code, ec.period_end AS claim_period_end,
                    ecl.expense_date AS source_date, ecl.description, ecl.amount,
                    na.id AS nominal_account_id, na.code AS nominal_code, na.name AS nominal_name,
                    na.account_type AS nominal_account_type
             FROM expense_claim_lines ecl
             INNER JOIN expense_claims ec ON ec.id = ecl.expense_claim_id
             INNER JOIN nominal_accounts na ON na.id = ecl.nominal_account_id
             WHERE ec.company_id = :company_id AND ec.accounting_period_id = :accounting_period_id
               AND ec.status = \'posted\'
               AND ec.posted_journal_id IS NOT NULL
               AND ecl.amount > 0
               AND COALESCE(na.prepayment_candidate, 0) = 1
               AND na.account_type IN (\'expense\', \'cost_of_sales\')',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
    }

    /** @return array{success: bool, journal?: array<string, mixed>, errors: list<string>} */
    private function sourceJournal(string $sourceType, array $source): array
    {
        if ($sourceType === self::SOURCE_EXPENSE_CLAIM_LINE) {
            $journalId = (int)($source['posted_journal_id'] ?? 0);
            $row = $journalId > 0 ? \InterfaceDB::fetchOne(
                'SELECT id, company_id, accounting_period_id, source_type, source_ref, journal_date, is_posted
                 FROM journals
                 WHERE id = :id
                   AND company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                   AND source_type = \'expense_register\'
                   AND source_ref = :source_ref
                   AND journal_date = :journal_date
                 LIMIT 1',
                [
                    'id' => $journalId,
                    'company_id' => (int)$source['company_id'],
                    'accounting_period_id' => (int)$source['accounting_period_id'],
                    'source_ref' => (string)($source['claim_reference_code'] ?? ''),
                    'journal_date' => (string)($source['claim_period_end'] ?? ''),
                ]
            ) : null;
            return is_array($row)
                ? ['success' => true, 'journal' => $row, 'errors' => []]
                : ['success' => false, 'errors' => ['The expense claim is not linked to its exact posted expense-register journal for this company and accounting period.']];
        }

        $transactionId = $sourceType === self::SOURCE_TRANSACTION
            ? (int)$source['source_id']
            : (int)($source['parent_transaction_id'] ?? 0);
        $rows = $transactionId > 0 ? \InterfaceDB::fetchAll(
            'SELECT id, company_id, accounting_period_id, source_type, source_ref, journal_date, is_posted
             FROM journals
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND source_type = \'bank_csv\'
               AND source_ref = :source_ref
               AND journal_date = :journal_date
             ORDER BY id',
            [
                'company_id' => (int)$source['company_id'],
                'accounting_period_id' => (int)$source['accounting_period_id'],
                'source_ref' => 'transaction:' . $transactionId,
                'journal_date' => (string)$source['source_date'],
            ]
        ) : [];

        if (count($rows) !== 1) {
            return [
                'success' => false,
                'errors' => [count($rows) === 0
                    ? 'The exact posted bank transaction journal could not be found for this company and accounting period.'
                    : 'More than one production bank journal matches this transaction; resolve the duplicate journals before treating it as a prepayment.'],
            ];
        }
        return ['success' => true, 'journal' => $rows[0], 'errors' => []];
    }

    private function requireLockedRow(string $sql, array $params): void
    {
        if ((int)\InterfaceDB::fetchColumn($sql, $params) <= 0) {
            throw new \RuntimeException('The prepayment source evidence disappeared while it was being locked.');
        }
    }

    private function normaliseSource(array $row, string $sourceType): array
    {
        $row['source_type'] = $sourceType;
        $row['source_id'] = (int)$row['source_id'];
        $row['nominal_account_id'] = (int)$row['nominal_account_id'];
        $row['amount_pence'] = (int)round(abs((float)$row['amount']) * 100);
        $row['amount'] = number_format($row['amount_pence'] / 100, 2, '.', '');
        if (!isset($row['company_id'])) {
            $row['company_id'] = (int)\InterfaceDB::fetchColumn(
                'SELECT company_id FROM accounting_periods WHERE id = :id',
                ['id' => (int)($row['accounting_period_id'] ?? 0)]
            );
        }
        return $row;
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tableExistence)) {
            return $this->tableExistence[$table];
        }

        try {
            return $this->tableExistence[$table] = \InterfaceDB::tableExists($table);
        } catch (\Throwable) {
            return $this->tableExistence[$table] = false;
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{eligible: list<array<string, mixed>>, excluded: list<array<string, mixed>>}
     */
    private function verifyCandidateRows(int $companyId, int $accountingPeriodId, array $rows): array
    {
        if ($rows === []) {
            return ['eligible' => [], 'excluded' => []];
        }

        $sources = array_map(
            fn(array $row): array => $this->normaliseSource($row, (string)$row['source_type']),
            $rows
        );
        $journals = $this->candidateJournals($companyId, $accountingPeriodId, $sources);
        $journalLines = $this->candidateJournalLines(array_values(array_unique(array_map(
            static fn(array $journal): int => (int)$journal['id'],
            $journals
        ))));
        $journalsBySource = [];
        foreach ($journals as $journal) {
            $journalsBySource[(string)$journal['source_type'] . '|' . (string)$journal['source_ref'] . '|' . (string)$journal['journal_date']][] = $journal;
            $journalsBySource['id|' . (int)$journal['id']][] = $journal;
        }
        $linesByJournal = [];
        foreach ($journalLines as $line) {
            $linesByJournal[(int)$line['journal_id']][] = $line;
        }

        $eligible = [];
        $excluded = [];
        foreach ($sources as $source) {
            $journalResolution = $this->candidateJournalResolution($source, $journalsBySource);
            if (empty($journalResolution['success']) || !is_array($journalResolution['journal'] ?? null)) {
                $excluded[] = $this->excludedCandidate($source, (array)($journalResolution['errors'] ?? []));
                continue;
            }

            $journal = (array)$journalResolution['journal'];
            if (empty($journal['is_posted'])) {
                $excluded[] = $this->excludedCandidate($source, ['The source journal is not posted.']);
                continue;
            }
            $matchingLines = array_values(array_filter(
                (array)($linesByJournal[(int)$journal['id']] ?? []),
                static fn(array $line): bool => (int)$line['nominal_account_id'] === (int)$source['nominal_account_id']
                    && (int)round((float)$line['debit'] * 100) === (int)$source['amount_pence']
                    && (float)$line['debit'] > 0
                    && (float)$line['credit'] === 0.0
            ));
            if (count($matchingLines) > 1) {
                $description = trim((string)($source['description'] ?? ''));
                $descriptionMatches = $description !== '' ? array_values(array_filter(
                    $matchingLines,
                    static fn(array $line): bool => trim((string)($line['line_description'] ?? '')) === $description
                )) : [];
                if (count($descriptionMatches) === 1) {
                    $matchingLines = $descriptionMatches;
                }
            }
            if (count($matchingLines) !== 1) {
                $excluded[] = $this->excludedCandidate($source, [$matchingLines === []
                    ? 'The posted source journal does not contain the expected positive debit to the candidate expense nominal.'
                    : 'The posted source journal contains ambiguous matching debit lines; make the source line descriptions unique before treating it as a prepayment.']);
                continue;
            }

            $source['source_journal_id'] = (int)$journal['id'];
            $source['source_journal_line_id'] = (int)$matchingLines[0]['id'];
            $source['source_journal_date'] = (string)$journal['journal_date'];
            $source['source_valid'] = true;
            $source['source_errors'] = [];
            $eligible[] = $source;
        }

        return ['eligible' => $eligible, 'excluded' => $excluded];
    }

    /** @param list<array<string, mixed>> $sources @return list<array<string, mixed>> */
    private function candidateJournals(int $companyId, int $accountingPeriodId, array $sources): array
    {
        $conditions = [];
        $params = ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId];
        $bankReferences = [];
        $expenseJournalIds = [];
        foreach ($sources as $source) {
            if ((string)$source['source_type'] === self::SOURCE_EXPENSE_CLAIM_LINE) {
                $expenseJournalIds[] = (int)($source['posted_journal_id'] ?? 0);
                continue;
            }
            $transactionId = (string)$source['source_type'] === self::SOURCE_TRANSACTION
                ? (int)$source['source_id']
                : (int)($source['parent_transaction_id'] ?? 0);
            if ($transactionId > 0) {
                $bankReferences[] = 'transaction:' . $transactionId;
            }
        }
        $bankReferences = array_values(array_unique($bankReferences));
        $expenseJournalIds = array_values(array_unique(array_filter($expenseJournalIds)));
        if ($bankReferences !== []) {
            $placeholders = [];
            foreach ($bankReferences as $index => $reference) {
                $key = 'bank_ref_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $reference;
            }
            $conditions[] = '(source_type = \'bank_csv\' AND source_ref IN (' . implode(', ', $placeholders) . '))';
        }
        if ($expenseJournalIds !== []) {
            $placeholders = [];
            foreach ($expenseJournalIds as $index => $journalId) {
                $key = 'expense_journal_id_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $journalId;
            }
            $conditions[] = 'id IN (' . implode(', ', $placeholders) . ')';
        }
        if ($conditions === []) {
            return [];
        }

        return \InterfaceDB::fetchAll(
            'SELECT id, company_id, accounting_period_id, source_type, source_ref, journal_date, is_posted
             FROM journals
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND (' . implode(' OR ', $conditions) . ')
             ORDER BY id',
            $params
        );
    }

    /** @param list<int> $journalIds @return list<array<string, mixed>> */
    private function candidateJournalLines(array $journalIds): array
    {
        if ($journalIds === []) {
            return [];
        }
        $params = [];
        $placeholders = [];
        foreach ($journalIds as $index => $journalId) {
            $key = 'journal_id_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $journalId;
        }

        return \InterfaceDB::fetchAll(
            'SELECT id, journal_id, nominal_account_id, debit, credit, COALESCE(line_description, \'\') AS line_description
             FROM journal_lines
             WHERE journal_id IN (' . implode(', ', $placeholders) . ')
               AND debit > 0
               AND credit = 0
             ORDER BY journal_id, id',
            $params
        );
    }

    /** @param array<string, list<array<string, mixed>>> $journalsBySource */
    private function candidateJournalResolution(array $source, array $journalsBySource): array
    {
        if ((string)$source['source_type'] === self::SOURCE_EXPENSE_CLAIM_LINE) {
            $journalId = (int)($source['posted_journal_id'] ?? 0);
            $matches = array_values(array_filter(
                (array)($journalsBySource['id|' . $journalId] ?? []),
                static fn(array $journal): bool => (string)$journal['source_type'] === 'expense_register'
                    && (string)$journal['source_ref'] === (string)($source['claim_reference_code'] ?? '')
                    && (string)$journal['journal_date'] === (string)($source['claim_period_end'] ?? '')
            ));
            return count($matches) === 1
                ? ['success' => true, 'journal' => $matches[0], 'errors' => []]
                : ['success' => false, 'errors' => ['The expense claim is not linked to its exact posted expense-register journal for this company and accounting period.']];
        }

        $transactionId = (string)$source['source_type'] === self::SOURCE_TRANSACTION
            ? (int)$source['source_id']
            : (int)($source['parent_transaction_id'] ?? 0);
        $key = 'bank_csv|transaction:' . $transactionId . '|' . (string)$source['source_date'];
        $matches = (array)($journalsBySource[$key] ?? []);
        if (count($matches) !== 1) {
            return ['success' => false, 'errors' => [count($matches) === 0
                ? 'The exact posted bank transaction journal could not be found for this company and accounting period.'
                : 'More than one production bank journal matches this transaction; resolve the duplicate journals before treating it as a prepayment.']];
        }
        return ['success' => true, 'journal' => $matches[0], 'errors' => []];
    }

    private function excludedCandidate(array $source, array $errors): array
    {
        $source['source_valid'] = false;
        $source['source_errors'] = array_values($errors !== [] ? $errors : ['The source is not eligible for prepayment review.']);
        $source['exclusion_reason'] = (string)($source['source_errors'][0] ?? 'The source is not eligible for prepayment review.');
        return $source;
    }
}
