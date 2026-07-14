<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class CorporationTaxComputationService
{
    private array $accountingPeriodLossScheduleCache = [];
    private array $activeCtPeriodsCache = [];
    private array $assetAdjustmentsCache = [];
    private array $associatedCompanyCountCache = [];
    private array $capitalAllowanceBreakdownCache = [];
    private array $ctPeriodCache = [];
    private array $ctPeriodLossScheduleCompleteCache = [];
    private array $ctPeriodLossScheduleCache = [];
    private array $ctPeriodSummaryCache = [];
    private array $profitAndLossSummaryCache = [];

    public function __construct(
        private readonly ?\eel_accounts\Service\YearEndMetricsService $metricsService = null,
        private readonly ?\eel_accounts\Service\CorporationTaxRateService $rateService = null,
    ) {
    }

    public function clearRuntimeCaches(): void
    {
        $this->accountingPeriodLossScheduleCache = [];
        $this->activeCtPeriodsCache = [];
        $this->assetAdjustmentsCache = [];
        $this->associatedCompanyCountCache = [];
        $this->capitalAllowanceBreakdownCache = [];
        $this->ctPeriodCache = [];
        $this->ctPeriodLossScheduleCompleteCache = [];
        $this->ctPeriodLossScheduleCache = [];
        $this->ctPeriodSummaryCache = [];
        $this->profitAndLossSummaryCache = [];
    }

    public function fetchSummary(int $companyId, int $accountingPeriodId): array {
        $metrics = $this->metricsService ?? new \eel_accounts\Service\YearEndMetricsService();
        $accountingPeriod = $metrics->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null) {
            return [
                'available' => false,
                'errors' => ['The selected accounting period could not be found.'],
            ];
        }

        try {
            $schedule = $this->rebuildLossSchedule($companyId);
        } catch (\Throwable $exception) {
            return [
                'available' => false,
                'errors' => ['The corporation tax computation could not be built: ' . $exception->getMessage()],
            ];
        }
        $current = $schedule[$accountingPeriodId] ?? null;
        if ($current === null) {
            return [
                'available' => false,
                'errors' => ['The corporation tax computation could not be built for the selected period.'],
            ];
        }

        $warnings = [];
        if ((int)($current['unknown_treatment_count'] ?? 0) > 0) {
            $warnings[] = 'Some nominal tax treatments are unknown and should be reviewed before relying on the estimate.';
        }
        if ((int)($current['other_treatment_count'] ?? 0) > 0) {
            $warnings[] = 'Some nominal tax treatments are marked as other and need manual review.';
        }
        if (!empty($current['asset_adjustment_warning'])) {
            $warnings[] = (string)$current['asset_adjustment_warning'];
        }
        foreach ((array)($current['ct_rate_warnings'] ?? []) as $warning) {
            $warnings[] = (string)$warning;
        }

        return [
            'available' => true,
            'accounting_profit' => round((float)$current['accounting_profit'], 2),
            'disallowable_add_backs' => round((float)$current['disallowable_add_backs'], 2),
            'depreciation_add_back' => round((float)$current['depreciation_add_back'], 2),
            'capital_allowances' => round((float)$current['capital_allowances'], 2),
            'taxable_before_losses' => round((float)$current['taxable_before_losses'], 2),
            'taxable_profit' => round((float)$current['taxable_profit'], 2),
            'taxable_loss' => round((float)$current['loss_created'], 2),
            'estimated_corporation_tax' => round((float)$current['estimated_corporation_tax'], 2),
            'estimated_rate' => round((float)$current['estimated_rate'], 6),
            'associated_company_count' => (int)($current['associated_company_count'] ?? 0),
            'ct_rate_bands' => (array)($current['ct_rate_bands'] ?? []),
            'loss_created_in_period' => round((float)$current['loss_created'], 2),
            'losses_brought_forward' => round((float)$current['loss_brought_forward'], 2),
            'losses_used' => round((float)$current['loss_utilised'], 2),
            'losses_carried_forward' => round((float)$current['loss_carried_forward'], 2),
            'other_treatment_count' => (int)$current['other_treatment_count'],
            'unknown_treatment_count' => (int)$current['unknown_treatment_count'],
            'warnings' => $warnings,
            'steps' => [
                ['label' => 'Accounting profit or loss', 'amount' => round((float)$current['accounting_profit'], 2)],
                ['label' => 'Add back disallowable expenses', 'amount' => round((float)$current['disallowable_add_backs'], 2)],
                ['label' => 'Add back depreciation', 'amount' => round((float)$current['depreciation_add_back'], 2)],
                ['label' => 'Deduct capital allowances', 'amount' => round(0 - (float)$current['capital_allowances'], 2)],
                ['label' => 'Taxable result before losses', 'amount' => round((float)$current['taxable_before_losses'], 2)],
                ['label' => 'Less losses brought forward utilised', 'amount' => round(0 - (float)$current['loss_utilised'], 2)],
                ['label' => 'Taxable profit after losses', 'amount' => round((float)$current['taxable_profit'], 2)],
                ['label' => 'Estimated corporation tax', 'amount' => round((float)$current['estimated_corporation_tax'], 2)],
            ],
            'schedule' => array_values(array_map(
                static fn(array $row): array => [
                    'accounting_period_id' => (int)$row['accounting_period_id'],
                    'label' => (string)$row['label'],
                    'loss_created' => round((float)$row['loss_created'], 2),
                    'loss_brought_forward' => round((float)$row['loss_brought_forward'], 2),
                    'loss_utilised' => round((float)$row['loss_utilised'], 2),
                    'loss_carried_forward' => round((float)$row['loss_carried_forward'], 2),
                    'taxable_before_losses' => round((float)$row['taxable_before_losses'], 2),
                    'taxable_profit' => round((float)$row['taxable_profit'], 2),
                ],
                $schedule
            )),
        ];
    }

    public function fetchSummaryForCtPeriodId(int $companyId, int $ctPeriodId): array {
        $cacheKey = $companyId . ':' . $ctPeriodId;
        if (isset($this->ctPeriodSummaryCache[$cacheKey])) {
            return $this->ctPeriodSummaryCache[$cacheKey];
        }

        $stored = $this->storedLockedSummaryForCtPeriodId($companyId, $ctPeriodId);
        if ($stored !== null) {
            return $this->ctPeriodSummaryCache[$cacheKey] = $stored;
        }

        $summary = $this->calculateSummaryForCtPeriodId($companyId, $ctPeriodId);
        return $this->ctPeriodSummaryCache[$cacheKey] = $this->withComputationPersistenceState($companyId, $ctPeriodId, $summary);
    }

    public function calculateSummaryForCtPeriodId(int $companyId, int $ctPeriodId): array {
        $ctPeriod = $this->fetchCtPeriod($companyId, $ctPeriodId);
        if ($ctPeriod === null) {
            return [
                'available' => false,
                'errors' => ['The selected CT period could not be found.'],
            ];
        }

        $accountingPeriodId = (int)$ctPeriod['accounting_period_id'];
        $pnl = $this->profitAndLossSummary(
            $companyId,
            $accountingPeriodId,
            (string)$ctPeriod['period_start'],
            (string)$ctPeriod['period_end']
        );
        $assetAdjustments = $this->fetchAssetAdjustmentsForCtPeriod($companyId, $accountingPeriodId, $ctPeriod);
        $taxableBeforeLosses = round(
            (float)($pnl['profit_before_tax'] ?? 0)
            + (float)($pnl['disallowable_add_backs'] ?? 0)
            + (float)$assetAdjustments['depreciation_add_back']
            - (float)$assetAdjustments['capital_allowances'],
            2
        );

        $losses = $this->ctPeriodLossPosition($companyId, $ctPeriodId);
        $lossUsed = min(max(0.0, $taxableBeforeLosses), (float)$losses['brought_forward']);
        $taxableProfit = max(0.0, round($taxableBeforeLosses - $lossUsed, 2));
        $lossCreated = $taxableBeforeLosses < 0 ? abs($taxableBeforeLosses) : 0.0;
        $lossCarriedForward = round((float)$losses['brought_forward'] - $lossUsed + $lossCreated, 2);
        $associatedCompanyCount = $this->associatedCompanyCount($companyId);
        $rateCalculation = ($this->rateService ?? new \eel_accounts\Service\CorporationTaxRateService())->calculate(
            (string)$ctPeriod['period_start'],
            (string)$ctPeriod['period_end'],
            $taxableProfit,
            $associatedCompanyCount
        );
        $computationHash = hash('sha256', json_encode([
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'ct_period_id' => $ctPeriodId,
            'period_start' => (string)$ctPeriod['period_start'],
            'period_end' => (string)$ctPeriod['period_end'],
            'accounting_profit' => (float)($pnl['profit_before_tax'] ?? 0),
            'disallowable' => (float)($pnl['disallowable_add_backs'] ?? 0),
            'depreciation' => (float)$assetAdjustments['depreciation_add_back'],
            'allowances' => (float)$assetAdjustments['capital_allowances'],
            'loss_bf' => (float)$losses['brought_forward'],
            'loss_used' => $lossUsed,
            'associated_company_count' => $associatedCompanyCount,
            'rate_liability' => (float)$rateCalculation['liability'],
        ], JSON_UNESCAPED_SLASHES));

        $row = [
            'accounting_period_id' => $accountingPeriodId,
            'ct_period_id' => $ctPeriodId,
            'label' => (string)($ctPeriod['display_label'] ?? ('CT Period ' . (int)$ctPeriod['sequence_no'])),
            'period_start' => (string)$ctPeriod['period_start'],
            'period_end' => (string)$ctPeriod['period_end'],
            'accounting_profit' => round((float)($pnl['profit_before_tax'] ?? 0), 2),
            'disallowable_add_backs' => round((float)($pnl['disallowable_add_backs'] ?? 0), 2),
            'depreciation_add_back' => round((float)$assetAdjustments['depreciation_add_back'], 2),
            'capital_allowances' => round((float)$assetAdjustments['capital_allowances'], 2),
            'taxable_before_losses' => $taxableBeforeLosses,
            'taxable_profit' => $taxableProfit,
            'estimated_corporation_tax' => round((float)$rateCalculation['liability'], 2),
            'estimated_rate' => round((float)$rateCalculation['effective_rate'], 6),
            'associated_company_count' => $associatedCompanyCount,
            'ct_rate_bands' => (array)($rateCalculation['bands'] ?? []),
            'ct_rate_warnings' => (array)($rateCalculation['warnings'] ?? []),
            'loss_created' => round($lossCreated, 2),
            'loss_brought_forward' => round((float)$losses['brought_forward'], 2),
            'loss_utilised' => round($lossUsed, 2),
            'loss_carried_forward' => $lossCarriedForward,
            'other_treatment_count' => (int)($pnl['other_treatment_count'] ?? 0),
            'unknown_treatment_count' => (int)($pnl['unknown_treatment_count'] ?? 0),
            'asset_adjustment_warning' => (string)($assetAdjustments['warning'] ?? ''),
            'computation_hash' => $computationHash,
        ];

        $summary = $this->summaryFromRows($row, [$row]);
        $summary['ct_period_id'] = $ctPeriodId;
        $summary['accounting_period_id'] = $accountingPeriodId;
        $summary['ct_period_sequence_no'] = (int)$ctPeriod['sequence_no'];
        $summary['ct_period_display_sequence_no'] = (int)($ctPeriod['display_sequence_no'] ?? $ctPeriod['sequence_no']);
        $summary['period_start'] = (string)$ctPeriod['period_start'];
        $summary['period_end'] = (string)$ctPeriod['period_end'];
        $summary['capital_allowance_breakdown'] = (array)($assetAdjustments['capital_allowance_breakdown'] ?? []);
        $summary['computation_hash'] = $computationHash;

        return $summary;
    }

    private function persistSummaryForCtPeriodIdWithCurrentCaches(int $companyId, int $ctPeriodId): array {
        $summary = $this->calculateSummaryForCtPeriodId($companyId, $ctPeriodId);
        if (empty($summary['available'])) {
            return $summary;
        }

        $row = [
            'accounting_period_id' => (int)($summary['accounting_period_id'] ?? 0),
            'ct_period_id' => (int)($summary['ct_period_id'] ?? 0),
            'period_start' => (string)($summary['period_start'] ?? ''),
            'period_end' => (string)($summary['period_end'] ?? ''),
            'taxable_before_losses' => round((float)($summary['taxable_before_losses'] ?? 0), 2),
            'taxable_profit' => round((float)($summary['taxable_profit'] ?? 0), 2),
            'loss_created' => round((float)($summary['loss_created_in_period'] ?? $summary['taxable_loss'] ?? 0), 2),
            'loss_brought_forward' => round((float)($summary['losses_brought_forward'] ?? 0), 2),
            'loss_utilised' => round((float)($summary['losses_used'] ?? 0), 2),
            'loss_carried_forward' => round((float)($summary['losses_carried_forward'] ?? 0), 2),
            'computation_hash' => (string)($summary['computation_hash'] ?? ''),
        ];
        $this->insertLossHistory($companyId, (int)$row['accounting_period_id'], (int)$row['ct_period_id'], (string)$row['computation_hash'], $row);
        $runId = $this->insertComputationRun($companyId, $row, $summary);
        if ($runId > 0) {
            (new \eel_accounts\Service\CorporationTaxPeriodService())->markLatestComputation((int)$row['ct_period_id'], $runId);
            unset($this->ctPeriodSummaryCache[$companyId . ':' . (int)$row['ct_period_id']]);
            unset($this->ctPeriodCache[$companyId . ':' . (int)$row['ct_period_id']]);
            $summary['computation_run_id'] = $runId;
            $summary = $this->withComputationPersistenceState($companyId, (int)$row['ct_period_id'], $summary);
        }

        return $summary;
    }

    public function persistSummariesForYearEndLock(int $companyId, int $accountingPeriodId): array
    {
        if (!\InterfaceDB::inTransaction()) {
            return [
                'success' => false,
                'errors' => ['Corporation Tax close evidence can only be persisted inside the Year End lock transaction.'],
                'summaries' => [],
            ];
        }

        $this->clearRuntimeCaches();
        $activePeriods = $this->activeCtPeriodsForAccountingPeriod($companyId, $accountingPeriodId);
        $periods = (array)($activePeriods['periods'] ?? []);
        $summaries = [];
        $errors = (array)($activePeriods['errors'] ?? []);
        foreach ($periods as $period) {
            $ctPeriodId = (int)($period['id'] ?? 0);
            if ($ctPeriodId <= 0) {
                continue;
            }
            $summary = $this->persistSummaryForCtPeriodIdWithCurrentCaches($companyId, $ctPeriodId);
            if (empty($summary['available'])) {
                foreach ((array)($summary['errors'] ?? ['CT period summary could not be persisted.']) as $error) {
                    $errors[] = (string)($period['display_label'] ?? ('CT Period ' . (int)($period['sequence_no'] ?? 0))) . ': ' . (string)$error;
                }
                continue;
            }
            $summaries[] = $summary;
        }

        return [
            'success' => $errors === [],
            'errors' => $errors,
            'summaries' => $summaries,
        ];
    }

    public function activeCtPeriodsForAccountingPeriod(int $companyId, int $accountingPeriodId): array
    {
        $cacheKey = $companyId . ':' . $accountingPeriodId;
        if (isset($this->activeCtPeriodsCache[$cacheKey])) {
            return $this->activeCtPeriodsCache[$cacheKey];
        }

        $sync = (new \eel_accounts\Service\CorporationTaxPeriodService())->syncForAccountingPeriod($companyId, $accountingPeriodId);
        $periods = array_values(array_filter(
            (array)($sync['periods'] ?? []),
            static fn(array $period): bool => (string)($period['status'] ?? '') !== 'superseded'
        ));
        usort($periods, static function (array $a, array $b): int {
            $sequenceCompare = (int)($a['sequence_no'] ?? 0) <=> (int)($b['sequence_no'] ?? 0);
            return $sequenceCompare !== 0 ? $sequenceCompare : ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
        });

        return $this->activeCtPeriodsCache[$cacheKey] = [
            'periods' => $periods,
            'errors' => (array)($sync['errors'] ?? []),
        ];
    }

    public function preloadCtPeriodLossPositionsForAccountingPeriod(int $companyId, int $accountingPeriodId): void
    {
        $periods = (array)($this->activeCtPeriodsForAccountingPeriod($companyId, $accountingPeriodId)['periods'] ?? []);
        $lastPeriod = end($periods);
        $lastCtPeriodId = is_array($lastPeriod) ? (int)($lastPeriod['id'] ?? 0) : 0;
        if ($lastCtPeriodId > 0) {
            $this->ctPeriodLossSchedule($companyId, $lastCtPeriodId);
        }
    }

    /**
     * Lightweight live CT position for dividend capacity. For a single CT
     * period, the full brought-forward loss position remains visible even when
     * the current period creates a further loss.
     *
     * @param array<string, mixed> $preTaxProfitLoss
     */
    public function fetchDividendCapacityEstimate(
        int $companyId,
        int $accountingPeriodId,
        string $asAtDate,
        array $preTaxProfitLoss
    ): array {
        $active = $this->activeCtPeriodsForAccountingPeriod($companyId, $accountingPeriodId);
        $periods = array_values(array_filter(
            (array)($active['periods'] ?? []),
            static fn(array $period): bool => (string)($period['period_start'] ?? '') <= $asAtDate
        ));
        if (count($periods) !== 1) {
            return $this->fullDividendCapacityEstimate($companyId, $accountingPeriodId, $periods, (array)($active['errors'] ?? []));
        }

        $ctPeriod = (array)$periods[0];
        $ctPeriodId = (int)($ctPeriod['id'] ?? 0);
        $periodStart = (string)($ctPeriod['period_start'] ?? '');
        $periodEnd = min($asAtDate, (string)($ctPeriod['period_end'] ?? $asAtDate));
        if ($ctPeriodId <= 0 || $periodStart === '' || $periodEnd < $periodStart) {
            return ['available' => false, 'errors' => ['A valid CT period could not be resolved for dividend capacity.'], 'periods' => [], 'totals' => []];
        }

        $breakdown = $this->capitalAllowanceBreakdown($companyId, $accountingPeriodId, $ctPeriodId);
        $capitalAllowances = $this->capitalAllowanceAmountFromBreakdown($breakdown);
        $depreciationAddBack = round((float)($preTaxProfitLoss['depreciation_expense'] ?? 0), 2);
        $taxableBeforeLosses = round(
            (float)($preTaxProfitLoss['profit_before_tax'] ?? 0)
            + (float)($preTaxProfitLoss['disallowable_add_backs'] ?? 0)
            + $depreciationAddBack
            - $capitalAllowances,
            2
        );

        $lossCalculation = $this->dividendCapacityLossCalculation(
            $taxableBeforeLosses,
            $this->ctPeriodLossPosition($companyId, $ctPeriodId)
        );
        $lossesBroughtForward = (float)$lossCalculation['losses_brought_forward'];
        $lossesUsed = (float)$lossCalculation['losses_used'];
        $taxableProfit = (float)$lossCalculation['taxable_profit'];
        $lossCreated = (float)$lossCalculation['loss_created'];
        $lossesCarriedForward = (float)$lossCalculation['losses_carried_forward'];
        $rateCalculation = ($this->rateService ?? new CorporationTaxRateService())->calculate(
            $periodStart,
            $periodEnd,
            $taxableProfit,
            $this->associatedCompanyCount($companyId)
        );
        $warnings = array_values(array_filter(array_map(
            'strval',
            (array)($breakdown['warnings'] ?? [])
        )));
        $row = [
            'available' => true,
            'ct_period_id' => $ctPeriodId,
            'accounting_period_id' => $accountingPeriodId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'accounting_profit' => round((float)($preTaxProfitLoss['profit_before_tax'] ?? 0), 2),
            'disallowable_add_backs' => round((float)($preTaxProfitLoss['disallowable_add_backs'] ?? 0), 2),
            'depreciation_add_back' => $depreciationAddBack,
            'capital_allowances' => round($capitalAllowances, 2),
            'taxable_before_losses' => $taxableBeforeLosses,
            'taxable_profit' => $taxableProfit,
            'taxable_loss' => round($lossCreated, 2),
            'loss_created_in_period' => round($lossCreated, 2),
            'losses_brought_forward' => $lossesBroughtForward,
            'losses_used' => round($lossesUsed, 2),
            'losses_carried_forward' => $lossesCarriedForward,
            'estimated_corporation_tax' => round((float)($rateCalculation['liability'] ?? 0), 2),
            'warnings' => $warnings,
            'calculation_status' => 'estimate',
            'summary_scope' => 'dividend_capacity',
        ];

        return $row + ['periods' => [$row], 'totals' => $row, 'errors' => []];
    }

    private function dividendCapacityLossCalculation(float $taxableBeforeLosses, array $lossPosition): array
    {
        $lossesBroughtForward = round(max(0.0, (float)($lossPosition['brought_forward'] ?? 0)), 2);
        $lossesUsed = $taxableBeforeLosses > 0
            ? min($taxableBeforeLosses, $lossesBroughtForward)
            : 0.0;
        $taxableProfit = round(max(0.0, $taxableBeforeLosses - $lossesUsed), 2);
        $lossCreated = $taxableBeforeLosses < 0 ? abs($taxableBeforeLosses) : 0.0;

        return [
            'losses_brought_forward' => $lossesBroughtForward,
            'losses_used' => round($lossesUsed, 2),
            'taxable_profit' => $taxableProfit,
            'loss_created' => round($lossCreated, 2),
            'losses_carried_forward' => round($lossesBroughtForward - $lossesUsed + $lossCreated, 2),
        ];
    }

    private function fullDividendCapacityEstimate(int $companyId, int $accountingPeriodId, array $periods, array $errors): array
    {
        $this->preloadCtPeriodLossPositionsForAccountingPeriod($companyId, $accountingPeriodId);
        $summaries = [];
        foreach ($periods as $period) {
            $summary = $this->fetchSummaryForCtPeriodId($companyId, (int)($period['id'] ?? 0));
            if (!empty($summary['available'])) {
                $summaries[] = $summary;
            } else {
                $errors = array_merge($errors, (array)($summary['errors'] ?? []));
            }
        }
        if ($summaries === []) {
            return ['available' => false, 'errors' => $errors !== [] ? $errors : ['No CT estimate is available.'], 'periods' => [], 'totals' => []];
        }
        $estimated = round(array_sum(array_map(static fn(array $row): float => (float)($row['estimated_corporation_tax'] ?? 0), $summaries)), 2);

        return [
            'available' => true,
            'errors' => $errors,
            'estimated_corporation_tax' => $estimated,
            'periods' => $summaries,
            'totals' => ['estimated_corporation_tax' => $estimated],
            'summary_scope' => 'dividend_capacity_full',
        ];
    }

    private function rebuildLossSchedule(int $companyId): array {
        if (isset($this->accountingPeriodLossScheduleCache[$companyId])) {
            return $this->accountingPeriodLossScheduleCache[$companyId];
        }

        $metrics = $this->metricsService ?? new \eel_accounts\Service\YearEndMetricsService();
        $accountingPeriods = array_reverse($metrics->fetchAccountingPeriods($companyId));
        if ($accountingPeriods === []) {
            return [];
        }

        $schedule = [];
        $lossPool = [];
        $associatedCompanyCount = $this->associatedCompanyCount($companyId);
        $rateService = $this->rateService ?? new \eel_accounts\Service\CorporationTaxRateService();

        try {
            foreach ($accountingPeriods as $accountingPeriod) {
                $accountingPeriodId = (int)($accountingPeriod['id'] ?? 0);
                $pnl = $this->profitAndLossSummary(
                    $companyId,
                    $accountingPeriodId,
                    (string)($accountingPeriod['period_start'] ?? ''),
                    (string)($accountingPeriod['period_end'] ?? '')
                );
                $assetAdjustments = $this->fetchAssetAdjustments($companyId, $accountingPeriodId);
                $taxableBeforeLosses = round(
                    (float)($pnl['profit_before_tax'] ?? 0)
                    + (float)($pnl['disallowable_add_backs'] ?? 0)
                    + (float)$assetAdjustments['depreciation_add_back']
                    - (float)$assetAdjustments['capital_allowances'],
                    2
                );

                $lossBf = round(array_sum(array_column($lossPool, 'amount_remaining')), 2);
                $lossUsed = 0.0;
                if ($taxableBeforeLosses > 0 && $lossBf > 0) {
                    $remainingTaxable = $taxableBeforeLosses;
                    foreach ($lossPool as &$lossRow) {
                        if ($remainingTaxable <= 0) {
                            break;
                        }

                        $usage = min((float)$lossRow['amount_remaining'], $remainingTaxable);
                        $lossRow['amount_remaining'] = round((float)$lossRow['amount_remaining'] - $usage, 2);
                        $lossRow['amount_used'] = round((float)$lossRow['amount_used'] + $usage, 2);
                        $remainingTaxable = round($remainingTaxable - $usage, 2);
                        $lossUsed = round($lossUsed + $usage, 2);
                    }
                    unset($lossRow);
                }

                $lossCreated = $taxableBeforeLosses < 0 ? abs($taxableBeforeLosses) : 0.0;
                if ($lossCreated > 0) {
                    $lossPool[] = [
                        'origin_accounting_period_id' => $accountingPeriodId,
                        'amount_originated' => $lossCreated,
                        'amount_used' => 0.0,
                        'amount_remaining' => $lossCreated,
                    ];
                }

                $lossCf = round(array_sum(array_column($lossPool, 'amount_remaining')), 2);
                $taxableProfit = max(0.0, round($taxableBeforeLosses - $lossUsed, 2));
                $rateCalculation = $rateService->calculate(
                    (string)($accountingPeriod['period_start'] ?? ''),
                    (string)($accountingPeriod['period_end'] ?? ''),
                    $taxableProfit,
                    $associatedCompanyCount
                );
                $computationHash = hash('sha256', json_encode([
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'accounting_profit' => (float)($pnl['profit_before_tax'] ?? 0),
                    'disallowable' => (float)($pnl['disallowable_add_backs'] ?? 0),
                    'depreciation' => (float)$assetAdjustments['depreciation_add_back'],
                    'allowances' => (float)$assetAdjustments['capital_allowances'],
                    'loss_bf' => $lossBf,
                    'loss_used' => $lossUsed,
                    'associated_company_count' => $associatedCompanyCount,
                    'rate_liability' => (float)$rateCalculation['liability'],
                ], JSON_UNESCAPED_SLASHES));

                $schedule[$accountingPeriodId] = [
                    'accounting_period_id' => $accountingPeriodId,
                    'label' => (string)($accountingPeriod['label'] ?? ''),
                    'accounting_profit' => round((float)($pnl['profit_before_tax'] ?? 0), 2),
                    'disallowable_add_backs' => round((float)($pnl['disallowable_add_backs'] ?? 0), 2),
                    'depreciation_add_back' => round((float)$assetAdjustments['depreciation_add_back'], 2),
                    'capital_allowances' => round((float)$assetAdjustments['capital_allowances'], 2),
                    'taxable_before_losses' => $taxableBeforeLosses,
                    'taxable_profit' => $taxableProfit,
                    'estimated_corporation_tax' => round((float)$rateCalculation['liability'], 2),
                    'estimated_rate' => round((float)$rateCalculation['effective_rate'], 6),
                    'associated_company_count' => $associatedCompanyCount,
                    'ct_rate_bands' => (array)($rateCalculation['bands'] ?? []),
                    'ct_rate_warnings' => (array)($rateCalculation['warnings'] ?? []),
                    'loss_created' => round($lossCreated, 2),
                    'loss_brought_forward' => $lossBf,
                    'loss_utilised' => $lossUsed,
                    'loss_carried_forward' => $lossCf,
                    'other_treatment_count' => (int)($pnl['other_treatment_count'] ?? 0),
                    'unknown_treatment_count' => (int)($pnl['unknown_treatment_count'] ?? 0),
                    'asset_adjustment_warning' => (string)($assetAdjustments['warning'] ?? ''),
                    'computation_hash' => $computationHash,
                ];
            }
        } catch (\Throwable $exception) {
            throw $exception;
        }

        return $this->accountingPeriodLossScheduleCache[$companyId] = $schedule;
    }

    private function fetchAssetAdjustments(int $companyId, int $accountingPeriodId): array {
        $cacheKey = $companyId . ':' . $accountingPeriodId . ':0';
        if (isset($this->assetAdjustmentsCache[$cacheKey])) {
            return $this->assetAdjustmentsCache[$cacheKey];
        }

        $depreciation = $this->depreciationAddBack($companyId, $accountingPeriodId, '', '');
        $breakdown = $this->capitalAllowanceBreakdown($companyId, $accountingPeriodId, 0);
        $allowances = $this->capitalAllowanceAmountFromBreakdown($breakdown);
        $warnings = (array)($breakdown['warnings'] ?? []);
        if ($this->tableExists('asset_register') && $this->countCompanyAssets($companyId) > 0 && abs($depreciation) < 0.005 && abs($allowances) < 0.005) {
            $warnings[] = 'Fixed assets exist, but no depreciation entries or capital allowance runs were found.';
        }

        return $this->assetAdjustmentsCache[$cacheKey] = [
            'depreciation_add_back' => round(max(0.0, $depreciation), 2),
            'capital_allowances' => round($allowances, 2),
            'warning' => implode(' ', array_values(array_unique(array_filter($warnings)))),
            'capital_allowance_breakdown' => $breakdown,
        ];
    }

    private function fetchAssetAdjustmentsForCtPeriod(int $companyId, int $accountingPeriodId, array $ctPeriod): array {
        $ctPeriodId = (int)($ctPeriod['id'] ?? 0);
        $cacheKey = $companyId . ':' . $accountingPeriodId . ':' . $ctPeriodId;
        if (isset($this->assetAdjustmentsCache[$cacheKey])) {
            return $this->assetAdjustmentsCache[$cacheKey];
        }

        $periodStart = (string)($ctPeriod['period_start'] ?? '');
        $periodEnd = (string)($ctPeriod['period_end'] ?? '');
        $breakdown = $this->capitalAllowanceBreakdown($companyId, $accountingPeriodId, $ctPeriodId);

        return $this->assetAdjustmentsCache[$cacheKey] = [
            'depreciation_add_back' => $this->depreciationAddBack($companyId, $accountingPeriodId, $periodStart, $periodEnd),
            'capital_allowances' => $this->capitalAllowanceAmountFromBreakdown($breakdown),
            'warning' => implode(' ', (array)($breakdown['warnings'] ?? [])),
            'capital_allowance_breakdown' => $breakdown,
        ];
    }

    private function depreciationAddBack(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): float
    {
        return (new \eel_accounts\Service\YearEndClosePreviewService())
            ->depreciationExpenseForPeriod($companyId, $accountingPeriodId, $periodStart, $periodEnd);
    }

    private function capitalAllowanceAmount(int $companyId, int $accountingPeriodId, int $ctPeriodId): float
    {
        return $this->capitalAllowanceAmountFromBreakdown(
            $this->capitalAllowanceBreakdown($companyId, $accountingPeriodId, $ctPeriodId)
        );
    }

    private function capitalAllowanceAmountFromBreakdown(array $breakdown): float
    {
        $allowances = 0.0;
        $charges = 0.0;
        foreach ((array)($breakdown['rows'] ?? []) as $row) {
            $allowances += (float)($row['aia_claimed'] ?? 0)
                + (float)($row['fya_claimed'] ?? 0)
                + (float)($row['wda_claimed'] ?? 0)
                + (float)($row['balancing_allowance'] ?? 0);
            $charges += (float)($row['balancing_charge'] ?? 0);
        }

        return round($allowances - $charges, 2);
    }

    private function capitalAllowanceBreakdown(int $companyId, int $accountingPeriodId, int $ctPeriodId = 0): array
    {
        $cacheKey = $companyId . ':' . $accountingPeriodId . ':' . $ctPeriodId;
        if (isset($this->capitalAllowanceBreakdownCache[$cacheKey])) {
            return $this->capitalAllowanceBreakdownCache[$cacheKey];
        }

        return $this->capitalAllowanceBreakdownCache[$cacheKey] =
            (new \eel_accounts\Service\CapitalAllowanceService())->fetchPeriodBreakdown($companyId, $accountingPeriodId, $ctPeriodId);
    }

    private function storedLockedSummaryForCtPeriodId(int $companyId, int $ctPeriodId): ?array
    {
        $period = (new \eel_accounts\Service\CorporationTaxPeriodService())->fetch($companyId, $ctPeriodId);
        if ($period === null || !(new \eel_accounts\Service\YearEndLockService())->isLocked($companyId, (int)$period['accounting_period_id'])) {
            return null;
        }

        $runId = (int)($period['latest_computation_run_id'] ?? 0);
        if ($runId <= 0 || !$this->tableExists('corporation_tax_computation_runs')) {
            return null;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT summary_json, computation_hash, status, generated_at
             FROM corporation_tax_computation_runs
             WHERE id = :id
               AND company_id = :company_id
               AND ct_period_id = :ct_period_id
             LIMIT 1',
            ['id' => $runId, 'company_id' => $companyId, 'ct_period_id' => $ctPeriodId]
        );
        $summary = is_array($row) ? json_decode((string)($row['summary_json'] ?? ''), true) : null;
        if (!is_array($summary)) {
            return null;
        }
        $summary['computation_run_id'] = $runId;
        $summary['summary_source'] = 'locked_snapshot';
        $summary['computation_persistence'] = [
            'status' => 'current',
            'status_label' => 'Persisted computation current',
            'current' => true,
            'run_id' => $runId,
            'stored_hash' => (string)($row['computation_hash'] ?? ''),
            'live_hash' => (string)($summary['computation_hash'] ?? $row['computation_hash'] ?? ''),
            'generated_at' => (string)($row['generated_at'] ?? ''),
            'locked_snapshot' => true,
        ];

        return $summary;
    }

    private function withComputationPersistenceState(int $companyId, int $ctPeriodId, array $summary): array
    {
        if (empty($summary['available'])) {
            return $summary;
        }

        $period = $this->fetchCtPeriod($companyId, $ctPeriodId);
        $runId = (int)($period['latest_computation_run_id'] ?? 0);
        $state = [
            'status' => 'not_persisted',
            'status_label' => 'No persisted computation',
            'current' => false,
            'run_id' => $runId,
            'stored_hash' => '',
            'live_hash' => (string)($summary['computation_hash'] ?? ''),
            'generated_at' => '',
            'locked_snapshot' => false,
        ];

        if ($runId > 0 && $this->tableExists('corporation_tax_computation_runs')) {
            $row = \InterfaceDB::fetchOne(
                'SELECT computation_hash, generated_at, status
                 FROM corporation_tax_computation_runs
                 WHERE id = :id
                   AND company_id = :company_id
                   AND ct_period_id = :ct_period_id
                 LIMIT 1',
                ['id' => $runId, 'company_id' => $companyId, 'ct_period_id' => $ctPeriodId]
            );
            if (is_array($row)) {
                $storedHash = (string)($row['computation_hash'] ?? '');
                $liveHash = (string)($summary['computation_hash'] ?? '');
                $current = $storedHash !== '' && $liveHash !== '' && hash_equals($storedHash, $liveHash);
                $state = [
                    'status' => $current ? 'current' : 'stale',
                    'status_label' => $current ? 'Persisted computation current' : 'Persisted computation stale',
                    'current' => $current,
                    'run_id' => $runId,
                    'stored_hash' => $storedHash,
                    'live_hash' => $liveHash,
                    'generated_at' => (string)($row['generated_at'] ?? ''),
                    'locked_snapshot' => false,
                ];
            }
        }

        $summary['computation_persistence'] = $state;
        if (in_array((string)$state['status'], ['stale', 'not_persisted'], true)) {
            $message = (string)$state['status'] === 'stale'
                ? 'The latest persisted CT computation is stale because the live accounting or tax inputs have changed.'
                : 'No CT computation snapshot has been persisted for the current live inputs.';
            $warnings = array_values(array_unique(array_merge((array)($summary['warnings'] ?? []), [$message])));
            $summary['warnings'] = $warnings;
            $summary['confidence_status'] = 'review_required';
            $summary['confidence_label'] = 'Review required';
        }

        return $summary;
    }

    private function insertLossHistory(int $companyId, int $accountingPeriodId, ?int $ctPeriodId, string $computationHash, array $row): void {
        if (!$this->tableExists('tax_loss_movement_history')) {
            return;
        }

        $deleteSql = 'DELETE FROM tax_loss_movement_history
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND computation_hash = :computation_hash';
        $deleteParams = [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'computation_hash' => $computationHash,
        ];
        if ($ctPeriodId !== null && \InterfaceDB::columnExists('tax_loss_movement_history', 'ct_period_id')) {
            $deleteSql .= ' AND ct_period_id = :ct_period_id';
            $deleteParams['ct_period_id'] = $ctPeriodId;
        }
        \InterfaceDB::prepareExecute($deleteSql, $deleteParams);

        $columns = ['company_id', 'accounting_period_id', 'computation_hash', 'loss_created', 'loss_brought_forward', 'loss_utilised', 'loss_carried_forward', 'taxable_before_losses', 'taxable_profit', 'computed_at'];
        $values = [':company_id', ':accounting_period_id', ':computation_hash', ':loss_created', ':loss_brought_forward', ':loss_utilised', ':loss_carried_forward', ':taxable_before_losses', ':taxable_profit', 'CURRENT_TIMESTAMP'];
        $params = [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'computation_hash' => $computationHash,
            'loss_created' => round((float)($row['loss_created'] ?? 0), 2),
            'loss_brought_forward' => round((float)($row['loss_brought_forward'] ?? 0), 2),
            'loss_utilised' => round((float)($row['loss_utilised'] ?? 0), 2),
            'loss_carried_forward' => round((float)($row['loss_carried_forward'] ?? 0), 2),
            'taxable_before_losses' => round((float)($row['taxable_before_losses'] ?? 0), 2),
            'taxable_profit' => round((float)($row['taxable_profit'] ?? 0), 2),
        ];
        if ($ctPeriodId !== null && \InterfaceDB::columnExists('tax_loss_movement_history', 'ct_period_id')) {
            $columns[] = 'ct_period_id';
            $values[] = ':ct_period_id';
            $params['ct_period_id'] = $ctPeriodId;
        }

        \InterfaceDB::prepareExecute(
            'INSERT INTO tax_loss_movement_history (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', $values) . ')',
            $params
        );
    }

    private function ctPeriodLossPosition(int $companyId, int $targetCtPeriodId): array {
        $schedule = $this->ctPeriodLossSchedule($companyId, $targetCtPeriodId);

        return (array)($schedule[$targetCtPeriodId] ?? ['brought_forward' => 0.0]);
    }

    private function ctPeriodLossSchedule(int $companyId, ?int $stopAtCtPeriodId = null): array
    {
        if ($stopAtCtPeriodId !== null && isset($this->ctPeriodLossScheduleCache[$companyId][$stopAtCtPeriodId])) {
            return $this->ctPeriodLossScheduleCache[$companyId];
        }
        if ($stopAtCtPeriodId === null && !empty($this->ctPeriodLossScheduleCompleteCache[$companyId])) {
            return $this->ctPeriodLossScheduleCache[$companyId];
        }

        $metrics = $this->metricsService ?? new \eel_accounts\Service\YearEndMetricsService();
        $accountingPeriods = array_reverse($metrics->fetchAccountingPeriods($companyId));
        $checkpoint = $stopAtCtPeriodId !== null
            ? $this->lockedLossCheckpointBefore($companyId, $stopAtCtPeriodId)
            : null;
        $checkpointEnd = is_array($checkpoint) ? (string)($checkpoint['period_end'] ?? '') : '';
        $checkpointLoss = is_array($checkpoint) ? max(0.0, (float)($checkpoint['losses_carried_forward'] ?? 0)) : 0.0;
        $lossPool = $checkpointLoss > 0 ? [['amount_remaining' => $checkpointLoss]] : [];
        $schedule = [];

        foreach ($accountingPeriods as $accountingPeriod) {
            $accountingPeriodId = (int)($accountingPeriod['id'] ?? 0);
            $ctPeriods = (array)($this->activeCtPeriodsForAccountingPeriod($companyId, $accountingPeriodId)['periods'] ?? []);
            foreach ($ctPeriods as $ctPeriod) {
                $ctPeriodId = (int)($ctPeriod['id'] ?? 0);
                if ($ctPeriodId <= 0) {
                    continue;
                }
                if ($checkpointEnd !== '' && (string)($ctPeriod['period_end'] ?? '') <= $checkpointEnd) {
                    continue;
                }

                $lossBroughtForward = round(array_sum(array_column($lossPool, 'amount_remaining')), 2);
                $pnl = $this->profitAndLossSummary(
                    $companyId,
                    $accountingPeriodId,
                    (string)$ctPeriod['period_start'],
                    (string)$ctPeriod['period_end']
                );
                $assetAdjustments = $this->fetchAssetAdjustmentsForCtPeriod($companyId, $accountingPeriodId, $ctPeriod);
                $taxableBeforeLosses = round(
                    (float)($pnl['profit_before_tax'] ?? 0)
                    + (float)($pnl['disallowable_add_backs'] ?? 0)
                    + (float)$assetAdjustments['depreciation_add_back']
                    - (float)$assetAdjustments['capital_allowances'],
                    2
                );

                $lossUsed = 0.0;
                if ($taxableBeforeLosses > 0) {
                    $remainingTaxable = $taxableBeforeLosses;
                    foreach ($lossPool as &$lossRow) {
                        if ($remainingTaxable <= 0) {
                            break;
                        }
                        $usage = min((float)$lossRow['amount_remaining'], $remainingTaxable);
                        $lossRow['amount_remaining'] = round((float)$lossRow['amount_remaining'] - $usage, 2);
                        $remainingTaxable = round($remainingTaxable - $usage, 2);
                        $lossUsed = round($lossUsed + $usage, 2);
                    }
                    unset($lossRow);
                } elseif ($taxableBeforeLosses < 0) {
                    $lossPool[] = ['amount_remaining' => abs($taxableBeforeLosses)];
                }

                $schedule[$ctPeriodId] = [
                    'brought_forward' => $lossBroughtForward,
                    'loss_utilised' => $lossUsed,
                    'loss_carried_forward' => round(array_sum(array_column($lossPool, 'amount_remaining')), 2),
                    'taxable_before_losses' => $taxableBeforeLosses,
                ];

                if ($stopAtCtPeriodId !== null && $ctPeriodId === $stopAtCtPeriodId) {
                    return $this->ctPeriodLossScheduleCache[$companyId] = $schedule;
                }
            }
        }

        $this->ctPeriodLossScheduleCompleteCache[$companyId] = true;
        return $this->ctPeriodLossScheduleCache[$companyId] = $schedule;
    }

    /**
     * Returns the latest valid checkpoint from the consecutive locked prefix
     * before the requested CT period. Locked periods are immutable and Year End
     * persists their final CT summaries before applying the lock.
     */
    private function lockedLossCheckpointBefore(int $companyId, int $targetCtPeriodId): ?array
    {
        $target = $this->fetchCtPeriod($companyId, $targetCtPeriodId);
        $targetStart = trim((string)($target['period_start'] ?? ''));
        if ($targetStart === '' || !$this->tableExists('year_end_reviews') || !$this->tableExists('corporation_tax_computation_runs')) {
            return null;
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT ap.id AS accounting_period_id,
                    ap.period_start AS accounting_period_start,
                    COALESCE(yer.is_locked, 0) AS is_locked,
                    ctp.id AS ct_period_id,
                    ctp.period_end,
                    ctp.latest_computation_run_id,
                    cr.summary_json
             FROM accounting_periods ap
             LEFT JOIN year_end_reviews yer
               ON yer.company_id = ap.company_id
              AND yer.accounting_period_id = ap.id
             LEFT JOIN corporation_tax_periods ctp
               ON ctp.accounting_period_id = ap.id
              AND ctp.company_id = ap.company_id
              AND ctp.status <> :superseded_status
              AND ctp.period_end < :target_start
             LEFT JOIN corporation_tax_computation_runs cr
               ON cr.id = ctp.latest_computation_run_id
              AND cr.company_id = ap.company_id
              AND cr.accounting_period_id = ap.id
              AND cr.ct_period_id = ctp.id
             WHERE ap.company_id = :company_id
               AND ap.period_start < :target_start_filter
             ORDER BY ap.period_start ASC, ctp.period_start ASC, ctp.id ASC',
            [
                'superseded_status' => 'superseded',
                'target_start' => $targetStart,
                'company_id' => $companyId,
                'target_start_filter' => $targetStart,
            ]
        );

        $periods = [];
        foreach ($rows as $row) {
            $periods[(int)($row['accounting_period_id'] ?? 0)][] = $row;
        }

        $checkpoint = null;
        foreach ($periods as $periodRows) {
            $first = (array)($periodRows[0] ?? []);
            if ((int)($first['is_locked'] ?? 0) !== 1) {
                break;
            }

            $periodCheckpoint = null;
            foreach ($periodRows as $row) {
                if ((int)($row['ct_period_id'] ?? 0) <= 0 || (int)($row['latest_computation_run_id'] ?? 0) <= 0) {
                    $periodCheckpoint = null;
                    break;
                }
                $summary = json_decode((string)($row['summary_json'] ?? ''), true);
                if (!is_array($summary) || !array_key_exists('losses_carried_forward', $summary)) {
                    $periodCheckpoint = null;
                    break;
                }
                $periodCheckpoint = [
                    'ct_period_id' => (int)$row['ct_period_id'],
                    'period_end' => (string)$row['period_end'],
                    'losses_carried_forward' => round((float)$summary['losses_carried_forward'], 2),
                ];
            }
            if ($periodCheckpoint === null) {
                break;
            }
            $checkpoint = $periodCheckpoint;
        }

        return $checkpoint;
    }

    private function summaryFromRows(array $current, array $schedule): array {
        $warnings = [];
        if ((int)($current['unknown_treatment_count'] ?? 0) > 0) {
            $warnings[] = 'Some nominal tax treatments are unknown and should be reviewed before relying on the estimate.';
        }
        if ((int)($current['other_treatment_count'] ?? 0) > 0) {
            $warnings[] = 'Some nominal tax treatments are marked as other and need manual review.';
        }
        if (!empty($current['asset_adjustment_warning'])) {
            $warnings[] = (string)$current['asset_adjustment_warning'];
        }
        foreach ((array)($current['ct_rate_warnings'] ?? []) as $warning) {
            $warnings[] = (string)$warning;
        }

        return [
            'available' => true,
            'accounting_profit' => round((float)$current['accounting_profit'], 2),
            'disallowable_add_backs' => round((float)$current['disallowable_add_backs'], 2),
            'depreciation_add_back' => round((float)$current['depreciation_add_back'], 2),
            'capital_allowances' => round((float)$current['capital_allowances'], 2),
            'taxable_before_losses' => round((float)$current['taxable_before_losses'], 2),
            'taxable_profit' => round((float)$current['taxable_profit'], 2),
            'taxable_loss' => round((float)$current['loss_created'], 2),
            'estimated_corporation_tax' => round((float)$current['estimated_corporation_tax'], 2),
            'estimated_rate' => round((float)$current['estimated_rate'], 6),
            'associated_company_count' => (int)($current['associated_company_count'] ?? 0),
            'ct_rate_bands' => (array)($current['ct_rate_bands'] ?? []),
            'loss_created_in_period' => round((float)$current['loss_created'], 2),
            'losses_brought_forward' => round((float)$current['loss_brought_forward'], 2),
            'losses_used' => round((float)$current['loss_utilised'], 2),
            'losses_carried_forward' => round((float)$current['loss_carried_forward'], 2),
            'other_treatment_count' => (int)$current['other_treatment_count'],
            'unknown_treatment_count' => (int)$current['unknown_treatment_count'],
            'warnings' => $warnings,
            'calculation_status' => 'estimate',
            'confidence_status' => $warnings === [] ? 'ready_for_review' : 'review_required',
            'confidence_label' => $warnings === [] ? 'Ready for review' : 'Review required',
            'steps' => [
                ['label' => 'Accounting profit or loss', 'amount' => round((float)$current['accounting_profit'], 2)],
                ['label' => 'Add back disallowable expenses', 'amount' => round((float)$current['disallowable_add_backs'], 2)],
                ['label' => 'Add back depreciation', 'amount' => round((float)$current['depreciation_add_back'], 2)],
                ['label' => 'Deduct capital allowances', 'amount' => round(0 - (float)$current['capital_allowances'], 2)],
                ['label' => 'Taxable result before losses', 'amount' => round((float)$current['taxable_before_losses'], 2)],
                ['label' => 'Less losses brought forward utilised', 'amount' => round(0 - (float)$current['loss_utilised'], 2)],
                ['label' => 'Taxable profit after losses', 'amount' => round((float)$current['taxable_profit'], 2)],
                ['label' => 'Estimated corporation tax', 'amount' => round((float)$current['estimated_corporation_tax'], 2)],
            ],
            'schedule' => array_values(array_map(
                static fn(array $row): array => [
                    'accounting_period_id' => (int)$row['accounting_period_id'],
                    'ct_period_id' => (int)($row['ct_period_id'] ?? 0),
                    'label' => (string)$row['label'],
                    'loss_created' => round((float)$row['loss_created'], 2),
                    'loss_brought_forward' => round((float)$row['loss_brought_forward'], 2),
                    'loss_utilised' => round((float)$row['loss_utilised'], 2),
                    'loss_carried_forward' => round((float)$row['loss_carried_forward'], 2),
                    'taxable_before_losses' => round((float)$row['taxable_before_losses'], 2),
                    'taxable_profit' => round((float)$row['taxable_profit'], 2),
                ],
                $schedule
            )),
        ];
    }

    public function fetchCurrentPeriodEstimate(
        int $companyId,
        int $accountingPeriodId,
        ?array $accountingPeriod = null,
        ?array $profitAndLoss = null
    ): array {
        $metrics = $this->metricsService ?? new \eel_accounts\Service\YearEndMetricsService();
        $accountingPeriod ??= $metrics->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null) {
            return [
                'available' => false,
                'errors' => ['The selected accounting period could not be found.'],
            ];
        }

        $periodStart = (string)($accountingPeriod['period_start'] ?? '');
        $periodEnd = (string)($accountingPeriod['period_end'] ?? '');
        $profitAndLoss ??= $this->profitAndLossSummary($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $assetAdjustments = $this->fetchAssetAdjustments($companyId, $accountingPeriodId);
        $taxableBeforeLosses = round(
            (float)($profitAndLoss['profit_before_tax'] ?? 0)
            + (float)($profitAndLoss['disallowable_add_backs'] ?? 0)
            + (float)$assetAdjustments['depreciation_add_back']
            - (float)$assetAdjustments['capital_allowances'],
            2
        );
        $lossesBroughtForward = $this->storedLossesBroughtForward($companyId, $accountingPeriodId, $periodStart);
        $lossesUsed = min(max(0.0, $taxableBeforeLosses), $lossesBroughtForward);
        $taxableProfit = max(0.0, round($taxableBeforeLosses - $lossesUsed, 2));
        $lossCreated = $taxableBeforeLosses < 0 ? abs($taxableBeforeLosses) : 0.0;
        $lossesCarriedForward = round($lossesBroughtForward - $lossesUsed + $lossCreated, 2);
        $associatedCompanyCount = $this->associatedCompanyCount($companyId);
        $rateCalculation = ($this->rateService ?? new \eel_accounts\Service\CorporationTaxRateService())->calculate(
            $periodStart,
            $periodEnd,
            $taxableProfit,
            $associatedCompanyCount
        );
        $warnings = [];

        if ((int)($profitAndLoss['unknown_treatment_count'] ?? 0) > 0) {
            $warnings[] = 'Some nominal tax treatments are unknown and should be reviewed before relying on the estimate.';
        }
        if ((int)($profitAndLoss['other_treatment_count'] ?? 0) > 0) {
            $warnings[] = 'Some nominal tax treatments are marked as other and need manual review.';
        }
        if (!empty($assetAdjustments['warning'])) {
            $warnings[] = (string)$assetAdjustments['warning'];
        }
        foreach ((array)($rateCalculation['warnings'] ?? []) as $warning) {
            $warnings[] = (string)$warning;
        }
        $warnings = array_values(array_unique(array_filter($warnings, static fn(string $warning): bool => trim($warning) !== '')));
        $confidenceStatus = $warnings === [] ? 'ready_for_review' : 'review_required';

        $steps = [
            ['label' => 'Accounting profit or loss', 'amount' => round((float)($profitAndLoss['profit_before_tax'] ?? 0), 2)],
            ['label' => 'Add back disallowable expenses', 'amount' => round((float)($profitAndLoss['disallowable_add_backs'] ?? 0), 2)],
            ['label' => 'Add back depreciation', 'amount' => round((float)$assetAdjustments['depreciation_add_back'], 2)],
            ['label' => 'Deduct capital allowances', 'amount' => round(0 - (float)$assetAdjustments['capital_allowances'], 2)],
            ['label' => 'Taxable result before losses', 'amount' => $taxableBeforeLosses],
            ['label' => 'Less losses brought forward utilised', 'amount' => round(0 - $lossesUsed, 2)],
            ['label' => 'Taxable profit after losses', 'amount' => $taxableProfit],
            ['label' => 'Estimated corporation tax', 'amount' => round((float)$rateCalculation['liability'], 2)],
        ];

        return [
            'available' => true,
            'accounting_profit' => round((float)($profitAndLoss['profit_before_tax'] ?? 0), 2),
            'disallowable_add_backs' => round((float)($profitAndLoss['disallowable_add_backs'] ?? 0), 2),
            'depreciation_add_back' => round((float)$assetAdjustments['depreciation_add_back'], 2),
            'capital_allowances' => round((float)$assetAdjustments['capital_allowances'], 2),
            'taxable_before_losses' => $taxableBeforeLosses,
            'taxable_profit' => $taxableProfit,
            'taxable_loss' => round($lossCreated, 2),
            'estimated_corporation_tax' => round((float)$rateCalculation['liability'], 2),
            'estimated_rate' => round((float)$rateCalculation['effective_rate'], 6),
            'associated_company_count' => $associatedCompanyCount,
            'ct_rate_bands' => (array)($rateCalculation['bands'] ?? []),
            'loss_created_in_period' => round($lossCreated, 2),
            'losses_brought_forward' => round($lossesBroughtForward, 2),
            'losses_used' => round($lossesUsed, 2),
            'losses_carried_forward' => $lossesCarriedForward,
            'other_treatment_count' => (int)($profitAndLoss['other_treatment_count'] ?? 0),
            'unknown_treatment_count' => (int)($profitAndLoss['unknown_treatment_count'] ?? 0),
            'warnings' => $warnings,
            'capital_allowance_breakdown' => (array)($assetAdjustments['capital_allowance_breakdown'] ?? []),
            'calculation_status' => 'estimate',
            'confidence_status' => $confidenceStatus,
            'confidence_label' => $confidenceStatus === 'ready_for_review' ? 'Ready for review' : 'Review required',
            'steps' => $steps,
            'schedule' => [
                [
                    'accounting_period_id' => $accountingPeriodId,
                    'label' => (string)($accountingPeriod['label'] ?? 'Selected period'),
                    'loss_created' => round($lossCreated, 2),
                    'loss_brought_forward' => round($lossesBroughtForward, 2),
                    'loss_utilised' => round($lossesUsed, 2),
                    'loss_carried_forward' => $lossesCarriedForward,
                    'taxable_before_losses' => $taxableBeforeLosses,
                    'taxable_profit' => $taxableProfit,
                ],
            ],
            'summary_scope' => 'current_period_estimate',
        ];
    }

    private function insertComputationRun(int $companyId, array $row, array $summary): int {
        if (!$this->tableExists('corporation_tax_computation_runs')) {
            return 0;
        }

        $summaryJson = json_encode($summary, JSON_UNESCAPED_SLASHES);
        if (!is_string($summaryJson)) {
            return 0;
        }

        \InterfaceDB::prepareExecute(
            'INSERT INTO corporation_tax_computation_runs (
                company_id,
                accounting_period_id,
                ct_period_id,
                period_start,
                period_end,
                status,
                computation_hash,
                summary_json
             ) VALUES (
                :company_id,
                :accounting_period_id,
                :ct_period_id,
                :period_start,
                :period_end,
                :status,
                :computation_hash,
                :summary_json
             )',
            [
                'company_id' => $companyId,
                'accounting_period_id' => (int)$row['accounting_period_id'],
                'ct_period_id' => (int)$row['ct_period_id'],
                'period_start' => (string)$row['period_start'],
                'period_end' => (string)$row['period_end'],
                'status' => 'generated',
                'computation_hash' => (string)$row['computation_hash'],
                'summary_json' => $summaryJson,
            ]
        );

        return (int)\InterfaceDB::fetchColumn(
            'SELECT id
             FROM corporation_tax_computation_runs
             WHERE company_id = :company_id
               AND ct_period_id = :ct_period_id
               AND computation_hash = :computation_hash
             ORDER BY id DESC
             LIMIT 1',
            [
                'company_id' => $companyId,
                'ct_period_id' => (int)$row['ct_period_id'],
                'computation_hash' => (string)$row['computation_hash'],
            ]
        );
    }

    private function countCompanyAssets(int $companyId): int {
        if (!$this->tableExists('asset_register')) {
            return 0;
        }

        return \InterfaceDB::countWhere('asset_register', 'company_id', $companyId);
    }

    private function storedLossesBroughtForward(int $companyId, int $accountingPeriodId, string $periodStart): float {
        if (!$this->tableExists('tax_loss_carryforwards')) {
            return 0.0;
        }

        if (trim($periodStart) === '') {
            return 0.0;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT COALESCE(SUM(t.amount_remaining), 0) AS amount
             FROM tax_loss_carryforwards t
             LEFT JOIN accounting_periods ap ON ap.id = t.origin_accounting_period_id
             WHERE t.company_id = :company_id
               AND t.origin_accounting_period_id <> :accounting_period_id
               AND (t.status = :open_status OR t.status IS NULL)
               AND (ap.period_start IS NULL OR ap.period_start < :period_start)',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'open_status' => 'open',
                'period_start' => $periodStart,
            ]
        ) ?: [];

        return round((float)($row['amount'] ?? 0), 2);
    }

    private function fetchCtPeriod(int $companyId, int $ctPeriodId): ?array
    {
        $cacheKey = $companyId . ':' . $ctPeriodId;
        if (array_key_exists($cacheKey, $this->ctPeriodCache)) {
            return $this->ctPeriodCache[$cacheKey];
        }

        return $this->ctPeriodCache[$cacheKey] =
            (new \eel_accounts\Service\CorporationTaxPeriodService())->fetch($companyId, $ctPeriodId);
    }

    private function profitAndLossSummary(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): array
    {
        $cacheKey = $companyId . ':' . $accountingPeriodId . ':' . $periodStart . ':' . $periodEnd;
        if (isset($this->profitAndLossSummaryCache[$cacheKey])) {
            return $this->profitAndLossSummaryCache[$cacheKey];
        }

        $metrics = $this->metricsService ?? new \eel_accounts\Service\YearEndMetricsService();
        return $this->profitAndLossSummaryCache[$cacheKey] =
            $metrics->profitAndLossSummary($companyId, $accountingPeriodId, $periodStart, $periodEnd);
    }

    private function associatedCompanyCount(int $companyId): int {
        if ($companyId <= 0) {
            return 0;
        }

        if (array_key_exists($companyId, $this->associatedCompanyCountCache)) {
            return $this->associatedCompanyCountCache[$companyId];
        }

        try {
            return $this->associatedCompanyCountCache[$companyId] =
                max(0, (int)(new \eel_accounts\Store\CompanySettingsStore($companyId))->get('associated_company_count', 0));
        } catch (\Throwable) {
            return $this->associatedCompanyCountCache[$companyId] = 0;
        }
    }

    private function tableExists(string $table): bool {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        try {
            $cache[$table] = \InterfaceDB::tableExists($table);
        } catch (\Throwable) {
            $cache[$table] = false;
        }

        return $cache[$table];
    }
}


