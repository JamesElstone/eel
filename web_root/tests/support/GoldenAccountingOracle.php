<?php
/** Pure accounting oracle. It deliberately has no eel_accounts or InterfaceDB dependencies. */
declare(strict_types=1);

final class GoldenAccountingOracle
{
    /** @return array<string, mixed> */
    public static function expected(int $periodId): array
    {
        $period = GoldenLedgerSpecification::periods()[$periodId] ?? null;
        if (!is_array($period)) {
            throw new InvalidArgumentException('Unknown golden accounting period: ' . $periodId);
        }

        $accounts = [];
        $totalDebits = 0.0;
        $totalCredits = 0.0;
        foreach ($period['journals'] as $journal) {
            $amount = round((float)$journal['amount'], 2);
            $debit = (string)$journal['debit'];
            $credit = (string)$journal['credit'];
            $accounts[$debit]['debit'] = round((float)($accounts[$debit]['debit'] ?? 0) + $amount, 2);
            $accounts[$credit]['credit'] = round((float)($accounts[$credit]['credit'] ?? 0) + $amount, 2);
            $totalDebits += $amount;
            $totalCredits += $amount;
        }

        foreach ($accounts as $key => $values) {
            $accounts[$key] = [
                'debit' => round((float)($values['debit'] ?? 0), 2),
                'credit' => round((float)($values['credit'] ?? 0), 2),
                'net' => round((float)($values['debit'] ?? 0) - (float)($values['credit'] ?? 0), 2),
            ];
        }

        $income = (float)$accounts['sales']['credit'];
        $costOfSales = (float)$accounts['materials']['debit'];
        $expenses = (float)$accounts['overheads']['debit']
            + (float)($accounts['hmrc_penalty']['debit'] ?? 0)
            + (float)($accounts['hmrc_interest']['debit'] ?? 0);
        $profit = round($income - $costOfSales - $expenses, 2);
        $disallowableAddBack = round((float)($period['disallowable_add_back'] ?? 0), 2);
        $taxableProfit = round(max(0, $profit + $disallowableAddBack), 2);
        $tax = round($taxableProfit * (float)$period['tax_rate'], 2);
        $fixedAssets = (float)($accounts['fixed_assets']['net'] ?? 0);

        $trialDebits = 0.0;
        $trialCredits = 0.0;
        foreach ($accounts as $account) {
            $net = (float)$account['net'];
            $trialDebits += max(0, $net);
            $trialCredits += max(0, -$net);
        }
        $periodIds = array_keys(GoldenLedgerSpecification::periods());
        $periodSequence = array_search($periodId, $periodIds, true);
        $directorLoanOpening = ($periodSequence === false ? 0 : $periodSequence) * 300.00;

        return [
            'period_start' => $period['start'],
            'period_end' => $period['end'],
            'journal_count' => count($period['journals']),
            'transaction_count' => $period['transactions'],
            'expense_claim_count' => $period['expense_claims'],
            'trial_balance' => [
                'total_debits' => round($trialDebits, 2),
                'total_credits' => round($trialCredits, 2),
                'difference' => round($trialDebits - $trialCredits, 2),
                'accounts' => $accounts,
            ],
            'journals' => ['total_debits' => round($totalDebits, 2), 'total_credits' => round($totalCredits, 2)],
            'profit_loss' => [
                'income' => $income,
                'cost_of_sales' => $costOfSales,
                'operating_expenses' => $expenses,
                'gross_profit' => round($income - $costOfSales, 2),
                'profit_before_tax' => $profit,
                'estimated_corporation_tax' => $tax,
                'profit_after_estimated_tax' => round($profit - $tax, 2),
            ],
            'corporation_tax' => [
                'accounting_profit' => $profit,
                'disallowable_add_backs' => $disallowableAddBack,
                'depreciation_add_back' => 0.00,
                'capital_allowances' => 0.00,
                'taxable_before_losses' => $taxableProfit,
                'taxable_profit' => $taxableProfit,
                'taxable_loss' => 0.00,
                'estimated_corporation_tax' => $tax,
                'estimated_rate' => (float)$period['tax_rate'],
                'losses_used' => 0.00,
                'warning_count' => 2,
            ],
            'companies_house' => [
                'company_name' => 'Golden Electrical Test Limited',
                'company_number' => 'T9100',
                'fixed_assets' => $fixedAssets,
                'current_assets' => (float)$accounts['bank']['net'] + max(0, (float)($accounts['hmrc_payable']['net'] ?? 0)),
                'creditors_within_one_year' => (float)-$accounts['director_loan']['net'] + max(0, -(float)($accounts['hmrc_payable']['net'] ?? 0)),
                'creditors_after_more_than_one_year' => 0.00,
                'net_current_assets_liabilities' => $profit,
                'total_assets_less_current_liabilities' => $profit,
                'net_assets_liabilities' => $profit,
                'equity_capital_reserves' => $profit,
                'balance_equation_difference' => 0.00,
                'is_balance_sheet_balanced' => true,
                'stored_filing_available' => false,
            ],
            'assets' => $period['asset_purchases'],
            'director_loan' => [
                'opening' => $directorLoanOpening,
                'movement' => 300.00,
                'closing' => $directorLoanOpening + 300.00,
            ],
        ];
    }
}
