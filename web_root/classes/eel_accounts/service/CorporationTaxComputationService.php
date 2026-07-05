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
    public function __construct(
        private readonly ?\eel_accounts\Service\YearEndMetricsService $metricsService = null,
        private readonly ?\eel_accounts\Service\CorporationTaxRateService $rateService = null,
    ) {
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
        $stored = $this->storedLockedSummaryForCtPeriodId($companyId, $ctPeriodId);
        if ($stored !== null) {
            return $stored;
        }

        return $this->calculateSummaryForCtPeriodId($companyId, $ctPeriodId);
    }

    public function calculateSummaryForCtPeriodId(int $companyId, int $ctPeriodId): array {
        $periodService = new \eel_accounts\Service\CorporationTaxPeriodService();
        $ctPeriod = $periodService->fetch($companyId, $ctPeriodId);
        if ($ctPeriod === null) {
            return [
                'available' => false,
                'errors' => ['The selected CT period could not be found.'],
            ];
        }

        $accountingPeriodId = (int)$ctPeriod['accounting_period_id'];
        $metrics = $this->metricsService ?? new \eel_accounts\Service\YearEndMetricsService();
        $pnl = $metrics->profitAndLossSummary(
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
        $summary['capital_allowance_breakdown'] = (new \eel_accounts\Service\CapitalAllowanceService())->fetchPeriodBreakdown($companyId, $accountingPeriodId, $ctPeriodId);
        $summary['computation_hash'] = $computationHash;

        return $summary;
    }

    public function persistSummaryForCtPeriodId(int $companyId, int $ctPeriodId): array {
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
            $summary['computation_run_id'] = $runId;
        }

        return $summary;
    }

    public function persistSummariesForAccountingPeriod(int $companyId, int $accountingPeriodId): array
    {
        $sync = (new \eel_accounts\Service\CorporationTaxPeriodService())->syncForAccountingPeriod($companyId, $accountingPeriodId);
        $periods = array_values(array_filter(
            (array)($sync['periods'] ?? []),
            static fn(array $period): bool => (string)($period['status'] ?? '') !== 'superseded'
        ));
        $summaries = [];
        $errors = (array)($sync['errors'] ?? []);
        foreach ($periods as $period) {
            $ctPeriodId = (int)($period['id'] ?? 0);
            if ($ctPeriodId <= 0) {
                continue;
            }
            $summary = $this->persistSummaryForCtPeriodId($companyId, $ctPeriodId);
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

    private function rebuildLossSchedule(int $companyId): array {
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
                $pnl = $metrics->profitAndLossSummary(
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

        return $schedule;
    }

    private function fetchAssetAdjustments(int $companyId, int $accountingPeriodId): array {
        $depreciation = $this->depreciationAddBack($companyId, $accountingPeriodId, '', '');
        $allowances = $this->capitalAllowanceAmount($companyId, $accountingPeriodId, 0);
        $warnings = (new \eel_accounts\Service\CapitalAllowanceService())->periodWarnings($companyId, $accountingPeriodId, 0);
        if ($this->tableExists('asset_register') && $this->countCompanyAssets($companyId) > 0 && abs($depreciation) < 0.005 && abs($allowances) < 0.005) {
            $warnings[] = 'Fixed assets exist, but no depreciation entries or capital allowance runs were found.';
        }

        return [
            'depreciation_add_back' => round(max(0.0, $depreciation), 2),
            'capital_allowances' => round($allowances, 2),
            'warning' => implode(' ', array_values(array_unique(array_filter($warnings)))),
        ];
    }

    private function fetchAssetAdjustmentsForCtPeriod(int $companyId, int $accountingPeriodId, array $ctPeriod): array {
        $ctPeriodId = (int)($ctPeriod['id'] ?? 0);
        $periodStart = (string)($ctPeriod['period_start'] ?? '');
        $periodEnd = (string)($ctPeriod['period_end'] ?? '');
        return [
            'depreciation_add_back' => $this->depreciationAddBack($companyId, $accountingPeriodId, $periodStart, $periodEnd),
            'capital_allowances' => $this->capitalAllowanceAmount($companyId, $accountingPeriodId, $ctPeriodId),
            'warning' => implode(' ', (new \eel_accounts\Service\CapitalAllowanceService())->periodWarnings($companyId, $accountingPeriodId, $ctPeriodId)),
        ];
    }

    private function depreciationAddBack(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): float
    {
        if (!$this->tableExists('asset_depreciation_entries')) {
            return 0.0;
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT period_start, period_end, amount
             FROM asset_depreciation_entries
             WHERE accounting_period_id = :accounting_period_id
               AND asset_id IN (
                    SELECT id FROM asset_register WHERE company_id = :company_id
               )',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        ) ?: [];

        $total = 0.0;
        foreach ($rows as $row) {
            $amount = round((float)($row['amount'] ?? 0), 2);
            if ($periodStart === '' || $periodEnd === '') {
                $total += $amount;
                continue;
            }

            $entryStart = (string)($row['period_start'] ?? '');
            $entryEnd = (string)($row['period_end'] ?? '');
            $entryDays = $this->periodDays($entryStart, $entryEnd);
            $overlapDays = $this->overlapDays($entryStart, $entryEnd, $periodStart, $periodEnd);
            if ($entryDays <= 0 || $overlapDays <= 0) {
                continue;
            }
            $total += round($amount * ($overlapDays / $entryDays), 2);
        }

        return round(max(0.0, $total), 2);
    }

    private function capitalAllowanceAmount(int $companyId, int $accountingPeriodId, int $ctPeriodId): float
    {
        $breakdown = (new \eel_accounts\Service\CapitalAllowanceService())->fetchPeriodBreakdown($companyId, $accountingPeriodId, $ctPeriodId);
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
            'SELECT summary_json
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

        return $summary;
    }

    private function periodDays(string $start, string $end): int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $end < $start) {
            return 0;
        }

        return max(1, (new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($end))->days + 1);
    }

    private function overlapDays(string $firstStart, string $firstEnd, string $secondStart, string $secondEnd): int
    {
        $start = max($firstStart, $secondStart);
        $end = min($firstEnd, $secondEnd);

        return $this->periodDays($start, $end);
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
        $periodService = new \eel_accounts\Service\CorporationTaxPeriodService();
        $target = $periodService->fetch($companyId, $targetCtPeriodId);
        if ($target === null) {
            return ['brought_forward' => 0.0];
        }

        $metrics = $this->metricsService ?? new \eel_accounts\Service\YearEndMetricsService();
        $accountingPeriods = array_reverse($metrics->fetchAccountingPeriods($companyId));
        $lossPool = [];

        foreach ($accountingPeriods as $accountingPeriod) {
            $accountingPeriodId = (int)($accountingPeriod['id'] ?? 0);
            $sync = $periodService->syncForAccountingPeriod($companyId, $accountingPeriodId);
            foreach ((array)($sync['periods'] ?? []) as $ctPeriod) {
                if ((string)($ctPeriod['status'] ?? '') === 'superseded') {
                    continue;
                }
                $ctPeriodId = (int)($ctPeriod['id'] ?? 0);
                if ($ctPeriodId === $targetCtPeriodId) {
                    return ['brought_forward' => round(array_sum(array_column($lossPool, 'amount_remaining')), 2)];
                }

                $pnl = $metrics->profitAndLossSummary(
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

                if ($taxableBeforeLosses > 0) {
                    $remainingTaxable = $taxableBeforeLosses;
                    foreach ($lossPool as &$lossRow) {
                        if ($remainingTaxable <= 0) {
                            break;
                        }
                        $usage = min((float)$lossRow['amount_remaining'], $remainingTaxable);
                        $lossRow['amount_remaining'] = round((float)$lossRow['amount_remaining'] - $usage, 2);
                        $remainingTaxable = round($remainingTaxable - $usage, 2);
                    }
                    unset($lossRow);
                } elseif ($taxableBeforeLosses < 0) {
                    $lossPool[] = ['amount_remaining' => abs($taxableBeforeLosses)];
                }
            }
        }

        return ['brought_forward' => 0.0];
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
        $profitAndLoss ??= $metrics->profitAndLossSummary($companyId, $accountingPeriodId, $periodStart, $periodEnd);
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
            'capital_allowance_breakdown' => (new \eel_accounts\Service\CapitalAllowanceService())->fetchPeriodBreakdown($companyId, $accountingPeriodId),
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

    private function associatedCompanyCount(int $companyId): int {
        if ($companyId <= 0) {
            return 0;
        }

        try {
            return max(0, (int)(new \eel_accounts\Store\CompanySettingsStore($companyId))->get('associated_company_count', 0));
        } catch (\Throwable) {
            return 0;
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


