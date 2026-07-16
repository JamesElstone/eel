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

        $periodResult = self::periodFinancialResult($periodId);
        $income = $periodResult['income'];
        $costOfSales = $periodResult['cost_of_sales'];
        $ledgerExpenses = $periodResult['ledger_expenses'];
        $depreciation = $periodResult['depreciation'];
        $expenses = $periodResult['operating_expenses'];
        $profit = $periodResult['profit_before_tax'];
        $disallowableAddBack = round((float)($period['disallowable_add_back'] ?? 0), 2);
        $taxResult = (array)(self::hmrcTaxSequence()[$periodId] ?? []);
        $capitalAllowances = round((float)($taxResult['capital_allowances'] ?? 0), 2);
        $taxableBeforeLosses = round((float)($taxResult['taxable_before_losses'] ?? 0), 2);
        $taxableProfit = round((float)($taxResult['taxable_profit'] ?? 0), 2);
        $taxableLoss = round(max(0.0, 0 - $taxableBeforeLosses), 2);
        $tax = round((float)($taxResult['corporation_tax'] ?? 0), 2);
        $estimatedRate = $taxableProfit > 0
            ? round($tax / $taxableProfit, 6)
            : 0.0;
        $aiaAllocations = self::aiaAllocationsForPeriod($periodId);
        $poolSummaries = self::capitalAllowancePoolSummaries($capitalAllowances);

        $closingAccounts = self::accountsThroughPeriod($periodId);
        $cumulativeDepreciation = self::cumulativeDepreciationThroughPeriod($periodId);
        $cumulativeCorporationTax = self::cumulativeCorporationTaxThroughPeriod($periodId);
        $fixedAssets = round((float)($closingAccounts['fixed_assets']['net'] ?? 0) - $cumulativeDepreciation, 2);
        $hmrcPayable = (float)($closingAccounts['hmrc_payable']['net'] ?? 0);
        $directorLoan = (float)($closingAccounts['director_loan']['net'] ?? 0);
        $currentAssets = round(
            (float)($closingAccounts['bank']['net'] ?? 0)
            + (float)($closingAccounts['prepaid_expenses']['net'] ?? 0)
            + max(0, $hmrcPayable)
            + max(0, $directorLoan),
            2
        );
        $creditorsWithinOneYear = round(
            max(0, -$directorLoan)
                + max(0, -$hmrcPayable)
                + $cumulativeCorporationTax,
            2
        );
        $creditorsAfterOneYear = 0.00;
        $netCurrentAssets = round($currentAssets - $creditorsWithinOneYear, 2);
        $totalAssetsLessCurrentLiabilities = round($fixedAssets + $netCurrentAssets, 2);
        $totalNetAssets = round($totalAssetsLessCurrentLiabilities - $creditorsAfterOneYear, 2);
        $explicitEquity = round(-(float)($closingAccounts['equity']['net'] ?? 0), 2);
        $capitalAndReserves = round(
            $explicitEquity
                + self::cumulativeProfitThroughPeriod($periodId)
                - $cumulativeCorporationTax,
            2
        );
        $balanceEquationDifference = round($totalNetAssets - $capitalAndReserves, 2);
        $hasFixedAssets = (float)($closingAccounts['fixed_assets']['net'] ?? 0) > 0.004;
        $warningMessages = [];
        if ($tax > 0) {
            $warningMessages[] = 'Corporation Tax estimate assumes non-ring-fence profits.';
            $warningMessages[] = 'Corporation Tax estimate assumes augmented profits equal taxable profits; review if exempt distributions were received.';
        }
        if ($hasFixedAssets && $depreciation < 0.005 && $capitalAllowances < 0.005) {
            array_unshift(
                $warningMessages,
                'Fixed assets exist, but no depreciation entries or capital allowance runs were found.'
            );
        }

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
                'capital_allowances' => $capitalAllowances,
                'taxable_before_losses' => $taxableBeforeLosses,
                'taxable_profit' => $taxableProfit,
                'taxable_loss' => $taxableLoss,
                'estimated_corporation_tax' => $tax,
                'estimated_rate' => $estimatedRate,
                'losses_brought_forward' => round((float)($taxResult['losses_brought_forward'] ?? 0), 2),
                'losses_used' => round((float)($taxResult['losses_used'] ?? 0), 2),
                'losses_carried_forward' => round((float)($taxResult['losses_carried_forward'] ?? 0), 2),
                'depreciation_row_count' => self::depreciationPreviewRowCount($periodId),
                'aia_allocations' => $aiaAllocations,
                'pool_summaries' => $poolSummaries,
                'warning_count' => count($warningMessages),
                'warning_messages' => $warningMessages,
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
     * Derive the period's straight-line charge from immutable asset facts.
     * Each endpoint is rounded independently, so adjacent periods telescope to
     * the exact cumulative useful-life target without calendar-year distortion.
     */
    private static function depreciationPreviewForPeriod(int $periodId): float
    {
        $periods = GoldenLedgerSpecification::periods();
        $period = $periods[$periodId] ?? null;
        if (!is_array($period) || empty($period['audit_complete'])) {
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
        $periodEnd = self::prepaymentDate((string)$period['end'], 'accounting period end');
        $openingDate = $periodStart->modify('-1 day');
        $total = 0.0;
        foreach ($assets as $asset) {
            $closingTarget = self::assetCumulativeDepreciationAt($asset, $periodEnd);
            $openingTarget = self::assetCumulativeDepreciationAt($asset, $openingDate);
            $total = round($total + max(0.0, $closingTarget - $openingTarget), 2);
        }

        return $total;
    }

    /** @return array{income: float, cost_of_sales: float, ledger_expenses: float, depreciation: float, operating_expenses: float, profit_before_tax: float} */
    private static function periodFinancialResult(int $periodId): array
    {
        $period = GoldenLedgerSpecification::periods()[$periodId] ?? null;
        if (!is_array($period)) {
            throw new InvalidArgumentException('Unknown golden accounting period: ' . $periodId);
        }

        $balances = [];
        foreach ((array)($period['journals'] ?? []) as $journal) {
            $amount = round((float)($journal['amount'] ?? 0), 2);
            $debit = (string)($journal['debit'] ?? '');
            $credit = (string)($journal['credit'] ?? '');
            $balances[$debit] = round((float)($balances[$debit] ?? 0) + $amount, 2);
            $balances[$credit] = round((float)($balances[$credit] ?? 0) - $amount, 2);
        }

        $income = round(0 - (float)($balances['sales'] ?? 0), 2);
        $costOfSales = round((float)($balances['materials'] ?? 0), 2);
        $ledgerExpenses = round(
            (float)($balances['overheads'] ?? 0)
            + (float)($balances['hmrc_penalty'] ?? 0)
            + (float)($balances['hmrc_interest'] ?? 0)
            + (float)($balances['prepayment_expense'] ?? 0),
            2
        );
        $depreciation = self::depreciationPreviewForPeriod($periodId);
        $operatingExpenses = round($ledgerExpenses + $depreciation, 2);

        return [
            'income' => $income,
            'cost_of_sales' => $costOfSales,
            'ledger_expenses' => $ledgerExpenses,
            'depreciation' => $depreciation,
            'operating_expenses' => $operatingExpenses,
            'profit_before_tax' => round($income - $costOfSales - $operatingExpenses, 2),
        ];
    }

    private static function assetCumulativeDepreciationAt(array $asset, DateTimeImmutable $referenceEnd): float
    {
        $purchaseDate = self::prepaymentDate((string)($asset['purchase_date'] ?? ''), 'asset purchase date');
        if ($referenceEnd < $purchaseDate) {
            return 0.0;
        }

        $lifeYears = max(1, (int)($asset['life_years'] ?? 1));
        $lifeEnd = $purchaseDate->modify('+' . $lifeYears . ' years')->modify('-1 day');
        $boundedEnd = $referenceEnd < $lifeEnd ? $referenceEnd : $lifeEnd;
        $lifeDays = (int)$purchaseDate->diff($lifeEnd->modify('+1 day'))->days;
        $elapsedDays = (int)$purchaseDate->diff($boundedEnd->modify('+1 day'))->days;
        $cost = round((float)($asset['cost'] ?? 0), 2);
        $residual = round((float)($asset['residual_value'] ?? 0), 2);
        $depreciableAmount = max(0.0, $cost - $residual);

        return round(min($depreciableAmount, $depreciableAmount * ($elapsedDays / max(1, $lifeDays))), 2);
    }

    private static function cumulativeDepreciationThroughPeriod(int $periodId): float
    {
        $total = 0.0;
        foreach (GoldenLedgerSpecification::periods() as $candidatePeriodId => $_period) {
            $total = round($total + self::depreciationPreviewForPeriod((int)$candidatePeriodId), 2);
            if ($candidatePeriodId === $periodId) {
                break;
            }
        }

        return $total;
    }

    private static function cumulativeProfitThroughPeriod(int $periodId): float
    {
        $total = 0.0;
        foreach (GoldenLedgerSpecification::periods() as $candidatePeriodId => $_period) {
            $total = round(
                $total + self::periodFinancialResult((int)$candidatePeriodId)['profit_before_tax'],
                2
            );
            if ($candidatePeriodId === $periodId) {
                break;
            }
        }

        return $total;
    }

    /** @return array<int, array<string, float>> */
    private static function hmrcTaxSequence(): array
    {
        static $sequence = null;
        if ($sequence === null) {
            $sequence = GoldenHmrcCorporationTaxOracle::calculateSequence(
                GoldenLedgerSpecification::hmrcTaxFacts()
            );
        }

        return $sequence;
    }

    private static function cumulativeCorporationTaxThroughPeriod(int $periodId): float
    {
        $total = 0.0;
        foreach (self::hmrcTaxSequence() as $candidatePeriodId => $taxResult) {
            $total = round($total + (float)($taxResult['corporation_tax'] ?? 0), 2);
            if ($candidatePeriodId === $periodId) {
                break;
            }
        }

        return $total;
    }

    /** @return list<array{asset_code: string, purchase_date: string, addition_amount: float, allowance_amount: float}> */
    private static function aiaAllocationsForPeriod(int $periodId): array
    {
        $period = GoldenLedgerSpecification::periods()[$periodId] ?? null;
        if (!is_array($period)) {
            return [];
        }

        $rows = [];
        foreach ((array)($period['asset_purchases'] ?? []) as $asset) {
            if (!in_array((string)($asset['category'] ?? ''), ['tools_equipment', 'plant_machinery', 'van'], true)) {
                continue;
            }
            $amount = round((float)($asset['cost'] ?? 0), 2);
            $rows[] = [
                'asset_code' => (string)($asset['code'] ?? ''),
                'purchase_date' => (string)($asset['purchase_date'] ?? ''),
                'addition_amount' => $amount,
                'allowance_amount' => $amount,
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            $date = strcmp($left['purchase_date'], $right['purchase_date']);
            return $date !== 0 ? $date : strcmp($left['asset_code'], $right['asset_code']);
        });

        return $rows;
    }

    /** @return array<string, array<string, float|string>> */
    private static function capitalAllowancePoolSummaries(float $capitalAllowances): array
    {
        $empty = [
            'opening_wdv' => 0.0,
            'additions' => 0.0,
            'aia_claimed' => 0.0,
            'fya_claimed' => 0.0,
            'disposal_value' => 0.0,
            'wda_claimed' => 0.0,
            'balancing_charge' => 0.0,
            'balancing_allowance' => 0.0,
            'closing_wdv' => 0.0,
        ];

        return [
            'main_rate_pool' => ['pool_type' => 'main_pool'] + array_replace(
                $empty,
                ['aia_claimed' => round($capitalAllowances, 2)]
            ),
            'special_rate_pool' => ['pool_type' => 'special_rate_pool'] + $empty,
        ];
    }

    private static function depreciationPreviewRowCount(int $periodId): int
    {
        $periods = GoldenLedgerSpecification::periods();
        $period = $periods[$periodId] ?? null;
        if (!is_array($period) || empty($period['audit_complete'])) {
            return 0;
        }

        $periodStart = self::prepaymentDate((string)$period['start'], 'accounting period start');
        $periodEnd = self::prepaymentDate((string)$period['end'], 'accounting period end');
        $count = 0;
        foreach ($periods as $candidatePeriodId => $candidatePeriod) {
            foreach ((array)($candidatePeriod['asset_purchases'] ?? []) as $asset) {
                $purchaseDate = self::prepaymentDate((string)($asset['purchase_date'] ?? ''), 'asset purchase date');
                $lifeEnd = $purchaseDate
                    ->modify('+' . max(1, (int)($asset['life_years'] ?? 1)) . ' years')
                    ->modify('-1 day');
                if ($purchaseDate <= $periodEnd && $lifeEnd >= $periodStart) {
                    $count++;
                }
            }
            if ($candidatePeriodId === $periodId) {
                break;
            }
        }

        return $count;
    }
}
