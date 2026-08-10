<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class CompaniesHouseFilingService
{
    public function __construct(
        private readonly \eel_accounts\Service\CompaniesHouseService $companiesHouseService,
        private readonly int $itemsPerPage = 100,
        private readonly ?\eel_accounts\Repository\CompanyRepository $companyRepository = null,
    ) {
    }

    public function fetchFullFilingHistory(int $companyId): array {
        $companyNumber = $this->companyNumber($companyId);

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

    public function fetchAccountsDocumentCandidates(int $companyId): array {
        $filingHistory = $this->fetchFullFilingHistory($companyId);
        $candidates = [];

        foreach ($filingHistory['items'] as $historyIndex => $item) {
            $documentMetadataPath = trim((string)($item['links']['document_metadata'] ?? ''));

            if ($documentMetadataPath === '') {
                continue;
            }

            if (strtolower(trim((string)($item['category'] ?? ''))) !== 'accounts') {
                continue;
            }

            $descriptionValues = is_array($item['description_values'] ?? null)
                ? (array)$item['description_values']
                : [];
            $significantDate = trim((string)(
                $descriptionValues['made_up_date']
                ?? $item['action_date']
                ?? ''
            ));

            $candidates[] = [
                'transaction_id' => trim((string)($item['transaction_id'] ?? '')),
                'type' => trim((string)($item['type'] ?? '')),
                'category' => trim((string)($item['category'] ?? '')),
                'description' => trim((string)($item['description'] ?? '')),
                'date' => trim((string)($item['date'] ?? '')),
                'significant_date' => $significantDate,
                'significant_date_type' => $significantDate !== '' ? 'made-up-date' : '',
                'paper_filed' => !empty($item['paper_filed']),
                'pages' => isset($item['pages']) ? (int)$item['pages'] : null,
                'document_metadata_path' => $documentMetadataPath,
                // Filing history is returned newest-first. Preserve that API
                // position before filtering; transaction_id is opaque and is
                // not a chronology signal.
                'filing_history_order' => (int)$historyIndex,
            ];
        }

        usort($candidates, static function (array $left, array $right): int {
            $leftDate = (string)($left['date'] ?? '');
            $rightDate = (string)($right['date'] ?? '');

            if ($leftDate !== $rightDate) {
                return strcmp($rightDate, $leftDate);
            }

            $leftOrder = $left['filing_history_order'] ?? null;
            $rightOrder = $right['filing_history_order'] ?? null;
            if (is_int($leftOrder) && is_int($rightOrder) && $leftOrder !== $rightOrder) {
                return $leftOrder <=> $rightOrder;
            }

            return strcmp((string)($right['transaction_id'] ?? ''), (string)($left['transaction_id'] ?? ''));
        });

        return $candidates;
    }

    private function companyNumber(int $companyId): string
    {
        if ($companyId <= 0) {
            throw new \InvalidArgumentException('A valid company id is required to fetch filing history.');
        }

        $company = ($this->companyRepository ?? new \eel_accounts\Repository\CompanyRepository())->fetchCompanyDetails($companyId);
        $companyNumber = strtoupper(trim((string)($company['company_number'] ?? '')));

        if ($companyNumber === '') {
            throw new \InvalidArgumentException('A company number is required to fetch filing history.');
        }

        return $companyNumber;
    }
}
