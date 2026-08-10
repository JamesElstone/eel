<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class CompaniesHouseAccountsIngestionService
{
    private readonly \eel_accounts\Service\CompaniesHouseFilingService $filingService;
    private readonly \eel_accounts\Service\CompaniesHouseDocumentService $documentService;
    private readonly \eel_accounts\Service\IxbrlParserService $ixbrlParser;
    private readonly \eel_accounts\Service\CompaniesHousePersistenceService $persistenceService;

    public function __construct(
        private readonly string $environment = 'TEST',
        private readonly int $timeoutSeconds = 20,
        ?CompaniesHouseFilingService $filingService = null,
        ?CompaniesHouseDocumentService $documentService = null,
        ?IxbrlParserService $ixbrlParser = null,
        ?CompaniesHousePersistenceService $persistenceService = null,
    ) {
        $companiesHouseService = new \eel_accounts\Service\CompaniesHouseService($this->environment, $this->timeoutSeconds);
        $this->filingService = $filingService
            ?? new \eel_accounts\Service\CompaniesHouseFilingService($companiesHouseService);
        $this->documentService = $documentService
            ?? new \eel_accounts\Service\CompaniesHouseDocumentService($this->environment, $this->timeoutSeconds);
        $this->ixbrlParser = $ixbrlParser ?? new \eel_accounts\Service\IxbrlParserService();
        $this->persistenceService = $persistenceService
            ?? new \eel_accounts\Service\CompaniesHousePersistenceService();
    }

    public function ingestForCompany(int $companyId, string $companyNumber): array {
        if ($companyId <= 0) {
            throw new \InvalidArgumentException('A valid company id is required before Companies House accounts can be ingested.');
        }

        $companyNumber = strtoupper(trim($companyNumber));

        if ($companyNumber === '') {
            return [
                'company_id' => $companyId,
                'company_number' => '',
                'candidate_count' => 0,
                'stored_document_count' => 0,
                'parsed_document_count' => 0,
                'failed_document_count' => 0,
                'documents' => [],
            ];
        }

        $candidates = $this->filingService->fetchAccountsDocumentCandidates($companyId);
        $results = [];
        $storedDocumentCount = 0;
        $parsedDocumentCount = 0;
        $failedDocumentCount = 0;

        foreach ($candidates as $candidate) {
            try {
                $result = $this->ingestCandidate($companyId, $companyNumber, $candidate);
                $storedDocumentCount++;

                if (($result['parse_status'] ?? '') === 'parsed_latest_year') {
                    $parsedDocumentCount++;
                } elseif (($result['parse_status'] ?? '') !== 'stored_document_only') {
                    $failedDocumentCount++;
                }

                $results[] = $result;
            } catch (\Throwable $e) {
                $failedDocumentCount++;
                $results[] = [
                    'transaction_id' => (string)($candidate['transaction_id'] ?? ''),
                    'filing_date' => (string)($candidate['date'] ?? ''),
                    'filing_type' => (string)($candidate['type'] ?? ''),
                    'significant_date' => $this->candidateSignificantDate($candidate),
                    'document_id' => $this->extractDocumentId((string)($candidate['document_metadata_path'] ?? '')),
                    'classification' => '',
                    'parse_status' => 'ingest_failed',
                    'parse_error' => $e->getMessage(),
                    'latest_year_context_count' => 0,
                    'latest_year_fact_count' => 0,
                ];
            }
        }

        return [
            'company_id' => $companyId,
            'company_number' => $companyNumber,
            'candidate_count' => count($candidates),
            'stored_document_count' => $storedDocumentCount,
            'parsed_document_count' => $parsedDocumentCount,
            'failed_document_count' => $failedDocumentCount,
            'documents' => $results,
        ];
    }

    private function ingestCandidate(int $companyId, string $companyNumber, array $candidate): array {
        $metadataException = '';
        try {
            $metadata = $this->documentService->fetchMetadata(
                (string)($candidate['document_metadata_path'] ?? '')
            );
        } catch (\Throwable $exception) {
            $metadataException = $exception->getMessage();
            $metadata = [
                'status' => 0,
                'url' => $this->documentService->absoluteUrl(
                    (string)($candidate['document_metadata_path'] ?? '')
                ),
                'body' => '',
                'content_types' => [],
                'classification' => 'metadata_only_unknown',
            ];
        }
        $content = null;
        $parsed = null;
        $parseStatus = 'stored_document_only';
        $parseError = null;

        if ((int)($metadata['status'] ?? 0) !== 200) {
            $parseStatus = 'metadata_fetch_failed';
            $parseError = $metadataException !== ''
                ? 'Document metadata request failed: ' . $metadataException
                : 'Document metadata request returned HTTP ' . (int)($metadata['status'] ?? 0) . '.';
            $metadata = $this->metadataFailurePlaceholder($metadata, $candidate, $parseError);
        } elseif (($metadata['classification'] ?? '') === 'digital_xhtml') {
            try {
                $content = $this->documentService->fetchPreferredContent($metadata);

                if ($content === null) {
                    $parseStatus = 'content_unavailable';
                    $parseError = 'No preferred XHTML/iXBRL content URL was available for this filing.';
                } elseif ((int)($content['status'] ?? 0) < 200 || (int)($content['status'] ?? 0) >= 300) {
                    $parseStatus = 'content_fetch_failed';
                    $parseError = 'The preferred XHTML/iXBRL content request returned HTTP ' . (int)($content['status'] ?? 0) . '.';
                } elseif ($this->contentLooksLikeXhtml($content)) {
                    $parsed = $this->ixbrlParser->parse((string)($content['body'] ?? ''));
                    $parseStatus = (($parsed['summary']['latest_year_fact_count'] ?? 0) > 0)
                        ? 'parsed_latest_year'
                        : 'parsed_no_latest_year_facts';
                } else {
                    $parseStatus = 'content_not_xhtml';
                    $parseError = 'The preferred document content did not look like XHTML/iXBRL.';
                }
            } catch (\Throwable $e) {
                $parseStatus = 'parse_failed';
                $parseError = $e->getMessage();
            }
        }

        $documentId = trim((string)($metadata['document_id'] ?? ''));
        $metadataUrl = trim((string)($metadata['url'] ?? ''));

        if ($documentId === '') {
            $documentId = $this->extractDocumentId((string)($candidate['document_metadata_path'] ?? ''));
        }

        if ($metadataUrl === '') {
            $metadataUrl = $this->documentService->absoluteUrl((string)($candidate['document_metadata_path'] ?? ''));
        }

        $preferredContentType = $this->documentService->choosePreferredContentType((array)($metadata['content_types'] ?? []));
        $significantDate = \HelperFramework::normaliseDate((string)($metadata['significant_date'] ?? ''));
        if (trim((string)$significantDate) === '') {
            $significantDate = $this->candidateSignificantDate($candidate);
        }
        $significantDateType = trim((string)($metadata['significant_date_type'] ?? ''));
        if ($significantDateType === '' && trim((string)$significantDate) !== '') {
            $significantDateType = trim((string)($candidate['significant_date_type'] ?? 'made-up-date'));
        }
        $documentRow = [
            'company_id' => $companyId,
            'company_number' => $companyNumber,
            'transaction_id' => (string)($candidate['transaction_id'] ?? ''),
            'filing_date' => \HelperFramework::normaliseDate((string)($candidate['date'] ?? '')),
            'filing_type' => (string)($candidate['type'] ?? ''),
            'filing_category' => (string)($candidate['category'] ?? ''),
            'filing_description' => (string)($candidate['description'] ?? ''),
            'document_id' => $documentId,
            'metadata_url' => $metadataUrl,
            'content_url' => $content !== null
                ? (string)($content['requested_url'] ?? '')
                : $this->documentService->absoluteUrl((string)($metadata['content_url'] ?? '')),
            'final_content_url' => $content !== null ? (string)($content['final_url'] ?? '') : null,
            'content_type' => $content !== null
                ? (string)($content['response_content_type'] ?? $preferredContentType)
                : $preferredContentType,
            'filename' => (string)($metadata['filename'] ?? ''),
            'classification' => (string)($metadata['classification'] ?? ''),
            'significant_date' => $significantDate,
            'significant_date_type' => $significantDateType,
            'pages' => $metadata['pages'] ?? $candidate['pages'] ?? null,
            'created_at_utc' => \HelperFramework::normaliseUtcDateTime((string)($metadata['created_at'] ?? '')),
            'fetched_at_utc' => gmdate('Y-m-d H:i:s'),
            'raw_metadata_json' => $this->metadataJsonForPersistence($metadata, $candidate),
            'raw_content_hash' => $content !== null ? hash('sha256', (string)($content['body'] ?? '')) : null,
            'parse_status' => $parseStatus,
            'parse_error' => $parseError,
        ];

        $persisted = $this->persistenceService->persistDocument($documentRow, $parsed);

        return [
            'transaction_id' => (string)($candidate['transaction_id'] ?? ''),
            'filing_date' => (string)($candidate['date'] ?? ''),
            'filing_type' => (string)($candidate['type'] ?? ''),
            'significant_date' => (string)$significantDate,
            'document_id' => $documentId,
            'classification' => (string)($metadata['classification'] ?? ''),
            'parse_status' => $parseStatus,
            'parse_error' => $parseError,
            'latest_year_context_count' => (int)($persisted['latest_year_context_count'] ?? 0),
            'latest_year_fact_count' => (int)($persisted['latest_year_fact_count'] ?? 0),
        ];
    }

    private function contentLooksLikeXhtml(array $content): bool {
        $responseContentType = strtolower(trim((string)($content['response_content_type'] ?? '')));

        if (
            str_contains($responseContentType, 'xhtml')
            || str_contains($responseContentType, 'html')
            || str_contains($responseContentType, 'xml')
            || str_contains($responseContentType, 'ixbrl')
            || str_contains($responseContentType, 'xbrl')
        ) {
            return true;
        }

        $bodyStart = substr((string)($content['body'] ?? ''), 0, 2000);

        return preg_match('/<(?:\?xml|html|ix:|xbrli:)/i', $bodyStart) === 1;
    }

    private function extractDocumentId(string $metadataPathOrUrl): string {
        $path = (string)parse_url($metadataPathOrUrl, PHP_URL_PATH);

        if (preg_match('#/document/([^/]+)#', $path, $matches) === 1) {
            return trim((string)$matches[1]);
        }

        return '';
    }

    /** @param array<string,mixed> $candidate */
    private function candidateSignificantDate(array $candidate): string
    {
        $descriptionValues = is_array($candidate['description_values'] ?? null)
            ? (array)$candidate['description_values']
            : [];
        return (string)(\HelperFramework::normaliseDate((string)(
            $candidate['significant_date']
            ?? $candidate['action_date']
            ?? ($descriptionValues['made_up_date'] ?? '')
        )) ?? '');
    }

    /** @param array<string,mixed> $metadata @param array<string,mixed> $candidate */
    private function metadataJsonForPersistence(array $metadata, array $candidate): string
    {
        $body = (string)($metadata['body'] ?? '');
        if (!array_key_exists('filing_history_order', $candidate)) {
            return $body;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return $body;
        }
        $decoded['_eel_filing_history_order'] = max(0, (int)$candidate['filing_history_order']);
        $decoded['_eel_filing_history_transaction_id'] = trim((string)($candidate['transaction_id'] ?? ''));

        return \eel_accounts\Support\Utf8::json($decoded, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Preserve the filing-history identity when the Document API is
     * temporarily unavailable. The existing document table is sufficient for
     * this placeholder; a later successful sync updates the same document id.
     *
     * @param array<string,mixed> $metadata
     * @param array<string,mixed> $candidate
     * @return array<string,mixed>
     */
    private function metadataFailurePlaceholder(array $metadata, array $candidate, string $error): array
    {
        $significantDate = $this->candidateSignificantDate($candidate);
        $documentId = trim((string)($metadata['document_id'] ?? ''));
        if ($documentId === '') {
            $documentId = $this->extractDocumentId(
                (string)($candidate['document_metadata_path'] ?? '')
            );
        }
        $payload = [
            'placeholder' => true,
            'source' => 'filing_history',
            'document_id' => $documentId,
            'significant_date' => $significantDate,
            'significant_date_type' => $significantDate !== '' ? 'made-up-date' : '',
            'metadata_status' => (int)($metadata['status'] ?? 0),
            'metadata_fetch_error' => $error,
            'filing_history' => $candidate,
        ];
        $encoded = \eel_accounts\Support\Utf8::json($payload, JSON_UNESCAPED_SLASHES);

        return array_replace($metadata, [
            'document_id' => $documentId,
            'significant_date' => $significantDate,
            'significant_date_type' => $significantDate !== '' ? 'made-up-date' : '',
            'body' => is_string($encoded) ? $encoded : '',
            'classification' => (string)($metadata['classification'] ?? 'metadata_only_unknown'),
            'content_types' => is_array($metadata['content_types'] ?? null)
                ? $metadata['content_types']
                : [],
        ]);
    }

}
