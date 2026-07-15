<?php
/** Pure accounting oracle. It deliberately has no eel_accounts or InterfaceDB dependencies. */
declare(strict_types=1);

final class GoldenAccountingOracle
{
    /**
     * Independently derives accounting-period prepayment allocations and their
     * resulting P&L and current-asset amounts from immutable test facts.
     *
     * @param array<string, mixed> $variant
     * @return array{total_days: int, allocations: array<int, array<string, int|string>>}
     */
    public static function prepaymentSchedule(array $variant): array
    {
        $totalPence = (int)($variant['total_pence'] ?? 0);
        $serviceStart = self::prepaymentDate((string)($variant['service_start_date'] ?? ''), 'service start');
        $serviceEnd = self::prepaymentDate((string)($variant['service_end_date'] ?? ''), 'service end');
        $totalDays = (int)$serviceStart->diff($serviceEnd->modify('+1 day'))->days;
        if ($totalPence <= 0 || $serviceEnd < $serviceStart || $totalDays <= 0) {
            throw new InvalidArgumentException('Invalid golden prepayment variant.');
        }

        $periods = (array)($variant['periods'] ?? []);
        usort($periods, static fn(array $left, array $right): int => strcmp(
            (string)($left['period_start'] ?? ''),
            (string)($right['period_start'] ?? '')
        ));

        $allocations = [];
        $allocationIndex = 0;
        foreach ($periods as $period) {
            $periodStart = self::prepaymentDate((string)($period['period_start'] ?? ''), 'period start');
            $periodEnd = self::prepaymentDate((string)($period['period_end'] ?? ''), 'period end');
            $overlapStart = $periodStart > $serviceStart ? $periodStart : $serviceStart;
            $overlapEnd = $periodEnd < $serviceEnd ? $periodEnd : $serviceEnd;
            if ($overlapEnd < $overlapStart) {
                continue;
            }

            $daysBeforeOverlap = (int)$serviceStart->diff($overlapStart)->days;
            $elapsedThroughOverlap = (int)$serviceStart->diff($overlapEnd->modify('+1 day'))->days;
            $recognisedBefore = self::roundPositiveFractionHalfUp($totalPence, $daysBeforeOverlap, $totalDays);
            $recognisedThrough = self::roundPositiveFractionHalfUp($totalPence, $elapsedThroughOverlap, $totalDays);
            $expensePence = $recognisedThrough - $recognisedBefore;
            $closingDeferredPence = $totalPence - $recognisedThrough;
            $isInitialPeriod = $allocationIndex === 0;

            $allocations[(int)($period['id'] ?? $period['accounting_period_id'] ?? 0)] = [
                'overlap_start' => $overlapStart->format('Y-m-d'),
                'overlap_end' => $overlapEnd->format('Y-m-d'),
                'overlap_days' => (int)$overlapStart->diff($overlapEnd->modify('+1 day'))->days,
                'expense_pence' => $expensePence,
                'closing_deferred_pence' => $closingDeferredPence,
                'posting_type' => $isInitialPeriod ? 'deferral' : 'release',
                'posting_pence' => $isInitialPeriod ? $closingDeferredPence : $expensePence,
                'p_and_l_expense_pence' => $isInitialPeriod ? $totalPence - $closingDeferredPence : $expensePence,
                'prepayment_asset_pence' => $closingDeferredPence,
            ];
            $allocationIndex++;
        }

        return ['total_days' => $totalDays, 'allocations' => $allocations];
    }

    private static function roundPositiveFractionHalfUp(int $amount, int $numerator, int $denominator): int
    {
        return intdiv(($amount * $numerator) + intdiv($denominator, 2), $denominator);
    }

    private static function prepaymentDate(string $value, string $label): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Invalid golden prepayment ' . $label . '.');
        }

        return $date;
    }

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
        $ledgerExpenses = (float)$accounts['overheads']['debit']
            + (float)($accounts['hmrc_penalty']['debit'] ?? 0)
            + (float)($accounts['hmrc_interest']['debit'] ?? 0)
            + (float)($accounts['prepayment_expense']['net'] ?? 0);
        $depreciation = self::depreciationPreviewForPeriod($periodId);
        $expenses = round($ledgerExpenses + $depreciation, 2);
        $profit = round($income - $costOfSales - $expenses, 2);
        $disallowableAddBack = round((float)($period['disallowable_add_back'] ?? 0), 2);
        $taxableProfit = round(max(0, $profit + $disallowableAddBack + $depreciation), 2);
        $tax = round($taxableProfit * (float)$period['tax_rate'], 2);

        $closingAccounts = self::accountsThroughPeriod($periodId);
        $fixedAssets = round((float)($closingAccounts['fixed_assets']['net'] ?? 0) - $depreciation, 2);
        $hmrcPayable = (float)($closingAccounts['hmrc_payable']['net'] ?? 0);
        $directorLoan = (float)($closingAccounts['director_loan']['net'] ?? 0);
        $currentAssets = round(
            (float)($closingAccounts['bank']['net'] ?? 0)
            + (float)($closingAccounts['prepaid_expenses']['net'] ?? 0)
            + max(0, $hmrcPayable)
            + max(0, $directorLoan),
            2
        );
        $creditorsWithinOneYear = round(max(0, -$directorLoan) + max(0, -$hmrcPayable), 2);
        $creditorsAfterOneYear = 0.00;
        $netCurrentAssets = round($currentAssets - $creditorsWithinOneYear, 2);
        $totalAssetsLessCurrentLiabilities = round($fixedAssets + $netCurrentAssets, 2);
        $totalNetAssets = round($totalAssetsLessCurrentLiabilities - $creditorsAfterOneYear, 2);
        $explicitEquity = round(-(float)($closingAccounts['equity']['net'] ?? 0), 2);
        $capitalAndReserves = round($explicitEquity + $profit, 2);
        $balanceEquationDifference = round($totalNetAssets - $capitalAndReserves, 2);
        $hasFixedAssets = (float)($closingAccounts['fixed_assets']['net'] ?? 0) > 0.004;

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
                'depreciation_add_back' => $depreciation,
                'capital_allowances' => 0.00,
                'taxable_before_losses' => $taxableProfit,
                'taxable_profit' => $taxableProfit,
                'taxable_loss' => 0.00,
                'estimated_corporation_tax' => $tax,
                'estimated_rate' => (float)$period['tax_rate'],
                'losses_used' => 0.00,
                'warning_count' => 2 + (int)($hasFixedAssets && $depreciation < 0.005),
            ],
            'companies_house' => [
                'company_name' => 'Golden Electrical Test Limited',
                'company_number' => 'T9100',
                'fixed_assets' => $fixedAssets,
                'current_assets' => $currentAssets,
                'creditors_within_one_year' => $creditorsWithinOneYear,
                'creditors_after_more_than_one_year' => $creditorsAfterOneYear,
                'net_current_assets_liabilities' => $netCurrentAssets,
                'total_assets_less_current_liabilities' => $totalAssetsLessCurrentLiabilities,
                'net_assets_liabilities' => $totalNetAssets,
                'equity_capital_reserves' => $capitalAndReserves,
                'balance_equation_difference' => $balanceEquationDifference,
                'is_balance_sheet_balanced' => abs($balanceEquationDifference) < 0.005,
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

    /** @return array<string, array{debit: float, credit: float, net: float}> */
    private static function accountsThroughPeriod(int $periodId): array
    {
        $accounts = [];
        foreach (GoldenLedgerSpecification::periods() as $candidatePeriodId => $period) {
            foreach ((array)($period['journals'] ?? []) as $journal) {
                $amount = round((float)($journal['amount'] ?? 0), 2);
                $debit = (string)($journal['debit'] ?? '');
                $credit = (string)($journal['credit'] ?? '');
                $accounts[$debit]['debit'] = round((float)($accounts[$debit]['debit'] ?? 0) + $amount, 2);
                $accounts[$credit]['credit'] = round((float)($accounts[$credit]['credit'] ?? 0) + $amount, 2);
            }

            if ($candidatePeriodId === $periodId) {
                break;
            }
        }

        foreach ($accounts as $key => $values) {
            $debit = round((float)($values['debit'] ?? 0), 2);
            $credit = round((float)($values['credit'] ?? 0), 2);
            $accounts[$key] = [
                'debit' => $debit,
                'credit' => $credit,
                'net' => round($debit - $credit, 2),
            ];
        }

        return $accounts;
    }

    /**
     * Derive the unposted straight-line charge from immutable asset facts.
     * The clean golden fixture has no brought-forward depreciation entries, so
     * each open period is intentionally previewed from a zero posted opening.
     */
    private static function depreciationPreviewForPeriod(int $periodId): float
    {
        $periods = GoldenLedgerSpecification::periods();
        $period = $periods[$periodId] ?? null;
        if (!is_array($period)) {
            return 0.0;
        }

        $periodEnd = self::prepaymentDate((string)$period['end'], 'accounting period end');
        if (new DateTimeImmutable('today') <= $periodEnd) {
            return 0.0;
        }

        $assets = [];
        foreach ($periods as $candidatePeriodId => $candidatePeriod) {
            array_push($assets, ...(array)($candidatePeriod['asset_purchases'] ?? []));
            if ($candidatePeriodId === $periodId) {
                break;
            }
        }

        $periodStart = self::prepaymentDate((string)$period['start'], 'accounting period start');
        $total = 0.0;
        foreach ($assets as $asset) {
            $purchaseDate = self::prepaymentDate((string)($asset['purchase_date'] ?? ''), 'asset purchase date');
            $lifeYears = max(1, (int)($asset['life_years'] ?? 1));
            $lifeEnd = $purchaseDate->modify('+' . $lifeYears . ' years')->modify('-1 day');
            $depreciationStart = $periodStart > $purchaseDate ? $periodStart : $purchaseDate;
            $depreciationEnd = $periodEnd < $lifeEnd ? $periodEnd : $lifeEnd;
            if ($depreciationEnd < $depreciationStart) {
                continue;
            }

            $days = (int)$depreciationStart->diff($depreciationEnd->modify('+1 day'))->days;
            $calendarYearStart = new DateTimeImmutable($depreciationStart->format('Y-01-01'));
            $calendarYearEnd = new DateTimeImmutable($depreciationStart->format('Y-12-31'));
            $yearDays = (int)$calendarYearStart->diff($calendarYearEnd->modify('+1 day'))->days;
            $cost = round((float)($asset['cost'] ?? 0), 2);
            $residual = round((float)($asset['residual_value'] ?? 0), 2);
            $depreciableAmount = max(0.0, $cost - $residual);
            $annualAmount = $depreciableAmount / $lifeYears;
            $total = round($total + round(min($depreciableAmount, $annualAmount * ($days / max(365, $yearDays))), 2), 2);
        }

        return $total;
    }
}
