<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class CompaniesHouseStoredDataService
{
    public function fetchDocumentSummariesByCompanyNumber(string $companyNumber): array {
        return InterfaceDB::fetchAll( 'SELECT
                d.id,
                d.company_number,
                d.filing_date,
                d.filing_type,
                d.filing_category,
                d.filing_description,
                d.document_id,
                d.classification,
                d.parse_status,
                MAX(CASE WHEN c.short_name = \'StartDateForPeriodCoveredByReport\' THEN f.normalised_date END) AS latest_year_period_start,
                MAX(CASE WHEN c.short_name = \'EndDateForPeriodCoveredByReport\' THEN f.normalised_date END) AS latest_year_period_end,
                MAX(CASE WHEN c.short_name = \'BalanceSheetDate\' THEN f.normalised_date END) AS balance_sheet_date,
                COUNT(DISTINCT CASE WHEN ctx.is_latest_year_context = 1 THEN ctx.id END) AS latest_year_context_count,
                COUNT(DISTINCT CASE WHEN f.is_latest_year_fact = 1 THEN f.id END) AS latest_year_fact_count
            FROM companies_house_documents d
            LEFT JOIN companies_house_document_contexts ctx ON ctx.document_fk = d.id
            LEFT JOIN companies_house_document_facts f ON f.document_fk = d.id
            LEFT JOIN companies_house_taxonomy_concepts c ON c.id = f.concept_fk
            WHERE d.company_number = ?
            GROUP BY
                d.id,
                d.company_number,
                d.filing_date,
                d.filing_type,
                d.filing_category,
                d.filing_description,
                d.document_id,
                d.classification,
                d.parse_status
            ORDER BY d.filing_date DESC, d.id DESC', [strtoupper(trim($companyNumber))]);
    }

    public function fetchFactsByDocumentRowId(int $documentRowId): array {
        $rows = InterfaceDB::fetchAll( 'SELECT
                f.id,
                c.concept_name,
                COALESCE(NULLIF(f.fact_name, \'\'), c.friendly_label) AS friendly_label,
                ctx.context_ref,
                ctx.period_start,
                ctx.period_end,
                ctx.instant_date,
                ctx.dimension_json,
                f.raw_value,
                f.normalised_numeric,
                f.normalised_text,
                f.normalised_date,
                f.unit_ref
            FROM companies_house_document_facts f
            INNER JOIN companies_house_taxonomy_concepts c ON c.id = f.concept_fk
            INNER JOIN companies_house_document_contexts ctx ON ctx.id = f.context_fk
            WHERE f.document_fk = ?
            ORDER BY COALESCE(c.friendly_label, c.concept_name), ctx.context_ref, f.id', [$documentRowId]);

        foreach ($rows as &$row) {
            $row['period_or_instant'] = $this->periodSummary($row);
            $row['dimension_summary'] = $this->dimensionSummary((string)($row['dimension_json'] ?? ''));
        }
        unset($row);

        return $rows;
    }

    public function fetchFiledPeriodsByCompanyNumber(string $companyNumber): array {
        $summaries = $this->fetchDocumentSummariesByCompanyNumber($companyNumber);

        return array_values(array_filter($summaries, static function (array $row): bool {
            return trim((string)($row['latest_year_period_end'] ?? '')) !== ''
                || trim((string)($row['balance_sheet_date'] ?? '')) !== '';
        }));
    }

    public function fetchFactsByCompanyPeriodEndAndConcept(
        string $companyNumber,
        string $periodEnd,
        ?string $conceptName = null
    ): array {
        $sql = 'SELECT
                    d.company_number,
                    d.document_id,
                    d.filing_date,
                    d.filing_type,
                    c.concept_name,
                    COALESCE(NULLIF(f.fact_name, \'\'), c.friendly_label) AS friendly_label,
                    ctx.context_ref,
                    ctx.period_start,
                    ctx.period_end,
                    ctx.instant_date,
                    ctx.dimension_json,
                    f.raw_value,
                    f.normalised_numeric,
                    f.normalised_text,
                    f.normalised_date,
                    f.unit_ref
                FROM companies_house_document_facts f
                INNER JOIN companies_house_documents d ON d.id = f.document_fk
                INNER JOIN companies_house_document_contexts ctx ON ctx.id = f.context_fk
                INNER JOIN companies_house_taxonomy_concepts c ON c.id = f.concept_fk
                WHERE d.company_number = ?
                  AND f.is_latest_year_fact = 1
                  AND (
                    EXISTS (
                        SELECT 1
                        FROM companies_house_document_facts fp
                        INNER JOIN companies_house_taxonomy_concepts cp ON cp.id = fp.concept_fk
                        WHERE fp.document_fk = d.id
                          AND cp.short_name = \'EndDateForPeriodCoveredByReport\'
                          AND fp.normalised_date = ?
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM companies_house_document_facts fb
                        INNER JOIN companies_house_taxonomy_concepts cb ON cb.id = fb.concept_fk
                        WHERE fb.document_fk = d.id
                          AND cb.short_name = \'BalanceSheetDate\'
                          AND fb.normalised_date = ?
                    )
                  )';
        $params = [strtoupper(trim($companyNumber)), $periodEnd, $periodEnd];

        if ($conceptName !== null && trim($conceptName) !== '') {
            $sql .= ' AND c.concept_name = ?';
            $params[] = trim($conceptName);
        }

        $sql .= ' ORDER BY d.filing_date DESC, friendly_label, ctx.context_ref';
        $rows = InterfaceDB::fetchAll( $sql, $params);

        foreach ($rows as &$row) {
            $row['period_or_instant'] = $this->periodSummary($row);
            $row['dimension_summary'] = $this->dimensionSummary((string)($row['dimension_json'] ?? ''));
        }
        unset($row);

        return $rows;
    }

    private function periodSummary(array $row): string {
        $periodStart = trim((string)($row['period_start'] ?? ''));
        $periodEnd = trim((string)($row['period_end'] ?? ''));
        $instantDate = trim((string)($row['instant_date'] ?? ''));

        if ($periodStart !== '' && $periodEnd !== '') {
            return $periodStart . ' to ' . $periodEnd;
        }

        return $instantDate;
    }

    private function dimensionSummary(string $dimensionJson): string {
        $dimensionJson = trim($dimensionJson);

        if ($dimensionJson === '') {
            return '';
        }

        $decoded = json_decode($dimensionJson, true);

        if (!is_array($decoded) || $decoded === []) {
            return $dimensionJson;
        }

        $parts = [];

        foreach ($decoded as $dimension) {
            if (!is_array($dimension)) {
                continue;
            }

            $dimensionName = trim((string)($dimension['dimension'] ?? ''));
            $memberName = trim((string)($dimension['member'] ?? ''));
            $parts[] = $dimensionName . ': ' . $memberName;
        }

        return implode('; ', array_filter($parts));
    }
}


