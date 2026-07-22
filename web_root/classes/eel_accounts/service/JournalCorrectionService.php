<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class JournalCorrectionService
{
    public function __construct(
        private readonly ?ManualJournalService $journalService = null,
        private readonly ?YearEndLockService $lockService = null,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function reverseJournal(
        int $companyId,
        int $sourceJournalId,
        int $accountingPeriodId,
        string $effectiveDate,
        string $reason,
        string $changedBy = 'web_app',
        string $idempotencyKey = ''
    ): array {
        $this->ensureSchema();
        $effectiveDate = trim($effectiveDate);
        $reason = trim($reason);
        $changedBy = trim($changedBy) !== '' ? trim($changedBy) : 'web_app';
        $idempotencyKey = trim($idempotencyKey) !== ''
            ? trim($idempotencyKey)
            : 'journal-reversal:' . $sourceJournalId;

        $errors = $this->validationErrors(
            $companyId,
            $sourceJournalId,
            $accountingPeriodId,
            $effectiveDate,
            $reason,
            $idempotencyKey
        );
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        $existing = $this->fetchReversalForSource($companyId, $sourceJournalId);
        if ($existing !== null) {
            if (hash_equals((string)$existing['idempotency_key'], $idempotencyKey)) {
                return $this->existingResult($existing);
            }
            return ['success' => false, 'errors' => ['This source journal has already been reversed by another correction.']];
        }

        $keyConflict = $this->fetchReversalForKey($companyId, $idempotencyKey);
        if ($keyConflict !== null) {
            return ['success' => false, 'errors' => ['This journal correction key has already been used for another journal.']];
        }

        $period = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($period === null || $effectiveDate < (string)$period['period_start'] || $effectiveDate > (string)$period['period_end']) {
            return ['success' => false, 'errors' => ['The correction date must fall inside the selected accounting period.']];
        }

        try {
            ($this->lockService ?? new YearEndLockService())->assertUnlocked(
                $companyId,
                $accountingPeriodId,
                'post a journal correction in this period'
            );
        } catch (\Throwable $exception) {
            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            $source = $this->fetchJournal($companyId, $sourceJournalId, true);
            if ($source === null) {
                throw new \RuntimeException('The source journal could not be found for this company.');
            }
            if ((int)($source['is_posted'] ?? 0) !== 1) {
                throw new \RuntimeException('Only posted journals can be reversed.');
            }

            $existing = $this->fetchReversalForSource($companyId, $sourceJournalId);
            if ($existing !== null) {
                if (!hash_equals((string)$existing['idempotency_key'], $idempotencyKey)) {
                    throw new \RuntimeException('This source journal has already been reversed by another correction.');
                }
                if ($ownsTransaction) {
                    \InterfaceDB::commit();
                }
                return $this->existingResult($existing);
            }

            $lines = array_map(static fn(array $line): array => [
                'nominal_account_id' => (int)$line['nominal_account_id'],
                'director_id' => (int)($line['director_id'] ?? 0) ?: null,
                'party_id' => (int)($line['party_id'] ?? 0) ?: null,
                'company_account_id' => (int)($line['company_account_id'] ?? 0) ?: null,
                'debit' => number_format((float)($line['credit'] ?? 0), 2, '.', ''),
                'credit' => number_format((float)($line['debit'] ?? 0), 2, '.', ''),
                'line_description' => 'Reversal: ' . trim((string)($line['line_description'] ?? $source['description'] ?? 'journal line')),
            ], (array)$source['lines']);

            $journalResult = ($this->journalService ?? new ManualJournalService())->saveTaggedJournal(
                $companyId,
                $accountingPeriodId,
                'journal_reversal',
                'source:' . $sourceJournalId,
                $effectiveDate,
                'Reversal of journal #' . $sourceJournalId . ' - ' . (string)$source['description'],
                $lines,
                'system_generated',
                $sourceJournalId,
                null,
                $reason,
                $changedBy
            );
            if (empty($journalResult['success']) || !is_array($journalResult['journal'] ?? null)) {
                throw new \RuntimeException((string)(($journalResult['errors'] ?? [])[0] ?? 'The reversing journal could not be posted.'));
            }

            $reversalJournalId = (int)$journalResult['journal']['id'];
            \InterfaceDB::prepareExecute(
                'INSERT INTO journal_reversals (
                    company_id, accounting_period_id, source_journal_id, reversal_journal_id,
                    effective_date, idempotency_key, reason, created_by
                 ) VALUES (
                    :company_id, :accounting_period_id, :source_journal_id, :reversal_journal_id,
                    :effective_date, :idempotency_key, :reason, :created_by
                 )',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'source_journal_id' => $sourceJournalId,
                    'reversal_journal_id' => $reversalJournalId,
                    'effective_date' => $effectiveDate,
                    'idempotency_key' => $idempotencyKey,
                    'reason' => $reason,
                    'created_by' => $changedBy,
                ]
            );

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }

            return [
                'success' => true,
                'errors' => [],
                'source_journal_id' => $sourceJournalId,
                'reversal_journal_id' => $reversalJournalId,
                'replacement_journal_id' => null,
                'already_reversed' => false,
            ];
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }
            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }
    }

    /**
     * The replacement specification accepts the arguments used by
     * ManualJournalService::saveTaggedJournal, excluding company and period.
     *
     * @param array<string,mixed> $replacement
     * @return array<string,mixed>
     */
    public function reverseAndReplaceJournal(
        int $companyId,
        int $sourceJournalId,
        int $accountingPeriodId,
        string $effectiveDate,
        string $reason,
        array $replacement,
        string $changedBy = 'web_app',
        string $idempotencyKey = ''
    ): array {
        $this->ensureSchema();
        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            $reversal = $this->reverseJournal(
                $companyId,
                $sourceJournalId,
                $accountingPeriodId,
                $effectiveDate,
                $reason,
                $changedBy,
                $idempotencyKey
            );
            if (empty($reversal['success'])) {
                throw new \RuntimeException((string)(($reversal['errors'] ?? [])[0] ?? 'The source journal could not be reversed.'));
            }

            $existingReplacementId = (int)($reversal['replacement_journal_id'] ?? 0);
            if ($existingReplacementId > 0) {
                if ($ownsTransaction) {
                    \InterfaceDB::commit();
                }
                return $reversal;
            }

            $replacementPeriodId = (int)($replacement['accounting_period_id'] ?? $accountingPeriodId);
            $replacementDate = (string)($replacement['journal_date'] ?? $effectiveDate);
            $replacementPeriod = $this->fetchAccountingPeriod($companyId, $replacementPeriodId);
            if (
                $replacementPeriod === null
                || $replacementDate < (string)$replacementPeriod['period_start']
                || $replacementDate > (string)$replacementPeriod['period_end']
            ) {
                throw new \RuntimeException('The replacement journal date must fall inside its accounting period.');
            }
            ($this->lockService ?? new YearEndLockService())->assertUnlocked(
                $companyId,
                $replacementPeriodId,
                'post a replacement journal in this period'
            );

            $replacementResult = ($this->journalService ?? new ManualJournalService())->saveTaggedJournal(
                $companyId,
                $replacementPeriodId,
                (string)($replacement['journal_tag'] ?? 'journal_replacement'),
                (string)($replacement['journal_key'] ?? ('source:' . $sourceJournalId)),
                $replacementDate,
                (string)($replacement['description'] ?? ('Replacement for journal #' . $sourceJournalId)),
                (array)($replacement['lines'] ?? []),
                (string)($replacement['entry_mode'] ?? 'system_generated'),
                (int)($reversal['reversal_journal_id'] ?? 0) ?: null,
                $sourceJournalId,
                (string)($replacement['notes'] ?? $reason),
                $changedBy,
                (string)($replacement['source_type'] ?? 'manual'),
                isset($replacement['source_ref']) ? (string)$replacement['source_ref'] : null
            );
            if (empty($replacementResult['success']) || !is_array($replacementResult['journal'] ?? null)) {
                throw new \RuntimeException((string)(($replacementResult['errors'] ?? [])[0] ?? 'The replacement journal could not be posted.'));
            }

            $replacementJournalId = (int)$replacementResult['journal']['id'];
            \InterfaceDB::prepareExecute(
                'UPDATE journal_reversals
                 SET replacement_journal_id = :replacement_journal_id
                 WHERE company_id = :company_id AND source_journal_id = :source_journal_id',
                [
                    'replacement_journal_id' => $replacementJournalId,
                    'company_id' => $companyId,
                    'source_journal_id' => $sourceJournalId,
                ]
            );

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }

            $reversal['replacement_journal_id'] = $replacementJournalId;
            return $reversal;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }
            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }
    }

    public function ensureSchema(): void
    {
        if (\InterfaceDB::tableExists('journal_reversals')) {
            return;
        }

        if (\InterfaceDB::driverName() === 'sqlite') {
            \InterfaceDB::prepareExecute(
                'CREATE TABLE IF NOT EXISTS journal_reversals (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    company_id INTEGER NOT NULL,
                    accounting_period_id INTEGER NOT NULL,
                    source_journal_id INTEGER NOT NULL UNIQUE,
                    reversal_journal_id INTEGER NOT NULL UNIQUE,
                    replacement_journal_id INTEGER NULL,
                    effective_date TEXT NOT NULL,
                    idempotency_key TEXT NOT NULL,
                    reason TEXT NOT NULL,
                    created_by TEXT NOT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (company_id, idempotency_key)
                 )'
            );
            return;
        }

        \InterfaceDB::prepareExecute(
            'CREATE TABLE IF NOT EXISTS journal_reversals (
                id BIGINT NOT NULL AUTO_INCREMENT,
                company_id INT NOT NULL,
                accounting_period_id INT NOT NULL,
                source_journal_id BIGINT NOT NULL,
                reversal_journal_id BIGINT NOT NULL,
                replacement_journal_id BIGINT NULL,
                effective_date DATE NOT NULL,
                idempotency_key VARCHAR(128) NOT NULL,
                reason TEXT NOT NULL,
                created_by VARCHAR(100) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_journal_reversals_source (source_journal_id),
                UNIQUE KEY uq_journal_reversals_reversal (reversal_journal_id),
                UNIQUE KEY uq_journal_reversals_company_key (company_id, idempotency_key),
                KEY idx_journal_reversals_period (company_id, accounting_period_id, effective_date),
                KEY idx_journal_reversals_replacement (replacement_journal_id),
                CONSTRAINT fk_journal_reversals_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT ON UPDATE CASCADE,
                CONSTRAINT fk_journal_reversals_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods(id) ON DELETE RESTRICT ON UPDATE CASCADE,
                CONSTRAINT fk_journal_reversals_source FOREIGN KEY (source_journal_id) REFERENCES journals(id) ON DELETE RESTRICT ON UPDATE CASCADE,
                CONSTRAINT fk_journal_reversals_reversal FOREIGN KEY (reversal_journal_id) REFERENCES journals(id) ON DELETE RESTRICT ON UPDATE CASCADE,
                CONSTRAINT fk_journal_reversals_replacement FOREIGN KEY (replacement_journal_id) REFERENCES journals(id) ON DELETE RESTRICT ON UPDATE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function linkReplacementJournal(int $companyId, int $sourceJournalId, int $replacementJournalId): array
    {
        $this->ensureSchema();
        if ($companyId <= 0 || $sourceJournalId <= 0 || $replacementJournalId <= 0) {
            return ['success' => false, 'errors' => ['Select valid source and replacement journals.']];
        }
        $source = $this->fetchJournal($companyId, $sourceJournalId, false);
        $replacement = $this->fetchJournal($companyId, $replacementJournalId, false);
        if ($source === null || $replacement === null) {
            return ['success' => false, 'errors' => ['The source or replacement journal could not be found for this company.']];
        }
        $reversal = $this->fetchReversalForSource($companyId, $sourceJournalId);
        if ($reversal === null) {
            return ['success' => false, 'errors' => ['The source journal must be reversed before a replacement can be linked.']];
        }
        $existingReplacementId = (int)($reversal['replacement_journal_id'] ?? 0);
        if ($existingReplacementId > 0 && $existingReplacementId !== $replacementJournalId) {
            return ['success' => false, 'errors' => ['This journal correction already has a different replacement.']];
        }
        \InterfaceDB::prepareExecute(
            'UPDATE journal_reversals
             SET replacement_journal_id = :replacement_journal_id
             WHERE company_id = :company_id AND source_journal_id = :source_journal_id',
            [
                'replacement_journal_id' => $replacementJournalId,
                'company_id' => $companyId,
                'source_journal_id' => $sourceJournalId,
            ]
        );
        return ['success' => true, 'errors' => [], 'replacement_journal_id' => $replacementJournalId];
    }

    /** @return list<string> */
    private function validationErrors(
        int $companyId,
        int $sourceJournalId,
        int $accountingPeriodId,
        string $effectiveDate,
        string $reason,
        string $idempotencyKey
    ): array {
        $errors = [];
        if ($companyId <= 0 || $sourceJournalId <= 0 || $accountingPeriodId <= 0) {
            $errors[] = 'Select a valid company, source journal and accounting period.';
        }
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $effectiveDate);
        if ($date === false || $date->format('Y-m-d') !== $effectiveDate) {
            $errors[] = 'Enter a valid correction date.';
        }
        if ($reason === '') {
            $errors[] = 'A correction reason is required.';
        }
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            $errors[] = 'The journal correction key is invalid.';
        }
        return $errors;
    }

    private function fetchJournal(int $companyId, int $journalId, bool $lock): ?array
    {
        $suffix = $lock && \InterfaceDB::driverName() !== 'sqlite' ? ' FOR UPDATE' : '';
        $row = \InterfaceDB::fetchOne(
            'SELECT id, company_id, accounting_period_id, source_type, source_ref,
                    journal_date, description, is_posted
             FROM journals
             WHERE id = :journal_id AND company_id = :company_id
             LIMIT 1' . $suffix,
            ['journal_id' => $journalId, 'company_id' => $companyId]
        );
        if (!is_array($row)) {
            return null;
        }
        $row['lines'] = \InterfaceDB::fetchAll(
            'SELECT nominal_account_id, director_id, party_id, company_account_id,
                    debit, credit, line_description
             FROM journal_lines WHERE journal_id = :journal_id ORDER BY id',
            ['journal_id' => $journalId]
        );
        return $row;
    }

    private function fetchAccountingPeriod(int $companyId, int $accountingPeriodId): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT id, company_id, period_start, period_end
             FROM accounting_periods
             WHERE id = :id AND company_id = :company_id LIMIT 1',
            ['id' => $accountingPeriodId, 'company_id' => $companyId]
        );
        return is_array($row) ? $row : null;
    }

    private function fetchReversalForSource(int $companyId, int $sourceJournalId): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM journal_reversals
             WHERE company_id = :company_id AND source_journal_id = :source_journal_id LIMIT 1',
            ['company_id' => $companyId, 'source_journal_id' => $sourceJournalId]
        );
        return is_array($row) ? $row : null;
    }

    private function fetchReversalForKey(int $companyId, string $idempotencyKey): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM journal_reversals
             WHERE company_id = :company_id AND idempotency_key = :idempotency_key LIMIT 1',
            ['company_id' => $companyId, 'idempotency_key' => $idempotencyKey]
        );
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    private function existingResult(array $row): array
    {
        return [
            'success' => true,
            'errors' => [],
            'source_journal_id' => (int)$row['source_journal_id'],
            'reversal_journal_id' => (int)$row['reversal_journal_id'],
            'replacement_journal_id' => (int)($row['replacement_journal_id'] ?? 0) ?: null,
            'already_reversed' => true,
        ];
    }
}
