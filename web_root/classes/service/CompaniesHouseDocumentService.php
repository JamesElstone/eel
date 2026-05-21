<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class CompaniesHouseDocumentService
{
    private const DOCUMENT_API_BASE = 'https://document-api.company-information.service.gov.uk';

    /** @var callable */
    private $outboundRequest;

    public function __construct(
        private readonly string $environment = 'TEST',
        private readonly int $timeoutSeconds = 20,
        ?callable $outboundRequest = null,
    ) {
        $this->outboundRequest = $outboundRequest ?? fn(array $request): array => CompaniesHouseOutbound::request($request, $this->environment);
    }

    public function fetchMetadata(string $metadataPathOrUrl): array {
        $url = $this->absoluteUrl($metadataPathOrUrl);
        $response = $this->requestJson($url);
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $contentTypes = $this->contentTypesFromMetadata($data);

        return [
            'status' => (int)$response['status'],
            'url' => (string)$response['url'],
            'body' => (string)$response['body'],
            'data' => $data,
            'document_id' => $this->documentIdFromMetadata($data, $url),
            'content_types' => $contentTypes,
            'classification' => $this->classifyContentTypes($contentTypes),
            'content_url' => trim((string)($data['links']['document'] ?? '')),
            'filename' => trim((string)($data['filename'] ?? '')),
            'created_at' => trim((string)($data['created_at'] ?? '')),
            'significant_date' => trim((string)($data['significant_date'] ?? '')),
            'significant_date_type' => trim((string)($data['significant_date_type'] ?? '')),
            'pages' => isset($data['pages']) ? (int)$data['pages'] : null,
        ];
    }

    public function fetchPreferredContent(array $metadata): ?array {
        $contentTypes = array_values(array_filter(array_map(
            static fn($value): string => strtolower(trim((string)$value)),
            is_array($metadata['content_types'] ?? null) ? $metadata['content_types'] : []
        )));

        $preferredType = $this->choosePreferredContentType($contentTypes);
        $contentUrl = trim((string)($metadata['content_url'] ?? ''));

        if ($preferredType === '' || $contentUrl === '') {
            return null;
        }

        return $this->fetchContent($contentUrl, $preferredType);
    }

    public function fetchContent(string $contentPathOrUrl, string $acceptType): array {
        $contentUrl = $this->absoluteUrl($contentPathOrUrl);
        $initial = $this->requestRaw($contentUrl, [
            'Accept' => $acceptType,
        ]);
        $status = (int)$initial['status'];
        $headers = is_array($initial['headers'] ?? null) ? $initial['headers'] : [];
        $redirectLocation = trim((string)($headers['location'] ?? ''));
        $finalResponse = $initial;

        if ($status >= 300 && $status < 400 && $redirectLocation !== '') {
            $finalResponse = $this->requestRaw($redirectLocation, [
                'Accept' => $acceptType,
            ], false);
        }

        return [
            'requested_url' => $contentUrl,
            'final_url' => (string)($finalResponse['url'] ?? $contentUrl),
            'status' => (int)($finalResponse['status'] ?? $status),
            'accept_type' => $acceptType,
            'response_content_type' => strtolower(trim((string)($finalResponse['headers']['content-type'] ?? ''))),
            'body' => (string)($finalResponse['body'] ?? ''),
            'headers' => is_array($finalResponse['headers'] ?? null) ? $finalResponse['headers'] : [],
        ];
    }

    public function absoluteUrl(string $pathOrUrl): string {
        $pathOrUrl = trim($pathOrUrl);

        if ($pathOrUrl === '') {
            return '';
        }

        if (preg_match('/^https?:\/\//i', $pathOrUrl) === 1) {
            return $pathOrUrl;
        }

        return rtrim(self::DOCUMENT_API_BASE, '/') . '/' . ltrim($pathOrUrl, '/');
    }

    public function choosePreferredContentType(array $contentTypes): string {
        foreach ($contentTypes as $contentType) {
            $contentType = strtolower(trim((string)$contentType));

            if (
                str_contains($contentType, 'xhtml')
                || str_contains($contentType, 'html')
                || str_contains($contentType, 'ixbrl')
                || str_contains($contentType, 'xbrl')
            ) {
                return $contentType;
            }
        }

        foreach ($contentTypes as $contentType) {
            $contentType = strtolower(trim((string)$contentType));

            if (str_contains($contentType, 'pdf')) {
                return $contentType;
            }
        }

        return trim((string)($contentTypes[0] ?? ''));
    }

    private function requestJson(string $url): array {
        $response = $this->requestRaw($url, [
            'Accept' => 'application/json',
        ]);

        return [
            'status' => (int)$response['status'],
            'url' => (string)$response['url'],
            'body' => (string)$response['body'],
            'headers' => is_array($response['headers'] ?? null) ? $response['headers'] : [],
            'data' => json_decode((string)$response['body'], true),
        ];
    }

    private function requestRaw(string $url, array $headers, bool $useAuth = true): array {
        $response = ($this->outboundRequest)([
            'provider' => 'COMPANIESHOUSE',
            'tag' => 'COMPANY_LOOKUP',
            'environment' => HelperFramework::normaliseEnvironmentMode($this->environment),
            'method' => 'GET',
            'url' => $url,
            'headers' => $headers,
            'auth' => $useAuth ? 'basic_api_key' : 'none',
            'timeout_seconds' => max(1, $this->timeoutSeconds),
        ]);

        return [
            'status' => (int)($response['status_code'] ?? 0),
            'url' => (string)($response['url'] ?? $url),
            'body' => (string)($response['body'] ?? ''),
            'headers' => is_array($response['headers'] ?? null) ? $response['headers'] : [],
        ];
    }

    private function contentTypesFromMetadata(array $metadata): array {
        $resources = is_array($metadata['resources'] ?? null) ? $metadata['resources'] : [];
        $contentTypes = [];

        foreach ($resources as $key => $resource) {
            $contentType = strtolower(trim((string)$key));

            if ($contentType === '' && is_array($resource)) {
                $contentType = strtolower(trim((string)($resource['content_type'] ?? '')));
            }

            if ($contentType !== '') {
                $contentTypes[] = $contentType;
            }
        }

        $contentTypes = array_values(array_unique($contentTypes));
        sort($contentTypes);

        return $contentTypes;
    }

    private function classifyContentTypes(array $contentTypes): string {
        foreach ($contentTypes as $contentType) {
            $contentType = strtolower(trim((string)$contentType));

            if (
                str_contains($contentType, 'xhtml')
                || str_contains($contentType, 'html')
                || str_contains($contentType, 'ixbrl')
                || str_contains($contentType, 'xbrl')
            ) {
                return 'digital_xhtml';
            }
        }

        foreach ($contentTypes as $contentType) {
            if (str_contains(strtolower((string)$contentType), 'pdf')) {
                return 'digital_pdf';
            }
        }

        return $contentTypes === [] ? 'metadata_only_unknown' : 'digital_other';
    }

    private function documentIdFromMetadata(array $metadata, string $metadataUrl): string {
        $documentId = trim((string)($metadata['id'] ?? ''));

        if ($documentId !== '') {
            return $documentId;
        }

        $path = (string)parse_url($metadataUrl, PHP_URL_PATH);

        if (preg_match('#/document/([^/]+)#', $path, $matches) === 1) {
            return trim((string)$matches[1]);
        }

        return '';
    }
}

