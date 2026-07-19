<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class CapitalAllowanceService
{
    private const RUN_HASH_ALGORITHM_VERSION = 'capital_allowance_pool_v2';
    private const RUN_HASH_VERSION_PREFIX = 'ca02:';

    /** @var array<int, array<string|int, mixed>> */
    private array $calculationCache = [];
    private ?\eel_accounts\Service\TaxRateRuleService $resolvedTaxRateRuleService = null;
    private ?\eel_accounts\Service\CorporationTaxPeriodService $resolvedCorporationTaxPeriodService = null;
    private ?\eel_accounts\Service\YearEndLockService $resolvedYearEndLockService = null;

    public function __construct(
        private readonly ?\eel_accounts\Service\TaxRateRuleService $taxRateRuleService = null,
        private readonly ?\eel_accounts\Service\CorporationTaxPeriodService $corporationTaxPeriodService = null
    ) {
    }

    public function hasRequiredSchema(): bool
    {
        return \InterfaceDB::tableExists('asset_register')
            && \InterfaceDB::tableExists('asset_vehicle_details')
            && \InterfaceDB::tableExists('capital_allowance_pool_runs')
            && \InterfaceDB::tableExists('capital_allowance_asset_calculations');
    }

    public function clearRuntimeCache(?int $companyId = null): void
    {
        if ($companyId === null) {
            $this->calculationCache = [];
            if ($this->resolvedTaxRateRuleService !== null) {
                $this->resolvedTaxRateRuleService->clearRuntimeCaches();
            }
            return;
        }

        unset($this->calculationCache[$companyId]);
        if ($this->resolvedTaxRateRuleService !== null) {
            $this->resolvedTaxRateRuleService->clearRuntimeCaches();
        }
    }

    public function rebuildForCompany(int $companyId): array
    {
        $scopeBlock = (new VatSupportScopeService())->mutationBlockResult($companyId, 'rebuild Corporation Tax capital allowance data');
        if ($scopeBlock !== null) {
            return $scopeBlock;
        }

        if ($companyId <= 0 || !$this->hasRequiredSchema()) {
            return [];
        }

        $this->clearRuntimeCache($companyId);

        $periods = $this->fetchAccountingPeriods($companyId);
        if ($periods === []) {
            return [];
        }

        $transaction = $this->beginMutationTransaction('capital_allowance_rebuild');
        try {
            $lockedPeriodIds = $this->lockedPeriodIdsForRebuild($companyId, $periods);
            $results = $this->buildForCompany($companyId, 'all', 0, $lockedPeriodIds);
            $results['success'] = true;
            $results['errors'] = [];
            $results['locked_period_ids'] = $lockedPeriodIds;
            $this->commitMutationTransaction($transaction);

            return $results;
        } catch (\Throwable $exception) {
            $this->rollBackMutationTransaction($transaction);

            return [
                'success' => false,
                'errors' => [$exception->getMessage()],
            ];
        }
    }

    /**
     * Calculate the complete capital-allowance sequence without changing any
     * CT-period, pool-run, or asset-calculation rows.
     */
    public function calculateForCompany(int $companyId): array
    {
        if ($companyId <= 0) {
            return [];
        }

        if (array_key_exists($companyId, $this->calculationCache)) {
            return $this->calculationCache[$companyId];
        }

        if (!$this->hasRequiredSchema()) {
            return [];
        }

        try {
            // A service instance may perform a persisted rebuild and a later
            // transient read in the same request. Start each fresh company
            // calculation from current rate rows, then cache within that run.
            $this->taxRateRules()->clearRuntimeCaches();
            $periods = $this->fetchAccountingPeriods($companyId);
            $lockedPeriodIds = $this->immutablePeriodIdsForCompany($companyId, $periods);

            return $this->calculationCache[$companyId] =
                $this->buildForCompany($companyId, 'none', 0, $lockedPeriodIds);
        } catch (\Throwable $exception) {
            return $this->calculationCache[$companyId] = [
                'success' => false,
                'errors' => [$exception->getMessage()],
            ];
        }
    }

    /**
     * Persist one accounting period's final capital-allowance evidence while
     * leaving later accounting periods untouched.
     */
    public function persistForAccountingPeriod(int $companyId, int $accountingPeriodId): array
    {
        $scopeBlock = (new VatSupportScopeService())->mutationBlockResult(
            $companyId,
            'persist Corporation Tax capital allowance data for this accounting period'
        );
        if ($scopeBlock !== null) {
            return $scopeBlock;
        }

        if ($accountingPeriodId <= 0) {
            return ['success' => false, 'errors' => ['Select an accounting period before persisting capital allowances.']];
        }

        $this->clearRuntimeCache($companyId);

        $period = \InterfaceDB::fetchOne(
            'SELECT id
             FROM accounting_periods
             WHERE id = :accounting_period_id
               AND company_id = :company_id
             LIMIT 1',
            [
                'accounting_period_id' => $accountingPeriodId,
                'company_id' => $companyId,
            ]
        );
        if (!is_array($period)) {
            return ['success' => false, 'errors' => ['The selected accounting period could not be found.']];
        }

        $transaction = $this->beginMutationTransaction('capital_allowance_period');

        try {
            if ($this->periodHasFinalCtStatus($companyId, $accountingPeriodId)) {
                throw new \RuntimeException(
                    'This accounting period has submitted or accepted Corporation Tax evidence, '
                    . 'so its capital allowance evidence cannot be replaced.'
                );
            }
            $lockService = $this->yearEndLockService();
            $lockService->assertUnlockedForUpdate(
                $companyId,
                $accountingPeriodId,
                'replace capital allowance evidence for this period'
            );
            $periods = $this->fetchAccountingPeriods($companyId);
            $lockedPeriodIds = array_values(array_map(
                static fn(array $candidate): int => (int)$candidate['id'],
                array_filter(
                    $periods,
                    fn(array $candidate): bool => (int)$candidate['id'] !== $accountingPeriodId
                        && (
                            $lockService->isLocked($companyId, (int)$candidate['id'])
                            || $this->periodHasFinalCtStatus($companyId, (int)$candidate['id'])
                        )
                )
            ));
            $results = $this->buildForCompany(
                $companyId,
                'period',
                $accountingPeriodId,
                $lockedPeriodIds
            );
            if (!isset($results[$accountingPeriodId])) {
                throw new \RuntimeException('Capital allowances could not be calculated for the selected accounting period.');
            }
            $this->commitMutationTransaction($transaction);

            return [
                'success' => true,
                'errors' => [],
                'result' => (array)$results[$accountingPeriodId],
            ];
        } catch (\Throwable $exception) {
            $this->rollBackMutationTransaction($transaction);

            return [
                'success' => false,
                'errors' => [$exception->getMessage()],
                'result' => [],
            ];
        }
    }

    private function buildForCompany(
        int $companyId,
        string $persistMode,
        int $targetAccountingPeriodId = 0,
        array $lockedPeriodIds = []
    ): array
    {
        if ($companyId <= 0 || !$this->hasRequiredSchema()) {
            return [];
        }

        $periods = $this->fetchAccountingPeriods($companyId);
        if ($periods === []) {
            return [];
        }

        $firstPeriod = (array)reset($periods);
        $lastPeriod = (array)end($periods);
        $calculationStart = (string)($firstPeriod['period_start'] ?? '');
        $calculationEnd = (string)($lastPeriod['period_end'] ?? '');
        $assetAdditions = $this->fetchAssetAdditions($companyId, $calculationStart, $calculationEnd);
        $assetDisposals = $this->fetchDisposals($companyId, $calculationStart, $calculationEnd);

        $cessationDate = $this->qualifyingActivityCessationDate($companyId);
        $lockedPeriodIds = array_fill_keys(array_map('intval', $lockedPeriodIds), true);

        $mainWdv = 0.0;
        $specialWdv = 0.0;
        $assetPools = [];
        $outstandingPooledAssets = [];
        $results = [];

        $ctPeriodService = $this->corporationTaxPeriodService();

        foreach ($periods as $period) {
            $periodId = (int)$period['id'];
            $locked = isset($lockedPeriodIds[$periodId]);
            if ($locked) {
                $this->applyLockedPeriodEvidence(
                    $companyId,
                    $period,
                    $cessationDate,
                    $mainWdv,
                    $specialWdv,
                    $assetPools,
                    $outstandingPooledAssets,
                    $results
                );
                continue;
            }

            $persistThisPeriod = $persistMode === 'all'
                || ($persistMode === 'period' && $periodId === $targetAccountingPeriodId);
            $ctPeriods = $this->ctPeriodsForAccountingPeriod(
                $ctPeriodService,
                $companyId,
                $period,
                $persistThisPeriod
            );
            if ($persistThisPeriod) {
                $this->deleteExistingRowsForAccountingPeriod($companyId, $periodId);
            }

            foreach ($ctPeriods as $ctPeriod) {
                $ctPeriodId = (int)($ctPeriod['id'] ?? 0);
                $periodStart = (string)$ctPeriod['period_start'];
                $periodEnd = (string)$ctPeriod['period_end'];
                $periodDays = $this->periodDays($periodStart, $periodEnd);
                $cessationInPeriod = $cessationDate !== ''
                    && $cessationDate >= $periodStart
                    && $cessationDate <= $periodEnd;
                $afterCessation = $cessationDate !== '' && $periodStart > $cessationDate;
                $activityEnd = $cessationInPeriod ? $cessationDate : $periodEnd;
                $activityDays = $this->periodDays($periodStart, $activityEnd);
                $aiaRemaining = $afterCessation || $cessationInPeriod
                    ? 0.0
                    : $this->aiaLimitForPeriod($periodStart, $activityEnd, $activityDays);
                $periodRows = [];
                $warnings = [];

                if ($afterCessation) {
                    $mainWdv = 0.0;
                    $specialWdv = 0.0;
                }

                $main = $this->emptyPool('main_pool', $mainWdv);
                $special = $this->emptyPool('special_rate_pool', $specialWdv);

                if (!$afterCessation) {
                    foreach ($this->assetEventsForPeriod($assetAdditions, 'purchase_date', $periodStart, $activityEnd) as $asset) {
                        $assetId = (int)$asset['id'];
                        $cost = round((float)$asset['cost'], 2);
                        $treatment = $this->additionTreatment($asset);
                        foreach ($treatment['warnings'] as $warning) {
                            $warnings[] = $warning;
                            $periodRows[] = $this->assetRow($companyId, $periodId, $ctPeriodId, $assetId, 'unreviewed', 'warning', $cost, 0.0, 0.0, $warning);
                        }

                        if ($treatment['pool'] === 'unreviewed') {
                            $assetPools[$assetId] = 'unreviewed';
                            continue;
                        }

                        if ($treatment['allowance_type'] === 'aia' && !$cessationInPeriod) {
                            $aia = round(min($cost, $aiaRemaining), 2);
                            $aiaRemaining = round($aiaRemaining - $aia, 2);
                            $remaining = round($cost - $aia, 2);
                            $main['additions'] += $remaining;
                            $main['aia_claimed'] += $aia;
                            $mainWdv += $remaining;
                            $assetPools[$assetId] = 'main_pool';
                            $outstandingPooledAssets[$assetId] = $this->outstandingPooledAsset($asset, 'main_pool');
                            $periodRows[] = $this->assetRow($companyId, $periodId, $ctPeriodId, $assetId, 'main_pool', 'aia', $cost, $aia);
                            if ($remaining > 0) {
                                $periodRows[] = $this->assetRow($companyId, $periodId, $ctPeriodId, $assetId, 'main_pool', 'main_pool_addition', $remaining);
                            }
                            continue;
                        }

                        if ($treatment['allowance_type'] === 'fya' && !$cessationInPeriod) {
                            $main['fya_claimed'] += $cost;
                            // The residual qualifying expenditure is nil after a
                            // 100% FYA, but it still enters the main pool no later
                            // than disposal so the disposal value creates the
                            // required balancing adjustment.
                            $assetPools[$assetId] = 'main_pool';
                            $outstandingPooledAssets[$assetId] = $this->outstandingPooledAsset($asset, 'main_pool');
                            $periodRows[] = $this->assetRow($companyId, $periodId, $ctPeriodId, $assetId, 'main_pool', 'fya', $cost, $cost);
                            continue;
                        }

                        if ($treatment['pool'] === 'special_rate_pool') {
                            $special['additions'] += $cost;
                            $specialWdv += $cost;
                            $assetPools[$assetId] = 'special_rate_pool';
                            $outstandingPooledAssets[$assetId] = $this->outstandingPooledAsset($asset, 'special_rate_pool');
                            $periodRows[] = $this->assetRow($companyId, $periodId, $ctPeriodId, $assetId, 'special_rate_pool', 'special_rate_pool_addition', $cost);
                            continue;
                        }

                        $main['additions'] += $cost;
                        $mainWdv += $cost;
                        $assetPools[$assetId] = 'main_pool';
                        $outstandingPooledAssets[$assetId] = $this->outstandingPooledAsset($asset, 'main_pool');
                        $periodRows[] = $this->assetRow($companyId, $periodId, $ctPeriodId, $assetId, 'main_pool', 'main_pool_addition', $cost);
                    }

                    foreach ($this->assetEventsForPeriod($assetDisposals, 'disposal_date', $periodStart, $activityEnd) as $asset) {
                        $assetId = (int)$asset['id'];
                        $pool = (string)($assetPools[$assetId] ?? $this->poolForExistingAsset($asset));
                        $proceeds = $this->qualifyingDisposalValue($asset);
                        if ($pool === 'special_rate_pool') {
                            $special['disposal_value'] += $proceeds;
                            $specialWdv = round($specialWdv - $proceeds, 2);
                            unset($outstandingPooledAssets[$assetId]);
                            $periodRows[] = $this->assetRow($companyId, $periodId, $ctPeriodId, $assetId, 'special_rate_pool', 'disposal_value', 0.0, 0.0, $proceeds);
                        } elseif ($pool === 'main_pool') {
                            $main['disposal_value'] += $proceeds;
                            $mainWdv = round($mainWdv - $proceeds, 2);
                            unset($outstandingPooledAssets[$assetId]);
                            $periodRows[] = $this->assetRow($companyId, $periodId, $ctPeriodId, $assetId, 'main_pool', 'disposal_value', 0.0, 0.0, $proceeds);
                        }
                    }
                }

                if ($cessationInPeriod) {
                    $mainMissing = $this->cessationValuationWarnings(
                        $outstandingPooledAssets,
                        'main_pool',
                        $cessationDate
                    );
                    $specialMissing = $this->cessationValuationWarnings(
                        $outstandingPooledAssets,
                        'special_rate_pool',
                        $cessationDate
                    );
                    $warnings = array_merge($warnings, $mainMissing, $specialMissing);

                    $this->finaliseCessationPool($main, $mainWdv, $mainMissing === []);
                    $this->finaliseCessationPool($special, $specialWdv, $specialMissing === []);
                } elseif (!$afterCessation) {
                    if ($mainWdv < 0) {
                        $main['balancing_charge'] = round(abs($mainWdv), 2);
                        $mainWdv = 0.0;
                    }
                    if ($specialWdv < 0) {
                        $special['balancing_charge'] = round(abs($specialWdv), 2);
                        $specialWdv = 0.0;
                    }

                    $mainRate = $this->mainRateForPeriod($periodStart, $periodEnd);
                    $mainWda = round($mainWdv * $mainRate * min(1.0, $periodDays / 365), 2);
                    $specialRate = $this->specialRateForPeriod($periodStart, $periodEnd);
                    $specialWda = round($specialWdv * $specialRate * min(1.0, $periodDays / 365), 2);
                    $main['wda_claimed'] = min($mainWdv, $mainWda);
                    $special['wda_claimed'] = min($specialWdv, $specialWda);
                    $mainWdv = round($mainWdv - $main['wda_claimed'], 2);
                    $specialWdv = round($specialWdv - $special['wda_claimed'], 2);
                }

                $main['closing_wdv'] = round($mainWdv, 2);
                $special['closing_wdv'] = round($specialWdv, 2);
                $main['warnings'] = $warnings;
                $special['warnings'] = $warnings;

                if ($persistThisPeriod) {
                    $this->insertPoolRun($companyId, $periodId, $ctPeriodId, $main);
                    $this->insertPoolRun($companyId, $periodId, $ctPeriodId, $special);
                    foreach ($periodRows as $row) {
                        $this->insertAssetCalculation($row);
                    }
                }

                $allowance = round($main['aia_claimed'] + $main['fya_claimed'] + $main['wda_claimed'] + $special['wda_claimed'] + $main['balancing_allowance'] + $special['balancing_allowance'], 2);
                $charge = round($main['balancing_charge'] + $special['balancing_charge'], 2);
                $result = [
                    'allowance' => $allowance,
                    'charge' => $charge,
                    'net_capital_allowances' => round($allowance - $charge, 2),
                    'warnings' => array_values(array_unique($warnings)),
                    'pools' => [$main, $special],
                    'asset_calculations' => $periodRows,
                    'ct_period_id' => $ctPeriodId,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'qualifying_activity_ceased_on' => $cessationDate,
                ];

                $results['ct_periods'][$ctPeriodId] = $result;
                if (!isset($results[$periodId])) {
                    $results[$periodId] = [
                        'allowance' => 0.0,
                        'charge' => 0.0,
                        'net_capital_allowances' => 0.0,
                        'warnings' => [],
                        'pools' => [],
                        'asset_calculations' => [],
                    ];
                }
                $results[$periodId]['allowance'] = round((float)$results[$periodId]['allowance'] + $allowance, 2);
                $results[$periodId]['charge'] = round((float)$results[$periodId]['charge'] + $charge, 2);
                $results[$periodId]['net_capital_allowances'] = round((float)$results[$periodId]['allowance'] - (float)$results[$periodId]['charge'], 2);
                $results[$periodId]['warnings'] = array_values(array_unique(array_merge((array)$results[$periodId]['warnings'], $warnings)));
                $results[$periodId]['pools'] = array_merge((array)$results[$periodId]['pools'], [$main, $special]);
                $results[$periodId]['asset_calculations'] = array_merge(
                    (array)$results[$periodId]['asset_calculations'],
                    $periodRows
                );

                if ($cessationInPeriod) {
                    // A qualifying activity has no pool to carry into a later CT
                    // period. Incomplete valuations are retained on this period
                    // only so the warning and unrelieved amount remain visible.
                    $mainWdv = 0.0;
                    $specialWdv = 0.0;
                    $outstandingPooledAssets = [];
                }
            }
        }

        return $results;
    }

    private function applyLockedPeriodEvidence(
        int $companyId,
        array $period,
        string $cessationDate,
        float &$mainWdv,
        float &$specialWdv,
        array &$assetPools,
        array &$outstandingPooledAssets,
        array &$results
    ): void {
        $periodId = (int)($period['id'] ?? 0);
        $hasCtPeriodColumn = \InterfaceDB::columnExists('capital_allowance_pool_runs', 'ct_period_id');
        $poolSql = $hasCtPeriodColumn && \InterfaceDB::tableExists('corporation_tax_periods')
            ? 'SELECT pr.*, ctp.sequence_no, ctp.period_start AS ct_period_start, ctp.period_end AS ct_period_end
               FROM capital_allowance_pool_runs pr
               LEFT JOIN corporation_tax_periods ctp ON ctp.id = pr.ct_period_id
               WHERE pr.company_id = :company_id
                 AND pr.accounting_period_id = :accounting_period_id
               ORDER BY COALESCE(ctp.sequence_no, 1) ASC, pr.ct_period_id ASC, pr.pool_type ASC, pr.id ASC'
            : 'SELECT pr.*, 1 AS sequence_no, NULL AS ct_period_start, NULL AS ct_period_end
               FROM capital_allowance_pool_runs pr
               WHERE pr.company_id = :company_id
                 AND pr.accounting_period_id = :accounting_period_id
               ORDER BY pr.pool_type ASC, pr.id ASC';
        $poolRows = \InterfaceDB::fetchAll(
            $poolSql,
            ['company_id' => $companyId, 'accounting_period_id' => $periodId]
        ) ?: [];
        if ($poolRows === []) {
            throw new \RuntimeException(
                'Locked accounting period ' . $periodId
                . ' has no persisted capital allowance evidence. Unlock it before rebuilding.'
            );
        }

        $assetRows = \InterfaceDB::fetchAll(
            'SELECT *
             FROM capital_allowance_asset_calculations
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             ORDER BY id ASC',
            ['company_id' => $companyId, 'accounting_period_id' => $periodId]
        ) ?: [];

        $poolGroups = [];
        foreach ($poolRows as $row) {
            $ctPeriodId = $hasCtPeriodColumn ? (int)($row['ct_period_id'] ?? 0) : 0;
            $poolGroups[$ctPeriodId][] = $row;
        }
        $assetGroups = [];
        foreach ($assetRows as $row) {
            $ctPeriodId = \InterfaceDB::columnExists('capital_allowance_asset_calculations', 'ct_period_id')
                ? (int)($row['ct_period_id'] ?? 0)
                : 0;
            $assetGroups[$ctPeriodId][] = $row;
        }

        foreach ($poolGroups as $ctPeriodId => $groupRows) {
            $pools = [];
            $warnings = [];
            foreach ($groupRows as $row) {
                $decoded = json_decode((string)($row['warnings_json'] ?? '[]'), true);
                $rowWarnings = array_values(array_map('strval', is_array($decoded) ? $decoded : []));
                $warnings = array_merge($warnings, $rowWarnings);
                $pool = $this->normalisePoolBreakdownRow($row);
                $pool['warnings'] = $rowWarnings;
                $pools[(string)$pool['pool_type']] = $pool;
            }
            if (!isset($pools['main_pool'], $pools['special_rate_pool'])) {
                throw new \RuntimeException(
                    'Locked accounting period ' . $periodId
                    . ' has incomplete capital allowance pool evidence. Unlock it before rebuilding.'
                );
            }

            $main = $pools['main_pool'];
            $special = $pools['special_rate_pool'];
            $mainWdv = round((float)$main['closing_wdv'], 2);
            $specialWdv = round((float)$special['closing_wdv'], 2);
            $periodAssetRows = (array)($assetGroups[$ctPeriodId] ?? []);
            foreach ($periodAssetRows as $row) {
                $assetId = (int)($row['asset_id'] ?? 0);
                $poolType = (string)($row['pool_type'] ?? '');
                $allowanceType = (string)($row['allowance_type'] ?? '');
                if ($assetId <= 0) {
                    continue;
                }
                if ($allowanceType === 'disposal_value') {
                    $assetPools[$assetId] = $poolType;
                    unset($outstandingPooledAssets[$assetId]);
                    continue;
                }
                if ($poolType === 'unreviewed') {
                    $assetPools[$assetId] = 'unreviewed';
                    unset($outstandingPooledAssets[$assetId]);
                    continue;
                }
                if (in_array($poolType, ['main_pool', 'special_rate_pool'], true)) {
                    $assetPools[$assetId] = $poolType;
                    $outstandingPooledAssets[$assetId] = [
                        'asset_id' => $assetId,
                        'asset_code' => '#' . $assetId,
                        'description' => '',
                        'pool' => $poolType,
                    ];
                }
            }

            $warnings = array_values(array_unique($warnings));
            $allowance = round(
                (float)$main['aia_claimed']
                + (float)$main['fya_claimed']
                + (float)$main['wda_claimed']
                + (float)$special['wda_claimed']
                + (float)$main['balancing_allowance']
                + (float)$special['balancing_allowance'],
                2
            );
            $charge = round(
                (float)$main['balancing_charge'] + (float)$special['balancing_charge'],
                2
            );
            $firstRow = (array)($groupRows[0] ?? []);
            $periodStart = trim((string)($firstRow['ct_period_start'] ?? ''));
            $periodEnd = trim((string)($firstRow['ct_period_end'] ?? ''));
            if ($periodStart === '') {
                $periodStart = (string)($period['period_start'] ?? '');
            }
            if ($periodEnd === '') {
                $periodEnd = (string)($period['period_end'] ?? '');
            }
            $result = [
                'allowance' => $allowance,
                'charge' => $charge,
                'net_capital_allowances' => round($allowance - $charge, 2),
                'warnings' => $warnings,
                'pools' => [$main, $special],
                'asset_calculations' => $periodAssetRows,
                'ct_period_id' => (int)$ctPeriodId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'qualifying_activity_ceased_on' => $cessationDate,
                'calculation_source' => 'locked_persisted',
            ];

            $results['ct_periods'][(int)$ctPeriodId] = $result;
            if (!isset($results[$periodId])) {
                $results[$periodId] = [
                    'allowance' => 0.0,
                    'charge' => 0.0,
                    'net_capital_allowances' => 0.0,
                    'warnings' => [],
                    'pools' => [],
                    'asset_calculations' => [],
                    'calculation_source' => 'locked_persisted',
                ];
            }
            $results[$periodId]['allowance'] = round((float)$results[$periodId]['allowance'] + $allowance, 2);
            $results[$periodId]['charge'] = round((float)$results[$periodId]['charge'] + $charge, 2);
            $results[$periodId]['net_capital_allowances'] = round(
                (float)$results[$periodId]['allowance'] - (float)$results[$periodId]['charge'],
                2
            );
            $results[$periodId]['warnings'] = array_values(array_unique(array_merge(
                (array)$results[$periodId]['warnings'],
                $warnings
            )));
            $results[$periodId]['pools'] = array_merge(
                (array)$results[$periodId]['pools'],
                [$main, $special]
            );
            $results[$periodId]['asset_calculations'] = array_merge(
                (array)$results[$periodId]['asset_calculations'],
                $periodAssetRows
            );

            if ($cessationDate !== '' && $cessationDate >= $periodStart && $cessationDate <= $periodEnd) {
                $mainWdv = 0.0;
                $specialWdv = 0.0;
                $outstandingPooledAssets = [];
            }
        }
    }

    public function fetchPeriodBreakdown(int $companyId, int $accountingPeriodId, int $ctPeriodId = 0): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || !\InterfaceDB::tableExists('capital_allowance_pool_runs')) {
            return ['available' => false, 'rows' => [], 'asset_calculations' => [], 'warnings' => []];
        }

        $sql = 'SELECT *
             FROM capital_allowance_pool_runs
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id';
        $params = ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId];
        if ($ctPeriodId > 0 && \InterfaceDB::columnExists('capital_allowance_pool_runs', 'ct_period_id')) {
            $sql .= ' AND ct_period_id = :ct_period_id';
            $params['ct_period_id'] = $ctPeriodId;
        }
        $sql .= ' ORDER BY pool_type ASC';

        $rows = \InterfaceDB::fetchAll(
            $sql,
            $params
        ) ?: [];
        $locked = $this->yearEndLockService()->isLocked($companyId, $accountingPeriodId)
            || $this->periodHasFinalCtStatus($companyId, $accountingPeriodId);
        if (!$locked || $rows === []) {
            $calculated = $this->calculateForCompany($companyId);
            if (isset($calculated['success']) && empty($calculated['success'])) {
                return [
                    'available' => false,
                    'rows' => [],
                    'asset_calculations' => [],
                    'warnings' => array_values(array_map(
                        'strval',
                        (array)($calculated['errors']
                            ?? ['Capital allowances could not be calculated.'])
                    )),
                    'calculation_source' => 'calculation_failed',
                ];
            }
            $calculatedPeriod = $ctPeriodId !== 0
                ? (array)($calculated['ct_periods'][$ctPeriodId] ?? [])
                : (array)($calculated[$accountingPeriodId] ?? []);
            $calculatedPools = (array)($calculatedPeriod['pools'] ?? []);
            if ($calculatedPools !== []) {
                $warnings = array_values(array_unique(array_map(
                    'strval',
                    (array)($calculatedPeriod['warnings'] ?? [])
                )));

                return [
                    'available' => true,
                    'rows' => array_map(
                        fn(array $pool): array => $this->normalisePoolBreakdownRow($pool),
                        $calculatedPools
                    ),
                    'asset_calculations' => array_map(
                        fn(array $row): array => $this->normaliseAssetCalculationRow($row),
                        (array)($calculatedPeriod['asset_calculations'] ?? [])
                    ),
                    'warnings' => $warnings,
                    'calculation_source' => 'transient',
                ];
            }
        }

        $warnings = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string)($row['warnings_json'] ?? '[]'), true);
            foreach (is_array($decoded) ? $decoded : [] as $warning) {
                $warnings[] = (string)$warning;
            }
        }

        return [
            'available' => $rows !== [],
            'rows' => array_map(
                fn(array $row): array => $this->normalisePoolBreakdownRow($row),
                $rows
            ),
            'asset_calculations' => $this->fetchPersistedAssetCalculationRows(
                $companyId,
                $accountingPeriodId,
                $ctPeriodId
            ),
            'warnings' => array_values(array_unique($warnings)),
            'calculation_source' => 'persisted',
        ];
    }

    public function periodWarnings(int $companyId, int $accountingPeriodId, int $ctPeriodId = 0): array
    {
        return (array)$this->fetchPeriodBreakdown($companyId, $accountingPeriodId, $ctPeriodId)['warnings'];
    }

    private function ctPeriodsForAccountingPeriod(
        \eel_accounts\Service\CorporationTaxPeriodService $service,
        int $companyId,
        array $period,
        bool $synchronise
    ): array
    {
        if (!\InterfaceDB::columnExists('capital_allowance_pool_runs', 'ct_period_id')
            || !\InterfaceDB::columnExists('capital_allowance_asset_calculations', 'ct_period_id')) {
            return [[
                'id' => 0,
                'accounting_period_id' => (int)($period['id'] ?? 0),
                'sequence_no' => 1,
                'period_start' => (string)($period['period_start'] ?? ''),
                'period_end' => (string)($period['period_end'] ?? ''),
                'status' => 'pending',
            ]];
        }

        $periodId = (int)($period['id'] ?? 0);
        $rows = [];
        if ($periodId > 0) {
            if ($synchronise) {
                $sync = $service->syncForAccountingPeriod($companyId, $periodId);
                if (empty($sync['success'])) {
                    throw new \RuntimeException(implode(
                        ' ',
                        array_map(
                            'strval',
                            (array)($sync['errors'] ?? ['Corporation Tax periods could not be synchronised.'])
                        )
                    ));
                }
                $rows = (array)($sync['periods'] ?? []);
            } else {
                $scope = (new \eel_accounts\Service\VatSupportScopeService())->fetchForCompany($companyId);
                if (!empty($scope['scope_evaluation_failed'])) {
                    throw new \RuntimeException((string)(
                        $scope['message']
                        ?? \eel_accounts\Service\VatSupportScopeService::SCOPE_EVALUATION_ERROR_MESSAGE
                    ));
                }
                if (!empty($scope['tax_year_end_read_only'])) {
                    $rows = $service->fetchExistingForAccountingPeriod($companyId, $periodId);
                } else {
                    $projection = $service->projectForAccountingPeriod(
                        $companyId,
                        $periodId,
                        $period
                    );
                    if (empty($projection['success'])) {
                        throw new \RuntimeException(implode(
                            ' ',
                            array_map(
                                'strval',
                                (array)($projection['errors'] ?? ['Corporation Tax periods could not be projected.'])
                            )
                        ));
                    }
                    $rows = (array)($projection['periods'] ?? []);
                }
            }
        }
        $rows = array_values(array_filter(
            $rows,
            static fn(array $row): bool => (string)($row['status'] ?? '') !== 'superseded'
        ));
        if ($rows !== []) {
            return $rows;
        }

        throw new \RuntimeException('No Corporation Tax periods could be resolved for the accounting period.');
    }

    private function fetchAccountingPeriods(int $companyId): array
    {
        $rows = \InterfaceDB::fetchAll(
            'SELECT id, label, period_start, period_end
             FROM accounting_periods
             WHERE company_id = :company_id
             ORDER BY period_start ASC, id ASC',
            ['company_id' => $companyId]
        ) ?: [];

        return array_values(array_filter($rows, static fn(array $row): bool => (int)($row['id'] ?? 0) > 0));
    }

    private function fetchAssetAdditions(int $companyId, string $periodStart, string $periodEnd): array
    {
        return \InterfaceDB::fetchAll(
            'SELECT ar.*,
                    na.code AS nominal_code,
                    vd.vehicle_type,
                    vd.first_registered_date,
                    vd.acquisition_condition,
                    vd.is_zero_emission,
                    vd.co2_emissions_g_km,
                    vd.payload_kg,
                    vd.contract_date
             FROM asset_register ar
             INNER JOIN nominal_accounts na ON na.id = ar.nominal_account_id
             LEFT JOIN asset_vehicle_details vd ON vd.asset_id = ar.id
             WHERE ar.company_id = :company_id
               AND ar.purchase_date BETWEEN :period_start AND :period_end
             ORDER BY ar.purchase_date ASC, ar.id ASC',
            ['company_id' => $companyId, 'period_start' => $periodStart, 'period_end' => $periodEnd]
        ) ?: [];
    }

    private function fetchDisposals(int $companyId, string $periodStart, string $periodEnd): array
    {
        return \InterfaceDB::fetchAll(
            'SELECT ar.*,
                    na.code AS nominal_code,
                    vd.vehicle_type,
                    vd.first_registered_date,
                    vd.acquisition_condition,
                    vd.is_zero_emission,
                    vd.co2_emissions_g_km
             FROM asset_register ar
             INNER JOIN nominal_accounts na ON na.id = ar.nominal_account_id
             LEFT JOIN asset_vehicle_details vd ON vd.asset_id = ar.id
             WHERE ar.company_id = :company_id
               AND ar.disposal_date BETWEEN :period_start AND :period_end
               AND ar.disposal_proceeds IS NOT NULL',
            ['company_id' => $companyId, 'period_start' => $periodStart, 'period_end' => $periodEnd]
        ) ?: [];
    }

    /**
     * Asset rows are fetched once for the complete company sequence and then
     * partitioned in memory, avoiding two SQL round trips for every CT period.
     * ISO dates preserve chronological ordering under string comparison.
     */
    private function assetEventsForPeriod(array $rows, string $dateColumn, string $periodStart, string $periodEnd): array
    {
        return array_values(array_filter(
            $rows,
            static function (array $row) use ($dateColumn, $periodStart, $periodEnd): bool {
                $date = (string)($row[$dateColumn] ?? '');

                return $date !== '' && $date >= $periodStart && $date <= $periodEnd;
            }
        ));
    }

    private function qualifyingDisposalValue(array $asset): float
    {
        $proceeds = max(0.0, (float)($asset['disposal_proceeds'] ?? 0));
        $qualifyingExpenditure = max(0.0, (float)($asset['cost'] ?? 0));

        return round(min($proceeds, $qualifyingExpenditure), 2);
    }

    private function qualifyingActivityCessationDate(int $companyId): string
    {
        if ($companyId <= 0) {
            return '';
        }

        $date = trim((string)(new \eel_accounts\Store\CompanySettingsStore($companyId))
            ->get('qualifying_activity_ceased_on', ''));

        return $this->isIsoDate($date) ? $date : '';
    }

    private function outstandingPooledAsset(array $asset, string $pool): array
    {
        return [
            'asset_id' => (int)($asset['id'] ?? 0),
            'asset_code' => trim((string)($asset['asset_code'] ?? '')),
            'description' => trim((string)($asset['description'] ?? '')),
            'pool' => $pool,
        ];
    }

    private function cessationValuationWarnings(array $outstandingAssets, string $pool, string $cessationDate): array
    {
        $warnings = [];
        foreach ($outstandingAssets as $asset) {
            if ((string)($asset['pool'] ?? '') !== $pool) {
                continue;
            }

            $label = trim((string)($asset['asset_code'] ?? ''));
            if ($label === '') {
                $label = trim((string)($asset['description'] ?? ''));
            }
            if ($label === '') {
                $label = '#' . (int)($asset['asset_id'] ?? 0);
            }

            $warnings[] = 'Qualifying activity ceased on ' . $cessationDate
                . ', but pooled asset ' . $label
                . ' has no disposal value dated on or before cessation. Enter its disposal date and value before relying on the Corporation Tax calculation.';
        }

        return $warnings;
    }

    private function finaliseCessationPool(array &$pool, float &$wdv, bool $hasCompleteValuations): void
    {
        $pool['wda_claimed'] = 0.0;
        if (!$hasCompleteValuations) {
            return;
        }

        if ($wdv < 0) {
            $pool['balancing_charge'] = round(abs($wdv), 2);
        } elseif ($wdv > 0) {
            $pool['balancing_allowance'] = round($wdv, 2);
        }

        $wdv = 0.0;
    }

    private function additionTreatment(array $asset): array
    {
        $category = (string)($asset['category'] ?? '');
        $nominalCode = (string)($asset['nominal_code'] ?? '');
        $vehicleType = (string)($asset['vehicle_type'] ?? '');
        $warnings = [];

        if ($nominalCode === '1320') {
            $warnings[] = 'Motor vehicle asset ' . (string)($asset['asset_code'] ?? '#' . (int)$asset['id']) . ' remains in default nominal 1320 and is excluded from capital allowance modelling until reviewed.';
            return ['pool' => 'unreviewed', 'allowance_type' => 'none', 'warnings' => $warnings];
        }

        if (in_array($category, ['tools_equipment', 'plant_machinery'], true)) {
            return ['pool' => 'main_pool', 'allowance_type' => 'aia', 'warnings' => $warnings];
        }

        if ($nominalCode === '1322' || $vehicleType === 'van' || $category === 'van') {
            return ['pool' => 'main_pool', 'allowance_type' => 'aia', 'warnings' => $warnings];
        }

        if ($nominalCode !== '1321' && $category !== 'car' && $vehicleType !== 'car') {
            return ['pool' => 'unreviewed', 'allowance_type' => 'none', 'warnings' => $warnings];
        }

        if (trim((string)($asset['acquisition_condition'] ?? '')) === '') {
            $warnings[] = 'Car asset ' . (string)($asset['asset_code'] ?? '#' . (int)$asset['id']) . ' is missing new/second-hand status.';
        }

        $isZeroEmission = (int)($asset['is_zero_emission'] ?? 0) === 1;
        $isNewUnused = (string)($asset['acquisition_condition'] ?? '') === 'new_unused';
        if ($isZeroEmission && $isNewUnused) {
            return ['pool' => 'main_pool', 'allowance_type' => 'fya', 'warnings' => $warnings];
        }

        $co2 = $asset['co2_emissions_g_km'] !== null ? (int)$asset['co2_emissions_g_km'] : null;
        if ($co2 === null && !$this->registeredBeforeMarch2001((string)($asset['first_registered_date'] ?? ''))) {
            $warnings[] = 'Car asset ' . (string)($asset['asset_code'] ?? '#' . (int)$asset['id']) . ' is missing CO2 emissions; special-rate pool treatment has been used.';
            return ['pool' => 'special_rate_pool', 'allowance_type' => 'wda', 'warnings' => $warnings];
        }

        return [
            'pool' => $co2 !== null && $co2 > $this->mainPoolCo2Threshold((string)($asset['purchase_date'] ?? ''))
                ? 'special_rate_pool'
                : 'main_pool',
            'allowance_type' => 'wda',
            'warnings' => $warnings,
        ];
    }

    private function poolForExistingAsset(array $asset): string
    {
        return (string)($this->additionTreatment($asset)['pool'] ?? 'unreviewed');
    }

    private function registeredBeforeMarch2001(string $date): bool
    {
        return $this->isIsoDate($date) && $date < '2001-03-01';
    }

    private function mainPoolCo2Threshold(string $purchaseDate): int
    {
        if ($purchaseDate >= '2021-04-01') {
            return 50;
        }
        if ($purchaseDate >= '2018-04-01') {
            return 110;
        }
        if ($purchaseDate >= '2015-04-01') {
            return 130;
        }
        if ($purchaseDate >= '2013-04-01') {
            return 130;
        }

        return 160;
    }

    private function emptyPool(string $poolType, float $openingWdv): array
    {
        return [
            'pool_type' => $poolType,
            'opening_wdv' => round($openingWdv, 2),
            'additions' => 0.0,
            'aia_claimed' => 0.0,
            'fya_claimed' => 0.0,
            'disposal_value' => 0.0,
            'wda_claimed' => 0.0,
            'balancing_charge' => 0.0,
            'balancing_allowance' => 0.0,
            'closing_wdv' => round($openingWdv, 2),
            'warnings' => [],
        ];
    }

    private function assetRow(
        int $companyId,
        int $periodId,
        int $ctPeriodId,
        int $assetId,
        string $pool,
        string $type,
        float $addition = 0.0,
        float $allowance = 0.0,
        float $disposal = 0.0,
        string $warning = ''
    ): array {
        return [
            'company_id' => $companyId,
            'accounting_period_id' => $periodId,
            'ct_period_id' => $ctPeriodId !== 0 ? $ctPeriodId : null,
            'asset_id' => $assetId,
            'pool_type' => $pool,
            'allowance_type' => $type,
            'addition_amount' => round($addition, 2),
            'allowance_amount' => round($allowance, 2),
            'disposal_value' => round($disposal, 2),
            'warning' => $warning,
        ];
    }

    private function insertPoolRun(int $companyId, int $periodId, int $ctPeriodId, array $pool): void
    {
        $payload = [
            'company_id' => $companyId,
            'accounting_period_id' => $periodId,
            'pool_type' => (string)$pool['pool_type'],
            'opening_wdv' => round((float)$pool['opening_wdv'], 2),
            'additions' => round((float)$pool['additions'], 2),
            'aia_claimed' => round((float)$pool['aia_claimed'], 2),
            'fya_claimed' => round((float)$pool['fya_claimed'], 2),
            'disposal_value' => round((float)$pool['disposal_value'], 2),
            'wda_claimed' => round((float)$pool['wda_claimed'], 2),
            'balancing_charge' => round((float)$pool['balancing_charge'], 2),
            'balancing_allowance' => round((float)$pool['balancing_allowance'], 2),
            'closing_wdv' => round((float)$pool['closing_wdv'], 2),
            'warnings_json' => json_encode(array_values(array_unique(array_map('strval', (array)($pool['warnings'] ?? [])))), JSON_UNESCAPED_SLASHES),
        ];
        $hasCtPeriodColumn = \InterfaceDB::columnExists('capital_allowance_pool_runs', 'ct_period_id');
        if ($hasCtPeriodColumn) {
            $payload['ct_period_id'] = $ctPeriodId > 0 ? $ctPeriodId : null;
        }
        $payload['run_hash'] = $this->serviceRunHash($payload);

        $columns = $hasCtPeriodColumn
            ? 'company_id, accounting_period_id, ct_period_id, pool_type, opening_wdv, additions, aia_claimed,
                fya_claimed, disposal_value, wda_claimed, balancing_charge, balancing_allowance,
                closing_wdv, warnings_json, run_hash, computed_at'
            : 'company_id, accounting_period_id, pool_type, opening_wdv, additions, aia_claimed,
                fya_claimed, disposal_value, wda_claimed, balancing_charge, balancing_allowance,
                closing_wdv, warnings_json, run_hash, computed_at';
        $values = $hasCtPeriodColumn
            ? ':company_id, :accounting_period_id, :ct_period_id, :pool_type, :opening_wdv, :additions, :aia_claimed,
                :fya_claimed, :disposal_value, :wda_claimed, :balancing_charge, :balancing_allowance,
                :closing_wdv, :warnings_json, :run_hash, CURRENT_TIMESTAMP'
            : ':company_id, :accounting_period_id, :pool_type, :opening_wdv, :additions, :aia_claimed,
                :fya_claimed, :disposal_value, :wda_claimed, :balancing_charge, :balancing_allowance,
                :closing_wdv, :warnings_json, :run_hash, CURRENT_TIMESTAMP';

        \InterfaceDB::prepareExecute(
            'INSERT INTO capital_allowance_pool_runs (' . $columns . ') VALUES (' . $values . ')',
            $payload
        );
    }

    private function insertAssetCalculation(array $row): void
    {
        $hasCtPeriodColumn = \InterfaceDB::columnExists('capital_allowance_asset_calculations', 'ct_period_id');
        $params = [
                'company_id' => (int)$row['company_id'],
                'accounting_period_id' => (int)$row['accounting_period_id'],
                'asset_id' => (int)$row['asset_id'],
                'pool_type' => (string)$row['pool_type'],
                'allowance_type' => (string)$row['allowance_type'],
                'addition_amount' => round((float)$row['addition_amount'], 2),
                'allowance_amount' => round((float)$row['allowance_amount'], 2),
                'disposal_value' => round((float)$row['disposal_value'], 2),
                'warning' => trim((string)($row['warning'] ?? '')) !== '' ? trim((string)$row['warning']) : null,
            ];
        if ($hasCtPeriodColumn) {
            $params['ct_period_id'] = isset($row['ct_period_id']) ? (int)$row['ct_period_id'] : null;
        }

        $columns = $hasCtPeriodColumn
            ? 'company_id, accounting_period_id, ct_period_id, asset_id, pool_type, allowance_type,
                addition_amount, allowance_amount, disposal_value, warning, created_at'
            : 'company_id, accounting_period_id, asset_id, pool_type, allowance_type,
                addition_amount, allowance_amount, disposal_value, warning, created_at';
        $values = $hasCtPeriodColumn
            ? ':company_id, :accounting_period_id, :ct_period_id, :asset_id, :pool_type, :allowance_type,
                :addition_amount, :allowance_amount, :disposal_value, :warning, CURRENT_TIMESTAMP'
            : ':company_id, :accounting_period_id, :asset_id, :pool_type, :allowance_type,
                :addition_amount, :allowance_amount, :disposal_value, :warning, CURRENT_TIMESTAMP';

        \InterfaceDB::prepareExecute(
            'INSERT INTO capital_allowance_asset_calculations (' . $columns . ') VALUES (' . $values . ')',
            $params
        );
    }

    private function deleteExistingRowsForAccountingPeriod(int $companyId, int $accountingPeriodId): void
    {
        \InterfaceDB::prepareExecute(
            'DELETE FROM capital_allowance_asset_calculations
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        );
        \InterfaceDB::prepareExecute(
            'DELETE FROM capital_allowance_pool_runs
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        );
    }

    private function lockedPeriodIdsForRebuild(int $companyId, array $periods): array
    {
        $lockedPeriodIds = [];
        $lockService = $this->yearEndLockService();
        foreach ($periods as $period) {
            $periodId = (int)($period['id'] ?? 0);
            if ($periodId <= 0) {
                continue;
            }
            if ($lockService->isLocked($companyId, $periodId)
                || $this->periodHasFinalCtStatus($companyId, $periodId)) {
                $lockedPeriodIds[] = $periodId;
                continue;
            }

            $lockService->assertUnlockedForUpdate(
                $companyId,
                $periodId,
                'rebuild capital allowance evidence for this period'
            );
        }

        return array_values(array_unique($lockedPeriodIds));
    }

    private function periodHasFinalCtStatus(int $companyId, int $accountingPeriodId): bool
    {
        if (!\InterfaceDB::tableExists('corporation_tax_periods')) {
            return false;
        }

        return (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM corporation_tax_periods
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND status IN (\'submitted\', \'accepted\')',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        ) > 0;
    }

    /** @return list<int> */
    private function immutablePeriodIdsForCompany(int $companyId, array $periods): array
    {
        $candidateIds = [];
        foreach ($periods as $period) {
            $periodId = (int)($period['id'] ?? 0);
            if ($periodId <= 0) {
                continue;
            }
            $candidateIds[$periodId] = true;
        }

        if ($candidateIds === []) {
            return [];
        }

        $immutableIds = [];
        if (\InterfaceDB::tableExists('year_end_reviews')) {
            foreach (\InterfaceDB::fetchAll(
                'SELECT accounting_period_id
                 FROM year_end_reviews
                 WHERE company_id = :company_id
                   AND is_locked = 1',
                ['company_id' => $companyId]
            ) ?: [] as $row) {
                $periodId = (int)($row['accounting_period_id'] ?? 0);
                if (isset($candidateIds[$periodId])) {
                    $immutableIds[$periodId] = true;
                }
            }
        }

        if (\InterfaceDB::tableExists('corporation_tax_periods')) {
            foreach (\InterfaceDB::fetchAll(
                'SELECT DISTINCT accounting_period_id
                 FROM corporation_tax_periods
                 WHERE company_id = :company_id
                   AND status IN (\'submitted\', \'accepted\')',
                ['company_id' => $companyId]
            ) ?: [] as $row) {
                $periodId = (int)($row['accounting_period_id'] ?? 0);
                if (isset($candidateIds[$periodId])) {
                    $immutableIds[$periodId] = true;
                }
            }
        }

        return array_values(array_map(
            'intval',
            array_filter(
                array_keys($candidateIds),
                static fn(int $periodId): bool => isset($immutableIds[$periodId])
            )
        ));
    }

    /** @return array{owns_transaction: bool, savepoint: string} */
    private function beginMutationTransaction(string $prefix): array
    {
        if (!\InterfaceDB::inTransaction()) {
            \InterfaceDB::beginTransaction();

            return ['owns_transaction' => true, 'savepoint' => ''];
        }

        $savepoint = preg_replace('/[^a-z0-9_]/i', '_', $prefix)
            . '_' . bin2hex(random_bytes(6));
        \InterfaceDB::execute('SAVEPOINT ' . $savepoint);

        return ['owns_transaction' => false, 'savepoint' => $savepoint];
    }

    /** @param array{owns_transaction: bool, savepoint: string} $transaction */
    private function commitMutationTransaction(array $transaction): void
    {
        if (!empty($transaction['owns_transaction'])) {
            \InterfaceDB::commit();
            return;
        }

        $savepoint = trim((string)($transaction['savepoint'] ?? ''));
        if ($savepoint !== '') {
            \InterfaceDB::execute('RELEASE SAVEPOINT ' . $savepoint);
        }
    }

    /** @param array{owns_transaction: bool, savepoint: string} $transaction */
    private function rollBackMutationTransaction(array $transaction): void
    {
        if (!empty($transaction['owns_transaction'])) {
            if (\InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }
            return;
        }

        $savepoint = trim((string)($transaction['savepoint'] ?? ''));
        if ($savepoint !== '' && \InterfaceDB::inTransaction()) {
            \InterfaceDB::execute('ROLLBACK TO SAVEPOINT ' . $savepoint);
            \InterfaceDB::execute('RELEASE SAVEPOINT ' . $savepoint);
        }
    }

    /** @return array<string, float|string> */
    private function normalisePoolBreakdownRow(array $row): array
    {
        return [
            'pool_type' => (string)($row['pool_type'] ?? ''),
            'opening_wdv' => round((float)($row['opening_wdv'] ?? 0), 2),
            'additions' => round((float)($row['additions'] ?? 0), 2),
            'aia_claimed' => round((float)($row['aia_claimed'] ?? 0), 2),
            'fya_claimed' => round((float)($row['fya_claimed'] ?? 0), 2),
            'disposal_value' => round((float)($row['disposal_value'] ?? 0), 2),
            'wda_claimed' => round((float)($row['wda_claimed'] ?? 0), 2),
            'balancing_charge' => round((float)($row['balancing_charge'] ?? 0), 2),
            'balancing_allowance' => round((float)($row['balancing_allowance'] ?? 0), 2),
            'closing_wdv' => round((float)($row['closing_wdv'] ?? 0), 2),
        ];
    }

    /** @return list<array<string, float|int|string>> */
    private function fetchPersistedAssetCalculationRows(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId
    ): array {
        if (!\InterfaceDB::tableExists('capital_allowance_asset_calculations')) {
            return [];
        }

        $sql = 'SELECT *
                FROM capital_allowance_asset_calculations
                WHERE company_id = :company_id
                  AND accounting_period_id = :accounting_period_id';
        $params = [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
        ];
        if ($ctPeriodId > 0 && \InterfaceDB::columnExists('capital_allowance_asset_calculations', 'ct_period_id')) {
            $sql .= ' AND ct_period_id = :ct_period_id';
            $params['ct_period_id'] = $ctPeriodId;
        }
        $sql .= ' ORDER BY asset_id ASC, id ASC';

        return array_map(
            fn(array $row): array => $this->normaliseAssetCalculationRow($row),
            \InterfaceDB::fetchAll($sql, $params) ?: []
        );
    }

    /** @return array<string, float|int|string> */
    private function normaliseAssetCalculationRow(array $row): array
    {
        return [
            'asset_id' => (int)($row['asset_id'] ?? 0),
            'pool_type' => (string)($row['pool_type'] ?? ''),
            'allowance_type' => (string)($row['allowance_type'] ?? ''),
            'addition_amount' => round((float)($row['addition_amount'] ?? 0), 2),
            'allowance_amount' => round((float)($row['allowance_amount'] ?? 0), 2),
            'disposal_value' => round((float)($row['disposal_value'] ?? 0), 2),
            'warning' => trim((string)($row['warning'] ?? '')),
        ];
    }

    private function serviceRunHash(array $row): string
    {
        $digest = hash('sha256', json_encode([
            'algorithm_version' => self::RUN_HASH_ALGORITHM_VERSION,
            'payload' => $this->canonicalRunHashPayload(
                $row,
                \InterfaceDB::columnExists('capital_allowance_pool_runs', 'ct_period_id')
            ),
        ], JSON_UNESCAPED_SLASHES));

        return self::RUN_HASH_VERSION_PREFIX
            . substr($digest, 0, 64 - strlen(self::RUN_HASH_VERSION_PREFIX));
    }

    private function canonicalRunHashPayload(array $row, bool $includeCtPeriodId): array
    {
        $payload = [
            'company_id' => (int)($row['company_id'] ?? 0),
            'accounting_period_id' => (int)($row['accounting_period_id'] ?? 0),
            'pool_type' => (string)($row['pool_type'] ?? ''),
            'opening_wdv' => round((float)($row['opening_wdv'] ?? 0), 2),
            'additions' => round((float)($row['additions'] ?? 0), 2),
            'aia_claimed' => round((float)($row['aia_claimed'] ?? 0), 2),
            'fya_claimed' => round((float)($row['fya_claimed'] ?? 0), 2),
            'disposal_value' => round((float)($row['disposal_value'] ?? 0), 2),
            'wda_claimed' => round((float)($row['wda_claimed'] ?? 0), 2),
            'balancing_charge' => round((float)($row['balancing_charge'] ?? 0), 2),
            'balancing_allowance' => round((float)($row['balancing_allowance'] ?? 0), 2),
            'closing_wdv' => round((float)($row['closing_wdv'] ?? 0), 2),
            'warnings_json' => (string)($row['warnings_json'] ?? '[]'),
        ];
        if ($includeCtPeriodId) {
            $ctPeriodId = $row['ct_period_id'] ?? null;
            $payload['ct_period_id'] = $ctPeriodId === null ? null : (int)$ctPeriodId;
        }

        return $payload;
    }

    private function periodDays(string $start, string $end): int
    {
        if (!$this->isIsoDate($start) || !$this->isIsoDate($end)) {
            return 365;
        }

        return max(1, (new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($end))->days + 1);
    }

    private function aiaLimitForPeriod(string $periodStart, string $periodEnd, int $periodDays): float
    {
        $annualLimit = $this->taxRateRules()->weightedAmountForPeriod(
            'capital_allowances',
            'plant_machinery',
            'aia_annual_limit',
            $periodStart,
            $periodEnd
        );

        return round($annualLimit * min(1.0, max(1, $periodDays) / 365), 2);
    }

    private function mainRateForPeriod(string $periodStart, string $periodEnd): float
    {
        return $this->taxRateRules()->weightedRateForPeriod(
            'capital_allowances',
            'plant_machinery',
            'main_pool_wda',
            $periodStart,
            $periodEnd
        );
    }

    private function specialRateForPeriod(string $periodStart, string $periodEnd): float
    {
        return $this->taxRateRules()->weightedRateForPeriod(
            'capital_allowances',
            'plant_machinery',
            'special_rate_pool_wda',
            $periodStart,
            $periodEnd
        );
    }

    private function taxRateRules(): \eel_accounts\Service\TaxRateRuleService
    {
        if ($this->resolvedTaxRateRuleService === null) {
            $this->resolvedTaxRateRuleService = $this->taxRateRuleService
                ?? new \eel_accounts\Service\TaxRateRuleService();
        }

        return $this->resolvedTaxRateRuleService;
    }

    private function corporationTaxPeriodService(): \eel_accounts\Service\CorporationTaxPeriodService
    {
        if ($this->resolvedCorporationTaxPeriodService === null) {
            $this->resolvedCorporationTaxPeriodService = $this->corporationTaxPeriodService
                ?? new \eel_accounts\Service\CorporationTaxPeriodService();
        }

        return $this->resolvedCorporationTaxPeriodService;
    }

    private function yearEndLockService(): \eel_accounts\Service\YearEndLockService
    {
        if ($this->resolvedYearEndLockService === null) {
            $this->resolvedYearEndLockService = new \eel_accounts\Service\YearEndLockService();
        }

        return $this->resolvedYearEndLockService;
    }

    private function isIsoDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();

        return $parsed instanceof \DateTimeImmutable
            && (!is_array($errors) || ((int)$errors['warning_count'] === 0 && (int)$errors['error_count'] === 0));
    }
}
