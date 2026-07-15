<?php
declare(strict_types=1);

namespace eel_accounts\Service;

final class PreTaxProfitLossService
{
    /** @var array<string, array<string, mixed>> Request-local cache only. */
    private array $results = [];

    public function __construct(private readonly ?PeriodLedgerReadService $ledgerService = null)
    {
    }

    public function calculate(int $companyId, int $accountingPeriodId, ?string $asAtDate = null, ?string $fromDate = null): array
    {
        $ledgerService = $this->ledgerService ?? new PeriodLedgerReadService();
        $scope = $ledgerService->scope($companyId, $accountingPeriodId, $asAtDate, $fromDate);
        $key = $scope->cacheKey();
        if (isset($this->results[$key])) {
            return $this->results[$key];
        }
        $dataset = $ledgerService->fetch($scope);
        $settings = (new \eel_accounts\Store\CompanySettingsStore($companyId))->all();
        $ctExpenseNominalId = (int)($settings['corporation_tax_expense_nominal_id'] ?? 0);
        $rules = new CorporationTaxTreatmentRuleService();
        $income = 0.0;
        $costOfSales = 0.0;
        $operatingExpenses = 0.0;
        $postedCt = 0.0;
        $disallowable = 0.0;
        $capital = 0.0;
        $otherCount = 0;
        $unknownCount = 0;

        foreach ($dataset->rows as $row) {
            $type = (string)($row['account_type'] ?? '');
            $debit = (float)($row['total_debit'] ?? 0);
            $credit = (float)($row['total_credit'] ?? 0);
            if ($type === 'income') {
                $income += $credit - $debit;
                continue;
            }
            $amount = $debit - $credit;
            if ($type === 'expense' && $ctExpenseNominalId > 0 && (int)($row['nominal_account_id'] ?? 0) === $ctExpenseNominalId) {
                $postedCt += $amount;
                continue;
            }
            if ($type === 'cost_of_sales') {
                $costOfSales += $amount;
            } else {
                $operatingExpenses += $amount;
            }
            $taxTreatment = (string)($rules->resolveTaxTreatment($row, $scope->periodStart, $scope->asAtDate)['tax_treatment'] ?? '');
            if ($taxTreatment === 'disallowable') {
                $disallowable += abs($amount);
            } elseif ($taxTreatment === 'capital') {
                $capital += abs($amount);
            } elseif ($taxTreatment === 'other') {
                $otherCount++;
            } elseif (!in_array($taxTreatment, ['allowable', 'disallowable', 'capital', 'other'], true)) {
                $unknownCount++;
            }
        }
        $postedOperatingExpenses = $operatingExpenses;
        $closePreview = new YearEndClosePreviewService();
        $prepaymentExpenseAdjustment = 0.0;
        foreach ($closePreview->prepaymentExpenseRowsForPeriod(
            $companyId,
            $accountingPeriodId,
            $scope->periodStart,
            $scope->asAtDate
        ) as $row) {
            $amount = (float)($row['amount'] ?? 0);
            $prepaymentExpenseAdjustment += $amount;
            if ((string)($row['account_type'] ?? '') === 'cost_of_sales') {
                $costOfSales += $amount;
            } else {
                $operatingExpenses += $amount;
            }
            $taxTreatment = (string)($row['tax_treatment'] ?? '');
            if ($taxTreatment === 'disallowable') {
                $disallowable += $amount;
            } elseif ($taxTreatment === 'capital') {
                $capital += $amount;
            }
        }
        $depreciation = $closePreview->depreciationExpenseForPeriod(
            $companyId,
            $accountingPeriodId,
            $scope->periodStart,
            $scope->asAtDate
        );
        $operatingExpenses = round($operatingExpenses + $depreciation, 2);
        $income = round($income, 2);
        $costOfSales = round($costOfSales, 2);
        $grossProfit = round($income - $costOfSales, 2);
        $profitBeforeTax = round($grossProfit - $operatingExpenses, 2);

        return $this->results[$key] = [
            'scope' => $scope,
            'dataset' => $dataset,
            'income_total' => $income,
            'cost_of_sales_total' => $costOfSales,
            'gross_profit' => $grossProfit,
            'posted_operating_expense_total' => round($postedOperatingExpenses, 2),
            'prepayment_expense_adjustment' => round($prepaymentExpenseAdjustment, 2),
            'depreciation_expense' => round($depreciation, 2),
            'operating_expense_total' => $operatingExpenses,
            'posted_corporation_tax_charge' => round($postedCt, 2),
            'profit_before_tax' => $profitBeforeTax,
            'disallowable_add_backs' => round($disallowable, 2),
            'capital_add_backs' => round($capital, 2),
            'other_treatment_count' => $otherCount,
            'unknown_treatment_count' => $unknownCount,
            'journal_count' => $dataset->journalCount,
        ];
    }

    public function clearRuntimeCache(): void
    {
        $this->results = [];
        ($this->ledgerService ?? new PeriodLedgerReadService())->clearRuntimeCache();
    }
}
