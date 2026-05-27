<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class IxbrlAccountsMappingService
{
    public function getAccountsMapping(int $companyId, int $accountingPeriodId): array
    {
        $trialBalance = (new IxbrlTrialBalanceService())->getTrialBalance($companyId, $accountingPeriodId);
        $buckets = $this->emptyBuckets();
        $sources = [];
        $income = 0.0;
        $costOfSales = 0.0;
        $expenses = 0.0;
        $explicitEquity = 0.0;

        foreach ($trialBalance as $row) {
            $accountType = (string)($row['account_type'] ?? '');
            $subtype = (string)($row['subtype_code'] ?? '');
            $name = trim((string)($row['code'] ?? '') . ' ' . (string)($row['name'] ?? ''));
            $net = round((float)($row['net_movement'] ?? 0), 2);
            $debit = (float)($row['total_debit'] ?? 0);
            $credit = (float)($row['total_credit'] ?? 0);

            if ($accountType === 'income') {
                $amount = round($credit - $debit, 2);
                $income += $amount;
                $this->addSource($sources, 'turnover', $name, $amount);
                continue;
            }
            if ($accountType === 'cost_of_sales') {
                $amount = round($debit - $credit, 2);
                $costOfSales += $amount;
                $this->addSource($sources, 'expenses', $name, $amount);
                continue;
            }
            if ($accountType === 'expense') {
                $amount = round($debit - $credit, 2);
                $expenses += $amount;
                $this->addSource($sources, 'expenses', $name, $amount);
                continue;
            }
            if ($accountType === 'asset') {
                $amount = round($net, 2);
                $bucket = in_array($subtype, ['fixed_asset', 'fixed_assets'], true) ? 'fixed_assets' : 'current_assets';
                $buckets[$bucket] += $amount;
                $this->addSource($sources, $bucket, $name, $amount);
                continue;
            }
            if ($accountType === 'liability') {
                $amount = round($credit - $debit, 2);
                $bucket = in_array($subtype, ['long_term_liability', 'creditors_after_one_year'], true) ? 'creditors_after_one_year' : 'creditors_within_one_year';
                $buckets[$bucket] += $amount;
                $this->addSource($sources, $bucket, $name, $amount);
                continue;
            }
            if ($accountType === 'equity') {
                $amount = round($credit - $debit, 2);
                $explicitEquity += $amount;
                $this->addSource($sources, 'explicit_equity', $name, $amount);
            }
        }

        $buckets['turnover'] = round($income, 2);
        $buckets['expenses'] = round($costOfSales + $expenses, 2);
        $buckets['profit_loss'] = round($income - $costOfSales - $expenses, 2);
        $buckets['net_current_assets_liabilities'] = round($buckets['current_assets'] - $buckets['creditors_within_one_year'], 2);
        $buckets['total_assets_less_current_liabilities'] = round($buckets['fixed_assets'] + $buckets['current_assets'] - $buckets['creditors_within_one_year'], 2);
        $buckets['net_assets_liabilities'] = round($buckets['total_assets_less_current_liabilities'] - $buckets['creditors_after_one_year'], 2);
        $buckets['equity'] = round($buckets['net_assets_liabilities'], 2);

        $assumptions = [
            'Equity / capital and reserves is derived from net assets for this MVP unless later period-close retained earnings support supersedes it.',
            'Fixed assets require a fixed_asset nominal subtype; otherwise asset accounts are treated as current assets.',
            'All liability accounts are treated as due within one year unless a long-term subtype is present.',
        ];
        if (abs($explicitEquity) >= 0.005 && abs($explicitEquity - $buckets['equity']) >= 0.005) {
            $assumptions[] = 'Explicit equity nominal balance differs from derived net assets; derived net assets is used for the first-pass accounts fact.';
        }

        return [
            'available' => $companyId > 0 && $accountingPeriodId > 0,
            'buckets' => array_map(static fn(float $value): float => round($value, 2), $buckets),
            'sources' => $sources,
            'assumptions' => $assumptions,
            'trial_balance_row_count' => count($trialBalance),
        ];
    }

    private function emptyBuckets(): array
    {
        return [
            'turnover' => 0.0,
            'expenses' => 0.0,
            'profit_loss' => 0.0,
            'current_assets' => 0.0,
            'fixed_assets' => 0.0,
            'creditors_within_one_year' => 0.0,
            'creditors_after_one_year' => 0.0,
            'net_current_assets_liabilities' => 0.0,
            'total_assets_less_current_liabilities' => 0.0,
            'net_assets_liabilities' => 0.0,
            'equity' => 0.0,
        ];
    }

    private function addSource(array &$sources, string $bucket, string $label, float $amount): void
    {
        $sources[$bucket] ??= [];
        $sources[$bucket][] = [
            'label' => $label,
            'amount' => round($amount, 2),
        ];
    }
}
