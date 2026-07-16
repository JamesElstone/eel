<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class IxbrlAccountsMappingService
{
    public function getAccountsMapping(
        int $companyId,
        int $accountingPeriodId,
        bool $includeYearEndCardPreviews = false
    ): array
    {
        $trialBalance = (new \eel_accounts\Service\IxbrlTrialBalanceService())->getTrialBalance($companyId, $accountingPeriodId);
        $closingMetrics = (new \eel_accounts\Service\IxbrlBalanceSheetMetricsService())
            ->fetchClosingMetrics(
                $companyId,
                $accountingPeriodId,
                $includeYearEndCardPreviews,
                $includeYearEndCardPreviews
            );
        $closingBuckets = (array)($closingMetrics['buckets'] ?? []);
        $closingSources = (array)($closingMetrics['sources'] ?? []);
        $directorLoanPresentation = (array)($closingMetrics['director_loan_reporting_presentation'] ?? []);
        $buckets = $this->emptyBuckets();
        $sources = $closingSources;
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
            if ($accountType === 'equity') {
                $amount = round($credit - $debit, 2);
                $explicitEquity += $amount;
                $this->addSource($sources, 'explicit_equity', $name, $amount);
            }
        }

        foreach (['fixed_assets', 'current_assets', 'creditors_within_one_year', 'creditors_after_more_than_one_year', 'net_current_assets_liabilities', 'total_assets_less_current_liabilities', 'net_assets_liabilities', 'equity_capital_reserves', 'equity'] as $key) {
            $buckets[$key] = round((float)($closingBuckets[$key] ?? 0), 2);
        }
        $buckets['creditors_after_one_year'] = $buckets['creditors_after_more_than_one_year'];

        $buckets['turnover'] = round($income, 2);
        $buckets['expenses'] = round($costOfSales + $expenses, 2);
        $buckets['profit_loss'] = round($income - $costOfSales - $expenses, 2);

        $assumptions = [
            'Balance sheet facts use closing posted-journal balances up to the period end, including opening and brought-forward journals, plus applicable pending Year End close-preview adjustments.',
            'Fixed assets require a fixed_asset nominal subtype; otherwise asset accounts are treated as current assets.',
            'Liability accounts are treated as due within one year unless an explicit long-term liability subtype or the period-specific Director Loan reporting presentation applies.',
        ];
        if (!empty($directorLoanPresentation['applicable'])) {
            $basis = !empty($directorLoanPresentation['explicit']) ? 'saved choice' : 'default';
            $nominal = (array)($directorLoanPresentation['liability_nominal'] ?? []);
            $nominalLabel = trim((string)($nominal['code'] ?? '') . ' ' . (string)($nominal['name'] ?? ''));
            $assumptions[] = 'Director Loan Liability'
                . ($nominalLabel !== '' ? ' (' . $nominalLabel . ')' : '')
                . ' is presented as '
                . strtolower((string)($directorLoanPresentation['classification_label'] ?? 'due within one year'))
                . ' for this accounting period using the ' . $basis . '.';
        }
        if (abs($explicitEquity) >= 0.005 && abs($explicitEquity - $buckets['equity_capital_reserves']) >= 0.005) {
            $assumptions[] = 'Explicit current-period equity movement differs from closing capital and reserves; the closing balance sheet metric is used for accounts facts.';
        }
        foreach ((array)($closingMetrics['warnings'] ?? []) as $warning) {
            $warning = trim((string)$warning);
            if ($warning !== '') {
                $assumptions[] = $warning;
            }
        }

        return [
            'available' => $companyId > 0 && $accountingPeriodId > 0,
            'buckets' => array_map(static fn(float $value): float => round($value, 2), $buckets),
            'sources' => $sources,
            'assumptions' => $assumptions,
            'trial_balance_row_count' => count($trialBalance),
            'closing_balance_row_count' => (int)($closingMetrics['row_count'] ?? 0),
            'balance_equation_difference' => (float)($closingMetrics['balance_equation_difference'] ?? 0),
            'is_balance_sheet_balanced' => !empty($closingMetrics['is_balance_sheet_balanced']),
            'reliable_closing_balance' => !empty($closingMetrics['reliable_closing_balance']),
            'prior_period_dependency' => (array)($closingMetrics['prior_period_dependency'] ?? []),
            'director_loan_reporting_presentation' => $directorLoanPresentation,
            'warnings' => array_values(array_map('strval', (array)($closingMetrics['warnings'] ?? []))),
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
            'creditors_after_more_than_one_year' => 0.0,
            'creditors_after_one_year' => 0.0,
            'net_current_assets_liabilities' => 0.0,
            'total_assets_less_current_liabilities' => 0.0,
            'net_assets_liabilities' => 0.0,
            'equity_capital_reserves' => 0.0,
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
