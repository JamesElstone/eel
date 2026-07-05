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
    ) {
    }

    public function fetchSummary(int $companyId, int $accountingPeriodId): array {
        $service = $this->taxComputationService ?? new \eel_accounts\Service\CorporationTaxComputationService(
            $this->metricsService ?? new \eel_accounts\Service\YearEndMetricsService()
        );

        return $service->fetchSummary($companyId, $accountingPeriodId);
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

        $periodService = new \eel_accounts\Service\CorporationTaxPeriodService();
        $sync = $periodService->syncForAccountingPeriod($companyId, $accountingPeriodId);
        $ctPeriods = array_values(array_filter(
            (array)($sync['periods'] ?? []),
            static fn(array $period): bool => (string)($period['status'] ?? '') !== 'superseded'
        ));

        if ($ctPeriods === []) {
            return [
                'available' => false,
                'errors' => (array)($sync['errors'] ?? ['No CT periods are available for the selected accounting period.']),
                'periods' => [],
                'totals' => [],
            ];
        }

        usort($ctPeriods, static function (array $a, array $b): int {
            $sequenceCompare = (int)($a['sequence_no'] ?? 0) <=> (int)($b['sequence_no'] ?? 0);
            return $sequenceCompare !== 0 ? $sequenceCompare : ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
        });

        $service = $this->taxComputationService ?? new \eel_accounts\Service\CorporationTaxComputationService(
            $this->metricsService ?? new \eel_accounts\Service\YearEndMetricsService()
        );
        $periodSummaries = [];
        $errors = (array)($sync['errors'] ?? []);

        foreach ($ctPeriods as $ctPeriod) {
            $ctPeriodId = (int)($ctPeriod['id'] ?? 0);
            if ($ctPeriodId <= 0) {
                continue;
            }

            $summary = $service->fetchSummaryForCtPeriodId($companyId, $ctPeriodId);
            if (empty($summary['available'])) {
                foreach ((array)($summary['errors'] ?? ['CT period summary could not be generated.']) as $error) {
                    $errors[] = 'CT period ' . (int)($ctPeriod['sequence_no'] ?? 0) . ': ' . (string)$error;
                }
                continue;
            }

            $periodSummaries[] = array_merge($summary, [
                'ct_period_id' => $ctPeriodId,
                'accounting_period_id' => $accountingPeriodId,
                'ct_period_sequence_no' => (int)($ctPeriod['sequence_no'] ?? 0),
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

        $totals = $this->totals($periodSummaries);
        $warnings = $this->warnings($periodSummaries);
        $confidenceStatus = $warnings === [] ? 'ready_for_review' : 'review_required';

        return array_merge($totals, [
            'available' => true,
            'errors' => $errors,
            'periods' => $periodSummaries,
            'totals' => $totals,
            'warnings' => $warnings,
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
            'depreciation_add_back',
            'capital_allowances',
            'taxable_before_losses',
            'taxable_profit',
            'taxable_loss',
            'estimated_corporation_tax',
            'loss_created_in_period',
            'losses_brought_forward',
            'losses_used',
            'losses_carried_forward',
        ];
        $totals = [];
        foreach ($fields as $field) {
            $totals[$field] = round(array_sum(array_map(
                static fn(array $period): float => (float)($period[$field] ?? 0),
                $periods
            )), 2);
        }

        $totals['other_treatment_count'] = array_sum(array_map(static fn(array $period): int => (int)($period['other_treatment_count'] ?? 0), $periods));
        $totals['unknown_treatment_count'] = array_sum(array_map(static fn(array $period): int => (int)($period['unknown_treatment_count'] ?? 0), $periods));
        $totals['ct_period_count'] = count($periods);

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
