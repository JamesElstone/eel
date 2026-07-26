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
 * Maps the stored, parsed original Companies House filing to current-taxonomy
 * facts that can be qualified as superseded in a revised accounts instance.
 */
final class IxbrlSupersededFactsService
{
    /** @return list<array<string, mixed>> */
    public function facts(int $companyId, int $originalDocumentId, string $periodEnd): array
    {
        if ($companyId <= 0 || $originalDocumentId <= 0 || !$this->validDate($periodEnd)) {
            throw new \InvalidArgumentException('A company, original filing and valid period end are required.');
        }
        foreach ([
            'companies_house_documents',
            'companies_house_document_facts',
            'companies_house_document_contexts',
            'companies_house_taxonomy_concepts',
        ] as $table) {
            if (!\InterfaceDB::tableExists($table)) {
                throw new \RuntimeException('Stored Companies House filing facts are unavailable.');
            }
        }
        $document = \InterfaceDB::fetchOne(
            'SELECT d.id, d.company_id, d.company_number, d.parse_status, c.company_number AS expected_company_number
             FROM companies_house_documents d
             INNER JOIN companies c ON c.id = :company_id
             WHERE d.id = :document_id
             LIMIT 1',
            ['company_id' => $companyId, 'document_id' => $originalDocumentId]
        );
        if (!is_array($document)
            || (int)($document['company_id'] ?? 0) !== $companyId
            || $this->companyNumber((string)($document['company_number'] ?? ''))
                !== $this->companyNumber((string)($document['expected_company_number'] ?? ''))) {
            throw new \RuntimeException('The selected original filing does not belong to this company.');
        }
        if (!in_array(
            (string)($document['parse_status'] ?? ''),
            ['parsed', 'parsed_latest_year'],
            true
        )) {
            throw new \RuntimeException('The selected original filing has not been parsed successfully.');
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT c.short_name, f.raw_value, f.normalised_numeric, f.unit_ref,
                    f.decimals_value, f.sign_hint, ctx.instant_date, ctx.period_end,
                    ctx.dimension_json
             FROM companies_house_document_facts f
             INNER JOIN companies_house_taxonomy_concepts c ON c.id = f.concept_fk
             INNER JOIN companies_house_document_contexts ctx ON ctx.id = f.context_fk
             WHERE f.document_fk = :document_id
               AND f.is_latest_year_fact = 1
               AND f.is_numeric = 1
             ORDER BY c.short_name, ctx.context_ref, f.id',
            ['document_id' => $originalDocumentId]
        );

        $facts = [];
        foreach ($rows as $row) {
            $shortName = (string)($row['short_name'] ?? '');
            $mapped = $this->mapping($shortName, (string)($row['dimension_json'] ?? ''));
            if ($mapped === null || trim((string)($row['normalised_numeric'] ?? '')) === '') {
                continue;
            }
            $contextDate = trim((string)($row['instant_date'] ?? $row['period_end'] ?? ''));
            if ($contextDate !== '' && $contextDate !== $periodEnd) {
                continue;
            }
            $value = round((float)$row['normalised_numeric'], 2);
            if ($shortName === 'Creditors'
                || str_starts_with($shortName, 'CreditorsDue')
                || in_array($shortName, [
                    'ProvisionsForLiabilitiesBalanceSheetSubtotal',
                    'AccruedLiabilitiesNotExpressedWithinCreditorsSubtotal',
                ], true)) {
                // The importer records accounting parentheses as a negative
                // presentation hint. These taxonomy balances are positive facts.
                $value = abs($value);
            }
            $key = $mapped['concept'] . '|' . $mapped['context_ref'];
            if (isset($facts[$key])) {
                continue;
            }
            $facts[$key] = [
                'concept' => $mapped['concept'],
                'context_ref' => $mapped['context_ref'],
                'value' => $value,
                'unit_ref' => $mapped['unit_ref'],
                'decimals' => $mapped['decimals'],
                'source_document_id' => $originalDocumentId,
                'source_short_name' => $shortName,
                'source_raw_value' => (string)($row['raw_value'] ?? ''),
            ];
        }

        if ($facts === []) {
            throw new \RuntimeException(
                'The selected original filing contains no supported balance-sheet facts for superseded tagging.'
            );
        }

        return array_values($facts);
    }

    /** @return array{concept:string,context_ref:string,unit_ref:string,decimals:string}|null */
    private function mapping(string $shortName, string $dimensionsJson): ?array
    {
        $base = match ($shortName) {
            'CalledUpShareCapitalNotPaidNotExpressedAsCurrentAsset' => [
                'core:CalledUpShareCapitalNotPaidNotExpressedAsCurrentAsset',
                'current_period_end_superseded',
            ],
            'FixedAssets' => ['core:FixedAssets', 'current_period_end_superseded'],
            'CurrentAssets' => ['core:CurrentAssets', 'current_period_end_superseded'],
            'PrepaymentsAccruedIncome',
            'PrepaymentsAccruedIncomeNotExpressedWithinCurrentAssetSubtotal' => [
                'core:PrepaymentsAccruedIncomeNotExpressedWithinCurrentAssetSubtotal',
                'current_period_end_superseded',
            ],
            'NetCurrentAssetsLiabilities' => [
                'core:NetCurrentAssetsLiabilities',
                'current_period_end_superseded',
            ],
            'TotalAssetsLessCurrentLiabilities' => [
                'core:TotalAssetsLessCurrentLiabilities',
                'current_period_end_superseded',
            ],
            'ProvisionsForLiabilitiesBalanceSheetSubtotal' => [
                'core:ProvisionsForLiabilitiesBalanceSheetSubtotal',
                'current_period_end_superseded',
            ],
            'AccruedLiabilitiesNotExpressedWithinCreditorsSubtotal' => [
                'core:AccruedLiabilitiesNotExpressedWithinCreditorsSubtotal',
                'current_period_end_superseded',
            ],
            'NetAssetsLiabilities' => ['core:NetAssetsLiabilities', 'current_period_end_superseded'],
            'CapitalAndReserves', 'Equity' => ['core:Equity', 'current_period_end_superseded'],
            'AverageNumberEmployeesDuringPeriod' => [
                'core:AverageNumberEmployeesDuringPeriod',
                'current_period_duration_superseded',
            ],
            'Creditors', 'CreditorsDueWithinOneYear', 'CreditorsDueAfterOneYear',
            'CreditorsDueAfterMoreThanOneYear' => $this->creditorMapping($shortName, $dimensionsJson),
            default => null,
        };
        if (!is_array($base)) {
            return null;
        }

        return [
            'concept' => (string)$base[0],
            'context_ref' => (string)$base[1],
            'unit_ref' => $shortName === 'AverageNumberEmployeesDuringPeriod' ? 'pure' : 'GBP',
            'decimals' => $shortName === 'AverageNumberEmployeesDuringPeriod' ? '0' : '2',
        ];
    }

    /** @return array{0:string,1:string}|null */
    private function creditorMapping(string $shortName, string $dimensionsJson): ?array
    {
        $afterOneYear = str_contains($shortName, 'After')
            || str_contains($dimensionsJson, 'AfterOneYear');
        $withinOneYear = str_contains($shortName, 'Within')
            || str_contains($dimensionsJson, 'WithinOneYear');
        if (!$afterOneYear && !$withinOneYear) {
            return null;
        }

        return [
            'core:Creditors',
            $afterOneYear
                ? 'current_period_end_superseded_creditors_after_one_year'
                : 'current_period_end_superseded_creditors_within_one_year',
        ];
    }

    private function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $date;
    }

    private function companyNumber(string $number): string
    {
        return strtoupper((string)preg_replace('/[^A-Z0-9]/i', '', $number));
    }
}
