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
    public function __construct(private readonly ?\eel_accounts\Service\TaxRateRuleService $taxRateRuleService = null)
    {
    }

    public function hasRequiredSchema(): bool
    {
        return \InterfaceDB::tableExists('asset_register')
            && \InterfaceDB::tableExists('asset_vehicle_details')
            && \InterfaceDB::tableExists('capital_allowance_pool_runs')
            && \InterfaceDB::tableExists('capital_allowance_asset_calculations');
    }

    public function rebuildForCompany(int $companyId): array
    {
        if ($companyId <= 0 || !$this->hasRequiredSchema()) {
            return [];
        }

        $periods = $this->fetchAccountingPeriods($companyId);
        if ($periods === []) {
            return [];
        }

        $this->deleteExistingRows($companyId);

        $mainWdv = 0.0;
        $specialWdv = 0.0;
        $assetPools = [];
        $results = [];

        $ctPeriodService = new \eel_accounts\Service\CorporationTaxPeriodService();

        foreach ($periods as $period) {
            $periodId = (int)$period['id'];
            $ctPeriods = $this->ctPeriodsForAccountingPeriod($ctPeriodService, $companyId, $period);

            foreach ($ctPeriods as $ctPeriod) {
                $ctPeriodId = (int)($ctPeriod['id'] ?? 0);
                $periodStart = (string)$ctPeriod['period_start'];
                $periodEnd = (string)$ctPeriod['period_end'];
                $periodDays = $this->periodDays($periodStart, $periodEnd);
            $aiaRemaining = $this->aiaLimitForPeriod($periodStart, $periodEnd, $periodDays);
            $periodRows = [];
            $warnings = [];

            $main = $this->emptyPool('main_pool', $mainWdv);
            $special = $this->emptyPool('special_rate_pool', $specialWdv);

            foreach ($this->fetchAssetAdditions($companyId, $periodStart, $periodEnd) as $asset) {
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

                if ($treatment['allowance_type'] === 'aia') {
                    $aia = round(min($cost, $aiaRemaining), 2);
                    $aiaRemaining = round($aiaRemaining - $aia, 2);
                    $remaining = round($cost - $aia, 2);
                    $main['additions'] += $remaining;
                    $main['aia_claimed'] += $aia;
                    $mainWdv += $remaining;
                    $assetPools[$assetId] = 'main_pool';
                    $periodRows[] = $this->assetRow($companyId, $periodId, $ctPeriodId, $assetId, 'main_pool', 'aia', $cost, $aia);
                    if ($remaining > 0) {
                        $periodRows[] = $this->assetRow($companyId, $periodId, $ctPeriodId, $assetId, 'main_pool', 'main_pool_addition', $remaining);
                    }
                    continue;
                }

                if ($treatment['allowance_type'] === 'fya') {
                    $main['fya_claimed'] += $cost;
                    $assetPools[$assetId] = 'fya';
                    $periodRows[] = $this->assetRow($companyId, $periodId, $ctPeriodId, $assetId, 'main_pool', 'fya', $cost, $cost);
                    continue;
                }

                if ($treatment['pool'] === 'special_rate_pool') {
                    $special['additions'] += $cost;
                    $specialWdv += $cost;
                    $assetPools[$assetId] = 'special_rate_pool';
                    $periodRows[] = $this->assetRow($companyId, $periodId, $ctPeriodId, $assetId, 'special_rate_pool', 'special_rate_pool_addition', $cost);
                    continue;
                }

                $main['additions'] += $cost;
                $mainWdv += $cost;
                $assetPools[$assetId] = 'main_pool';
                $periodRows[] = $this->assetRow($companyId, $periodId, $ctPeriodId, $assetId, 'main_pool', 'main_pool_addition', $cost);
            }

            foreach ($this->fetchDisposals($companyId, $periodStart, $periodEnd) as $asset) {
                $assetId = (int)$asset['id'];
                $pool = (string)($assetPools[$assetId] ?? $this->poolForExistingAsset($asset));
                $proceeds = round(max(0.0, (float)($asset['disposal_proceeds'] ?? 0)), 2);
                if ($pool === 'special_rate_pool') {
                    $special['disposal_value'] += $proceeds;
                    $specialWdv = round($specialWdv - $proceeds, 2);
                    $periodRows[] = $this->assetRow($companyId, $periodId, $ctPeriodId, $assetId, 'special_rate_pool', 'disposal_value', 0.0, 0.0, $proceeds);
                } elseif ($pool === 'main_pool') {
                    $main['disposal_value'] += $proceeds;
                    $mainWdv = round($mainWdv - $proceeds, 2);
                    $periodRows[] = $this->assetRow($companyId, $periodId, $ctPeriodId, $assetId, 'main_pool', 'disposal_value', 0.0, 0.0, $proceeds);
                }
            }

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
            $main['closing_wdv'] = $mainWdv;
            $special['closing_wdv'] = $specialWdv;
            $main['warnings'] = $warnings;
            $special['warnings'] = [];

            $this->insertPoolRun($companyId, $periodId, $ctPeriodId, $main);
            $this->insertPoolRun($companyId, $periodId, $ctPeriodId, $special);
            foreach ($periodRows as $row) {
                $this->insertAssetCalculation($row);
            }

            $allowance = round($main['aia_claimed'] + $main['fya_claimed'] + $main['wda_claimed'] + $special['wda_claimed'] + $main['balancing_allowance'] + $special['balancing_allowance'], 2);
            $charge = round($main['balancing_charge'] + $special['balancing_charge'], 2);
            $result = [
                'allowance' => $allowance,
                'charge' => $charge,
                'net_capital_allowances' => round($allowance - $charge, 2),
                'warnings' => array_values(array_unique($warnings)),
                'pools' => [$main, $special],
            ];
            $result['ct_period_id'] = $ctPeriodId;
            $result['period_start'] = $periodStart;
            $result['period_end'] = $periodEnd;

            $results['ct_periods'][$ctPeriodId] = $result;
            if (!isset($results[$periodId])) {
                $results[$periodId] = ['allowance' => 0.0, 'charge' => 0.0, 'net_capital_allowances' => 0.0, 'warnings' => [], 'pools' => []];
            }
            $results[$periodId]['allowance'] = round((float)$results[$periodId]['allowance'] + $allowance, 2);
            $results[$periodId]['charge'] = round((float)$results[$periodId]['charge'] + $charge, 2);
            $results[$periodId]['net_capital_allowances'] = round((float)$results[$periodId]['allowance'] - (float)$results[$periodId]['charge'], 2);
            $results[$periodId]['warnings'] = array_values(array_unique(array_merge((array)$results[$periodId]['warnings'], $warnings)));
            $results[$periodId]['pools'] = array_merge((array)$results[$periodId]['pools'], [$main, $special]);
            }
        }

        return $results;
    }

    public function fetchPeriodBreakdown(int $companyId, int $accountingPeriodId, int $ctPeriodId = 0): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || !\InterfaceDB::tableExists('capital_allowance_pool_runs')) {
            return ['available' => false, 'rows' => [], 'warnings' => []];
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
        $warnings = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string)($row['warnings_json'] ?? '[]'), true);
            foreach (is_array($decoded) ? $decoded : [] as $warning) {
                $warnings[] = (string)$warning;
            }
        }

        return [
            'available' => $rows !== [],
            'rows' => array_map(static function (array $row): array {
                return [
                    'pool_type' => (string)$row['pool_type'],
                    'opening_wdv' => round((float)$row['opening_wdv'], 2),
                    'additions' => round((float)$row['additions'], 2),
                    'aia_claimed' => round((float)$row['aia_claimed'], 2),
                    'fya_claimed' => round((float)$row['fya_claimed'], 2),
                    'disposal_value' => round((float)$row['disposal_value'], 2),
                    'wda_claimed' => round((float)$row['wda_claimed'], 2),
                    'balancing_charge' => round((float)$row['balancing_charge'], 2),
                    'balancing_allowance' => round((float)$row['balancing_allowance'], 2),
                    'closing_wdv' => round((float)$row['closing_wdv'], 2),
                ];
            }, $rows),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    public function periodWarnings(int $companyId, int $accountingPeriodId, int $ctPeriodId = 0): array
    {
        return (array)$this->fetchPeriodBreakdown($companyId, $accountingPeriodId, $ctPeriodId)['warnings'];
    }

    private function ctPeriodsForAccountingPeriod(\eel_accounts\Service\CorporationTaxPeriodService $service, int $companyId, array $period): array
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
        $sync = $periodId > 0 ? $service->syncForAccountingPeriod($companyId, $periodId) : ['periods' => []];
        $rows = array_values(array_filter((array)($sync['periods'] ?? []), static fn(array $row): bool => (string)($row['status'] ?? '') !== 'superseded'));
        if ($rows !== []) {
            return $rows;
        }

        return [[
            'id' => 0,
            'accounting_period_id' => $periodId,
            'sequence_no' => 1,
            'period_start' => (string)($period['period_start'] ?? ''),
            'period_end' => (string)($period['period_end'] ?? ''),
            'status' => 'pending',
        ]];
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
            'ct_period_id' => $ctPeriodId > 0 ? $ctPeriodId : null,
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
        $payload['run_hash'] = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES));

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

    private function deleteExistingRows(int $companyId): void
    {
        \InterfaceDB::prepareExecute('DELETE FROM capital_allowance_asset_calculations WHERE company_id = :company_id', ['company_id' => $companyId]);
        \InterfaceDB::prepareExecute('DELETE FROM capital_allowance_pool_runs WHERE company_id = :company_id', ['company_id' => $companyId]);
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
        return $this->taxRateRuleService ?? new \eel_accounts\Service\TaxRateRuleService();
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
