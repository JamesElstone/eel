<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class CompaniesHousePersistenceService
{
    private const RAW_VALUE_MAX_BYTES = 255;
    private const RAW_VALUE_HASH_MARKER = '[sha256=';

    private readonly ?\Closure $beforePersistenceRecheck;

    public function __construct(?callable $beforePersistenceRecheck = null) {
        $this->beforePersistenceRecheck = $beforePersistenceRecheck !== null
            ? \Closure::fromCallable($beforePersistenceRecheck)
            : null;
    }

    public function persistDocument(array $document, ?array $parsedDocument = null): array {
        $ownsTransaction = !\InterfaceDB::inTransaction();
        $savepoint = '';
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        } else {
            $savepoint = 'ch_document_persist_' . bin2hex(random_bytes(6));
            \InterfaceDB::execute('SAVEPOINT ' . $savepoint);
        }

        try {
            if ($this->beforePersistenceRecheck !== null) {
                ($this->beforePersistenceRecheck)();
            }

            $preserved = $this->successfullyParsedDocumentToPreserve($document, $parsedDocument);
            if ($preserved !== null) {
                $this->completeTransaction($ownsTransaction, $savepoint);

                return [
                    'document_row_id' => (int)$preserved['id'],
                    'latest_year_context_count' => (int)$preserved['latest_year_context_count'],
                    'latest_year_fact_count' => (int)$preserved['latest_year_fact_count'],
                    'preserved_existing_parse' => true,
                ];
            }

            $documentRowId = $this->upsertDocumentRow($document);

            if ($parsedDocument !== null) {
                $this->replaceDocumentContextsAndFacts($documentRowId, $parsedDocument);
            }

            $this->completeTransaction($ownsTransaction, $savepoint);

            return [
                'document_row_id' => $documentRowId,
                'latest_year_context_count' => (int)($parsedDocument['summary']['latest_year_context_count'] ?? 0),
                'latest_year_fact_count' => (int)($parsedDocument['summary']['latest_year_fact_count'] ?? 0),
            ];
        } catch (\Throwable $e) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            } elseif ($savepoint !== '' && \InterfaceDB::inTransaction()) {
                \InterfaceDB::execute('ROLLBACK TO SAVEPOINT ' . $savepoint);
                \InterfaceDB::execute('RELEASE SAVEPOINT ' . $savepoint);
            }

            throw $e;
        }
    }

    private function completeTransaction(bool $ownsTransaction, string $savepoint): void {
        if ($ownsTransaction) {
            \InterfaceDB::commit();
            return;
        }
        if ($savepoint !== '') {
            \InterfaceDB::execute('RELEASE SAVEPOINT ' . $savepoint);
        }
    }

    /**
     * A transient metadata/content/parse failure must not downgrade an already
     * parsed immutable Companies House document. New failed documents are
     * still inserted as placeholders so the latest AAMD remains visible.
     *
     * @return array<string,mixed>|null
     */
    private function successfullyParsedDocumentToPreserve(
        array $document,
        ?array $parsedDocument
    ): ?array {
        if ($this->incomingParseIsComplete($document, $parsedDocument)) {
            return null;
        }
        $documentId = trim((string)($document['document_id'] ?? ''));
        if ($documentId === '' || !\InterfaceDB::tableExists('companies_house_documents')) {
            return null;
        }
        $lock = \InterfaceDB::driverName() === 'sqlite' ? '' : ' FOR UPDATE';
        $existing = \InterfaceDB::fetchOne(
            'SELECT id, parse_status
             FROM companies_house_documents
             WHERE document_id = :document_id
             LIMIT 1' . $lock,
            ['document_id' => $documentId]
        );
        if (!is_array($existing)
            || !in_array(strtolower(trim((string)($existing['parse_status'] ?? ''))), [
                'parsed',
                'parsed_latest_year',
            ], true)) {
            return null;
        }

        $existing['latest_year_context_count'] = (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*) FROM companies_house_document_contexts
             WHERE document_fk = :document_id AND is_latest_year_context = 1',
            ['document_id' => (int)$existing['id']]
        );
        $existing['latest_year_fact_count'] = (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*) FROM companies_house_document_facts
             WHERE document_fk = :document_id AND is_latest_year_fact = 1',
            ['document_id' => (int)$existing['id']]
        );
        if ((int)$existing['latest_year_fact_count'] <= 0) {
            return null;
        }

        return $existing;
    }

    private function incomingParseIsComplete(array $document, ?array $parsedDocument): bool {
        $incomingStatus = strtolower(trim((string)($document['parse_status'] ?? '')));
        if ($parsedDocument === null
            || !in_array($incomingStatus, ['parsed', 'parsed_latest_year'], true)
            || (int)($parsedDocument['summary']['latest_year_context_count'] ?? 0) <= 0
            || (int)($parsedDocument['summary']['latest_year_fact_count'] ?? 0) <= 0) {
            return false;
        }

        $latestContextRefs = [];
        foreach ((array)($parsedDocument['contexts'] ?? []) as $context) {
            $contextRef = trim((string)($context['context_ref'] ?? ''));
            if ($contextRef !== '' && !empty($context['is_latest_year_context'])) {
                $latestContextRefs[$contextRef] = true;
            }
        }

        foreach ((array)($parsedDocument['facts'] ?? []) as $fact) {
            $contextRef = trim((string)($fact['context_ref'] ?? ''));
            if (!empty($fact['is_latest_year_fact']) && isset($latestContextRefs[$contextRef])) {
                return true;
            }
        }

        return false;
    }

    private function upsertDocumentRow(array $document): int {
        $sql = 'INSERT INTO companies_house_documents (
                    company_id,
                    company_number,
                    transaction_id,
                    filing_date,
                    filing_type,
                    filing_category,
                    filing_description,
                    document_id,
                    metadata_url,
                    content_url,
                    final_content_url,
                    content_type,
                    filename,
                    classification,
                    significant_date,
                    significant_date_type,
                    pages,
                    created_at_utc,
                    fetched_at_utc,
                    raw_metadata_json,
                    raw_content_hash,
                    parse_status,
                    parse_error
                ) VALUES (
                    :company_id,
                    :company_number,
                    :transaction_id,
                    :filing_date,
                    :filing_type,
                    :filing_category,
                    :filing_description,
                    :document_id,
                    :metadata_url,
                    :content_url,
                    :final_content_url,
                    :content_type,
                    :filename,
                    :classification,
                    :significant_date,
                    :significant_date_type,
                    :pages,
                    :created_at_utc,
                    :fetched_at_utc,
                    :raw_metadata_json,
                    :raw_content_hash,
                    :parse_status,
                    :parse_error
                )';
        $sql .= \InterfaceDB::driverName() === 'sqlite'
            ? ' ON CONFLICT(document_id) DO UPDATE SET
                    company_id = excluded.company_id,
                    company_number = excluded.company_number,
                    transaction_id = excluded.transaction_id,
                    filing_date = excluded.filing_date,
                    filing_type = excluded.filing_type,
                    filing_category = excluded.filing_category,
                    filing_description = excluded.filing_description,
                    metadata_url = excluded.metadata_url,
                    content_url = excluded.content_url,
                    final_content_url = excluded.final_content_url,
                    content_type = excluded.content_type,
                    filename = excluded.filename,
                    classification = excluded.classification,
                    significant_date = excluded.significant_date,
                    significant_date_type = excluded.significant_date_type,
                    pages = excluded.pages,
                    created_at_utc = excluded.created_at_utc,
                    fetched_at_utc = excluded.fetched_at_utc,
                    raw_metadata_json = excluded.raw_metadata_json,
                    raw_content_hash = excluded.raw_content_hash,
                    parse_status = excluded.parse_status,
                    parse_error = excluded.parse_error'
            : ' ON DUPLICATE KEY UPDATE
                    company_id = VALUES(company_id),
                    company_number = VALUES(company_number),
                    transaction_id = VALUES(transaction_id),
                    filing_date = VALUES(filing_date),
                    filing_type = VALUES(filing_type),
                    filing_category = VALUES(filing_category),
                    filing_description = VALUES(filing_description),
                    metadata_url = VALUES(metadata_url),
                    content_url = VALUES(content_url),
                    final_content_url = VALUES(final_content_url),
                    content_type = VALUES(content_type),
                    filename = VALUES(filename),
                    classification = VALUES(classification),
                    significant_date = VALUES(significant_date),
                    significant_date_type = VALUES(significant_date_type),
                    pages = VALUES(pages),
                    created_at_utc = VALUES(created_at_utc),
                    fetched_at_utc = VALUES(fetched_at_utc),
                    raw_metadata_json = VALUES(raw_metadata_json),
                    raw_content_hash = VALUES(raw_content_hash),
                    parse_status = VALUES(parse_status),
                    parse_error = VALUES(parse_error)';

        $stmt = \InterfaceDB::prepare($sql);
        $stmt->execute([
            'company_id' => $this->nullableInt($document['company_id'] ?? null),
            'company_number' => $this->requiredString($document['company_number'] ?? null, 'company_number'),
            'transaction_id' => $this->requiredString($document['transaction_id'] ?? null, 'transaction_id'),
            'filing_date' => $this->nullableString($document['filing_date'] ?? null),
            'filing_type' => $this->nullableString($document['filing_type'] ?? null),
            'filing_category' => $this->nullableString($document['filing_category'] ?? null),
            'filing_description' => $this->nullableString($document['filing_description'] ?? null),
            'document_id' => $this->requiredString($document['document_id'] ?? null, 'document_id'),
            'metadata_url' => $this->requiredString($document['metadata_url'] ?? null, 'metadata_url'),
            'content_url' => $this->nullableString($document['content_url'] ?? null),
            'final_content_url' => $this->nullableString($document['final_content_url'] ?? null),
            'content_type' => $this->nullableString($document['content_type'] ?? null),
            'filename' => $this->nullableString($document['filename'] ?? null),
            'classification' => $this->nullableString($document['classification'] ?? null),
            'significant_date' => $this->nullableString($document['significant_date'] ?? null),
            'significant_date_type' => $this->nullableString($document['significant_date_type'] ?? null),
            'pages' => $this->nullableInt($document['pages'] ?? null),
            'created_at_utc' => $this->nullableString($document['created_at_utc'] ?? null),
            'fetched_at_utc' => $this->nullableString($document['fetched_at_utc'] ?? gmdate('Y-m-d H:i:s')),
            'raw_metadata_json' => $this->nullableString($document['raw_metadata_json'] ?? null),
            'raw_content_hash' => $this->nullableString($document['raw_content_hash'] ?? null),
            'parse_status' => $this->nullableString($document['parse_status'] ?? null),
            'parse_error' => $this->nullableString($document['parse_error'] ?? null),
        ]);

        return $this->findDocumentRowId((string)$document['document_id']);
    }

    private function replaceDocumentContextsAndFacts(int $documentRowId, array $parsedDocument): void {
        $deleteFacts = \InterfaceDB::prepare('DELETE FROM companies_house_document_facts WHERE document_fk = ?');
        $deleteFacts->execute([$documentRowId]);

        $deleteContexts = \InterfaceDB::prepare('DELETE FROM companies_house_document_contexts WHERE document_fk = ?');
        $deleteContexts->execute([$documentRowId]);

        $contextInsert = \InterfaceDB::prepare(
            'INSERT INTO companies_house_document_contexts (
                document_fk,
                context_ref,
                period_start,
                period_end,
                instant_date,
                is_latest_year_context,
                dimension_json,
                created_at_utc
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $contextIdsByRef = [];

        foreach ((array)($parsedDocument['contexts'] ?? []) as $context) {
            $contextInsert->execute([
                $documentRowId,
                $this->requiredString($context['context_ref'] ?? null, 'context_ref'),
                $this->nullableString($context['period_start'] ?? null),
                $this->nullableString($context['period_end'] ?? null),
                $this->nullableString($context['instant_date'] ?? null),
                !empty($context['is_latest_year_context']) ? 1 : 0,
                $this->nullableString($context['dimension_json'] ?? null),
                gmdate('Y-m-d H:i:s'),
            ]);

            $contextIdsByRef[(string)$context['context_ref']] = $this->findContextRowId($documentRowId, (string)$context['context_ref']);
        }

        $factInsert = \InterfaceDB::prepare(
            'INSERT INTO companies_house_document_facts (
                document_fk,
                context_fk,
                concept_fk,
                fact_name,
                raw_value,
                normalised_numeric,
                normalised_text,
                normalised_date,
                unit_ref,
                decimals_value,
                sign_hint,
                is_numeric,
                is_latest_year_fact,
                created_at_utc
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ((array)($parsedDocument['facts'] ?? []) as $fact) {
            $contextRef = (string)($fact['context_ref'] ?? '');

            if ($contextRef === '' || !isset($contextIdsByRef[$contextRef])) {
                continue;
            }

            $conceptId = $this->upsertConcept([
                'concept_name' => $fact['concept_name'] ?? '',
                'short_name' => $fact['short_name'] ?? null,
                'friendly_label' => $fact['concept_friendly_label'] ?? $fact['friendly_label'] ?? null,
                'value_type' => $fact['value_type'] ?? null,
            ]);

            $factInsert->execute([
                $documentRowId,
                $contextIdsByRef[$contextRef],
                $conceptId,
                $this->nullableString($fact['fact_name'] ?? null),
                $this->rawValueForStorage($fact['raw_value'] ?? null),
                $this->nullableDecimal($fact['normalised_numeric'] ?? null),
                $this->nullableString($fact['normalised_text'] ?? null),
                $this->nullableString($fact['normalised_date'] ?? null),
                $this->nullableString($fact['unit_ref'] ?? null),
                $this->nullableString($fact['decimals_value'] ?? null),
                $this->nullableString($fact['sign_hint'] ?? null),
                !empty($fact['is_numeric']) ? 1 : 0,
                !empty($fact['is_latest_year_fact']) ? 1 : 0,
                gmdate('Y-m-d H:i:s'),
            ]);
        }
    }

    private function upsertConcept(array $concept): int {
        $sql = 'INSERT INTO companies_house_taxonomy_concepts (
                concept_name,
                short_name,
                friendly_label,
                value_type,
                created_at_utc
            ) VALUES (?, ?, ?, ?, ?)
            ';
        $sql .= \InterfaceDB::driverName() === 'sqlite'
            ? 'ON CONFLICT(concept_name) DO UPDATE SET
                short_name = COALESCE(NULLIF(excluded.short_name, \'\'), short_name),
                friendly_label = COALESCE(NULLIF(excluded.friendly_label, \'\'), friendly_label),
                value_type = COALESCE(NULLIF(excluded.value_type, \'\'), value_type)'
            : 'ON DUPLICATE KEY UPDATE
                short_name = COALESCE(NULLIF(VALUES(short_name), \'\'), short_name),
                friendly_label = COALESCE(NULLIF(VALUES(friendly_label), \'\'), friendly_label),
                value_type = COALESCE(NULLIF(VALUES(value_type), \'\'), value_type)';
        $stmt = \InterfaceDB::prepare($sql);

        $stmt->execute([
            $this->requiredString($concept['concept_name'] ?? null, 'concept_name'),
            $this->nullableString($concept['short_name'] ?? null),
            $this->nullableString($concept['friendly_label'] ?? null),
            $this->nullableString($concept['value_type'] ?? null),
            gmdate('Y-m-d H:i:s'),
        ]);

        $find = \InterfaceDB::prepare('SELECT id FROM companies_house_taxonomy_concepts WHERE concept_name = ?');
        $find->execute([(string)$concept['concept_name']]);
        $id = $find->fetchColumn();

        if ($id === false) {
            throw new \RuntimeException('Unable to resolve taxonomy concept id for ' . (string)$concept['concept_name'] . '.');
        }

        return (int)$id;
    }

    private function findDocumentRowId(string $documentId): int {
        $find = \InterfaceDB::prepare('SELECT id FROM companies_house_documents WHERE document_id = ?');
        $find->execute([$documentId]);
        $id = $find->fetchColumn();

        if ($id === false) {
            throw new \RuntimeException('Unable to resolve stored document id for ' . $documentId . '.');
        }

        return (int)$id;
    }

    private function findContextRowId(int $documentRowId, string $contextRef): int {
        $find = \InterfaceDB::prepare('SELECT id FROM companies_house_document_contexts WHERE document_fk = ? AND context_ref = ?');
        $find->execute([$documentRowId, $contextRef]);
        $id = $find->fetchColumn();

        if ($id === false) {
            throw new \RuntimeException('Unable to resolve stored context id for ' . $contextRef . '.');
        }

        return (int)$id;
    }

    private function requiredString(mixed $value, string $field): string {
        $value = trim((string)$value);

        if ($value === '') {
            throw new \InvalidArgumentException('Missing required field: ' . $field);
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string {
        $value = trim((string)$value);

        return $value === '' ? null : $value;
    }

    /**
     * raw_value is part of a VARCHAR(255) uniqueness key. Text facts retain
     * their complete value in normalised_text. ODBC bindings may enforce this
     * boundary in bytes, so oversized values use an ASCII-only full digest.
     */
    private function rawValueForStorage(mixed $value): ?string {
        $value = $this->nullableString($value);
        if ($value === null || strlen($value) <= self::RAW_VALUE_MAX_BYTES) {
            return $value;
        }

        return self::RAW_VALUE_HASH_MARKER . hash('sha256', $value) . ']';
    }

    private function nullableInt(mixed $value): ?int {
        if ($value === null || $value === '') {
            return null;
        }

        return (int)$value;
    }

    private function nullableDecimal(mixed $value): ?string {
        $value = trim((string)$value);

        if ($value === '') {
            return null;
        }

        return $value;
    }
}
