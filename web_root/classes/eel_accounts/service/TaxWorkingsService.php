<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class TaxWorkingsService
{
    public function fetchWorkings(int $companyId, int $accountingPeriodId, int $ctPeriodId = 0): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return ['available' => false, 'errors' => ['Select a company and accounting period to inspect tax workings.']];
        }

        $metrics = new \eel_accounts\Service\YearEndMetricsService();
        $period = $metrics->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($period === null) {
            return ['available' => false, 'errors' => ['The selected accounting period could not be found.']];
        }

        $ctPeriod = null;
        if ($ctPeriodId > 0) {
            $ctPeriod = (new \eel_accounts\Service\CorporationTaxPeriodService())->fetch($companyId, $ctPeriodId);
            if ($ctPeriod === null || (int)($ctPeriod['accounting_period_id'] ?? 0) !== $accountingPeriodId) {
                return ['available' => false, 'errors' => ['The selected CT period does not belong to this accounting period.']];
            }
        }

        $estimate = $ctPeriodId > 0
            ? (new \eel_accounts\Service\CorporationTaxComputationService())->fetchSummaryForCtPeriodId($companyId, $ctPeriodId)
            : (new \eel_accounts\Service\YearEndTaxReadinessService($metrics))->fetchCurrentPeriodEstimate($companyId, $accountingPeriodId);
        if (empty($estimate['available'])) {
            return [
                'available' => false,
                'errors' => (array)($estimate['errors'] ?? ['Tax workings are not available for this period.']),
            ];
        }

        $periodStart = $ctPeriod !== null ? (string)$ctPeriod['period_start'] : (string)$period['period_start'];
        $periodEnd = $ctPeriod !== null ? (string)$ctPeriod['period_end'] : (string)$period['period_end'];
        $poolRows = $this->poolRows($companyId, $accountingPeriodId, (array)($estimate['capital_allowance_breakdown'] ?? []), $ctPeriodId);
        $assetCalculations = $this->assetCalculationRows($companyId, $accountingPeriodId, $ctPeriodId);
        $carRows = $this->carRows($companyId, $accountingPeriodId, $ctPeriodId);
        $warnings = $this->warningRows($estimate, $poolRows, $assetCalculations, $carRows, $companyId, $accountingPeriodId);

        return [
            'available' => true,
            'guidance' => \eel_accounts\Service\TaxGuidanceService::all(),
            'period' => $period,
            'selected_ct_period' => $ctPeriod,
            'summary' => $estimate,
            'bridge' => (array)($estimate['steps'] ?? []),
            'disallowable_add_backs' => $this->disallowableAddBackRows($companyId, $accountingPeriodId, $periodStart, $periodEnd),
            'depreciation_add_back' => $this->depreciationRows($companyId, $accountingPeriodId, $ctPeriodId),
            'capital_allowances_summary' => $this->capitalAllowanceSummary($poolRows),
            'aia_allocation' => array_values(array_filter($assetCalculations, static fn(array $row): bool => (string)$row['allowance_type'] === 'aia')),
            'main_rate_pool' => $this->poolByType($poolRows, 'main_pool'),
            'special_rate_pool' => $this->poolByType($poolRows, 'special_rate_pool'),
            'car_co2_treatment' => $carRows,
            'disposals_balancing' => $this->disposalRows($assetCalculations, $companyId, $accountingPeriodId, $periodStart, $periodEnd),
            'losses' => (array)($estimate['schedule'] ?? []),
            'rate_bands' => (array)($estimate['ct_rate_bands'] ?? []),
            'provision' => $ctPeriodId > 0 ? (new \eel_accounts\Service\CorporationTaxProvisionService())->fetchPosition($companyId, $accountingPeriodId, $ctPeriodId) : [],
            'warnings' => $warnings,
        ];
    }

    private function disallowableAddBackRows(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): array
    {
        if (!$this->tableExists('journals') || !$this->tableExists('journal_lines') || !$this->tableExists('nominal_accounts')) {
            return [];
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT na.id,
                    na.code,
                    na.name,
                    na.account_type,
                    COALESCE(na.tax_treatment, \'allowable\') AS tax_treatment,
                    SUM(COALESCE(jl.debit, 0)) AS total_debit,
                    SUM(COALESCE(jl.credit, 0)) AS total_credit
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             INNER JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             LEFT JOIN journal_entry_metadata jem_close
               ON jem_close.journal_id = j.id
              AND jem_close.journal_tag = :close_journal_tag
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
               AND j.is_posted = 1
               AND j.journal_date BETWEEN :period_start AND :period_end
               AND jem_close.id IS NULL
               AND (na.account_type = :cost_type OR na.account_type = :expense_type)
             GROUP BY na.id, na.code, na.name, na.account_type, na.tax_treatment
             ORDER BY na.code ASC, na.name ASC',
            [
                'close_journal_tag' => \eel_accounts\Service\RetainedEarningsCloseService::JOURNAL_TAG,
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'cost_type' => 'cost_of_sales',
                'expense_type' => 'expense',
            ]
        ) ?: [];

        $rules = new \eel_accounts\Service\CorporationTaxTreatmentRuleService();
        $result = [];
        foreach ($rows as $row) {
            $treatment = (string)($rules->resolveTaxTreatment($row, $periodStart, $periodEnd)['tax_treatment'] ?? '');
            if (!in_array($treatment, ['disallowable', 'other'], true) && in_array($treatment, ['allowable', 'capital'], true)) {
                continue;
            }
            $amount = round((float)($row['total_debit'] ?? 0) - (float)($row['total_credit'] ?? 0), 2);
            if ($amount == 0.0 && $treatment !== 'other') {
                continue;
            }
            $result[] = [
                'nominal_code' => (string)$row['code'],
                'nominal_name' => (string)$row['name'],
                'tax_treatment' => $treatment !== '' ? $treatment : 'unknown',
                'amount' => abs($amount),
            ];
        }

        return $result;
    }

    private function depreciationRows(int $companyId, int $accountingPeriodId, int $ctPeriodId = 0): array
    {
        if (!$this->tableExists('asset_depreciation_entries')) {
            return [];
        }

        $periodFilter = '';
        $params = [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
        ];
        if ($ctPeriodId > 0) {
            $ctPeriod = (new \eel_accounts\Service\CorporationTaxPeriodService())->fetch($companyId, $ctPeriodId);
            if ($ctPeriod !== null) {
                $periodFilter = ' AND ade.period_start <= :period_end AND ade.period_end >= :period_start';
                $params['period_start'] = (string)$ctPeriod['period_start'];
                $params['period_end'] = (string)$ctPeriod['period_end'];
            }
        }

        $sql = 'SELECT ade.amount,
                    ade.period_start,
                    ade.period_end,
                    \'add\' AS direction,
                    ar.asset_code,
                    ar.description
             FROM asset_depreciation_entries ade
             INNER JOIN asset_register ar ON ar.id = ade.asset_id
             WHERE ar.company_id = :company_id
               AND ade.accounting_period_id = :accounting_period_id'
            . $periodFilter
            . ' ORDER BY ar.purchase_date ASC, ar.id ASC, ade.id ASC';

        $rows = \InterfaceDB::fetchAll($sql, $params) ?: [];
        if ($ctPeriodId <= 0 || !isset($params['period_start'], $params['period_end'])) {
            return $rows;
        }

        foreach ($rows as &$row) {
            $entryStart = (string)($row['period_start'] ?? '');
            $entryEnd = (string)($row['period_end'] ?? '');
            $entryDays = $this->periodDays($entryStart, $entryEnd);
            $overlapDays = $this->overlapDays($entryStart, $entryEnd, (string)$params['period_start'], (string)$params['period_end']);
            $row['amount'] = $entryDays > 0 && $overlapDays > 0
                ? round((float)($row['amount'] ?? 0) * ($overlapDays / $entryDays), 2)
                : 0.0;
        }
        unset($row);

        return $rows;
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
        return $this->periodDays(max($firstStart, $secondStart), min($firstEnd, $secondEnd));
    }

    private function poolRows(int $companyId, int $accountingPeriodId, array $breakdown, int $ctPeriodId = 0): array
    {
        $rows = (array)($breakdown['rows'] ?? []);
        if ($rows !== []) {
            return $rows;
        }

        return (array)(new \eel_accounts\Service\CapitalAllowanceService())->fetchPeriodBreakdown($companyId, $accountingPeriodId, $ctPeriodId)['rows'];
    }

    private function assetCalculationRows(int $companyId, int $accountingPeriodId, int $ctPeriodId = 0): array
    {
        if (!$this->tableExists('capital_allowance_asset_calculations')) {
            return [];
        }

        $sql = 'SELECT cac.*,
                    ar.asset_code,
                    ar.description,
                    ar.purchase_date,
                    ar.cost,
                    ar.category,
                    ar.disposal_date,
                    ar.disposal_proceeds,
                    na.code AS nominal_code,
                    na.name AS nominal_name,
                    vd.vehicle_type,
                    vd.registration_mark,
                    vd.co2_emissions_g_km,
                    vd.acquisition_condition,
                    vd.is_zero_emission
             FROM capital_allowance_asset_calculations cac
             INNER JOIN asset_register ar ON ar.id = cac.asset_id
             LEFT JOIN nominal_accounts na ON na.id = ar.nominal_account_id
             LEFT JOIN asset_vehicle_details vd ON vd.asset_id = ar.id
             WHERE cac.company_id = :company_id
               AND cac.accounting_period_id = :accounting_period_id';
        $params = ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId];
        if ($ctPeriodId > 0 && \InterfaceDB::columnExists('capital_allowance_asset_calculations', 'ct_period_id')) {
            $sql .= ' AND cac.ct_period_id = :ct_period_id';
            $params['ct_period_id'] = $ctPeriodId;
        }
        $sql .= ' ORDER BY ar.purchase_date ASC, ar.id ASC, cac.id ASC';

        return \InterfaceDB::fetchAll($sql, $params) ?: [];
    }

    private function carRows(int $companyId, int $accountingPeriodId, int $ctPeriodId = 0): array
    {
        if (!$this->tableExists('asset_register') || !$this->tableExists('nominal_accounts')) {
            return [];
        }

        $calcFilter = 'WHERE company_id = :company_id_calc
                  AND accounting_period_id = :accounting_period_id_calc';
        $params = [
                'company_id_calc' => $companyId,
                'accounting_period_id_calc' => $accountingPeriodId,
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ];
        if ($ctPeriodId > 0 && \InterfaceDB::columnExists('capital_allowance_asset_calculations', 'ct_period_id')) {
            $calcFilter .= ' AND ct_period_id = :ct_period_id_calc';
            $params['ct_period_id_calc'] = $ctPeriodId;
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT ar.id,
                    ar.asset_code,
                    ar.description,
                    ar.purchase_date,
                    ar.cost,
                    ar.category,
                    na.code AS nominal_code,
                    vd.vehicle_type,
                    vd.registration_mark,
                    vd.make_model,
                    vd.acquisition_condition,
                    vd.is_zero_emission,
                    vd.co2_emissions_g_km,
                    vd.first_registered_date,
                    calc.pool_type,
                    calc.allowance_type,
                    calc.allowance_amount,
                    calc.warning
             FROM asset_register ar
             INNER JOIN nominal_accounts na ON na.id = ar.nominal_account_id
             LEFT JOIN asset_vehicle_details vd ON vd.asset_id = ar.id
             LEFT JOIN (
                SELECT asset_id,
                       MAX(pool_type) AS pool_type,
                       GROUP_CONCAT(DISTINCT allowance_type ORDER BY allowance_type SEPARATOR \', \') AS allowance_type,
                       SUM(allowance_amount) AS allowance_amount,
                       GROUP_CONCAT(DISTINCT warning SEPARATOR \' \') AS warning
                FROM capital_allowance_asset_calculations
                ' . $calcFilter . '
                GROUP BY asset_id
             ) calc ON calc.asset_id = ar.id
             WHERE ar.company_id = :company_id
               AND ar.accounting_period_id = :accounting_period_id
               AND (na.code = \'1321\' OR ar.category = \'car\' OR vd.vehicle_type = \'car\')
             ORDER BY ar.purchase_date ASC, ar.id ASC',
            $params
        ) ?: [];

        foreach ($rows as $index => $row) {
            $warnings = [];
            if (trim((string)($row['acquisition_condition'] ?? '')) === '') {
                $warnings[] = 'Missing new/second-hand status';
            }
            if (($row['co2_emissions_g_km'] ?? null) === null && !$this->registeredBeforeMarch2001((string)($row['first_registered_date'] ?? ''))) {
                $warnings[] = 'Missing CO2 emissions';
            }
            if (trim((string)($row['warning'] ?? '')) !== '') {
                $warnings[] = trim((string)$row['warning']);
            }
            $rows[$index]['warnings'] = array_values(array_unique($warnings));
        }

        return $rows;
    }

    private function disposalRows(array $assetCalculations, int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): array
    {
        $rows = array_values(array_filter($assetCalculations, static fn(array $row): bool => (float)($row['disposal_value'] ?? 0) > 0 || (float)($row['disposal_proceeds'] ?? 0) > 0));
        if ($rows !== []) {
            return $rows;
        }

        if (!$this->tableExists('asset_register')) {
            return [];
        }

        return \InterfaceDB::fetchAll(
            'SELECT ar.asset_code,
                    ar.description,
                    ar.disposal_date,
                    ar.disposal_proceeds,
                    na.code AS nominal_code,
                    na.name AS nominal_name
             FROM asset_register ar
             LEFT JOIN nominal_accounts na ON na.id = ar.nominal_account_id
             WHERE ar.company_id = :company_id
               AND ar.accounting_period_id = :accounting_period_id
               AND ar.disposal_date IS NOT NULL
               AND ar.disposal_date BETWEEN :period_start AND :period_end
             ORDER BY ar.disposal_date ASC, ar.id ASC',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId, 'period_start' => $periodStart, 'period_end' => $periodEnd]
        ) ?: [];
    }

    private function capitalAllowanceSummary(array $poolRows): array
    {
        $summary = [
            'additions' => 0.0,
            'aia_claimed' => 0.0,
            'fya_claimed' => 0.0,
            'wda_claimed' => 0.0,
            'disposal_value' => 0.0,
            'balancing_charge' => 0.0,
            'balancing_allowance' => 0.0,
            'closing_wdv' => 0.0,
            'net_capital_allowances' => 0.0,
        ];
        foreach ($poolRows as $row) {
            foreach (array_keys($summary) as $key) {
                if ($key === 'net_capital_allowances') {
                    continue;
                }
                $summary[$key] = round($summary[$key] + (float)($row[$key] ?? 0), 2);
            }
        }
        $summary['net_capital_allowances'] = round(
            $summary['aia_claimed'] + $summary['fya_claimed'] + $summary['wda_claimed'] + $summary['balancing_allowance'] - $summary['balancing_charge'],
            2
        );

        return $summary;
    }

    private function poolByType(array $poolRows, string $poolType): array
    {
        foreach ($poolRows as $row) {
            if ((string)($row['pool_type'] ?? '') === $poolType) {
                return $row;
            }
        }

        return [];
    }

    private function warningRows(array $estimate, array $poolRows, array $assetCalculations, array $carRows, int $companyId, int $accountingPeriodId): array
    {
        $warnings = [];
        foreach ((array)($estimate['warnings'] ?? []) as $warning) {
            $warnings[] = (string)$warning;
        }
        foreach ($poolRows as $row) {
            foreach ((array)($row['warnings'] ?? []) as $warning) {
                $warnings[] = (string)$warning;
            }
        }
        foreach ($assetCalculations as $row) {
            if (trim((string)($row['warning'] ?? '')) !== '') {
                $warnings[] = trim((string)$row['warning']);
            }
        }
        foreach ($carRows as $row) {
            foreach ((array)($row['warnings'] ?? []) as $warning) {
                $warnings[] = (string)$warning;
            }
        }
        foreach ((new \eel_accounts\Service\VehicleService())->periodReviewWarnings($companyId, $accountingPeriodId) as $warning) {
            $warnings[] = (string)$warning;
        }

        $rows = [];
        foreach (array_values(array_unique(array_filter($warnings, static fn(string $warning): bool => trim($warning) !== ''))) as $warning) {
            $rows[] = [
                'message' => $warning,
                'workflow_label' => $this->workflowLabelForWarning($warning),
                'workflow_url' => $this->workflowUrlForWarning($warning),
            ];
        }

        return $rows;
    }

    private function workflowLabelForWarning(string $warning): string
    {
        $lower = strtolower($warning);
        if (str_contains($lower, 'vehicle') || str_contains($lower, 'car') || str_contains($lower, 'co2')) {
            return 'Open Vehicles';
        }
        if (str_contains($lower, 'nominal') || str_contains($lower, 'tax treatment') || str_contains($lower, 'rate')) {
            return 'Open Tax Rates';
        }
        if (str_contains($lower, 'upload') || str_contains($lower, 'csv')) {
            return 'Open Uploads';
        }
        if (str_contains($lower, 'transaction') || str_contains($lower, 'categor')) {
            return 'Open Transactions';
        }
        if (str_contains($lower, 'asset') || str_contains($lower, 'allowance')) {
            return 'Open Assets';
        }

        return 'Open Year End';
    }

    private function workflowUrlForWarning(string $warning): string
    {
        return match ($this->workflowLabelForWarning($warning)) {
            'Open Vehicles' => '?page=vehicles',
            'Open Tax Rates' => '?page=tax_rates',
            'Open Uploads' => '?page=uploads',
            'Open Transactions' => '?page=transactions',
            'Open Assets' => '?page=assets',
            default => '?page=year_end',
        };
    }

    private function registeredBeforeMarch2001(string $date): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && $date < '2001-03-01';
    }

    private function tableExists(string $table): bool
    {
        try {
            return \InterfaceDB::tableExists($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
