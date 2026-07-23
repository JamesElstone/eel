<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class YearEndCompaniesHouseComparisonService
{
    private const DEFAULT_SOFT_THRESHOLD = 1.00;

    public function __construct(
        private readonly ?\eel_accounts\Service\YearEndMetricsService $metricsService = null,
        private readonly ?\eel_accounts\Service\CompaniesHouseStoredDataService $storedDataService = null,
    ) {
    }

    public function fetchComparison(
        int $companyId,
        int $accountingPeriodId,
        ?array $accountingPeriod = null,
        ?array $appMetrics = null
    ): array {
        $metrics = $this->metricsService ?? new \eel_accounts\Service\YearEndMetricsService();
        $accountingPeriod ??= $metrics->fetchAccountingPeriod($companyId, $accountingPeriodId);
        $company = $metrics->fetchCompanySummary($companyId);

        if ($accountingPeriod === null || $company === null) {
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

        $appMetrics ??= $metrics->fetchBalanceSheetMetricValues(
            $companyId,
            $accountingPeriodId,
            (string)$accountingPeriod['period_start'],
            (string)$accountingPeriod['period_end']
        );
        $reliableClosingBalance = array_key_exists('reliable_closing_balance', $appMetrics)
            ? !empty($appMetrics['reliable_closing_balance'])
            : true;
        $priorPeriodDependency = (array)($appMetrics['prior_period_dependency'] ?? []);
        $warnings = array_values(array_filter(array_map('strval', (array)($appMetrics['warnings'] ?? []))));
        $threshold = $this->comparisonThreshold($companyId);
        $stored = $this->storedDataService ?? new \eel_accounts\Service\CompaniesHouseStoredDataService();
        $summaries = $stored->fetchDocumentSummariesByCompanyNumber($companyNumber);
        $nearest = $this->findNearestSummary($summaries, (string)$accountingPeriod['period_end']);
        $exact = $this->findExactSummary($summaries, (string)$accountingPeriod['period_end']);
        $hasExactFiling = $exact !== null;
        $facts = $hasExactFiling ? $this->fetchMetricFacts((int)$exact['id']) : [];
        $rows = $this->buildRows($appMetrics, $facts, $threshold, $hasExactFiling);
        $comparableCount = count(array_filter($rows, static fn(array $row): bool => $row['variance'] !== null));
        $matchedCount = count(array_filter($rows, static fn(array $row): bool => (string)$row['status'] === 'pass'));
        $mismatchCount = count(array_filter($rows, static fn(array $row): bool => in_array((string)$row['status'], ['warning', 'fail'], true)));
        $comparisonScope = $hasExactFiling ? 'exact_filing' : ($summaries === [] ? 'no_exact_filing' : ($nearest === null ? 'stored_filing_unparseable' : 'no_exact_filing'));
        $comparisonNote = 'No exact Companies House accounts filing is available for this accounting period. Filed values and variances are shown as -.';
        if ($hasExactFiling && $comparableCount === 0) {
            $comparisonNote = 'An exact-period Companies House filing was selected, but it contains no comparable numeric facts for these metrics.';
        } elseif ($hasExactFiling && $mismatchCount > 0) {
            $comparisonNote = 'An exact-period Companies House filing was selected, but ' . $mismatchCount . ' of ' . $comparableCount . ' comparable values differ from the current reconstructed accounts.';
        } elseif ($hasExactFiling) {
            $comparisonNote = 'An exact-period Companies House filing was selected and all ' . $matchedCount . ' comparable values match the current reconstructed accounts.';
        }
        if (!$reliableClosingBalance) {
            $comparisonNote = 'Provisional comparison only: the prior accounting period must be locked before these reconstructed closing balances can be approved. ' . $comparisonNote;
        }

        return [
            'available' => true,
            'has_exact_filing' => $hasExactFiling,
            'threshold' => $threshold,
            'comparison_scope' => $comparisonScope,
            'comparison_note' => $comparisonNote,
            'comparable_count' => $comparableCount,
            'matched_count' => $matchedCount,
            'mismatch_count' => $mismatchCount,
            'reliable_closing_balance' => $reliableClosingBalance,
            'can_acknowledge' => $reliableClosingBalance,
            'prior_period_dependency' => $priorPeriodDependency,
            'warnings' => $warnings,
            'filing' => $hasExactFiling ? [
                'document_row_id' => (int)($exact['id'] ?? 0),
                'filing_date' => (string)($exact['filing_date'] ?? ''),
                'filing_type' => (string)($exact['filing_type'] ?? ''),
                'period_start' => (string)($exact['period_start'] ?? ''),
                'period_end' => (string)($exact['period_end'] ?? ''),
                'parse_status' => (string)($exact['parse_status'] ?? ''),
            ] : null,
            'nearest_filing' => $nearest,
            'rows' => $rows,
        ];
    }

    private function buildRows(array $appMetrics, array $facts, float $threshold, bool $hasExactFiling): array {
        $rows = [];
        foreach ($this->metricMap() as $metricKey => $label) {
            $appValue = isset($appMetrics[$metricKey]) ? round((float)$appMetrics[$metricKey], 2) : null;
            $filedFact = (array)($facts[$metricKey] ?? []);
            $filedValue = $hasExactFiling && array_key_exists('value', $filedFact) ? round((float)$filedFact['value'], 2) : null;
            $variance = ($appValue !== null && $filedValue !== null) ? round($appValue - $filedValue, 2) : null;
            $status = $hasExactFiling ? 'not_applicable' : 'not_filed';
            if ($variance !== null) {
                $status = abs($variance) < 0.005 ? 'pass' : (abs($variance) <= $threshold ? 'warning' : 'fail');
            }
            $rows[] = [
                'metric_key' => $metricKey, 'label' => $label, 'app_value' => $appValue,
                'filed_value' => $filedValue, 'variance' => $variance, 'status' => $status,
                'source_concept' => (string)($filedFact['source_concept'] ?? ''),
            ];
        }
        return $rows;
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

    private function findExactSummary(array $summaries, string $periodEnd): ?array {
        foreach ($summaries as $summary) {
            $candidateEnd = (string)($summary['latest_year_period_end'] ?? $summary['balance_sheet_date'] ?? '');
            if ($candidateEnd !== $periodEnd) {
                continue;
            }

            return [
                'id' => (int)($summary['id'] ?? 0),
                'filing_date' => (string)($summary['filing_date'] ?? ''),
                'filing_type' => (string)($summary['filing_type'] ?? ''),
                'period_start' => (string)($summary['latest_year_period_start'] ?? ''),
                'period_end' => $candidateEnd,
                'parse_status' => (string)($summary['parse_status'] ?? ''),
            ];
        }

        return null;
    }

    private function fetchMetricFacts(int $documentRowId): array {
        $placeholders = implode(', ', array_fill(0, count($this->factShortNameMap()), '?'));
        $stmt = \InterfaceDB::prepare(
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
            if (!isset($facts[$metricKey])) {
                $facts[$metricKey] = [
                    'value' => round((float)$row['normalised_numeric'], 2),
                    'source_concept' => $shortName,
                ];
            }
        }

        return $facts;
    }

    private function factShortNameMap(): array {
        return [
            'FixedAssets' => 'fixed_assets',
            'CurrentAssets' => 'current_assets',
            'PrepaymentsAccruedIncome' => 'prepayments_accrued_income',
            'CreditorsDueWithinOneYear' => 'creditors_within_one_year',
            'CreditorsDueAfterOneYear' => 'creditors_after_more_than_one_year',
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
            'prepayments_accrued_income' => 'Prepayments and accrued income',
            'creditors_within_one_year' => 'Creditors within one year',
            'creditors_after_more_than_one_year' => 'Creditors after more than one year',
            'net_current_assets_liabilities' => 'Net current assets/liabilities',
            'total_assets_less_current_liabilities' => 'Total assets less current liabilities',
            'net_assets_liabilities' => 'Net assets/liabilities',
            'equity_capital_reserves' => 'Equity / capital and reserves',
        ];
    }

    private function comparisonThreshold(int $companyId): float {
        $metrics = $this->metricsService ?? new \eel_accounts\Service\YearEndMetricsService();
        $settings = $metrics->fetchCompanySettings($companyId);
        $value = isset($settings['year_end_comparison_soft_threshold']) ? (float)$settings['year_end_comparison_soft_threshold'] : self::DEFAULT_SOFT_THRESHOLD;

        return $value > 0 ? round($value, 2) : self::DEFAULT_SOFT_THRESHOLD;
    }
}
