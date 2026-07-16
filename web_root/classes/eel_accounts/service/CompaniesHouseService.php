<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class CompaniesHouseService
{
    /** @var callable */
    private $outboundRequest;

    public function __construct(
        private readonly string $environment = 'TEST',
        private readonly int $timeoutSeconds = 20,
        ?callable $outboundRequest = null,
    ) {
        $this->outboundRequest = $outboundRequest ?? fn(array $request): array => \eel_accounts\Outbound\CompaniesHouseOutbound::request($request, $this->environment);
    }

    public function request(string $path, array $query = []): array {
        $response = ($this->outboundRequest)([
            'provider' => 'COMPANIESHOUSE',
            'tag' => 'COMPANY_LOOKUP',
            'environment' => \HelperFramework::normaliseEnvironmentMode($this->environment),
            'method' => 'GET',
            'path' => $path,
            'query' => $query,
            'headers' => [
                'Accept' => 'application/json',
            ],
            'auth' => 'basic_api_key',
            'timeout_seconds' => max(1, $this->timeoutSeconds),
        ]);

        $status = (int)($response['status_code'] ?? 0);
        $data = json_decode((string)($response['body'] ?? ''), true);

        if ($status >= 400 && $status !== 404) {
            $message = is_array($data) && isset($data['error']) ? (string)$data['error'] : 'HTTP ' . $status;

            if (is_array($data) && isset($data['message'])) {
                $message = (string)$data['message'];
            }

            throw new \RuntimeException('Companies House request failed: ' . $message);
        }

        return [
            'status' => $status,
            'data' => is_array($data) ? $data : [],
            'url' => (string)($response['url'] ?? ''),
            'raw' => $response,
        ];
    }

    public function fetchProfileByNumber(string $companyNumber): array {
        $lookup = $this->request('/company/' . trim($companyNumber));

        return $lookup['status'] === 200 ? $lookup['data'] : [];
    }

    public function fetchActiveDirectorCountByNumber(string $companyNumber, int $itemsPerPage = 100, int $maxPages = 20): array
    {
        $companyNumber = trim($companyNumber);
        if ($companyNumber === '') {
            return [
                'success' => false,
                'director_count' => null,
                'errors' => ['A Companies House company number is required before checking directors.'],
            ];
        }

        $itemsPerPage = max(1, min(100, $itemsPerPage));
        $maxPages = max(1, $maxPages);
        $startIndex = 0;
        $pageCount = 0;
        $directorCount = 0;
        $officers = [];
        $totalResults = null;

        try {
            while (true) {
                $lookup = $this->request(
                    '/company/' . rawurlencode($companyNumber) . '/officers',
                    [
                        'items_per_page' => $itemsPerPage,
                        'start_index' => $startIndex,
                    ]
                );

                if ((int)$lookup['status'] !== 200) {
                    return [
                        'success' => false,
                        'director_count' => null,
                        'errors' => ['Companies House officers could not be checked for company number ' . $companyNumber . '.'],
                    ];
                }

                $data = (array)$lookup['data'];
                if (!array_key_exists('items', $data) || !is_array($data['items'])) {
                    return [
                        'success' => false,
                        'director_count' => null,
                        'errors' => ['Companies House officers response did not include a valid officers list.'],
                    ];
                }

                foreach ($data['items'] as $item) {
                    if (is_array($item) && $this->isActiveDirectorOfficer($item)) {
                        $directorCount++;
                    }

                    if (is_array($item)) {
                        $officers[] = $item;
                    }
                }

                $pageCount++;
                $items = (array)$data['items'];
                $itemCount = count($items);
                $totalResults = array_key_exists('total_results', $data) ? max(0, (int)$data['total_results']) : $totalResults;
                $responseStartIndex = array_key_exists('start_index', $data) ? max(0, (int)$data['start_index']) : $startIndex;
                $nextStartIndex = $responseStartIndex + $itemCount;

                if ($totalResults === null || $nextStartIndex >= $totalResults) {
                    break;
                }

                if ($itemCount === 0) {
                    return [
                        'success' => false,
                        'director_count' => null,
                        'errors' => ['Companies House officers pagination did not advance.'],
                    ];
                }

                if ($pageCount >= $maxPages) {
                    return [
                        'success' => false,
                        'director_count' => null,
                        'errors' => ['Companies House officers check exceeded the pagination limit.'],
                    ];
                }

                $startIndex = $nextStartIndex;
            }
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'director_count' => null,
                'errors' => ['Companies House officers could not be checked: ' . $exception->getMessage()],
            ];
        }

        return [
            'success' => true,
            'director_count' => $directorCount,
            'officers_json' => $this->officersPayloadJson($officers, $directorCount, $totalResults),
            'errors' => [],
            'checked_at' => date('c'),
        ];
    }

    public function checkSingleActiveDirectorByNumber(string $companyNumber): array
    {
        // Kept as a compatibility entry point for callers compiled against the
        // old single-director workflow. Multiple directors are now supported.
        return $this->fetchActiveDirectorCountByNumber($companyNumber);
    }

    public static function storedAddressLines(array $settings): array
    {
        $lines = [];

        foreach (
            [
                'registered_office_care_of',
                'registered_office_po_box',
                'registered_office_premises',
                'registered_office_address_line_1',
                'registered_office_address_line_2',
                'registered_office_locality',
                'registered_office_region',
                'registered_office_postal_code',
                'registered_office_country',
            ] as $field
        ) {
            $value = trim((string)($settings[$field] ?? ''));

            if ($value !== '') {
                $lines[] = $value;
            }
        }

        return array_values(array_unique($lines));
    }

    public function singleDirectorErrorMessage(int $directorCount): string
    {
        return 'This company has ' . $directorCount . ' active director' . ($directorCount === 1 ? '' : 's') . '.';
    }

    private function isActiveDirectorOfficer(array $officer): bool
    {
        return strtolower(trim((string)($officer['officer_role'] ?? ''))) === 'director'
            && trim((string)($officer['resigned_on'] ?? '')) === '';
    }

    private function officersPayloadJson(array $officers, int $directorCount, ?int $totalResults): ?string
    {
        $payload = [
            'items' => $officers,
            'active_director_count' => $directorCount,
            'total_results' => $totalResults,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

        return $json === false ? null : $json;
    }
}

