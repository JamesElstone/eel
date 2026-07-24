<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class YearEndTaxReadinessService
{
    public function __construct(
        private readonly ?\eel_accounts\Service\YearEndMetricsService $metricsService = null,
        private readonly ?\eel_accounts\Service\CorporationTaxComputationService $taxComputationService = null,
        private readonly ?\eel_accounts\Service\CorporationTaxProvisionService $provisionService = null,
    ) {
    }

    public function fetchSummary(int $companyId, int $accountingPeriodId): array {
        return $this->fetchAccountingPeriodCtSummary($companyId, $accountingPeriodId);
    }

    public function fetchCurrentPeriodEstimate(
        int $companyId,
        int $accountingPeriodId,
        ?array $accountingPeriod = null,
        ?array $profitAndLoss = null
    ): array {
        $service = $this->taxComputationService ?? new \eel_accounts\Service\CorporationTaxComputationService(
            $this->metricsService ?? new \eel_accounts\Service\YearEndMetricsService()
        );

        return $service->fetchCurrentPeriodEstimate($companyId, $accountingPeriodId, $accountingPeriod, $profitAndLoss);
    }

    public function fetchAccountingPeriodCtSummary(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [
                'available' => false,
                'errors' => ['Select a company and accounting period before reviewing Corporation Tax.'],
                'periods' => [],
                'totals' => [],
            ];
        }

        $service = $this->taxComputationService ?? new \eel_accounts\Service\CorporationTaxComputationService(
            $this->metricsService ?? new \eel_accounts\Service\YearEndMetricsService()
        );
        $activePeriods = $service->activeCtPeriodsForAccountingPeriod($companyId, $accountingPeriodId);
        $ctPeriods = (array)($activePeriods['periods'] ?? []);

        if ($ctPeriods === []) {
            return [
                'available' => false,
                'errors' => (array)($activePeriods['errors'] ?? ['No CT periods are available for the selected accounting period.']),
                'periods' => [],
                'totals' => [],
            ];
        }

        $service->preloadCtPeriodLossPositionsForAccountingPeriod($companyId, $accountingPeriodId);

        $periodSummaries = [];
        $errors = (array)($activePeriods['errors'] ?? []);
        $expectedPeriodCount = count($ctPeriods);

        foreach ($ctPeriods as $ctPeriod) {
            $ctPeriodId = (int)($ctPeriod['id'] ?? 0);
            if ($ctPeriodId === 0) {
                continue;
            }

            $summary = $service->fetchSummaryForCtPeriodId($companyId, $ctPeriodId);
            if (empty($summary['available'])) {
                foreach ((array)($summary['errors'] ?? ['CT period summary could not be generated.']) as $error) {
                    $errors[] = (string)($ctPeriod['display_label'] ?? ('CT period ' . (int)($ctPeriod['sequence_no'] ?? 0))) . ': ' . (string)$error;
                }
                continue;
            }

            $returnPosition = (new CorporationTaxReturnPositionService($service))->fetchForCtPeriod(
                $companyId,
                $accountingPeriodId,
                $ctPeriodId,
                $summary
            );
            if (empty($returnPosition['available'])) {
                foreach ((array)($returnPosition['errors'] ?? ['The Corporation Tax return position could not be calculated.']) as $error) {
                    $errors[] = (string)($ctPeriod['display_label'] ?? ('CT period ' . (int)($ctPeriod['sequence_no'] ?? 0))) . ': ' . (string)$error;
                }
                continue;
            }

            $periodSummaries[] = array_merge($summary, $returnPosition, [
                'ct_period_id' => $ctPeriodId,
                'accounting_period_id' => $accountingPeriodId,
                'ct_period_sequence_no' => (int)($ctPeriod['sequence_no'] ?? 0),
                'ct_period_display_sequence_no' => (int)($ctPeriod['display_sequence_no'] ?? ($ctPeriod['sequence_no'] ?? 0)),
                'period_start' => (string)($ctPeriod['period_start'] ?? ($summary['period_start'] ?? '')),
                'period_end' => (string)($ctPeriod['period_end'] ?? ($summary['period_end'] ?? '')),
                'period_label' => $this->periodLabel((string)($ctPeriod['period_start'] ?? ''), (string)($ctPeriod['period_end'] ?? '')),
            ]);
        }

        if ($periodSummaries === []) {
            return [
                'available' => false,
                'errors' => $errors !== [] ? $errors : ['No CT period summaries could be generated.'],
                'periods' => [],
                'totals' => [],
            ];
        }

        $periodSummaries = (new CorporationTaxHardGateService())->apply($companyId, $periodSummaries);
        $totals = $this->totals($periodSummaries);
        $warnings = $this->warnings($periodSummaries);
        $prepaymentPreviewWarnings = array_values(array_unique(array_merge(...array_map(
            static fn(array $period): array => array_map(
                'strval',
                (array)($period['prepayment_preview_warnings'] ?? [])
            ),
            $periodSummaries
        ))));
        $prepaymentPreviewReliable = !in_array(false, array_map(
            static fn(array $period): bool => !array_key_exists('prepayment_preview_reliable', $period)
                || !empty($period['prepayment_preview_reliable']),
            $periodSummaries
        ), true);
        $confidenceStatus = $warnings === [] ? 'ready_for_review' : 'review_required';
        $provision = ($this->provisionService ?? new \eel_accounts\Service\CorporationTaxProvisionService())
            ->fetchAccountingPeriodPosition($companyId, $accountingPeriodId, $periodSummaries);
        $freeze = (new YearEndTaxFreezeService())->build(
            $companyId,
            $accountingPeriodId,
            $periodSummaries,
            array_values(array_map('strval', $errors)),
            $expectedPeriodCount
        );

        return array_merge($totals, $freeze, [
            'available' => true,
            'errors' => $errors,
            'periods' => $periodSummaries,
            'totals' => $totals,
            'warnings' => $warnings,
            'prepayment_preview_reliable' => $prepaymentPreviewReliable,
            'prepayment_preview_warnings' => $prepaymentPreviewWarnings,
            'provision' => $provision,
            'calculation_status' => 'estimate',
            'confidence_status' => $confidenceStatus,
            'confidence_label' => $confidenceStatus === 'ready_for_review' ? 'Ready for review' : 'Review required',
            'summary_scope' => 'accounting_period_ct_periods',
        ]);
    }

    private function totals(array $periods): array
    {
        $fields = [
            'accounting_profit',
            'disallowable_add_backs',
            'capital_add_backs',
            'depreciation_add_back',
            'capital_allowances',
            'taxable_before_losses',
            'taxable_profit',
            'taxable_loss',
            'ordinary_corporation_tax',
            's455_tax',
            'ct600a_tax',
            'estimated_corporation_tax',
            'loss_created_in_period',
            'losses_used',
        ];
        $totals = [];
        foreach ($fields as $field) {
            $totals[$field] = round(array_sum(array_map(
                static fn(array $period): float => (float)($period[$field] ?? 0),
                $periods
            )), 2);
        }
        $firstPeriod = (array)reset($periods);
        $lastPeriod = (array)end($periods);
        $totals['losses_brought_forward'] = round((float)($firstPeriod['losses_brought_forward'] ?? 0), 2);
        $totals['losses_carried_forward'] = round((float)($lastPeriod['losses_carried_forward'] ?? 0), 2);
        $totals['estimated_rate'] = (float)$totals['taxable_profit'] > 0.0
            ? round((float)$totals['ordinary_corporation_tax'] / (float)$totals['taxable_profit'], 6)
            : 0.0;
        $totals['ct_rate_bands'] = [];
        foreach ($periods as $period) {
            foreach ((array)($period['ct_rate_bands'] ?? []) as $band) {
                if (is_array($band)) {
                    $totals['ct_rate_bands'][] = $band;
                }
            }
        }

        $totals['other_treatment_count'] = array_sum(array_map(static fn(array $period): int => (int)($period['other_treatment_count'] ?? 0), $periods));
        $totals['unknown_treatment_count'] = array_sum(array_map(static fn(array $period): int => (int)($period['unknown_treatment_count'] ?? 0), $periods));
        $totals['other_treatment_amount'] = round(array_sum(array_map(static fn(array $period): float => (float)($period['other_treatment_amount'] ?? 0), $periods)), 2);
        $totals['unknown_treatment_amount'] = round(array_sum(array_map(static fn(array $period): float => (float)($period['unknown_treatment_amount'] ?? 0), $periods)), 2);
        $totals['ct_period_count'] = count($periods);
        $totals['hard_gate_diagnostic_count'] = array_sum(array_map(
            static fn(array $period): int => count((array)($period['hard_gate_diagnostics'] ?? [])),
            $periods
        ));

        return $totals;
    }

    private function warnings(array $periods): array
    {
        $warnings = [];
        foreach ($periods as $period) {
            foreach ((array)($period['warnings'] ?? []) as $warning) {
                $warning = trim((string)$warning);
                if ($warning !== '') {
                    $warnings[] = $warning;
                }
            }
        }

        return array_values(array_unique($warnings));
    }

    private function periodLabel(string $start, string $end): string
    {
        if ($start === '' || $end === '') {
            return 'CT period';
        }

        return \eel_accounts\Service\TaxPeriodService::accountingPeriodLabel($start, $end);
    }
}
