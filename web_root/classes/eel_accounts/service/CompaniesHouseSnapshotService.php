<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class CompaniesHouseSnapshotService
{
    public function __construct(
        private readonly ?\eel_accounts\Service\TrialBalanceService $trialBalanceService = null,
        private readonly ?\eel_accounts\Service\IxbrlAccountsMappingService $mappingService = null,
    ) {
    }

    public function fetchSnapshot(int $companyId, int $accountingPeriodId): array
    {
        $trialBalanceService = $this->trialBalanceService ?? new \eel_accounts\Service\TrialBalanceService();
        $context = $trialBalanceService->fetchPageContext($companyId, $accountingPeriodId);
        if ($context === null) {
            return [
                'available' => false,
                'errors' => ['The selected company or accounting period could not be found.'],
            ];
        }

        $mapping = ($this->mappingService ?? new \eel_accounts\Service\IxbrlAccountsMappingService())
            ->getAccountsMapping($companyId, $accountingPeriodId, true);
        $buckets = (array)($mapping['buckets'] ?? []);
        $sources = (array)($mapping['sources'] ?? []);
        $company = (array)($context['company'] ?? []);
        $period = (array)($context['accounting_period'] ?? []);

        $fixedAssets = $this->money($buckets['fixed_assets'] ?? 0);
        $currentAssets = $this->money($buckets['current_assets'] ?? 0);
        $prepaymentsAccruedIncome = $this->money($buckets['prepayments_accrued_income'] ?? 0);
        $creditorsWithinOneYear = $this->money($buckets['creditors_within_one_year'] ?? 0);
        $creditorsAfterOneYear = $this->money($buckets['creditors_after_more_than_one_year'] ?? 0);
        $netCurrentAssets = $this->money($currentAssets + $prepaymentsAccruedIncome - $creditorsWithinOneYear);
        $totalAssetsLessCurrentLiabilities = $this->money($fixedAssets + $currentAssets + $prepaymentsAccruedIncome - $creditorsWithinOneYear);
        $totalNetAssets = $this->money($totalAssetsLessCurrentLiabilities - $creditorsAfterOneYear);
        $capitalAndReserves = $this->money($buckets['equity_capital_reserves'] ?? 0);
        $balanceEquationDifference = $this->money($totalNetAssets - $capitalAndReserves);
        $reliableClosingBalance = !empty($mapping['reliable_closing_balance']);
        $warnings = array_values(array_unique(array_filter(array_map(
            'strval',
            (array)($mapping['warnings'] ?? [])
        ))));
        if (abs($balanceEquationDifference) >= 0.005) {
            $warnings[] = 'Balance sheet metrics do not agree with capital and reserves.';
        }

        return [
            'available' => true,
            'company' => [
                'name' => (string)($company['company_name'] ?? ''),
                'number' => (string)($company['company_number'] ?? ''),
            ],
            'period' => [
                'start' => (string)($period['period_start'] ?? ''),
                'end' => (string)($period['period_end'] ?? ''),
                'balance_sheet_date' => (string)($period['period_end'] ?? ''),
            ],
            'fields' => [
                $this->field('company_name', 'Company name', (string)($company['company_name'] ?? ''), false),
                $this->field('company_number', 'Company number', (string)($company['company_number'] ?? ''), false),
                $this->field('period_start', 'Accounting period start', (string)($period['period_start'] ?? ''), false),
                $this->field('period_end', 'Accounting period end', (string)($period['period_end'] ?? ''), false),
                $this->field('balance_sheet_date', 'Balance sheet date', (string)($period['period_end'] ?? ''), false),
                $this->field('fixed_assets', 'Fixed assets', $fixedAssets, true, 'Fixed-asset subtype ledger balances.'),
                $this->field('current_assets', 'Current assets', $currentAssets, true, 'Current assets exclude fixed assets. Bank balances sit here.'),
                $this->field('prepayments_accrued_income', 'Prepayments and accrued income', $prepaymentsAccruedIncome, true, 'Prepayment and accrued-income asset subtypes shown separately from current assets.'),
                $this->field('creditors_within_one_year', 'Creditors: amounts falling due within one year', $creditorsWithinOneYear, true),
                $this->field('net_current_assets_liabilities', 'Net current assets / liabilities', $netCurrentAssets, true, 'Current assets plus prepayments and accrued income less creditors due within one year.'),
                $this->field('total_assets_less_current_liabilities', 'Total assets less current liabilities', $totalAssetsLessCurrentLiabilities, true, 'Fixed assets plus current assets plus prepayments and accrued income less creditors due within one year.'),
                $this->field('creditors_after_more_than_one_year', 'Creditors: amounts falling due after more than one year', $creditorsAfterOneYear, true, 'Includes explicit long-term/non-current liability subtypes and any period-specific Director Loan repayment presentation.'),
                $this->field('net_assets_liabilities', 'Total net assets / liabilities', $totalNetAssets, true, 'Total assets less current liabilities less creditors after more than one year.'),
                $this->field('equity_capital_reserves', 'Capital and reserves', $capitalAndReserves, true),
            ],
            'checks' => [
                $this->check('Micro-entity balance sheet total', $this->money($fixedAssets + $currentAssets + $prepaymentsAccruedIncome), 'Fixed assets plus current assets plus prepayments and accrued income.'),
                $this->check('Balance equation check', $balanceEquationDifference, 'Total net assets less capital and reserves.'),
                $this->check(
                    'Balance sheet balanced',
                    !empty($mapping['is_balance_sheet_balanced']) && abs($balanceEquationDifference) < 0.005 ? 'Yes' : 'No',
                    abs($balanceEquationDifference) < 0.005
                        ? 'Balance sheet metrics agree.'
                        : 'Review ledger classifications before using these figures for filing.'
                ),
            ],
            'sources' => $this->summariseSources($sources),
            'assumptions' => array_values(array_filter(array_map('strval', (array)($mapping['assumptions'] ?? [])))),
            'warnings' => array_values(array_unique($warnings)),
            'is_balance_sheet_balanced' => abs($balanceEquationDifference) < 0.005,
            'balance_equation_difference' => $balanceEquationDifference,
            'reliable_closing_balance' => $reliableClosingBalance,
            'prior_period_dependency' => (array)($mapping['prior_period_dependency'] ?? []),
        ];
    }

    private function field(string $key, string $label, string|float $value, bool $money, string $note = ''): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'is_money' => $money,
            'note' => $note,
        ];
    }

    private function check(string $label, string|float $value, string $detail): array
    {
        return [
            'label' => $label,
            'value' => $value,
            'detail' => $detail,
        ];
    }

    private function summariseSources(array $sources): array
    {
        $summary = [];
        foreach ($sources as $bucket => $rows) {
            if (!in_array((string)$bucket, $this->balanceSheetSourceKeys(), true)) {
                continue;
            }

            $amount = 0.0;
            $count = 0;
            foreach ((array)$rows as $row) {
                $amount += (float)($row['amount'] ?? 0);
                $count++;
            }

            $summary[] = [
                'bucket' => (string)$bucket,
                'label' => \HelperFramework::labelFromKey((string)$bucket, '_'),
                'count' => $count,
                'amount' => $this->money($amount),
            ];
        }

        return $summary;
    }

    private function balanceSheetSourceKeys(): array
    {
        return [
            'fixed_assets',
            'current_assets',
            'prepayments_accrued_income',
            'creditors_within_one_year',
            'creditors_after_more_than_one_year',
            'equity_capital_reserves',
        ];
    }

    private function money(mixed $value): float
    {
        return round((float)$value, 2);
    }
}
