<?php
declare(strict_types=1);

final class TrialBalanceComparisonService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?YearEndMetricsService $metricsService = null,
        private readonly ?CompaniesHouseStoredDataService $storedDataService = null,
    ) {
    }

    public function fetchComparison(int $companyId, int $taxYearId): array {
        $metrics = $this->metricsService ?? new YearEndMetricsService($this->pdo);
        $taxYear = $metrics->fetchTaxYear($companyId, $taxYearId);
        $company = $metrics->fetchCompanySummary($companyId);

        if ($taxYear === null || $company === null) {
            return [
                'available' => false,
                'errors' => ['The selected company or accounting period could not be found.'],
            ];
        }

        $companyNumber = strtoupper(trim((string)($company['company_number'] ?? '')));
        if ($companyNumber === '') {
            return [
                'available' => false,
                'errors' => ['No Companies House number is stored for this company.'],
            ];
        }

        $stored = $this->storedDataService ?? new CompaniesHouseStoredDataService($this->pdo);
        $summaries = $stored->fetchDocumentSummariesByCompanyNumber($companyNumber);
        $nearest = $this->findNearestSummary($summaries, (string)$taxYear['period_end']);
        if ($nearest === null) {
            return [
                'available' => false,
                'errors' => ['No stored Companies House accounts filings were found for this company.'],
            ];
        }

        $facts = $this->fetchMetricFacts((int)$nearest['id']);
        $ledger = $metrics->fetchBalanceSheetMetricValues(
            $companyId,
            $taxYearId,
            (string)$taxYear['period_start'],
            (string)$taxYear['period_end']
        );

        $rows = [];
        foreach ($this->metricMap() as $metricKey => $label) {
            $filedValue = array_key_exists($metricKey, $facts) ? round((float)$facts[$metricKey], 2) : null;
            $ledgerValue = array_key_exists($metricKey, $ledger) ? round((float)$ledger[$metricKey], 2) : null;
            $difference = ($filedValue !== null && $ledgerValue !== null) ? round($ledgerValue - $filedValue, 2) : null;
            $status = 'not_mapped';

            if ($filedValue !== null && $ledgerValue !== null) {
                $status = abs($difference) < 0.005 ? 'matches' : 'differs';
            }

            $rows[] = [
                'metric_key' => $metricKey,
                'label' => $label,
                'filed_value' => $filedValue,
                'current_ledger_value' => $ledgerValue,
                'difference' => $difference,
                'status' => $status,
            ];
        }

        return [
            'available' => true,
            'filing' => $nearest,
            'rows' => $rows,
        ];
    }

    private function findNearestSummary(array $summaries, string $periodEnd): ?array {
        $target = strtotime($periodEnd) ?: 0;
        $best = null;
        $bestDistance = null;

        foreach ($summaries as $summary) {
            $candidateEnd = (string)($summary['latest_year_period_end'] ?? $summary['balance_sheet_date'] ?? '');
            if ($candidateEnd === '') {
                continue;
            }

            $distance = abs((strtotime($candidateEnd) ?: 0) - $target);
            if ($best === null || $distance < (int)$bestDistance) {
                $best = [
                    'id' => (int)($summary['id'] ?? 0),
                    'filing_date' => (string)($summary['filing_date'] ?? ''),
                    'filing_type' => (string)($summary['filing_type'] ?? ''),
                    'period_start' => (string)($summary['latest_year_period_start'] ?? ''),
                    'period_end' => $candidateEnd,
                    'parse_status' => (string)($summary['parse_status'] ?? ''),
                ];
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    private function fetchMetricFacts(int $documentRowId): array {
        $placeholders = implode(', ', array_fill(0, count($this->factShortNameMap()), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT c.short_name,
                    f.normalised_numeric
             FROM companies_house_document_facts f
             INNER JOIN companies_house_taxonomy_concepts c ON c.id = f.concept_fk
             WHERE f.document_fk = ?
               AND c.short_name IN (' . $placeholders . ')
               AND f.is_latest_year_fact = 1'
        );
        $stmt->execute(array_merge([$documentRowId], array_keys($this->factShortNameMap())));

        $facts = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $shortName = (string)($row['short_name'] ?? '');
            $metricKey = $this->factShortNameMap()[$shortName] ?? null;
            if ($metricKey === null || trim((string)($row['normalised_numeric'] ?? '')) === '') {
                continue;
            }
            $facts[$metricKey] = round((float)$row['normalised_numeric'], 2);
        }

        return $facts;
    }

    private function factShortNameMap(): array {
        return [
            'FixedAssets' => 'fixed_assets',
            'CurrentAssets' => 'current_assets',
            'CreditorsDueWithinOneYear' => 'creditors_within_one_year',
            'CreditorsDueAfterMoreThanOneYear' => 'creditors_after_more_than_one_year',
            'NetCurrentAssetsLiabilities' => 'net_current_assets_liabilities',
            'TotalAssetsLessCurrentLiabilities' => 'total_assets_less_current_liabilities',
            'NetAssetsLiabilities' => 'net_assets_liabilities',
            'CapitalAndReserves' => 'equity_capital_reserves',
            'Equity' => 'equity_capital_reserves',
        ];
    }

    private function metricMap(): array {
        return [
            'fixed_assets' => 'Fixed assets',
            'current_assets' => 'Current assets',
            'creditors_within_one_year' => 'Creditors within one year',
            'creditors_after_more_than_one_year' => 'Creditors after more than one year',
            'net_current_assets_liabilities' => 'Net current assets / liabilities',
            'total_assets_less_current_liabilities' => 'Total assets less current liabilities',
            'net_assets_liabilities' => 'Net assets / liabilities',
            'equity_capital_reserves' => 'Equity',
        ];
    }
}
