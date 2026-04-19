<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class CompaniesHouseFilingService
{
    public function __construct(
        private readonly CompaniesHouseService $companiesHouseService,
        private readonly int $itemsPerPage = 100,
    ) {
    }

    public function fetchFullFilingHistory(string $companyNumber): array {
        $companyNumber = strtoupper(trim($companyNumber));

        if ($companyNumber === '') {
            throw new InvalidArgumentException('A company number is required to fetch filing history.');
        }

        $items = [];
        $startIndex = 0;
        $totalCount = null;

        do {
            $response = $this->companiesHouseService->request(
                '/company/' . rawurlencode($companyNumber) . '/filing-history',
                [
                    'items_per_page' => max(1, $this->itemsPerPage),
                    'start_index' => $startIndex,
                ]
            );

            if ((int)$response['status'] !== 200) {
                break;
            }

            $data = is_array($response['data'] ?? null) ? $response['data'] : [];
            $pageItems = is_array($data['items'] ?? null) ? $data['items'] : [];
            $totalCount = isset($data['total_count']) ? (int)$data['total_count'] : count($pageItems);

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

        return [
            'company_number' => $companyNumber,
            'total_count' => $totalCount ?? count($items),
            'items' => $items,
        ];
    }

    public function fetchAccountsDocumentCandidates(string $companyNumber): array {
        $filingHistory = $this->fetchFullFilingHistory($companyNumber);
        $candidates = [];

        foreach ($filingHistory['items'] as $item) {
            $documentMetadataPath = trim((string)($item['links']['document_metadata'] ?? ''));

            if ($documentMetadataPath === '') {
                continue;
            }

            if (strtolower(trim((string)($item['category'] ?? ''))) !== 'accounts') {
                continue;
            }

            $candidates[] = [
                'transaction_id' => trim((string)($item['transaction_id'] ?? '')),
                'type' => trim((string)($item['type'] ?? '')),
                'category' => trim((string)($item['category'] ?? '')),
                'description' => trim((string)($item['description'] ?? '')),
                'date' => trim((string)($item['date'] ?? '')),
                'paper_filed' => !empty($item['paper_filed']),
                'pages' => isset($item['pages']) ? (int)$item['pages'] : null,
                'document_metadata_path' => $documentMetadataPath,
            ];
        }

        usort($candidates, static function (array $left, array $right): int {
            $leftDate = (string)($left['date'] ?? '');
            $rightDate = (string)($right['date'] ?? '');

            if ($leftDate !== $rightDate) {
                return strcmp($rightDate, $leftDate);
            }

            return strcmp((string)($right['transaction_id'] ?? ''), (string)($left['transaction_id'] ?? ''));
        });

        return $candidates;
    }
}
