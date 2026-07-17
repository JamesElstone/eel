<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class IxbrlBalanceSheetMetricsService
{
    public function fetchClosingMetrics(
        int $companyId,
        int $accountingPeriodId,
        bool $includePriorOpenPeriodPreviews = false,
        bool $reportPendingPreviewReliability = false
    ): array
    {
        $period = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($period === null) {
            return $this->emptyResult();
        }

        return $this->fetchClosingMetricsForPeriod(
            $companyId,
            (string)$period['period_start'],
            (string)$period['period_end'],
            $accountingPeriodId,
            null,
            null,
            null,
            $includePriorOpenPeriodPreviews,
            $reportPendingPreviewReliability
        );
    }

    public function fetchClosingMetricsForPeriod(
        int $companyId,
        string $periodStart,
        string $periodEnd,
        ?int $accountingPeriodId = null,
        ?array $depreciationPreview = null,
        ?array $prepaymentPreview = null,
        ?float $profitBeforeTax = null,
        bool $includePriorOpenPeriodPreviews = false,
        bool $reportPendingPreviewReliability = false
    ): array
    {
        if ($companyId <= 0 || !$this->validDate($periodEnd)) {
            return $this->emptyResult();
        }

        $params = ['company_id' => $companyId, 'period_end' => $periodEnd];

        $rows = \InterfaceDB::fetchAll(
            'SELECT
                na.id AS nominal_account_id,
                na.code,
                na.name,
                na.account_type,
                COALESCE(nas.code, \'\') AS subtype_code,
                COALESCE(SUM(COALESCE(jl.debit, 0) - COALESCE(jl.credit, 0)), 0) AS debit_credit_balance
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             INNER JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             WHERE j.company_id = :company_id
               AND j.is_posted = 1
               AND j.journal_date <= :period_end
             GROUP BY na.id, na.code, na.name, na.account_type, nas.code
             ORDER BY na.sort_order, na.code',
            $params
        );
        $pendingPreviewContext = null;
        $rows = $this->applyPendingClosePreviewAdjustments(
            $rows,
            $companyId,
            $accountingPeriodId,
            $periodEnd,
            $depreciationPreview,
            $prepaymentPreview,
            $profitBeforeTax,
            $includePriorOpenPeriodPreviews,
            $pendingPreviewContext
        );

        $buckets = $this->emptyBuckets();
        $sources = [];
        $equity = 0.0;
        $directorLoanPresentation = $accountingPeriodId !== null && $accountingPeriodId > 0
            ? (new DirectorLoanReportingPresentationService())->resolveForReporting($companyId, $accountingPeriodId)
            : [];
        $directorLoanLiabilityNominalId = !empty($directorLoanPresentation['applicable'])
            ? (int)($directorLoanPresentation['liability_nominal_account_id'] ?? 0)
            : 0;

        foreach ($rows as $row) {
            $nominalAccountId = (int)($row['nominal_account_id'] ?? 0);
            $accountType = (string)($row['account_type'] ?? '');
            $subtype = (string)($row['subtype_code'] ?? '');
            $label = trim((string)($row['code'] ?? '') . ' ' . (string)($row['name'] ?? ''));
            $balance = round((float)($row['debit_credit_balance'] ?? 0), 2);

            if ($accountType === 'asset') {
                $bucket = $this->isFixedAssetSubtype($subtype)
                    ? 'fixed_assets'
                    : ($this->isPrepaymentsAccruedIncomeSubtype($subtype)
                        ? 'prepayments_accrued_income'
                        : 'current_assets');
                $amount = $balance;
                $buckets[$bucket] += $amount;
                $this->addSource($sources, $bucket, $label, $amount);
                continue;
            }

            if ($accountType === 'liability') {
                $amount = round(0 - $balance, 2);
                if ($amount < -0.004) {
                    $assetAmount = round(abs($amount), 2);
                    $buckets['current_assets'] += $assetAmount;
                    $this->addSource($sources, 'current_assets', $label, $assetAmount);
                    continue;
                }

                $isPresentedDirectorLoanLiability = $directorLoanLiabilityNominalId > 0
                    && $nominalAccountId === $directorLoanLiabilityNominalId;
                $bucket = $isPresentedDirectorLoanLiability
                    ? ((string)($directorLoanPresentation['classification'] ?? '')
                        === DirectorLoanReportingPresentationService::AFTER_MORE_THAN_ONE_YEAR
                            ? 'creditors_after_more_than_one_year'
                            : 'creditors_within_one_year')
                    : ($this->isLongTermLiabilitySubtype($subtype)
                        ? 'creditors_after_more_than_one_year'
                        : 'creditors_within_one_year');
                $buckets[$bucket] += $amount;
                $this->addSource($sources, $bucket, $label, $amount);
                continue;
            }

            if ($accountType === 'equity') {
                $amount = round(0 - $balance, 2);
                $equity += $amount;
                $this->addSource($sources, 'equity_capital_reserves', $label, $amount);
            }
        }

        $buckets['fixed_assets'] = round($buckets['fixed_assets'], 2);
        $buckets['current_assets'] = round($buckets['current_assets'], 2);
        $buckets['prepayments_accrued_income'] = round($buckets['prepayments_accrued_income'], 2);
        $buckets['creditors_within_one_year'] = round($buckets['creditors_within_one_year'], 2);
        $buckets['creditors_after_more_than_one_year'] = round($buckets['creditors_after_more_than_one_year'], 2);
        $buckets['creditors_after_one_year'] = $buckets['creditors_after_more_than_one_year'];
        $buckets['net_current_assets_liabilities'] = round(
            $buckets['current_assets']
                + $buckets['prepayments_accrued_income']
                - $buckets['creditors_within_one_year'],
            2
        );
        $buckets['total_assets_less_current_liabilities'] = round(
            $buckets['fixed_assets']
                + $buckets['current_assets']
                + $buckets['prepayments_accrued_income']
                - $buckets['creditors_within_one_year'],
            2
        );
        $buckets['net_assets_liabilities'] = round($buckets['total_assets_less_current_liabilities'] - $buckets['creditors_after_more_than_one_year'], 2);
        $buckets['equity_capital_reserves'] = round($equity, 2);
        $buckets['equity'] = $buckets['equity_capital_reserves'];
        $sources['creditors_after_one_year'] = (array)($sources['creditors_after_more_than_one_year'] ?? []);
        $sources['equity'] = (array)($sources['equity_capital_reserves'] ?? []);
        $this->addFormulaSource($sources, 'net_current_assets_liabilities', 'Current assets', $buckets['current_assets'], 'current_assets');
        $this->addFormulaSource($sources, 'net_current_assets_liabilities', 'Prepayments and accrued income', $buckets['prepayments_accrued_income'], 'prepayments_accrued_income');
        $this->addFormulaSource($sources, 'net_current_assets_liabilities', 'Less: creditors due within one year', -$buckets['creditors_within_one_year'], 'creditors_within_one_year');
        $this->addFormulaSource($sources, 'total_assets_less_current_liabilities', 'Fixed assets', $buckets['fixed_assets'], 'fixed_assets');
        $this->addFormulaSource($sources, 'total_assets_less_current_liabilities', 'Current assets', $buckets['current_assets'], 'current_assets');
        $this->addFormulaSource($sources, 'total_assets_less_current_liabilities', 'Prepayments and accrued income', $buckets['prepayments_accrued_income'], 'prepayments_accrued_income');
        $this->addFormulaSource($sources, 'total_assets_less_current_liabilities', 'Less: creditors due within one year', -$buckets['creditors_within_one_year'], 'creditors_within_one_year');
        $this->addFormulaSource($sources, 'net_assets_liabilities', 'Total assets less current liabilities', $buckets['total_assets_less_current_liabilities'], 'total_assets_less_current_liabilities');
        $this->addFormulaSource($sources, 'net_assets_liabilities', 'Less: creditors due after one year', -$buckets['creditors_after_more_than_one_year'], 'creditors_after_more_than_one_year');
        $this->assertSourcesReconcile($sources, $buckets);
        $priorPeriodDependency = $this->priorPeriodDependency($companyId, $periodStart);
        $balanceEquationDifference = round($buckets['net_assets_liabilities'] - $buckets['equity_capital_reserves'], 2);
        $warnings = [];
        if (empty($priorPeriodDependency['satisfied'])) {
            $warnings[] = (string)($priorPeriodDependency['detail'] ?? 'The prior accounting period must be locked before these closing balances are final.');
        }
        if ($reportPendingPreviewReliability) {
            foreach ((array)($pendingPreviewContext['warnings'] ?? []) as $warning) {
                $warnings[] = (string)$warning;
            }
        }
        if (abs($balanceEquationDifference) >= 0.005) {
            $warnings[] = 'Balance sheet metrics do not agree with explicitly posted capital and reserves.';
        }
        $pendingPreviewReliable = !$reportPendingPreviewReliability
            || $pendingPreviewContext === null
            || !empty($pendingPreviewContext['reliable']);

        return [
            'available' => true,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'buckets' => $buckets,
            'sources' => $sources,
            'row_count' => count($rows),
            'balance_equation_difference' => $balanceEquationDifference,
            'is_balance_sheet_balanced' => abs($balanceEquationDifference) < 0.005,
            'reliable_closing_balance' => !empty($priorPeriodDependency['satisfied']) && $pendingPreviewReliable,
            'prior_period_dependency' => $priorPeriodDependency,
            'pending_close_preview' => $reportPendingPreviewReliability
                ? ($pendingPreviewContext ?? [
                    'adjustments' => [],
                    'reliable' => true,
                    'warnings' => [],
                    'periods' => [],
                ])
                : [
                    'adjustments' => [],
                    'reliable' => true,
                    'warnings' => [],
                    'periods' => [],
                ],
            'director_loan_reporting_presentation' => $directorLoanPresentation,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    public function metricAliases(): array
    {
        return [
            'creditors_after_one_year' => 'creditors_after_more_than_one_year',
            'equity' => 'equity_capital_reserves',
        ];
    }

    private function fetchAccountingPeriod(int $companyId, int $accountingPeriodId): ?array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return null;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT id, company_id, period_start, period_end
             FROM accounting_periods
             WHERE id = :id
               AND company_id = :company_id
             LIMIT 1',
            ['id' => $accountingPeriodId, 'company_id' => $companyId]
        );

        return is_array($row) ? $row : null;
    }

    private function applyPendingClosePreviewAdjustments(
        array $rows,
        int $companyId,
        ?int $accountingPeriodId,
        string $periodEnd,
        ?array $depreciationPreview = null,
        ?array $prepaymentPreview = null,
        ?float $profitBeforeTax = null,
        bool $includePriorOpenPeriodPreviews = false,
        ?array &$pendingPreviewContext = null
    ): array
    {
        if ($accountingPeriodId === null || $accountingPeriodId <= 0) {
            return $rows;
        }

        $pendingPreviewContext = (new \eel_accounts\Service\YearEndClosePreviewService())
            ->pendingBalanceSheetAdjustmentContext(
                $companyId,
                $accountingPeriodId,
                $periodEnd,
                $depreciationPreview,
                $prepaymentPreview,
                $profitBeforeTax,
                $includePriorOpenPeriodPreviews
            );
        $adjustments = (array)($pendingPreviewContext['adjustments'] ?? []);
        if ($adjustments === []) {
            return $rows;
        }

        $indexedRows = [];
        foreach ($rows as $row) {
            $nominalId = (int)($row['nominal_account_id'] ?? 0);
            if ($nominalId <= 0) {
                continue;
            }

            $indexedRows[$nominalId] = $row;
        }

        foreach ($adjustments as $adjustment) {
            $nominalId = (int)($adjustment['nominal_account_id'] ?? 0);
            if ($nominalId <= 0) {
                continue;
            }

            $movement = round((float)($adjustment['debit'] ?? 0) - (float)($adjustment['credit'] ?? 0), 2);
            if (abs($movement) < 0.005) {
                continue;
            }

            if (!isset($indexedRows[$nominalId])) {
                $indexedRows[$nominalId] = [
                    'nominal_account_id' => $nominalId,
                    'code' => (string)($adjustment['code'] ?? ''),
                    'name' => (string)($adjustment['name'] ?? ''),
                    'account_type' => (string)($adjustment['account_type'] ?? ''),
                    'subtype_code' => (string)($adjustment['subtype_code'] ?? ''),
                    'debit_credit_balance' => 0.0,
                ];
            }

            $indexedRows[$nominalId]['debit_credit_balance'] = round(
                (float)($indexedRows[$nominalId]['debit_credit_balance'] ?? 0) + $movement,
                2
            );
        }

        return array_values($indexedRows);
    }

    private function emptyResult(): array
    {
        return [
            'available' => false,
            'period_start' => '',
            'period_end' => '',
            'buckets' => $this->emptyBuckets(),
            'sources' => [],
            'row_count' => 0,
            'balance_equation_difference' => 0.0,
            'is_balance_sheet_balanced' => false,
            'reliable_closing_balance' => false,
            'prior_period_dependency' => [
                'status' => 'unavailable',
                'satisfied' => false,
                'prior_accounting_period' => null,
                'detail' => 'The closing balance dependency could not be determined.',
            ],
            'pending_close_preview' => [
                'adjustments' => [],
                'reliable' => false,
                'warnings' => [],
                'periods' => [],
            ],
            'warnings' => [],
        ];
    }

    private function priorPeriodDependency(int $companyId, string $periodStart): array
    {
        if ($companyId <= 0 || !$this->validDate($periodStart)) {
            return [
                'status' => 'unavailable',
                'satisfied' => false,
                'prior_accounting_period' => null,
                'detail' => 'The prior accounting period dependency could not be determined.',
            ];
        }

        $priorPeriod = \InterfaceDB::fetchOne(
            'SELECT id, company_id, label, period_start, period_end
             FROM accounting_periods
             WHERE company_id = :company_id
               AND period_end < :period_start
             ORDER BY period_end DESC, id DESC
             LIMIT 1',
            ['company_id' => $companyId, 'period_start' => $periodStart]
        );
        if (!is_array($priorPeriod)) {
            return [
                'status' => 'first_period',
                'satisfied' => true,
                'prior_accounting_period' => null,
                'detail' => 'This is the first recorded accounting period, so no prior-period lock is required.',
            ];
        }

        $locked = (new \eel_accounts\Service\YearEndLockService())
            ->isLocked($companyId, (int)($priorPeriod['id'] ?? 0));

        return [
            'status' => $locked ? 'prior_period_locked' : 'prior_period_unlocked',
            'satisfied' => $locked,
            'prior_accounting_period' => $priorPeriod,
            'detail' => $locked
                ? 'The prior accounting period is locked and its closing balances are included in this roll-forward.'
                : 'The prior accounting period is not locked. These closing balances are provisional until its Year End close is completed.',
        ];
    }

    private function emptyBuckets(): array
    {
        return [
            'fixed_assets' => 0.0,
            'current_assets' => 0.0,
            'prepayments_accrued_income' => 0.0,
            'creditors_within_one_year' => 0.0,
            'creditors_after_more_than_one_year' => 0.0,
            'creditors_after_one_year' => 0.0,
            'net_current_assets_liabilities' => 0.0,
            'total_assets_less_current_liabilities' => 0.0,
            'net_assets_liabilities' => 0.0,
            'equity_capital_reserves' => 0.0,
            'equity' => 0.0,
        ];
    }

    private function isFixedAssetSubtype(string $subtype): bool
    {
        return in_array($subtype, ['fixed_asset', 'fixed_assets'], true)
            || str_starts_with($subtype, 'fixed_asset_');
    }

    private function isPrepaymentsAccruedIncomeSubtype(string $subtype): bool
    {
        $subtype = strtolower(trim($subtype));
        return in_array($subtype, [
            'prepayment',
            'prepayments',
            'accrued_income',
            'prepayments_accrued_income',
        ], true)
            || str_starts_with($subtype, 'prepayment_')
            || str_starts_with($subtype, 'accrued_income_');
    }

    private function isLongTermLiabilitySubtype(string $subtype): bool
    {
        return in_array($subtype, ['long_term_liability', 'non_current_liability', 'creditors_after_one_year', 'creditors_after_more_than_one_year', 'director_loan_long_term_liability'], true);
    }

    private function addSource(
        array &$sources,
        string $bucket,
        string $label,
        float $amount,
        array $metadata = []
    ): void
    {
        $sources[$bucket] ??= [];
        $sources[$bucket][] = array_merge([
            'label' => $label,
            'amount' => round($amount, 2),
        ], $metadata);
    }

    private function addFormulaSource(
        array &$sources,
        string $bucket,
        string $label,
        float $amount,
        string $component
    ): void {
        $this->addSource(
            $sources,
            $bucket,
            $label,
            $amount,
            ['source_type' => 'formula', 'formula_component' => $component]
        );
    }

    private function assertSourcesReconcile(array $sources, array $buckets): void
    {
        foreach ([
            'fixed_assets',
            'current_assets',
            'prepayments_accrued_income',
            'creditors_within_one_year',
            'creditors_after_more_than_one_year',
            'creditors_after_one_year',
            'net_current_assets_liabilities',
            'total_assets_less_current_liabilities',
            'net_assets_liabilities',
            'equity_capital_reserves',
            'equity',
        ] as $bucket) {
            $sourceTotal = round(array_sum(array_map(
                static fn(array $row): float => (float)($row['amount'] ?? 0),
                (array)($sources[$bucket] ?? [])
            )), 2);
            $bucketTotal = round((float)($buckets[$bucket] ?? 0), 2);
            if (abs($sourceTotal - $bucketTotal) >= 0.005) {
                throw new \LogicException(sprintf(
                    'iXBRL balance-sheet source rows for %s total %.2f but the bucket total is %.2f.',
                    $bucket,
                    $sourceTotal,
                    $bucketTotal
                ));
            }
        }
    }

    private function validDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}
