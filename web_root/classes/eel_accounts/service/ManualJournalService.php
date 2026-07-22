<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class ManualJournalService
{
    public function __construct(
        private readonly ?\eel_accounts\Service\YearEndLockService $lockService = null,
    ) {
    }

    public function fetchJournalByTag(int $companyId, int $accountingPeriodId, string $journalTag, string $journalKey = 'primary'): ?array {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || trim($journalTag) === '') {
            return null;
        }

        if ($this->hasMetadataTable()) {
            $row = \InterfaceDB::fetchOne( 'SELECT j.id,
                        j.company_id,
                        j.accounting_period_id,
                        j.source_type,
                        COALESCE(j.source_ref, \'\') AS source_ref,
                        j.journal_date,
                        j.description,
                        j.is_posted,
                        jem.journal_tag,
                        jem.journal_key,
                        jem.entry_mode,
                        jem.related_journal_id,
                        jem.replacement_of_journal_id,
                        COALESCE(jem.notes, \'\') AS notes
                 FROM journal_entry_metadata jem
                 INNER JOIN journals j ON j.id = jem.journal_id
                 WHERE jem.company_id = :company_id
                   AND jem.accounting_period_id = :accounting_period_id
                   AND jem.journal_tag = :journal_tag
                   AND (jem.journal_key = :journal_key OR jem.journal_key LIKE :journal_key_prefix)
                 ORDER BY j.id DESC
                 LIMIT 1', [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'journal_tag' => trim($journalTag),
                'journal_key' => trim($journalKey),
                'journal_key_prefix' => trim($journalKey) . ':%',
            ]);
            if (is_array($row)) {
                $row['lines'] = $this->fetchJournalLines((int)$row['id']);
                return $row;
            }
        }

        $sourceRef = $this->sourceRef(trim($journalTag), $accountingPeriodId . ':' . trim($journalKey));
        $row = \InterfaceDB::fetchOne( 'SELECT id,
                    company_id,
                    accounting_period_id,
                    source_type,
                    COALESCE(source_ref, \'\') AS source_ref,
                    journal_date,
                    description,
                    is_posted
             FROM journals
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND source_type = :source_type
               AND source_ref = :source_ref
             LIMIT 1', [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'source_type' => 'manual',
            'source_ref' => $sourceRef,
        ]);
        if (!is_array($row)) {
            return null;
        }

        $row['journal_tag'] = trim($journalTag);
        $row['journal_key'] = trim($journalKey);
        $row['entry_mode'] = 'manual';
        $row['related_journal_id'] = null;
        $row['replacement_of_journal_id'] = null;
        $row['notes'] = '';
        $row['lines'] = $this->fetchJournalLines((int)$row['id']);

        return $row;
    }

    public function listJournalsByTags(int $companyId, int $accountingPeriodId, array $journalTags): array {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || $journalTags === []) {
            return [];
        }

        $tags = array_values(array_filter(array_map(static fn(string $tag): string => trim($tag), $journalTags), static fn(string $tag): bool => $tag !== ''));
        if ($tags === []) {
            return [];
        }

        if ($this->hasMetadataTable()) {
            $placeholders = implode(', ', array_fill(0, count($tags), '?'));
            $stmt = \InterfaceDB::prepare(
                'SELECT j.id,
                        j.company_id,
                        j.accounting_period_id,
                        j.source_type,
                        COALESCE(j.source_ref, \'\') AS source_ref,
                        j.journal_date,
                        j.description,
                        j.is_posted,
                        jem.journal_tag,
                        jem.journal_key,
                        jem.entry_mode,
                        jem.related_journal_id,
                        jem.replacement_of_journal_id,
                        COALESCE(jem.notes, \'\') AS notes
                 FROM journal_entry_metadata jem
                 INNER JOIN journals j ON j.id = jem.journal_id
                 WHERE jem.company_id = ?
                   AND jem.accounting_period_id = ?
                   AND jem.journal_tag IN (' . $placeholders . ')
                 ORDER BY j.journal_date DESC, j.id DESC'
            );
            $stmt->execute(array_merge([$companyId, $accountingPeriodId], $tags));
            $rows = $stmt->fetchAll() ?: [];
        } else {
            $sourceRefs = array_map(fn(string $tag): string => $this->sourceRef($tag, $accountingPeriodId . ':'), $tags);
            $conditions = [];
            $params = [$companyId, $accountingPeriodId, 'manual'];
            foreach ($sourceRefs as $sourceRefPrefix) {
                $conditions[] = 'source_ref LIKE ?';
                $params[] = $sourceRefPrefix . '%';
            }

            $stmt = \InterfaceDB::prepare(
                'SELECT id,
                        company_id,
                        accounting_period_id,
                        source_type,
                        COALESCE(source_ref, \'\') AS source_ref,
                        journal_date,
                        description,
                        is_posted
                 FROM journals
                 WHERE company_id = ?
                   AND accounting_period_id = ?
                   AND source_type = ?
                   AND (' . implode(' OR ', $conditions) . ')
                 ORDER BY journal_date DESC, id DESC'
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll() ?: [];
            foreach ($rows as &$row) {
                $row['journal_tag'] = $this->inferTagFromSourceRef((string)($row['source_ref'] ?? ''));
                $row['journal_key'] = '';
                $row['entry_mode'] = 'manual';
                $row['related_journal_id'] = null;
                $row['replacement_of_journal_id'] = null;
                $row['notes'] = '';
            }
            unset($row);
        }

        foreach ($rows as &$row) {
            $row['lines'] = $this->fetchJournalLines((int)$row['id']);
        }
        unset($row);

        return $rows;
    }

    public function saveTaggedJournal(
        int $companyId,
        int $accountingPeriodId,
        string $journalTag,
        string $journalKey,
        string $journalDate,
        string $description,
        array $lines,
        string $entryMode = 'manual',
        ?int $relatedJournalId = null,
        ?int $replacementOfJournalId = null,
        ?string $notes = null,
        string $changedBy = 'web_app',
        string $sourceType = 'manual',
        ?string $sourceRefOverride = null
    ): array {
        $journalTag = trim($journalTag);
        $journalKey = trim($journalKey) !== '' ? trim($journalKey) : 'primary';
        $description = trim($description);
        $journalDate = trim($journalDate);
        $sourceType = trim($sourceType) !== '' ? trim($sourceType) : 'manual';

        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return ['success' => false, 'errors' => ['Select a valid company and accounting period first.']];
        }

        if ($journalTag === '') {
            return ['success' => false, 'errors' => ['A journal tag is required.']];
        }

        if (!$this->isValidDate($journalDate)) {
            return ['success' => false, 'errors' => ['Enter a valid journal date.']];
        }

        if ($description === '') {
            return ['success' => false, 'errors' => ['Enter a journal description.']];
        }

        $normalisedLines = $this->normaliseLines($lines);
        if (!empty($normalisedLines['errors'])) {
            return ['success' => false, 'errors' => $normalisedLines['errors']];
        }

        ($this->lockService ?? new \eel_accounts\Service\YearEndLockService())->assertUnlocked($companyId, $accountingPeriodId, 'post journals in this period');

        $existing = $this->fetchJournalByTag($companyId, $accountingPeriodId, $journalTag, $journalKey);
        $ownsTransaction = !\InterfaceDB::inTransaction();

        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            $storedJournalKey = $journalKey;
            if ($existing !== null) {
                $storedJournalKey .= ':' . date('YmdHis') . ':' . bin2hex(random_bytes(3));
            }
            $sourceRef = trim((string)$sourceRefOverride) !== ''
                ? trim((string)$sourceRefOverride)
                : $this->sourceRef($journalTag, $accountingPeriodId . ':' . $storedJournalKey);
            $insert = \InterfaceDB::prepare(
                'INSERT INTO journals (
                    company_id,
                    accounting_period_id,
                    source_type,
                    source_ref,
                    journal_date,
                    description,
                    is_posted,
                    created_at,
                    updated_at
                 ) VALUES (
                    :company_id,
                    :accounting_period_id,
                    :source_type,
                    :source_ref,
                    :journal_date,
                    :description,
                    1,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                 )'
            );
            $insert->execute([
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'source_type' => $sourceType,
                'source_ref' => $sourceRef,
                'journal_date' => $journalDate,
                'description' => $description,
            ]);

            $journalId = $this->findJournalId($companyId, $sourceType, $sourceRef);
            if ($journalId === null) {
                throw new \RuntimeException('The journal could not be reloaded after insert.');
            }

            foreach ((array)$normalisedLines['lines'] as $line) {
                $this->insertJournalLine($journalId, $line);
            }

            if ($this->hasMetadataTable()) {
                $meta = \InterfaceDB::prepare(
                    'INSERT INTO journal_entry_metadata (
                        journal_id,
                        company_id,
                        accounting_period_id,
                        journal_tag,
                        journal_key,
                        entry_mode,
                        related_journal_id,
                        replacement_of_journal_id,
                        notes
                     ) VALUES (
                        :journal_id,
                        :company_id,
                        :accounting_period_id,
                        :journal_tag,
                        :journal_key,
                        :entry_mode,
                        :related_journal_id,
                        :replacement_of_journal_id,
                        :notes
                     )'
                );
                $meta->execute([
                    'journal_id' => $journalId,
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'journal_tag' => $journalTag,
                    'journal_key' => $storedJournalKey,
                    'entry_mode' => $entryMode === 'system_generated' ? 'system_generated' : 'manual',
                    'related_journal_id' => $relatedJournalId !== null && $relatedJournalId > 0 ? $relatedJournalId : ($existing !== null ? (int)$existing['id'] : null),
                    'replacement_of_journal_id' => $replacementOfJournalId !== null && $replacementOfJournalId > 0 ? $replacementOfJournalId : null,
                    'notes' => trim((string)$notes) !== '' ? trim((string)$notes) : null,
                ]);
            }

            ($this->lockService ?? new \eel_accounts\Service\YearEndLockService())->writeAuditLog(
                $companyId,
                $accountingPeriodId,
                $existing === null ? 'journal_created' : 'journal_appended',
                $changedBy,
                $existing,
                [
                    'journal_id' => $journalId,
                    'journal_tag' => $journalTag,
                    'journal_key' => $journalKey,
                    'description' => $description,
                    'journal_date' => $journalDate,
                    'entry_mode' => $entryMode,
                ],
                $notes
            );

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }

        return [
            'success' => true,
            'journal' => $this->fetchJournalByTag($companyId, $accountingPeriodId, $journalTag, $journalKey),
            'appended_to_existing' => $existing !== null,
            'related_journal_id' => $existing !== null ? (int)$existing['id'] : null,
        ];
    }

    public function deleteTaggedJournal(
        int $companyId,
        int $accountingPeriodId,
        string $journalTag,
        string $journalKey = 'primary',
        string $changedBy = 'web_app'
    ): array {
        return [
            'success' => true,
            'deleted' => false,
            'skipped' => true,
            'errors' => [],
        ];
    }

    private function normaliseLines(array $lines): array {
        $errors = [];
        $normalised = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($lines as $line) {
            $nominalAccountId = is_array($line) && isset($line['nominal_account_id']) ? (int)$line['nominal_account_id'] : 0;
            $debit = is_array($line) ? round((float)($line['debit'] ?? 0), 2) : 0.0;
            $credit = is_array($line) ? round((float)($line['credit'] ?? 0), 2) : 0.0;
            $lineDescription = is_array($line) ? trim((string)($line['line_description'] ?? '')) : '';

            if ($nominalAccountId <= 0) {
                $errors[] = 'Every journal line needs a nominal account.';
                continue;
            }
            if ($debit < 0 || $credit < 0) {
                $errors[] = 'Debit and credit values cannot be negative.';
                continue;
            }
            if ($debit > 0 && $credit > 0) {
                $errors[] = 'A journal line cannot contain both a debit and a credit.';
                continue;
            }
            if ($debit === 0.0 && $credit === 0.0) {
                continue;
            }

            $normalised[] = [
                'nominal_account_id' => $nominalAccountId,
                'director_id' => isset($line['director_id']) && (int)$line['director_id'] > 0 ? (int)$line['director_id'] : null,
                'party_id' => isset($line['party_id']) && (int)$line['party_id'] > 0 ? (int)$line['party_id'] : null,
                'company_account_id' => isset($line['company_account_id']) ? (int)$line['company_account_id'] : null,
                'debit' => $debit,
                'credit' => $credit,
                'line_description' => $lineDescription !== '' ? $lineDescription : null,
            ];
            $totalDebit += $debit;
            $totalCredit += $credit;
        }

        if ($normalised === []) {
            $errors[] = 'Enter at least one non-zero journal line.';
        }
        if (count($normalised) < 2) {
            $errors[] = 'A journal must contain at least two non-zero lines.';
        }
        if (abs(round($totalDebit - $totalCredit, 2)) >= 0.005) {
            $errors[] = 'Total debits must equal total credits before the journal can be saved.';
        }

        return ['errors' => $errors, 'lines' => $normalised];
    }

    private function fetchJournalLines(int $journalId): array {
        return \InterfaceDB::fetchAll( 'SELECT jl.id,
                    jl.nominal_account_id,
                    jl.director_id,
                    jl.party_id,
                    jl.company_account_id,
                    jl.debit,
                    jl.credit,
                    COALESCE(jl.line_description, \'\') AS line_description,
                    COALESCE(na.code, \'\') AS nominal_code,
                    COALESCE(na.name, \'\') AS nominal_name,
                    COALESCE(na.account_type, \'\') AS nominal_account_type
             FROM journal_lines jl
             LEFT JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             WHERE jl.journal_id = :journal_id
             ORDER BY jl.id ASC', ['journal_id' => $journalId]);
    }

    private function insertJournalLine(int $journalId, array $line): void {
        $stmt = \InterfaceDB::prepare(
            'INSERT INTO journal_lines (
                journal_id,
                nominal_account_id,
                director_id,
                party_id,
                company_account_id,
                debit,
                credit,
                line_description
             ) VALUES (
                :journal_id,
                :nominal_account_id,
                :director_id,
                :party_id,
                :company_account_id,
                :debit,
                :credit,
                :line_description
             )'
        );
        $stmt->execute([
            'journal_id' => $journalId,
            'nominal_account_id' => (int)$line['nominal_account_id'],
            'director_id' => (int)($line['director_id'] ?? 0) > 0 ? (int)$line['director_id'] : null,
            'party_id' => (int)($line['party_id'] ?? 0) > 0 ? (int)$line['party_id'] : null,
            'company_account_id' => isset($line['company_account_id']) && (int)$line['company_account_id'] > 0 ? (int)$line['company_account_id'] : null,
            'debit' => number_format((float)$line['debit'], 2, '.', ''),
            'credit' => number_format((float)$line['credit'], 2, '.', ''),
            'line_description' => trim((string)($line['line_description'] ?? '')) !== '' ? trim((string)$line['line_description']) : null,
        ]);
    }

    private function findJournalId(int $companyId, string $sourceType, string $sourceRef): ?int {
        $value = \InterfaceDB::fetchColumn( 'SELECT id
             FROM journals
             WHERE company_id = :company_id
               AND source_type = :source_type
               AND source_ref = :source_ref
             LIMIT 1', [
            'company_id' => $companyId,
            'source_type' => $sourceType,
            'source_ref' => $sourceRef,
        ]);

        return $value !== false ? (int)$value : null;
    }

    private function sourceRef(string $journalTag, string $journalKey): string {
        $key = trim($journalKey);
        return 'meta:' . trim($journalTag) . ':' . ($key !== '' ? $key : 'primary');
    }

    private function inferTagFromSourceRef(string $sourceRef): string {
        if (preg_match('/^meta:([^:]+)/', $sourceRef, $matches) === 1) {
            return (string)$matches[1];
        }

        return 'manual';
    }

    private function hasMetadataTable(): bool {
        return $this->tableExists('journal_entry_metadata');
    }

    private function tableExists(string $table): bool {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        try {
            $cache[$table] = \InterfaceDB::tableExists($table);
        } catch (\Throwable) {
            $cache[$table] = false;
        }

        return $cache[$table];
    }

    private function isValidDate(string $value): bool {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
    }
}


