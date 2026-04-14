<?php
declare(strict_types=1);

final class CompaniesHouseDocumentRepository
{
    public function fetchFiledAccountingPeriods(int $companyId, ?string $companyNumber = null): array
    {
        $companyNumber = strtoupper(trim((string)$companyNumber));
        $filters = [];
        $params = [];

        if ($companyId > 0) {
            $filters[] = 'd.company_id = ?';
            $params[] = $companyId;
        }

        if ($companyNumber !== '') {
            $filters[] = 'd.company_number = ?';
            $params[] = $companyNumber;
        }

        if ($filters === []) {
            return [];
        }

        $stmt = InterfaceDB::prepare(
            'SELECT
                d.id AS document_row_id,
                d.document_id,
                d.filing_date,
                d.filing_type,
                d.filing_category,
                d.filing_description,
                MAX(CASE WHEN c.short_name = \'StartDateForPeriodCoveredByReport\' THEN f.normalised_date END) AS period_start,
                MAX(CASE WHEN c.short_name = \'EndDateForPeriodCoveredByReport\' THEN f.normalised_date END) AS period_end,
                MAX(CASE WHEN c.short_name = \'BalanceSheetDate\' THEN f.normalised_date END) AS balance_sheet_date
            FROM companies_house_documents d
            LEFT JOIN companies_house_document_facts f
                ON f.document_fk = d.id
               AND f.is_latest_year_fact = 1
            LEFT JOIN companies_house_taxonomy_concepts c
                ON c.id = f.concept_fk
            WHERE (' . implode(' OR ', $filters) . ')
              AND d.filing_category = \'accounts\'
              AND d.classification = \'digital_xhtml\'
              AND d.parse_status = \'parsed_latest_year\'
            GROUP BY
                d.id,
                d.document_id,
                d.filing_date,
                d.filing_type,
                d.filing_category,
                d.filing_description
            ORDER BY d.filing_date DESC, d.id DESC'
        );
        $stmt->execute($params);

        $periodsByKey = [];

        foreach ($stmt->fetchAll() as $row) {
            $periodStart = trim((string)($row['period_start'] ?? ''));
            $periodEnd = trim((string)($row['period_end'] ?? ''));
            $balanceSheetDate = trim((string)($row['balance_sheet_date'] ?? ''));

            if ($periodEnd === '' && $balanceSheetDate !== '') {
                $periodEnd = $balanceSheetDate;
            }

            if ($periodStart === '' || $periodEnd === '') {
                continue;
            }

            $key = $periodStart . '|' . $periodEnd;
            $row['period_start'] = $periodStart;
            $row['period_end'] = $periodEnd;
            $row['balance_sheet_date'] = $balanceSheetDate;

            if (!isset($periodsByKey[$key])) {
                $periodsByKey[$key] = $row;
                continue;
            }

            $existing = $periodsByKey[$key];
            $rowRank = [$row['filing_date'] ?? '', (int)($row['document_row_id'] ?? 0)];
            $existingRank = [$existing['filing_date'] ?? '', (int)($existing['document_row_id'] ?? 0)];

            if ($rowRank > $existingRank) {
                $periodsByKey[$key] = $row;
            }
        }

        $periods = array_values($periodsByKey);
        usort($periods, static function (array $a, array $b): int {
            return [$a['period_start'], $a['period_end'], $a['document_id']] <=> [$b['period_start'], $b['period_end'], $b['document_id']];
        });

        return $periods;
    }

    public function fetchStoredDocumentIds(int $companyId, ?string $companyNumber = null): array
    {
        $companyNumber = strtoupper(trim((string)$companyNumber));
        $filters = [];
        $params = [];

        if ($companyId > 0) {
            $filters[] = 'company_id = ?';
            $params[] = $companyId;
        }

        if ($companyNumber !== '') {
            $filters[] = 'company_number = ?';
            $params[] = $companyNumber;
        }

        if ($filters === []) {
            return [];
        }

        $stmt = InterfaceDB::prepare(
            'SELECT document_id
             FROM companies_house_documents
             WHERE ' . implode(' OR ', $filters) . '
             ORDER BY document_id'
        );
        $stmt->execute($params);

        return array_values(array_filter(array_map(static function ($value): string {
            return trim((string)$value);
        }, $stmt->fetchAll(PDO::FETCH_COLUMN))));
    }
}
