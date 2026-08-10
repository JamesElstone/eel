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
        private readonly ?\eel_accounts\Service\CompaniesHousePeriodFilingResolverService $filingResolver = null,
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
        $originalSummaries = array_values(array_filter(
            $summaries,
            fn(array $summary): bool => !$this->summaryIsRevision($summary)
        ));
        $nearest = $this->findNearestSummary($originalSummaries, (string)$accountingPeriod['period_end']);
        $resolution = ($this->filingResolver ?? new \eel_accounts\Service\CompaniesHousePeriodFilingResolverService($stored))
            ->resolve(
                $companyId,
                $accountingPeriodId,
                $companyNumber,
                (string)$accountingPeriod['period_end'],
                $summaries
            );
        $exact = is_array($resolution['original'] ?? null) ? (array)$resolution['original'] : null;
        $hasExactFiling = $exact !== null;
        if ($hasExactFiling) {
            // `nearest_filing` predates revised-filing observation and forms
            // part of the signed Year End basis. Keep its legacy six-key
            // shape anchored to the resolved original so a later AAMD cannot
            // invalidate an existing approval.
            $nearest = $this->legacySummary($exact);
        }
        $filingKind = $hasExactFiling ? 'revised' : 'original';
        $filingReason = $hasExactFiling ? 'exact_period_filing_found' : 'no_exact_period_filing_found';
        $originalParseStatus = strtolower(trim((string)($exact['parse_status'] ?? '')));
        $originalReadable = $hasExactFiling
            && in_array($originalParseStatus, ['parsed', 'parsed_latest_year'], true);
        $facts = $originalReadable ? $this->fetchMetricFacts((int)$exact['id']) : [];
        $rows = $this->buildRows($appMetrics, $facts, $threshold, $hasExactFiling);
        $comparableCount = count(array_filter($rows, static fn(array $row): bool => $row['variance'] !== null));
        $matchedCount = count(array_filter($rows, static fn(array $row): bool => (string)$row['status'] === 'pass'));
        $mismatchCount = count(array_filter($rows, static fn(array $row): bool => in_array((string)$row['status'], ['warning', 'fail'], true)));
        $originalVerifiable = !$hasExactFiling || ($originalReadable && $comparableCount > 0);
        $comparisonScope = $hasExactFiling
            ? ($originalVerifiable ? 'exact_filing' : 'exact_filing_unverifiable')
            : ($originalSummaries === []
                ? 'no_exact_filing'
                : ($nearest === null ? 'stored_filing_unparseable' : 'no_exact_filing'));
        $comparisonNote = 'No exact Companies House accounts filing is available for this accounting period. Filed values and variances are shown as -.';
        if ($hasExactFiling && !$originalReadable) {
            $comparisonNote = 'The exact-period original Companies House filing is stored but could not be parsed, so this comparison cannot be approved.';
            $warnings[] = trim((string)($exact['parse_error'] ?? '')) !== ''
                ? trim((string)$exact['parse_error'])
                : 'The exact-period original Companies House filing must be parsed before this comparison can be approved.';
        } elseif ($hasExactFiling && $comparableCount === 0) {
            $comparisonNote = 'An exact-period Companies House filing was selected, but it contains no comparable numeric facts for these metrics.';
            $warnings[] = 'The exact-period original Companies House filing contains no comparable facts and cannot be approved.';
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
            'filing_kind' => $filingKind,
            'filing_reason' => $filingReason,
            'threshold' => $threshold,
            'comparison_scope' => $comparisonScope,
            'comparison_note' => $comparisonNote,
            'comparable_count' => $comparableCount,
            'matched_count' => $matchedCount,
            'mismatch_count' => $mismatchCount,
            'reliable_closing_balance' => $reliableClosingBalance,
            'can_acknowledge' => $reliableClosingBalance && $originalVerifiable,
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
            'filing_evidence' => [
                'filing_kind' => $filingKind,
                'reason' => $filingReason,
                'document_row_id' => $hasExactFiling ? (int)($exact['id'] ?? 0) : null,
                'filing_date' => $hasExactFiling ? (string)($exact['filing_date'] ?? '') : null,
                'period_start' => $hasExactFiling ? (string)($exact['period_start'] ?? '') : null,
                'period_end' => $hasExactFiling
                    ? (string)($exact['period_end'] ?? '')
                    : (string)$accountingPeriod['period_end'],
            ],
            'nearest_filing' => $nearest,
            'rows' => $rows,
        ];
    }

    /**
     * Observe the newest imported revised filing without changing the original
     * comparison contract that was signed during Year End approval.
     */
    public function fetchRevisedObservation(
        int $companyId,
        int $accountingPeriodId,
        ?array $accountingPeriod = null,
        ?array $appMetrics = null
    ): array {
        $metrics = $this->metricsService ?? new \eel_accounts\Service\YearEndMetricsService();
        $accountingPeriod ??= $metrics->fetchAccountingPeriod($companyId, $accountingPeriodId);
        $company = $metrics->fetchCompanySummary($companyId);
        if ($accountingPeriod === null || $company === null) {
            return $this->unavailableRevisedObservation(
                ['The selected company or accounting period could not be found.']
            );
        }

        $companyNumber = strtoupper(trim((string)($company['company_number'] ?? '')));
        if ($companyNumber === '') {
            return $this->unavailableRevisedObservation(
                ['No Companies House number is stored for this company.']
            );
        }

        $appMetrics ??= $metrics->fetchBalanceSheetMetricValues(
            $companyId,
            $accountingPeriodId,
            (string)$accountingPeriod['period_start'],
            (string)$accountingPeriod['period_end']
        );
        $threshold = $this->comparisonThreshold($companyId);
        $stored = $this->storedDataService ?? new \eel_accounts\Service\CompaniesHouseStoredDataService();
        $summaries = $stored->fetchDocumentSummariesByCompanyNumber($companyNumber);
        $resolution = ($this->filingResolver ?? new \eel_accounts\Service\CompaniesHousePeriodFilingResolverService($stored))
            ->resolve(
                $companyId,
                $accountingPeriodId,
                $companyNumber,
                (string)$accountingPeriod['period_end'],
                $summaries
            );
        $revisions = array_values(array_filter((array)($resolution['revisions'] ?? []), 'is_array'));
        $latest = is_array($resolution['latest_revision'] ?? null)
            ? (array)$resolution['latest_revision']
            : null;
        $hasRevision = $latest !== null;
        $parseStatus = trim((string)($latest['parse_status'] ?? ''));
        $parseError = trim((string)($latest['parse_error'] ?? ''));
        $readable = $hasRevision && in_array(
            $parseStatus,
            ['parsed', 'parsed_latest_year'],
            true
        );
        $factEvidenceError = '';
        $facts = [];
        if ($readable) {
            try {
                $facts = $this->fetchRevisedMetricFacts((int)$latest['id']);
            } catch (\Throwable $exception) {
                $factEvidenceError = trim($exception->getMessage());
            }
        }
        $extractedFactCount = count($facts);
        $rows = $this->buildRevisedRows($appMetrics, $facts, $threshold, $hasRevision, $readable);
        $comparableCount = count(array_filter($rows, static fn(array $row): bool => $row['variance'] !== null));
        $matchedCount = count(array_filter($rows, static fn(array $row): bool => (string)$row['status'] === 'pass'));
        $mismatchCount = count(array_filter(
            $rows,
            static fn(array $row): bool => in_array((string)$row['status'], ['warning', 'fail'], true)
        ));
        $missingNonZeroRows = array_values(array_filter(
            $rows,
            static fn(array $row): bool => (string)($row['status'] ?? '') === 'missing'
        ));
        $missingNonZeroCount = count($missingNonZeroRows);

        $original = $this->fetchComparison($companyId, $accountingPeriodId, $accountingPeriod, $appMetrics);
        $originalCorrectionRequired = (int)($original['mismatch_count'] ?? 0) > 0;
        $reconciliationState = 'awaiting';
        if ($hasRevision && (
            !$readable
            || $factEvidenceError !== ''
            || $extractedFactCount === 0
            || $comparableCount === 0
            || $missingNonZeroCount > 0
        )) {
            $reconciliationState = 'unverifiable';
        } elseif ($hasRevision && $mismatchCount > 0) {
            $reconciliationState = 'mismatch';
        } elseif ($hasRevision) {
            $reconciliationState = 'verified';
        }
        $revisionReconciled = $reconciliationState === 'verified';
        $filingOutstanding = $hasRevision
            ? !$revisionReconciled
            : $originalCorrectionRequired;

        $warnings = array_values(array_filter(array_map(
            'strval',
            (array)($appMetrics['warnings'] ?? [])
        )));
        $errors = [];
        if ($hasRevision && !$readable) {
            $errors[] = $parseError !== ''
                ? $parseError
                : 'The latest revised Companies House filing could not be parsed for comparison.';
        } elseif ($hasRevision && $factEvidenceError !== '') {
            $errors[] = $factEvidenceError;
        } elseif ($hasRevision && $extractedFactCount === 0) {
            $errors[] = 'The latest revised Companies House filing contains no supported numeric facts for these metrics.';
        } elseif ($hasRevision && $comparableCount === 0) {
            $errors[] = 'The latest revised Companies House filing contains no comparable numeric facts for these metrics.';
        } elseif ($hasRevision && $missingNonZeroCount > 0) {
            $errors[] = 'The latest revised Companies House filing omits non-zero reconstructed values for: '
                . implode(', ', array_map(
                    static fn(array $row): string => (string)($row['label'] ?? $row['metric_key'] ?? ''),
                    $missingNonZeroRows
                ))
                . '.';
        }

        $note = 'No revised Companies House filing has been imported for this accounting period.';
        if ($reconciliationState === 'verified') {
            $note = 'The latest revised filing reconciles to all ' . $matchedCount . ' comparable reconstructed values.';
        } elseif ($reconciliationState === 'mismatch') {
            $note = $mismatchCount . ' of ' . $comparableCount
                . ' comparable values in the latest revised filing differ from the current reconstructed accounts.';
        } elseif ($reconciliationState === 'unverifiable') {
            $note = (string)($errors[0] ?? 'The latest revised filing cannot be verified.');
        }

        return [
            'available' => true,
            'has_revised_filing' => $hasRevision,
            'revision_count' => count($revisions),
            'filing' => $latest,
            'revisions' => $revisions,
            'rows' => $rows,
            'extracted_fact_count' => $extractedFactCount,
            'comparable_count' => $comparableCount,
            'matched_count' => $matchedCount,
            'mismatch_count' => $mismatchCount,
            'missing_non_zero_count' => $missingNonZeroCount,
            'coverage_complete' => $hasRevision
                && $readable
                && $extractedFactCount > 0
                && $comparableCount > 0
                && $missingNonZeroCount === 0,
            'reconciliation_state' => $reconciliationState,
            'revision_reconciled' => $revisionReconciled,
            'filing_outstanding' => $filingOutstanding,
            'action_required' => $filingOutstanding,
            'comparison_note' => $note,
            'parse_status' => $parseStatus,
            'parse_error' => $parseError,
            'evidence_error' => $factEvidenceError,
            'warnings' => $warnings,
            'errors' => $errors,
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

    private function buildRevisedRows(
        array $appMetrics,
        array $facts,
        float $threshold,
        bool $hasRevision,
        bool $readable
    ): array {
        $rows = [];
        foreach ($this->metricMap() as $metricKey => $label) {
            $appValue = isset($appMetrics[$metricKey]) ? round((float)$appMetrics[$metricKey], 2) : null;
            $filedFact = (array)($facts[$metricKey] ?? []);
            $hasFiledFact = $readable && array_key_exists('value', $filedFact);
            $implicitZero = $readable
                && !$hasFiledFact
                && $appValue !== null
                && abs($appValue) < 0.005;
            $revisedValue = $hasFiledFact
                ? round((float)$filedFact['value'], 2)
                : ($implicitZero ? 0.0 : null);
            $variance = ($appValue !== null && $revisedValue !== null)
                ? round($appValue - $revisedValue, 2)
                : null;
            $status = !$hasRevision ? 'not_filed' : ($readable ? 'not_applicable' : 'unavailable');
            if ($variance !== null) {
                $status = abs($variance) < 0.005 ? 'pass' : (abs($variance) <= $threshold ? 'warning' : 'fail');
            } elseif ($readable && $appValue !== null && abs($appValue) >= 0.005) {
                $status = 'missing';
            }
            $rows[] = [
                'metric_key' => $metricKey,
                'label' => $label,
                'app_value' => $appValue,
                'revised_filed_value' => $revisedValue,
                'variance' => $variance,
                'status' => $status,
                'source_concept' => (string)($filedFact['source_concept'] ?? ''),
                'implicit_zero' => $implicitZero,
            ];
        }
        return $rows;
    }

    private function findNearestSummary(array $summaries, string $periodEnd): ?array {
        $target = strtotime($periodEnd) ?: 0;
        $best = null;
        $bestDistance = null;

        foreach ($summaries as $summary) {
            if ($this->summaryIsRevision((array)$summary)) {
                continue;
            }
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

    /** @param array<string,mixed> $summary */
    private function summaryIsRevision(array $summary): bool
    {
        $filingType = strtoupper(trim((string)($summary['filing_type'] ?? '')));
        $description = strtolower(trim((string)($summary['filing_description'] ?? '')));
        $marker = $summary['revision_marker'] ?? false;
        $markerIsTrue = is_bool($marker)
            ? $marker
            : in_array(strtolower(trim((string)$marker)), ['1', 'true', 'yes'], true);

        return $filingType === 'AAMD'
            || str_contains($description, 'accounts-amended')
            || str_contains($description, 'amended-accounts')
            || $markerIsTrue;
    }

    private function findExactSummary(array $summaries, string $periodEnd): ?array {
        foreach ($summaries as $summary) {
            if ($this->summaryIsRevision((array)$summary)) {
                continue;
            }
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

    /** @param array<string,mixed> $summary @return array<string,mixed> */
    private function legacySummary(array $summary): array
    {
        return [
            'id' => (int)($summary['id'] ?? $summary['document_row_id'] ?? 0),
            'filing_date' => (string)($summary['filing_date'] ?? ''),
            'filing_type' => (string)($summary['filing_type'] ?? ''),
            'period_start' => (string)(
                $summary['period_start']
                ?? $summary['latest_year_period_start']
                ?? ''
            ),
            'period_end' => (string)(
                $summary['period_end']
                ?? $summary['latest_year_period_end']
                ?? $summary['balance_sheet_date']
                ?? ''
            ),
            'parse_status' => (string)($summary['parse_status'] ?? ''),
        ];
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

    /**
     * Revised accounts contain hidden superseded facts alongside the current
     * facts. Read only non-superseded contexts, and resolve the generic
     * Creditors concept through its maturity dimension.
     */
    private function fetchRevisedMetricFacts(int $documentRowId): array
    {
        $factShortNameMap = $this->revisedFactShortNameMap();
        $shortNames = array_values(array_unique(array_merge(
            array_keys($factShortNameMap),
            ['Creditors']
        )));
        $placeholders = implode(', ', array_fill(0, count($shortNames), '?'));
        $stmt = \InterfaceDB::prepare(
             'SELECT c.short_name,
                    f.normalised_numeric,
                    f.sign_hint,
                    ctx.context_ref,
                    ctx.dimension_json,
                    f.id
             FROM companies_house_document_facts f
             INNER JOIN companies_house_taxonomy_concepts c ON c.id = f.concept_fk
             INNER JOIN companies_house_document_contexts ctx ON ctx.id = f.context_fk
             WHERE f.document_fk = ?
               AND c.short_name IN (' . $placeholders . ')
               AND f.is_latest_year_fact = 1
             ORDER BY f.id ASC'
        );
        $stmt->execute(array_merge([$documentRowId], $shortNames));

        $facts = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            if ($this->isSupersededContext(
                (string)($row['context_ref'] ?? ''),
                (string)($row['dimension_json'] ?? '')
            )) {
                continue;
            }
            if (trim((string)($row['normalised_numeric'] ?? '')) === '') {
                continue;
            }

            $shortName = (string)($row['short_name'] ?? '');
            $metricKey = $shortName === 'Creditors'
                ? $this->creditorMetricFromDimensions((string)($row['dimension_json'] ?? ''))
                : ($factShortNameMap[$shortName] ?? null);
            if ($metricKey === null) {
                continue;
            }
            $value = $this->revisedSemanticNumericValue($row);
            if (in_array($metricKey, [
                'creditors_within_one_year',
                'creditors_after_more_than_one_year',
            ], true)) {
                $value = abs($value);
            }
            if (isset($facts[$metricKey])) {
                $existingValue = round((float)($facts[$metricKey]['value'] ?? 0.0), 2);
                if (abs($existingValue - $value) >= 0.005) {
                    throw new \RuntimeException(
                        'The latest revised Companies House filing contains conflicting current facts for '
                        . (string)($this->metricMap()[$metricKey] ?? $metricKey)
                        . ' and cannot be verified.'
                    );
                }
                continue;
            }
            $facts[$metricKey] = [
                'value' => $value,
                'source_concept' => $shortName,
            ];
        }

        return $facts;
    }

    /** @param array<string,mixed> $fact */
    private function revisedSemanticNumericValue(array $fact): float
    {
        $value = round((float)($fact['normalised_numeric'] ?? 0.0), 2);
        // Companies House AAMD iXBRL can render a sign="-" fact inside
        // presentation parentheses. The stored parser value double-applies
        // those two visual signals and is therefore positive. For revised
        // observation only, the authoritative inline sign restores the
        // negative accounting value. The original approval-bound extractor is
        // intentionally unchanged.
        if (str_contains(
            strtolower(trim((string)($fact['sign_hint'] ?? ''))),
            'ix_sign'
        )) {
            return -abs($value);
        }

        return $value;
    }

    private function isSupersededContext(string $contextRef, string $dimensionJson): bool
    {
        if (str_contains(strtolower($contextRef), 'superseded')) {
            return true;
        }
        $dimensions = json_decode($dimensionJson, true);
        if (!is_array($dimensions)) {
            return false;
        }
        foreach ($dimensions as $dimension) {
            if (!is_array($dimension)) {
                continue;
            }
            $dimensionName = strtolower((string)($dimension['dimension'] ?? ''));
            $memberName = strtolower((string)($dimension['member'] ?? ''));
            if (str_ends_with($dimensionName, 'originalreviseddatadimension')
                && str_ends_with($memberName, 'superseded')) {
                return true;
            }
        }
        return false;
    }

    private function creditorMetricFromDimensions(string $dimensionJson): ?string
    {
        $dimensions = json_decode($dimensionJson, true);
        if (!is_array($dimensions)) {
            return null;
        }
        foreach ($dimensions as $dimension) {
            if (!is_array($dimension)) {
                continue;
            }
            $dimensionName = strtolower((string)($dimension['dimension'] ?? ''));
            if (!str_ends_with($dimensionName, 'maturitiesorexpirationperiodsdimension')) {
                continue;
            }
            $memberName = strtolower((string)($dimension['member'] ?? ''));
            if (str_ends_with($memberName, 'withinoneyear')) {
                return 'creditors_within_one_year';
            }
            if (str_ends_with($memberName, 'afteroneyear')
                || str_ends_with($memberName, 'aftermorethanoneyear')) {
                return 'creditors_after_more_than_one_year';
            }
        }
        return null;
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

    private function unavailableRevisedObservation(array $errors): array
    {
        return [
            'available' => false,
            'has_revised_filing' => false,
            'revision_count' => 0,
            'filing' => null,
            'revisions' => [],
            'rows' => [],
            'extracted_fact_count' => 0,
            'comparable_count' => 0,
            'matched_count' => 0,
            'mismatch_count' => 0,
            'missing_non_zero_count' => 0,
            'coverage_complete' => false,
            'reconciliation_state' => 'unverifiable',
            'revision_reconciled' => false,
            'filing_outstanding' => true,
            'action_required' => true,
            'comparison_note' => (string)($errors[0] ?? 'The revised filing observation is unavailable.'),
            'parse_status' => '',
            'parse_error' => '',
            'evidence_error' => '',
            'warnings' => [],
            'errors' => $errors,
        ];
    }

    private function revisedFactShortNameMap(): array
    {
        return $this->factShortNameMap() + [
            'PrepaymentsAccruedIncomeNotExpressedWithinCurrentAssetSubtotal'
                => 'prepayments_accrued_income',
        ];
    }
}
