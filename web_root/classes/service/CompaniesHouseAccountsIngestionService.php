<?php
declare(strict_types=1);

final class CompaniesHouseAccountsIngestionService
{
    private readonly CompaniesHouseFilingService $filingService;
    private readonly CompaniesHouseDocumentService $documentService;
    private readonly IxbrlParserService $ixbrlParser;
    private readonly CompaniesHousePersistenceService $persistenceService;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $environment = 'TEST',
        private readonly int $timeoutSeconds = 20,
    ) {
        $companiesHouseService = new CompaniesHouseService($this->environment, $this->timeoutSeconds);
        $this->filingService = new CompaniesHouseFilingService($companiesHouseService);
        $this->documentService = new CompaniesHouseDocumentService($this->environment, $this->timeoutSeconds);
        $this->ixbrlParser = new IxbrlParserService();
        $this->persistenceService = new CompaniesHousePersistenceService($this->pdo);
    }

    public function ingestForCompany(int $companyId, string $companyNumber): array {
        if ($companyId <= 0) {
            throw new InvalidArgumentException('A valid company id is required before Companies House accounts can be ingested.');
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

        $candidates = $this->filingService->fetchAccountsDocumentCandidates($companyNumber);
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
                }

                $results[] = $result;
            } catch (Throwable $e) {
                $failedDocumentCount++;
                $results[] = [
                    'transaction_id' => (string)($candidate['transaction_id'] ?? ''),
                    'filing_date' => (string)($candidate['date'] ?? ''),
                    'filing_type' => (string)($candidate['type'] ?? ''),
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
        $metadata = $this->documentService->fetchMetadata((string)($candidate['document_metadata_path'] ?? ''));
        $content = null;
        $parsed = null;
        $parseStatus = 'stored_document_only';
        $parseError = null;

        if ((int)($metadata['status'] ?? 0) !== 200) {
            $parseStatus = 'metadata_fetch_failed';
            $parseError = 'Document metadata request returned HTTP ' . (int)($metadata['status'] ?? 0) . '.';
        } elseif (($metadata['classification'] ?? '') === 'digital_xhtml') {
            try {
                $content = $this->documentService->fetchPreferredContent($metadata);

                if ($content === null) {
                    $parseStatus = 'content_unavailable';
                    $parseError = 'No preferred XHTML/iXBRL content URL was available for this filing.';
                } elseif ($this->contentLooksLikeXhtml($content)) {
                    $parsed = $this->ixbrlParser->parse((string)($content['body'] ?? ''));
                    $parseStatus = (($parsed['summary']['latest_year_fact_count'] ?? 0) > 0)
                        ? 'parsed_latest_year'
                        : 'parsed_no_latest_year_facts';
                } else {
                    $parseStatus = 'content_not_xhtml';
                    $parseError = 'The preferred document content did not look like XHTML/iXBRL.';
                }
            } catch (Throwable $e) {
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
        $documentRow = [
            'company_id' => $companyId,
            'company_number' => $companyNumber,
            'transaction_id' => (string)($candidate['transaction_id'] ?? ''),
            'filing_date' => $this->normaliseDate((string)($candidate['date'] ?? '')),
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
            'significant_date' => $this->normaliseDate((string)($metadata['significant_date'] ?? '')),
            'significant_date_type' => (string)($metadata['significant_date_type'] ?? ''),
            'pages' => $metadata['pages'] ?? $candidate['pages'] ?? null,
            'created_at_utc' => $this->normaliseDateTime((string)($metadata['created_at'] ?? '')),
            'fetched_at_utc' => gmdate('Y-m-d H:i:s'),
            'raw_metadata_json' => (string)($metadata['body'] ?? ''),
            'raw_content_hash' => $content !== null ? hash('sha256', (string)($content['body'] ?? '')) : null,
            'parse_status' => $parseStatus,
            'parse_error' => $parseError,
        ];

        $persisted = $this->persistenceService->persistDocument($documentRow, $parsed);

        return [
            'transaction_id' => (string)($candidate['transaction_id'] ?? ''),
            'filing_date' => (string)($candidate['date'] ?? ''),
            'filing_type' => (string)($candidate['type'] ?? ''),
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

    private function normaliseDate(?string $value): ?string {
        $value = trim((string)$value);

        if ($value === '') {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : substr($value, 0, 10);
    }

    private function normaliseDateTime(?string $value): ?string {
        $value = trim((string)$value);

        if ($value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }
}
