<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class IxbrlBalanceSheetMetricsService
{
    public function fetchClosingMetrics(int $companyId, int $accountingPeriodId): array
    {
        $period = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($period === null) {
            return $this->emptyResult();
        }

        return $this->fetchClosingMetricsForPeriod(
            $companyId,
            (string)$period['period_start'],
            (string)$period['period_end']
        );
    }

    public function fetchClosingMetricsForPeriod(int $companyId, string $periodStart, string $periodEnd): array
    {
        if ($companyId <= 0 || !$this->validDate($periodEnd)) {
            return $this->emptyResult();
        }

        $rows = InterfaceDB::fetchAll(
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
            ['company_id' => $companyId, 'period_end' => $periodEnd]
        );

        $buckets = $this->emptyBuckets();
        $sources = [];
        $equity = 0.0;

        foreach ($rows as $row) {
            $accountType = (string)($row['account_type'] ?? '');
            $subtype = (string)($row['subtype_code'] ?? '');
            $label = trim((string)($row['code'] ?? '') . ' ' . (string)($row['name'] ?? ''));
            $balance = round((float)($row['debit_credit_balance'] ?? 0), 2);

            if ($accountType === 'asset') {
                $bucket = $this->isFixedAssetSubtype($subtype) ? 'fixed_assets' : 'current_assets';
                $amount = $balance;
                $buckets[$bucket] += $amount;
                $this->addSource($sources, $bucket, $label, $amount);
                continue;
            }

            if ($accountType === 'liability') {
                $bucket = $this->isLongTermLiabilitySubtype($subtype) ? 'creditors_after_more_than_one_year' : 'creditors_within_one_year';
                $amount = round(0 - $balance, 2);
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
        $buckets['creditors_within_one_year'] = round($buckets['creditors_within_one_year'], 2);
        $buckets['creditors_after_more_than_one_year'] = round($buckets['creditors_after_more_than_one_year'], 2);
        $buckets['creditors_after_one_year'] = $buckets['creditors_after_more_than_one_year'];
        $buckets['net_current_assets_liabilities'] = round($buckets['current_assets'] - $buckets['creditors_within_one_year'], 2);
        $buckets['total_assets_less_current_liabilities'] = round($buckets['fixed_assets'] + $buckets['current_assets'] - $buckets['creditors_within_one_year'], 2);
        $buckets['net_assets_liabilities'] = round($buckets['total_assets_less_current_liabilities'] - $buckets['creditors_after_more_than_one_year'], 2);
        $buckets['equity_capital_reserves'] = round(abs($equity) >= 0.005 ? $equity : $buckets['net_assets_liabilities'], 2);
        $buckets['equity'] = $buckets['equity_capital_reserves'];

        return [
            'available' => true,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'buckets' => $buckets,
            'sources' => $sources,
            'row_count' => count($rows),
            'balance_equation_difference' => round($buckets['net_assets_liabilities'] - $buckets['equity_capital_reserves'], 2),
            'is_balance_sheet_balanced' => abs($buckets['net_assets_liabilities'] - $buckets['equity_capital_reserves']) < 0.005,
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

        $row = InterfaceDB::fetchOne(
            'SELECT id, company_id, period_start, period_end
             FROM accounting_periods
             WHERE id = :id
               AND company_id = :company_id
             LIMIT 1',
            ['id' => $accountingPeriodId, 'company_id' => $companyId]
        );

        return is_array($row) ? $row : null;
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
        ];
    }

    private function emptyBuckets(): array
    {
        return [
            'fixed_assets' => 0.0,
            'current_assets' => 0.0,
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

    private function isLongTermLiabilitySubtype(string $subtype): bool
    {
        return in_array($subtype, ['long_term_liability', 'non_current_liability', 'creditors_after_one_year', 'creditors_after_more_than_one_year'], true);
    }

    private function addSource(array &$sources, string $bucket, string $label, float $amount): void
    {
        $sources[$bucket] ??= [];
        $sources[$bucket][] = [
            'label' => $label,
            'amount' => round($amount, 2),
        ];
    }

    private function validDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}
