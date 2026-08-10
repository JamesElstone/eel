<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class CompaniesHousePdfDownloadService
{
    public function __construct(
        private readonly string $environment = 'TEST',
        private readonly int $timeoutSeconds = 20,
        private readonly ?\eel_accounts\Service\CompaniesHouseService $companiesHouseService = null,
        private readonly ?\eel_accounts\Service\CompaniesHouseDocumentService $documentService = null,
        private readonly ?\eel_accounts\Service\FileCheckService $fileCheckService = null,
    ) {
    }

    public function downloadForCompany(int $companyId, string $companyNumber): array
    {
        if ($companyId <= 0) {
            throw new \InvalidArgumentException('A valid company id is required before Companies House PDFs can be downloaded.');
        }

        $companyNumber = strtoupper(trim($companyNumber));
        if ($companyNumber === '') {
            return $this->emptyResult($companyId, '');
        }

        $directory = $this->fileCheckService()->ensureCompaniesHousePdfDirectory($companyId);
        $items = $this->fetchFilingHistoryItems($companyNumber);
        $documents = [];
        $downloaded = 0;
        $skipped = 0;
        $failed = 0;
        $candidates = [];

        foreach ($items as $item) {
            $metadataUrl = trim((string)($item['links']['document_metadata'] ?? ''));
            if ($metadataUrl === '') {
                continue;
            }

            try {
                $metadata = $this->documentService()->fetchMetadata($metadataUrl);

                if (!in_array('application/pdf', (array)($metadata['content_types'] ?? []), true)) {
                    continue;
                }

                $documentId = trim((string)($metadata['document_id'] ?? ''));
                if ($documentId === '') {
                    $documentId = $this->extractDocumentId($metadataUrl);
                    $metadata['document_id'] = $documentId;
                }

                $candidates[] = [
                    'item' => $item,
                    'metadata_url' => $metadataUrl,
                    'metadata' => $metadata,
                    'document_id' => $documentId,
                    'base_filename' => $this->pdfFilename($metadata, $item, $documentId),
                ];
            } catch (\Throwable $exception) {
                $candidates[] = [
                    'failure' => $this->failedDocumentResult($item, $metadataUrl, $exception),
                ];
            }
        }

        $filenameCounts = [];
        foreach ($candidates as $candidate) {
            if (isset($candidate['failure'])) {
                continue;
            }

            $collisionKey = strtolower((string)$candidate['base_filename']);
            $filenameCounts[$collisionKey] = ($filenameCounts[$collisionKey] ?? 0) + 1;
        }

        foreach ($candidates as $candidate) {
            if (isset($candidate['failure'])) {
                $failed++;
                $documents[] = $candidate['failure'];
                continue;
            }

            $item = (array)$candidate['item'];
            $metadataUrl = (string)$candidate['metadata_url'];
            $metadata = (array)$candidate['metadata'];

            try {
                $baseFilename = (string)$candidate['base_filename'];
                $collisionKey = strtolower($baseFilename);
                $hasExplicitCollision = ($filenameCounts[$collisionKey] ?? 0) > 1;
                $filename = $hasExplicitCollision
                    ? $this->pdfFilenameWithDocumentId($baseFilename, (string)$candidate['document_id'])
                    : $baseFilename;
                $path = $directory . DIRECTORY_SEPARATOR . $filename;
                $body = null;

                if ($hasExplicitCollision && is_file($path)) {
                    $body = $this->fetchPdfBody($metadata);
                    if ($this->existingFileMatchesBody($path, $body)) {
                        $skipped++;
                        $documents[] = $this->documentResult($item, $metadata, $filename, $path, 'already_present');
                        continue;
                    }
                    throw new \RuntimeException(
                        'Existing document-specific Companies House PDF does not match the downloaded content.'
                    );
                }

                if (!$hasExplicitCollision && is_file($path)) {
                    // A failed peer metadata request can hide a filename collision. Prove that an
                    // unsuffixed legacy file belongs to this document before treating it as present.
                    $body = $this->fetchPdfBody($metadata);

                    if ($this->existingFileMatchesBody($path, $body)) {
                        $skipped++;
                        $documents[] = $this->documentResult($item, $metadata, $filename, $path, 'already_present');
                        continue;
                    }

                    $filename = $this->pdfFilenameWithDocumentId($baseFilename, (string)$candidate['document_id']);
                    $path = $directory . DIRECTORY_SEPARATOR . $filename;

                    if ($this->existingFileMatchesBody($path, $body)) {
                        $skipped++;
                        $documents[] = $this->documentResult($item, $metadata, $filename, $path, 'already_present');
                        continue;
                    }

                    if (is_file($path)) {
                        throw new \RuntimeException('Existing document-specific Companies House PDF does not match the downloaded content.');
                    }
                }

                if ($body === null) {
                    $body = $this->fetchPdfBody($metadata);
                }

                $this->writeFile($path, $body);
                $downloaded++;
                $documents[] = $this->documentResult($item, $metadata, $filename, $path, 'downloaded');
            } catch (\Throwable $exception) {
                $failed++;
                $documents[] = $this->failedDocumentResult($item, $metadataUrl, $exception);
            }
        }

        return [
            'company_id' => $companyId,
            'company_number' => $companyNumber,
            'filing_count' => count($items),
            'downloaded_count' => $downloaded,
            'skipped_existing_count' => $skipped,
            'failed_count' => $failed,
            'directory' => $directory,
            'documents' => $documents,
        ];
    }

    private function emptyResult(int $companyId, string $companyNumber): array
    {
        return [
            'company_id' => $companyId,
            'company_number' => $companyNumber,
            'filing_count' => 0,
            'downloaded_count' => 0,
            'skipped_existing_count' => 0,
            'failed_count' => 0,
            'directory' => '',
            'documents' => [],
        ];
    }

    private function fetchFilingHistoryItems(string $companyNumber): array
    {
        $items = [];
        $startIndex = 0;
        $totalCount = null;

        do {
            $response = $this->companiesHouseService()->request(
                '/company/' . rawurlencode($companyNumber) . '/filing-history',
                [
                    'items_per_page' => 100,
                    'start_index' => $startIndex,
                ]
            );

            if ((int)($response['status'] ?? 0) !== 200) {
                throw new \RuntimeException('Companies House filing history request returned HTTP ' . (int)($response['status'] ?? 0) . '.');
            }

            $data = is_array($response['data'] ?? null) ? $response['data'] : [];
            $pageItems = is_array($data['items'] ?? null) ? $data['items'] : [];
            $totalCount = isset($data['total_count']) ? max(0, (int)$data['total_count']) : count($pageItems);

            foreach ($pageItems as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }

            if ($pageItems === []) {
                break;
            }

            $startIndex += count($pageItems);
        } while ($totalCount === null || $startIndex < $totalCount);

        return $items;
    }

    private function fetchPdfBody(array $metadata): string
    {
        $content = $this->documentService()->fetchContent(
            (string)($metadata['content_url'] ?? ''),
            'application/pdf'
        );

        if ((int)($content['status'] ?? 0) !== 200) {
            throw new \RuntimeException('Document content request returned HTTP ' . (int)($content['status'] ?? 0) . '.');
        }

        $body = (string)($content['body'] ?? '');
        if ($body === '') {
            throw new \RuntimeException('Document content response was empty.');
        }

        return $body;
    }

    private function existingFileMatchesBody(string $path, string $body): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $hash = hash_file('sha256', $path);

        return is_string($hash) && hash_equals(hash('sha256', $body), $hash);
    }

    private function pdfFilename(array $metadata, array $item, string $documentId): string
    {
        $filename = trim((string)($metadata['filename'] ?? ''));

        if ($filename === '') {
            $filename = implode('_', array_filter([
                trim((string)($item['date'] ?? '')),
                trim((string)($item['type'] ?? '')),
                $documentId !== '' ? substr($documentId, 0, 12) : '',
            ]));
        }

        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?? '';
        $filename = trim($filename, '._-');

        if ($filename === '') {
            $filename = 'companies_house_document_' . date('YmdHis');
        }

        if (!str_ends_with(strtolower($filename), '.pdf')) {
            $filename .= '.pdf';
        }

        return $filename;
    }

    private function pdfFilenameWithDocumentId(string $baseFilename, string $documentId): string
    {
        $documentId = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($documentId)) ?? '';
        $documentId = trim($documentId, '._-');

        if ($documentId === '') {
            throw new \RuntimeException('A Companies House document id is required to disambiguate duplicate PDF filenames.');
        }

        $extension = str_ends_with(strtolower($baseFilename), '.pdf') ? substr($baseFilename, -4) : '.pdf';
        $basename = str_ends_with(strtolower($baseFilename), '.pdf') ? substr($baseFilename, 0, -4) : $baseFilename;

        return $basename . '_' . $documentId . $extension;
    }

    private function writeFile(string $path, string $body): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            throw new \RuntimeException('Companies House PDF directory does not exist: ' . $directory);
        }

        $tempPath = $path . '.tmp-' . bin2hex(random_bytes(6));
        $bytes = file_put_contents($tempPath, $body, LOCK_EX);

        if ($bytes === false || $bytes !== strlen($body)) {
            @unlink($tempPath);
            throw new \RuntimeException('Companies House PDF could not be written to disk.');
        }

        if (!@rename($tempPath, $path)) {
            @unlink($tempPath);
            throw new \RuntimeException('Companies House PDF could not be moved into place.');
        }
    }

    private function documentResult(array $item, array $metadata, string $filename, string $path, string $status): array
    {
        return [
            'transaction_id' => (string)($item['transaction_id'] ?? ''),
            'filing_date' => (string)($item['date'] ?? ''),
            'filing_type' => (string)($item['type'] ?? ''),
            'document_id' => (string)($metadata['document_id'] ?? ''),
            'filename' => $filename,
            'path' => $path,
            'status' => $status,
        ];
    }

    private function failedDocumentResult(array $item, string $metadataUrl, \Throwable $exception): array
    {
        return [
            'transaction_id' => (string)($item['transaction_id'] ?? ''),
            'filing_date' => (string)($item['date'] ?? ''),
            'filing_type' => (string)($item['type'] ?? ''),
            'document_id' => $this->extractDocumentId($metadataUrl),
            'status' => 'failed',
            'error' => $exception->getMessage(),
        ];
    }

    private function extractDocumentId(string $metadataPathOrUrl): string
    {
        $path = (string)parse_url($metadataPathOrUrl, PHP_URL_PATH);

        if (preg_match('#/document/([^/]+)#', $path, $matches) === 1) {
            return trim((string)$matches[1]);
        }

        return '';
    }

    private function companiesHouseService(): \eel_accounts\Service\CompaniesHouseService
    {
        return $this->companiesHouseService ?? new \eel_accounts\Service\CompaniesHouseService($this->environment, $this->timeoutSeconds);
    }

    private function documentService(): \eel_accounts\Service\CompaniesHouseDocumentService
    {
        return $this->documentService ?? new \eel_accounts\Service\CompaniesHouseDocumentService($this->environment, $this->timeoutSeconds);
    }

    private function fileCheckService(): \eel_accounts\Service\FileCheckService
    {
        return $this->fileCheckService ?? new \eel_accounts\Service\FileCheckService();
    }
}
