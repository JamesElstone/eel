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

        $vatSupportScope = (array)($estimate['vat_support_scope']
            ?? (new \eel_accounts\Service\VatSupportScopeService())->fetchForCompany($companyId));
        if (!empty($vatSupportScope['tax_year_end_read_only'])) {
            return $this->historicalSnapshotWorkings($period, $ctPeriod, $estimate, $vatSupportScope);
        }

        $periodStart = $ctPeriod !== null ? (string)$ctPeriod['period_start'] : (string)$period['period_start'];
        $periodEnd = $ctPeriod !== null ? (string)$ctPeriod['period_end'] : (string)$period['period_end'];
        $accountingAllocationBasis = (array)($estimate['accounting_allocation_basis'] ?? []);
        $timeApportioned = !empty($accountingAllocationBasis['time_apportioned']);
        $detailPeriodStart = $timeApportioned ? (string)$period['period_start'] : $periodStart;
        $detailPeriodEnd = $timeApportioned ? (string)$period['period_end'] : $periodEnd;
        $prepaymentContext = (new PrepaymentScheduleService())
            ->fetchPreviewAdjustmentContext($companyId, $accountingPeriodId);
        $estimate = $this->applyPrepaymentReliabilityToEstimate($estimate, $prepaymentContext);
        $addBackRows = $this->addBackRows(
            $companyId,
            $accountingPeriodId,
            $detailPeriodStart,
            $detailPeriodEnd,
            (array)($prepaymentContext['adjustments'] ?? [])
        );
        if ($timeApportioned) {
            $addBackRows['disallowable'] = $this->apportionAddBackRows(
                (array)($addBackRows['disallowable'] ?? []),
                (float)($estimate['disallowable_add_backs'] ?? 0),
                $accountingAllocationBasis
            );
            $addBackRows['capital'] = $this->apportionAddBackRows(
                (array)($addBackRows['capital'] ?? []),
                (float)($estimate['capital_add_backs'] ?? 0),
                $accountingAllocationBasis
            );
        }
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
            'disallowable_add_backs' => (array)($addBackRows['disallowable'] ?? []),
            'capital_add_backs' => (array)($addBackRows['capital'] ?? []),
            'depreciation_add_back' => $this->depreciationRows(
                $companyId,
                $accountingPeriodId,
                $ctPeriodId,
                (float)($estimate['depreciation_add_back'] ?? 0)
            ),
            'capital_allowances_summary' => $this->capitalAllowanceSummary($poolRows),
            'aia_allocation' => array_values(array_filter($assetCalculations, static fn(array $row): bool => (string)$row['allowance_type'] === 'aia')),
            'main_rate_pool' => $this->poolByType($poolRows, 'main_pool'),
            'special_rate_pool' => $this->poolByType($poolRows, 'special_rate_pool'),
            'car_co2_treatment' => $carRows,
            'disposals_balancing' => $this->disposalRows(
                $companyId,
                $accountingPeriodId,
                $ctPeriodId,
                $periodStart,
                $periodEnd
            ),
            'losses' => (array)($estimate['schedule'] ?? []),
            'rate_bands' => (array)($estimate['ct_rate_bands'] ?? []),
            'provision' => $ctPeriodId > 0 ? (new \eel_accounts\Service\CorporationTaxProvisionService())->fetchPosition($companyId, $accountingPeriodId, $ctPeriodId) : [],
            'warnings' => $warnings,
        ];
    }

    /**
     * A persisted CT summary is immutable evidence. Do not combine it with
     * current nominal, asset, provision or warning rows after LIVE HMRC VAT
     * confirmation has placed the company outside the supported product scope.
     * Ordinary bookkeeping remains available and those live rows may therefore
     * have changed since the summary was persisted.
     *
     * @param array<string, mixed> $period
     * @param array<string, mixed>|null $ctPeriod
     * @param array<string, mixed> $estimate
     * @param array<string, mixed> $vatSupportScope
     * @return array<string, mixed>
     */
    private function historicalSnapshotWorkings(
        array $period,
        ?array $ctPeriod,
        array $estimate,
        array $vatSupportScope
    ): array {
        $warnings = array_values(array_unique(array_filter(array_merge(
            (array)($estimate['warnings'] ?? []),
            [
                (string)($vatSupportScope['message'] ?? VatSupportScopeService::UNSUPPORTED_MESSAGE),
                'Detailed nominal, asset and provision rows are hidden because they are live data and may no longer match this persisted historical computation.',
            ]
        ), static fn(string $warning): bool => trim($warning) !== '')));

        return [
            'available' => true,
            'historical_snapshot_only' => true,
            'vat_support_scope' => $vatSupportScope,
            'guidance' => TaxGuidanceService::all(),
            'period' => $period,
            'selected_ct_period' => $ctPeriod,
            'summary' => $estimate,
            'bridge' => (array)($estimate['steps'] ?? []),
            'disallowable_add_backs' => [],
            'capital_add_backs' => [],
            'depreciation_add_back' => [],
            'capital_allowances_summary' => $this->capitalAllowanceSummary(
                (array)($estimate['capital_allowance_breakdown']['rows'] ?? [])
            ),
            'aia_allocation' => [],
            'main_rate_pool' => [],
            'special_rate_pool' => [],
            'car_co2_treatment' => [],
            'disposals_balancing' => [],
            'losses' => (array)($estimate['schedule'] ?? []),
            'rate_bands' => (array)($estimate['ct_rate_bands'] ?? []),
            'provision' => [],
            'warnings' => $warnings,
        ];
    }

    /**
     * Build the signed nominal movements behind the ordinary P&L add-backs.
     * This deliberately uses the same ledger reader, close-journal exclusions,
     * Corporation Tax expense exclusion and dated prepayment treatment as
     * PreTaxProfitLossService.
     *
     * @return array{disallowable: list<array<string, mixed>>, capital: list<array<string, mixed>>}
     */
    private function addBackRows(
        int $companyId,
        int $accountingPeriodId,
        string $periodStart,
        string $periodEnd,
        array $prepaymentPreview = []
    ): array
    {
        if (!$this->tableExists('journals') || !$this->tableExists('journal_lines') || !$this->tableExists('nominal_accounts')) {
            return ['disallowable' => [], 'capital' => []];
        }

        $ledger = new PeriodLedgerReadService();
        $scope = $ledger->scope($companyId, $accountingPeriodId, $periodEnd, $periodStart);
        $settings = (new \eel_accounts\Store\CompanySettingsStore($companyId))->all();
        $ctExpenseNominalId = (int)($settings['corporation_tax_expense_nominal_id'] ?? 0);
        $rules = new CorporationTaxTreatmentRuleService();
        $result = ['disallowable' => [], 'capital' => []];

        foreach ((new DatedTaxTreatmentLedgerService())->fetch($scope) as $row) {
            if ($ctExpenseNominalId > 0
                && (int)($row['nominal_account_id'] ?? 0) === $ctExpenseNominalId) {
                continue;
            }
            $journalDate = (string)($row['journal_date'] ?? '');
            $treatment = (string)($rules->resolveTaxTreatment(
                $row,
                $journalDate,
                $journalDate
            )['tax_treatment'] ?? '');
            if ((string)($row['account_type'] ?? '') === 'income' && $treatment !== 'capital') {
                continue;
            }
            if (!in_array($treatment, ['disallowable', 'capital'], true)) {
                continue;
            }
            $amount = round((float)($row['total_debit'] ?? 0) - (float)($row['total_credit'] ?? 0), 2);
            if (abs($amount) < 0.005) {
                continue;
            }
            $result[$treatment][] = [
                'nominal_code' => (string)($row['code'] ?? ''),
                'nominal_name' => (string)($row['name'] ?? ''),
                'tax_treatment' => $treatment,
                'journal_date' => $journalDate,
                'source' => 'posted_ledger',
                'source_label' => 'Posted ledger',
                'amount' => $amount,
            ];
        }

        foreach ((new YearEndClosePreviewService())->prepaymentExpenseRowsForPeriod(
            $companyId,
            $accountingPeriodId,
            $periodStart,
            $periodEnd,
            $prepaymentPreview
        ) as $row) {
            $journalDate = (string)($row['journal_date'] ?? '');
            $treatment = (string)($rules->resolveTaxTreatment(
                $row,
                $journalDate,
                $journalDate
            )['tax_treatment'] ?? '');
            if (!in_array($treatment, ['disallowable', 'capital'], true)) {
                continue;
            }
            $amount = round((float)($row['amount'] ?? 0), 2);
            if (abs($amount) < 0.005) {
                continue;
            }
            $result[$treatment][] = [
                'nominal_code' => (string)($row['code'] ?? ''),
                'nominal_name' => (string)($row['name'] ?? ''),
                'tax_treatment' => $treatment,
                'journal_date' => $journalDate,
                'source' => 'pending_prepayment',
                'source_label' => 'Pending prepayment',
                'amount' => $amount,
            ];
        }

        foreach (['disallowable', 'capital'] as $treatment) {
            usort($result[$treatment], static function (array $left, array $right): int {
                return [
                    (string)($left['journal_date'] ?? ''),
                    (string)($left['nominal_code'] ?? ''),
                    (string)($left['source'] ?? ''),
                ] <=> [
                    (string)($right['journal_date'] ?? ''),
                    (string)($right['nominal_code'] ?? ''),
                    (string)($right['source'] ?? ''),
                ];
            });
        }

        return $result;
    }

    /**
     * Keep Tax Workings conservative even when it is displaying an older
     * persisted summary which predates prepayment-reliability fields.
     *
     * @param array<string, mixed> $estimate
     * @param array<string, mixed> $prepaymentContext
     * @return array<string, mixed>
     */
    private function applyPrepaymentReliabilityToEstimate(array $estimate, array $prepaymentContext): array
    {
        if (!empty($prepaymentContext['success'])) {
            return $estimate;
        }

        $warnings = array_merge(
            (array)($estimate['warnings'] ?? []),
            [CorporationTaxComputationService::PREPAYMENT_PREVIEW_WARNING],
            array_map('strval', (array)($prepaymentContext['errors'] ?? []))
        );
        $estimate['warnings'] = array_values(array_unique(array_filter(
            array_map('trim', array_map('strval', $warnings)),
            static fn(string $warning): bool => $warning !== ''
        )));
        $estimate['prepayment_preview_reliable'] = false;
        $estimate['prepayment_preview_warnings'] = array_values(array_unique(array_filter(
            array_map('trim', array_map(
                'strval',
                (array)($prepaymentContext['errors'] ?? [])
            )),
            static fn(string $warning): bool => $warning !== ''
        )));
        $estimate['confidence_status'] = 'review_required';
        $estimate['confidence_label'] = 'Review required';

        return $estimate;
    }

    /**
     * The CT computation allocates whole-accounting-period ordinary P&L
     * add-backs by inclusive days when an accounting period is split. Apply the
     * same ratio to each signed detail movement, then put only the penny
     * rounding residual on the final row so the visible detail cross-casts to
     * the selected CT-period summary.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $basis
     * @return list<array<string, mixed>>
     */
    private function apportionAddBackRows(array $rows, float $expectedAmount, array $basis): array
    {
        if ($rows === []) {
            return [];
        }

        $accountingDays = (int)($basis['accounting_period_days'] ?? 0);
        $ctPeriodDays = (int)($basis['ct_period_days'] ?? 0);
        if ($accountingDays <= 0 || $ctPeriodDays <= 0) {
            return $rows;
        }

        $allocatedTotal = 0.0;
        foreach ($rows as &$row) {
            $wholePeriodAmount = round((float)($row['amount'] ?? 0), 2);
            $row['whole_period_amount'] = $wholePeriodAmount;
            $row['amount'] = round($wholePeriodAmount * ($ctPeriodDays / $accountingDays), 2);
            $row['allocation_basis'] = 'whole_accounting_period_inclusive_days';
            $allocatedTotal = round($allocatedTotal + (float)$row['amount'], 2);
        }
        unset($row);

        $residual = round($expectedAmount - $allocatedTotal, 2);
        if (abs($residual) >= 0.005) {
            $lastIndex = array_key_last($rows);
            $rows[$lastIndex]['amount'] = round((float)$rows[$lastIndex]['amount'] + $residual, 2);
            $rows[$lastIndex]['rounding_residual'] = $residual;
        }

        return array_values(array_filter(
            $rows,
            static fn(array $row): bool => abs((float)($row['amount'] ?? 0)) >= 0.005
        ));
    }

    private function depreciationRows(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId = 0,
        float $expectedAmount = 0.0
    ): array
    {
        $rows = (new \eel_accounts\Service\YearEndClosePreviewService())
            ->depreciationRowsForPeriod($companyId, $accountingPeriodId);
        if ($rows === []) {
            return [];
        }

        $assetDetails = $this->assetDetailsById(array_values(array_unique(array_map(
            static fn(array $row): int => (int)($row['asset_id'] ?? 0),
            $rows
        ))));
        foreach ($rows as &$row) {
            $details = (array)($assetDetails[(int)($row['asset_id'] ?? 0)] ?? []);
            $row['asset_code'] = (string)($row['asset_code'] ?? $details['asset_code'] ?? '');
            $row['description'] = (string)($row['description'] ?? $details['description'] ?? '');
            $row['direction'] = 'add';
        }
        unset($row);

        if ($ctPeriodId > 0) {
            $periodService = new \eel_accounts\Service\CorporationTaxPeriodService();
            $ctPeriod = $periodService->fetch($companyId, $ctPeriodId);
            $accountingPeriod = (new \eel_accounts\Repository\AccountingPeriodRepository())
                ->fetchAccountingPeriod($companyId, $accountingPeriodId);
            $activeCtPeriods = array_values(array_filter(
                $periodService->fetchForAccountingPeriod($companyId, $accountingPeriodId),
                static fn(array $period): bool => (string)($period['status'] ?? '') !== 'superseded'
            ));
            if ($ctPeriod !== null && $accountingPeriod !== null && count($activeCtPeriods) > 1) {
                $accountingDays = $this->periodDays(
                    (string)$accountingPeriod['period_start'],
                    (string)$accountingPeriod['period_end']
                );
                $ctPeriodDays = $this->periodDays(
                    (string)$ctPeriod['period_start'],
                    (string)$ctPeriod['period_end']
                );
                foreach ($rows as &$row) {
                    $row['amount'] = $accountingDays > 0
                        ? round((float)($row['amount'] ?? 0) * ($ctPeriodDays / $accountingDays), 2)
                        : 0.0;
                }
                unset($row);
            }
        }

        $rows = array_values(array_filter(
            $rows,
            static fn(array $row): bool => (float)($row['amount'] ?? 0) > 0
        ));
        if ($rows === []) {
            return [];
        }

        $detailTotal = round(array_sum(array_map(
            static fn(array $row): float => (float)($row['amount'] ?? 0),
            $rows
        )), 2);
        $residual = round($expectedAmount - $detailTotal, 2);
        if (abs($residual) >= 0.005) {
            $lastIndex = array_key_last($rows);
            $rows[$lastIndex]['amount'] = round((float)$rows[$lastIndex]['amount'] + $residual, 2);
        }

        return $rows;
    }

    private function periodDays(string $start, string $end): int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $end < $start) {
            return 0;
        }

        return max(1, (new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($end))->days + 1);
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

    private function disposalRows(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        string $periodStart,
        string $periodEnd
    ): array
    {
        if (!$this->tableExists('asset_register')) {
            return [];
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT ar.id AS asset_id,
                    ar.asset_code,
                    ar.description,
                    ar.disposal_date,
                    ar.disposal_proceeds,
                    na.code AS nominal_code,
                    na.name AS nominal_name
             FROM asset_register ar
             LEFT JOIN nominal_accounts na ON na.id = ar.nominal_account_id
             WHERE ar.company_id = :company_id
               AND ar.disposal_date IS NOT NULL
               AND ar.disposal_date BETWEEN :period_start AND :period_end
             ORDER BY ar.disposal_date ASC, ar.id ASC',
            ['company_id' => $companyId, 'period_start' => $periodStart, 'period_end' => $periodEnd]
        ) ?: [];
        if ($rows === [] || !$this->tableExists('capital_allowance_asset_calculations')) {
            return $rows;
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

        $calculationsByAsset = [];
        foreach (\InterfaceDB::fetchAll($sql, $params) ?: [] as $calculation) {
            $assetId = (int)($calculation['asset_id'] ?? 0);
            if ($assetId <= 0) {
                continue;
            }
            if (!isset($calculationsByAsset[$assetId])) {
                $calculationsByAsset[$assetId] = $calculation;
                continue;
            }
            $current = &$calculationsByAsset[$assetId];
            foreach ([
                'addition_amount',
                'allowance_amount',
                'disposal_value',
                'balancing_charge',
                'balancing_allowance',
            ] as $amountField) {
                $current[$amountField] = round(
                    (float)($current[$amountField] ?? 0) + (float)($calculation[$amountField] ?? 0),
                    2
                );
            }
            $allowanceTypes = array_values(array_unique(array_filter([
                trim((string)($current['allowance_type'] ?? '')),
                trim((string)($calculation['allowance_type'] ?? '')),
            ])));
            $current['allowance_type'] = implode(', ', $allowanceTypes);
            $warnings = array_values(array_unique(array_filter([
                trim((string)($current['warning'] ?? '')),
                trim((string)($calculation['warning'] ?? '')),
            ])));
            $current['warning'] = implode(' ', $warnings);
            unset($current);
        }

        foreach ($rows as &$row) {
            $calculation = $calculationsByAsset[(int)($row['asset_id'] ?? 0)] ?? null;
            if (is_array($calculation)) {
                $row = array_merge($row, $calculation);
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * @param list<int> $assetIds
     * @return array<int, array<string, mixed>>
     */
    private function assetDetailsById(array $assetIds): array
    {
        $assetIds = array_values(array_filter(array_unique($assetIds), static fn(int $assetId): bool => $assetId > 0));
        if ($assetIds === [] || !$this->tableExists('asset_register')) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($assetIds), '?'));
        $rows = \InterfaceDB::prepareExecute(
            'SELECT id, asset_code, description
             FROM asset_register
             WHERE id IN (' . $placeholders . ')',
            $assetIds
        )->fetchAll() ?: [];

        $map = [];
        foreach ($rows as $row) {
            $map[(int)($row['id'] ?? 0)] = $row;
        }

        return $map;
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
