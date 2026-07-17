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

    public function calculate(
        int $companyId,
        int $accountingPeriodId,
        ?string $asAtDate = null,
        ?string $fromDate = null,
        ?array $depreciationPreview = null,
        ?array $prepaymentPreview = null
    ): array {
        $ledgerService = $this->ledgerService ?? new PeriodLedgerReadService();
        $scope = $ledgerService->scope($companyId, $accountingPeriodId, $asAtDate, $fromDate);
        $key = $scope->cacheKey() . ':' . md5(serialize([$depreciationPreview, $prepaymentPreview]));
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
        }
        foreach ((new DatedTaxTreatmentLedgerService())->fetch($scope) as $row) {
            if ($ctExpenseNominalId > 0
                && (int)($row['nominal_account_id'] ?? 0) === $ctExpenseNominalId) {
                continue;
            }
            $amount = (float)($row['total_debit'] ?? 0) - (float)($row['total_credit'] ?? 0);
            $journalDate = (string)($row['journal_date'] ?? '');
            $taxTreatment = (string)($rules->resolveTaxTreatment(
                $row,
                $journalDate,
                $journalDate
            )['tax_treatment'] ?? '');
            if ((string)($row['account_type'] ?? '') === 'income') {
                if ($taxTreatment === 'capital') {
                    // A credit income movement is negative on the signed
                    // debit-minus-credit bridge, removing the gain from
                    // taxable trading profit at its actual journal date.
                    $capital += $amount;
                }
                continue;
            }
            if ($taxTreatment === 'disallowable') {
                $disallowable += $amount;
            } elseif ($taxTreatment === 'capital') {
                $capital += $amount;
            } elseif ($taxTreatment === 'other') {
                $otherCount++;
            } elseif (!in_array($taxTreatment, ['allowable', 'disallowable', 'capital', 'other'], true)) {
                $unknownCount++;
            }
        }
        $postedOperatingExpenses = $operatingExpenses;
        $closePreview = new YearEndClosePreviewService();
        $prepaymentExpenseAdjustment = 0.0;
        $prepaymentPreviewReliable = true;
        $prepaymentPreviewWarnings = [];
        if ($prepaymentPreview === null) {
            $prepaymentContext = (new PrepaymentScheduleService())
                ->fetchPreviewAdjustmentContext($companyId, $accountingPeriodId);
            $prepaymentPreview = (array)($prepaymentContext['adjustments'] ?? []);
            $prepaymentPreviewReliable = !empty($prepaymentContext['success']);
            $prepaymentPreviewWarnings = array_values(array_unique(array_map(
                'strval',
                (array)($prepaymentContext['errors'] ?? [])
            )));
        }
        $prepaymentExpenseRows = $closePreview->prepaymentExpenseRowsForPeriod(
            $companyId,
            $accountingPeriodId,
            $scope->periodStart,
            $scope->asAtDate,
            $prepaymentPreview
        );
        foreach ($prepaymentExpenseRows as $row) {
            $amount = (float)($row['amount'] ?? 0);
            $prepaymentExpenseAdjustment += $amount;
            if ((string)($row['account_type'] ?? '') === 'cost_of_sales') {
                $costOfSales += $amount;
            } else {
                $operatingExpenses += $amount;
            }
            $journalDate = (string)($row['journal_date'] ?? '');
            $taxTreatment = (string)($rules->resolveTaxTreatment(
                $row,
                $journalDate,
                $journalDate
            )['tax_treatment'] ?? '');
            if ($taxTreatment === 'disallowable') {
                $disallowable += $amount;
            } elseif ($taxTreatment === 'capital') {
                $capital += $amount;
            }
        }
        $depreciationPreview ??= (new AssetService())->previewDepreciationRun($companyId, $accountingPeriodId);
        $depreciationExpenseRows = $closePreview->depreciationRowsForPeriod(
            $companyId,
            $accountingPeriodId,
            $scope->periodStart,
            $scope->asAtDate,
            $depreciationPreview
        );
        $depreciation = 0.0;
        foreach ($depreciationExpenseRows as $row) {
            $depreciation = round($depreciation + (float)($row['amount'] ?? 0), 2);
        }
        $depreciation = round(max(0.0, $depreciation), 2);
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
            'prepayment_expense_rows' => $prepaymentExpenseRows,
            'prepayment_preview_reliable' => $prepaymentPreviewReliable,
            'prepayment_preview_warnings' => $prepaymentPreviewWarnings,
            'depreciation_expense' => round($depreciation, 2),
            'depreciation_expense_rows' => $depreciationExpenseRows,
            'depreciation_preview' => $depreciationPreview,
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
