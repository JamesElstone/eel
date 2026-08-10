<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/**
 * Resolves the immutable Companies House document history for one accounting
 * period without allowing a later amendment to replace the original filing.
 */
final class CompaniesHousePeriodFilingResolverService
{
    private const REVISION_MARKER = 'ReportAnAmendedRevisedVersionPreviouslyFiledReportTruefalse';

    public function __construct(
        private readonly ?CompaniesHouseStoredDataService $storedDataService = null,
    ) {
    }

    /**
     * @param list<array<string,mixed>>|null $summaries
     * @return array{
     *     original:?array,
     *     revisions:list<array>,
     *     latest_revision:?array,
     *     latest_filed:?array,
     *     effective:?array,
     *     pinned_original_document_id:?int,
     *     pin_source:string
     * }
     */
    public function resolve(
        int $companyId,
        int $accountingPeriodId,
        string $companyNumber,
        string $periodEnd,
        ?array $summaries = null
    ): array {
        $companyNumber = strtoupper(trim($companyNumber));
        $periodEnd = trim($periodEnd);
        if ($companyId <= 0 || $accountingPeriodId <= 0 || $companyNumber === '' || $periodEnd === '') {
            return $this->emptyResolution();
        }

        $summaries ??= ($this->storedDataService ?? new CompaniesHouseStoredDataService())
            ->fetchDocumentSummariesByCompanyNumber($companyNumber);
        $summaries = $this->enrichSummaries($summaries);

        return $this->resolveSummaries(
            $summaries,
            $periodEnd,
            $this->pinnedOriginalCandidates($companyId, $accountingPeriodId)
        );
    }

    /**
     * Pure selection branch used by focused tests and callers that already
     * hold a Companies House document summary set.
     *
     * @param list<array<string,mixed>> $summaries
     * @param list<array{document_id:int,source:string}|int> $pinnedOriginalCandidates
     * @return array{
     *     original:?array,
     *     revisions:list<array>,
     *     latest_revision:?array,
     *     latest_filed:?array,
     *     effective:?array,
     *     pinned_original_document_id:?int,
     *     pin_source:string
     * }
     */
    public function resolveSummaries(
        array $summaries,
        string $periodEnd,
        array $pinnedOriginalCandidates = []
    ): array {
        $periodEnd = trim($periodEnd);
        $exact = [];
        foreach ($summaries as $summary) {
            if (!is_array($summary)) {
                continue;
            }
            $document = $this->normaliseSummary($summary);
            if ((string)$document['period_end'] !== $periodEnd || (int)$document['id'] <= 0) {
                continue;
            }
            $exact[(int)$document['id']] = $document;
        }

        $original = null;
        $pinnedDocumentId = null;
        $pinSource = '';
        foreach ($pinnedOriginalCandidates as $candidate) {
            $candidateId = is_array($candidate)
                ? (int)($candidate['document_id'] ?? 0)
                : (int)$candidate;
            if ($candidateId <= 0 || !isset($exact[$candidateId])) {
                continue;
            }
            $original = $exact[$candidateId];
            $pinnedDocumentId = $candidateId;
            $pinSource = is_array($candidate) ? trim((string)($candidate['source'] ?? '')) : 'provided';
            break;
        }

        if ($original === null) {
            $originals = array_values(array_filter(
                $exact,
                static fn(array $document): bool => empty($document['is_revision'])
                    && strtoupper(trim((string)($document['filing_type'] ?? ''))) === 'AA'
            ));
            usort($originals, fn(array $left, array $right): int => $this->compareOldestFirst($left, $right));
            $original = $originals[0] ?? null;
        }

        $originalId = (int)($original['id'] ?? 0);
        $revisions = array_values(array_filter(
            $exact,
            static fn(array $document): bool => !empty($document['is_revision'])
                && (int)$document['id'] !== $originalId
        ));
        $revisions = $this->applyMissingMetadataOrderingFailSafe($revisions);
        usort($revisions, fn(array $left, array $right): int => $this->compareNewestFirst($left, $right));
        $latestRevision = $revisions[0] ?? null;

        return [
            'original' => $original,
            'revisions' => $revisions,
            'latest_revision' => $latestRevision,
            'latest_filed' => $latestRevision ?? $original,
            'effective' => $latestRevision ?? $original,
            'pinned_original_document_id' => $pinnedDocumentId,
            'pin_source' => $pinSource,
        ];
    }

    /** @param array<string,mixed> $summary @return array<string,mixed> */
    private function normaliseSummary(array $summary): array
    {
        $id = (int)($summary['id'] ?? $summary['document_row_id'] ?? 0);
        $periodStart = trim((string)($summary['period_start'] ?? $summary['latest_year_period_start'] ?? ''));
        $periodEnd = trim((string)(
            $summary['period_end']
            ?? $summary['latest_year_period_end']
            ?? $summary['balance_sheet_date']
            ?? ''
        ));
        $filingType = trim((string)($summary['filing_type'] ?? ''));
        $filingDescription = trim((string)($summary['filing_description'] ?? ''));
        $rawMetadata = json_decode((string)($summary['raw_metadata_json'] ?? ''), true);
        $rawMetadata = is_array($rawMetadata) ? $rawMetadata : [];
        $significantDate = $this->dateOnly((string)(
            $summary['significant_date']
            ?? $rawMetadata['significant_date']
            ?? ''
        ));
        $metadataCreatedAt = trim((string)(
            $rawMetadata['created_at']
            ?? $summary['metadata_created_at']
            ?? $summary['created_at_utc']
            ?? ''
        ));
        $historyOrderValue = $rawMetadata['_eel_filing_history_order']
            ?? (($rawMetadata['filing_history'] ?? [])['filing_history_order'] ?? null)
            ?? ($summary['filing_history_order'] ?? null);
        $filingHistoryOrder = is_numeric($historyOrderValue) && (int)$historyOrderValue >= 0
            ? (int)$historyOrderValue
            : null;

        if ($periodEnd === '') {
            $periodEnd = $significantDate;
        }

        $document = [
            'id' => $id,
            'document_row_id' => $id,
            'document_id' => trim((string)($summary['document_id'] ?? '')),
            'transaction_id' => trim((string)($summary['transaction_id'] ?? '')),
            'filing_date' => trim((string)($summary['filing_date'] ?? '')),
            'filing_type' => $filingType,
            'filing_category' => trim((string)($summary['filing_category'] ?? '')),
            'filing_description' => $filingDescription,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'significant_date' => $significantDate,
            'metadata_created_at' => $metadataCreatedAt,
            'filing_history_order' => $filingHistoryOrder,
            'parse_status' => trim((string)($summary['parse_status'] ?? '')),
            'parse_error' => trim((string)($summary['parse_error'] ?? '')),
            'is_revision' => $this->isRevision($filingType, $filingDescription, $summary['revision_marker'] ?? false),
        ];
        $document['effective_timestamp'] = $this->effectiveTimestamp($document);

        return $document;
    }

    private function isRevision(string $filingType, string $description, mixed $marker): bool
    {
        $filingType = strtoupper(trim($filingType));
        $description = strtolower(trim($description));

        return $filingType === 'AAMD'
            || str_contains($description, 'accounts-amended')
            || str_contains($description, 'amended-accounts')
            || $this->truthy($marker);
    }

    /**
     * Companies House can expose a same-day filing-history entry before its
     * document metadata is available. In that case the missing creation time
     * must not make the failed placeholder look older than a parsed peer.
     * Collapse that filing-date cohort onto the documented date so the stable
     * row-id fallback decides its order and the newest unknown stays visible.
     *
     * @param list<array<string,mixed>> $revisions
     * @return list<array<string,mixed>>
     */
    private function applyMissingMetadataOrderingFailSafe(array $revisions): array
    {
        $datesWithMissingMetadata = [];
        foreach ($revisions as $revision) {
            $filingDate = $this->dateOnly((string)($revision['filing_date'] ?? ''));
            if ($filingDate !== '' && trim((string)($revision['metadata_created_at'] ?? '')) === '') {
                $datesWithMissingMetadata[$filingDate] = true;
            }
        }

        foreach ($revisions as &$revision) {
            $filingDate = $this->dateOnly((string)($revision['filing_date'] ?? ''));
            if ($filingDate !== '' && isset($datesWithMissingMetadata[$filingDate])) {
                $revision['effective_timestamp'] = $filingDate . 'T00:00:00Z';
            }
        }
        unset($revision);

        return $revisions;
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function compareNewestFirst(array $left, array $right): int
    {
        $effectiveTimestamp = strcmp(
            (string)($right['effective_timestamp'] ?? $this->effectiveTimestamp($right)),
            (string)($left['effective_timestamp'] ?? $this->effectiveTimestamp($left))
        );
        if ($effectiveTimestamp !== 0) {
            return $effectiveTimestamp;
        }
        $date = strcmp((string)$right['filing_date'], (string)$left['filing_date']);
        if ($date !== 0) {
            return $date;
        }
        $leftHistoryOrder = $left['filing_history_order'] ?? null;
        $rightHistoryOrder = $right['filing_history_order'] ?? null;
        if (is_int($leftHistoryOrder)
            && is_int($rightHistoryOrder)
            && $leftHistoryOrder !== $rightHistoryOrder) {
            return $leftHistoryOrder <=> $rightHistoryOrder;
        }
        $transaction = strcmp(
            (string)($right['transaction_id'] ?? ''),
            (string)($left['transaction_id'] ?? '')
        );
        return $transaction !== 0 ? $transaction : ((int)$right['id'] <=> (int)$left['id']);
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function compareOldestFirst(array $left, array $right): int
    {
        $effectiveTimestamp = strcmp(
            (string)($left['effective_timestamp'] ?? $this->effectiveTimestamp($left)),
            (string)($right['effective_timestamp'] ?? $this->effectiveTimestamp($right))
        );
        if ($effectiveTimestamp !== 0) {
            return $effectiveTimestamp;
        }
        $date = strcmp((string)$left['filing_date'], (string)$right['filing_date']);
        return $date !== 0 ? $date : ((int)$left['id'] <=> (int)$right['id']);
    }

    /**
     * @param list<array<string,mixed>> $summaries
     * @return list<array<string,mixed>>
     */
    private function enrichSummaries(array $summaries): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn(array $summary): int => (int)($summary['id'] ?? $summary['document_row_id'] ?? 0),
            array_filter($summaries, 'is_array')
        ))));
        if ($ids === [] || !\InterfaceDB::tableExists('companies_house_documents')) {
            return $summaries;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $attributes = [];
        foreach (\InterfaceDB::fetchAll(
            'SELECT id, transaction_id, significant_date, created_at_utc,
                    raw_metadata_json, parse_error
             FROM companies_house_documents
             WHERE id IN (' . $placeholders . ')',
            $ids
        ) as $row) {
            $attributes[(int)$row['id']] = $row;
        }

        $revisionMarkers = [];
        if (\InterfaceDB::tableExists('companies_house_document_facts')
            && \InterfaceDB::tableExists('companies_house_taxonomy_concepts')) {
            $params = array_merge($ids, [self::REVISION_MARKER]);
            foreach (\InterfaceDB::fetchAll(
                'SELECT f.document_fk, f.raw_value, f.normalised_text
                 FROM companies_house_document_facts f
                 INNER JOIN companies_house_taxonomy_concepts c ON c.id = f.concept_fk
                 WHERE f.document_fk IN (' . $placeholders . ')
                   AND c.short_name = ?',
                $params
            ) as $row) {
                if ($this->truthy($row['normalised_text'] ?? $row['raw_value'] ?? false)) {
                    $revisionMarkers[(int)$row['document_fk']] = true;
                }
            }
        }

        foreach ($summaries as &$summary) {
            if (!is_array($summary)) {
                continue;
            }
            $id = (int)($summary['id'] ?? $summary['document_row_id'] ?? 0);
            $summary = array_replace($summary, (array)($attributes[$id] ?? []));
            $summary['revision_marker'] = !empty($revisionMarkers[$id]);
        }
        unset($summary);

        return $summaries;
    }

    /** @return list<array{document_id:int,source:string}> */
    private function pinnedOriginalCandidates(int $companyId, int $accountingPeriodId): array
    {
        $candidates = [];
        if (\InterfaceDB::tableExists('year_end_review_acknowledgements')) {
            $rows = \InterfaceDB::fetchAll(
                'SELECT basis_json
                 FROM year_end_review_acknowledgements
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                   AND check_code IN (:mismatch_check, :no_filing_check)
                 ORDER BY acknowledged_at DESC, id DESC',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'mismatch_check' => 'companies_house_mismatch_acknowledgement',
                    'no_filing_check' => 'companies_house_no_filing_acknowledgement',
                ]
            );
            foreach ($rows as $row) {
                $basis = json_decode((string)($row['basis_json'] ?? ''), true);
                if (!is_array($basis)) {
                    continue;
                }
                $documentId = $this->approvalDocumentId($basis);
                $this->appendPin($candidates, $documentId, 'year_end_approval');
            }
        }

        if (\InterfaceDB::tableExists('companies_house_accounts_eligibility')) {
            foreach (\InterfaceDB::fetchAll(
                'SELECT original_document_id
                 FROM companies_house_accounts_eligibility
                 WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id
                   AND original_document_id IS NOT NULL
                 ORDER BY updated_at DESC, id DESC',
                ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
            ) as $row) {
                $this->appendPin($candidates, (int)($row['original_document_id'] ?? 0), 'eligibility');
            }
        }

        if (\InterfaceDB::tableExists('companies_house_accounts_submissions')) {
            foreach (\InterfaceDB::fetchAll(
                'SELECT original_document_id
                 FROM companies_house_accounts_submissions
                 WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id
                   AND original_document_id IS NOT NULL
                 ORDER BY CASE WHEN lifecycle = :accepted THEN 0 ELSE 1 END,
                          accepted_at DESC, id DESC',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'accepted' => 'accepted',
                ]
            ) as $row) {
                $this->appendPin($candidates, (int)($row['original_document_id'] ?? 0), 'submission');
            }
        }

        return $candidates;
    }

    /** @param list<array{document_id:int,source:string}> $candidates */
    private function appendPin(array &$candidates, int $documentId, string $source): void
    {
        if ($documentId <= 0) {
            return;
        }
        foreach ($candidates as $candidate) {
            if ((int)$candidate['document_id'] === $documentId) {
                return;
            }
        }
        $candidates[] = ['document_id' => $documentId, 'source' => $source];
    }

    /** @param array<string,mixed> $basis */
    private function approvalDocumentId(array $basis): int
    {
        return (int)(
            ($basis['facts']['filing']['document_row_id'] ?? null)
            ?? ($basis['facts']['filing']['id'] ?? null)
            ?? ($basis['facts']['filing_evidence']['document_row_id'] ?? null)
            ?? ($basis['facts']['filing_evidence']['id'] ?? null)
            ?? 0
        );
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes'], true);
    }

    private function dateOnly(string $value): string
    {
        $value = trim($value);
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1
            ? substr($value, 0, 10)
            : '';
    }

    /** @param array<string,mixed> $document */
    private function effectiveTimestamp(array $document): string
    {
        $metadataCreatedAt = trim((string)($document['metadata_created_at'] ?? ''));
        if ($metadataCreatedAt !== '') {
            return str_replace(' ', 'T', $metadataCreatedAt);
        }

        $filingDate = $this->dateOnly((string)($document['filing_date'] ?? ''));
        return $filingDate !== '' ? $filingDate . 'T00:00:00Z' : '';
    }

    /** @return array{original:null,revisions:list<array>,latest_revision:null,latest_filed:null,effective:null,pinned_original_document_id:null,pin_source:string} */
    private function emptyResolution(): array
    {
        return [
            'original' => null,
            'revisions' => [],
            'latest_revision' => null,
            'latest_filed' => null,
            'effective' => null,
            'pinned_original_document_id' => null,
            'pin_source' => '',
        ];
    }
}
