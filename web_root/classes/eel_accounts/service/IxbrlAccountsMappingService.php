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
        bool $includeYearEndCardPreviews = false,
        ?array $depreciationPreview = null,
        ?array $prepaymentPreview = null
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
        $explicitEquity = 0.0;

        foreach ($trialBalance as $row) {
            $accountType = (string)($row['account_type'] ?? '');
            $subtype = (string)($row['subtype_code'] ?? '');
            $name = trim((string)($row['code'] ?? '') . ' ' . (string)($row['name'] ?? ''));
            $net = round((float)($row['net_movement'] ?? 0), 2);
            $debit = (float)($row['total_debit'] ?? 0);
            $credit = (float)($row['total_credit'] ?? 0);

            if ($accountType === 'equity') {
                $amount = round($credit - $debit, 2);
                $explicitEquity += $amount;
                $this->addSource($sources, 'explicit_equity', $name, $amount);
            }
        }

        // PeriodLedgerReadService excludes the posted retained-earnings close,
        // so a locked period retains its original income-statement movements.
        $period = \InterfaceDB::fetchOne(
            'SELECT period_start, period_end
             FROM accounting_periods
             WHERE id = :id AND company_id = :company_id
             LIMIT 1',
            ['id' => $accountingPeriodId, 'company_id' => $companyId]
        );
        $profitAndLoss = is_array($period)
            ? (new PreTaxProfitLossService())->calculate(
                $companyId,
                $accountingPeriodId,
                (string)$period['period_end'],
                (string)$period['period_start'],
                $depreciationPreview,
                $prepaymentPreview
            )
            : [];
        $income = round((float)($profitAndLoss['income_total'] ?? 0), 2);
        $costOfSales = round((float)($profitAndLoss['cost_of_sales_total'] ?? 0), 2);
        $administrativeExpenses = round((float)($profitAndLoss['operating_expense_total'] ?? 0), 2);
        $taxOnProfit = round((float)($profitAndLoss['posted_corporation_tax_charge'] ?? 0), 2);
        $profitBeforeTax = round((float)($profitAndLoss['profit_before_tax'] ?? 0), 2);
        $settings = (new \eel_accounts\Store\CompanySettingsStore($companyId))->all();
        $corporationTaxExpenseNominalId = (int)($settings['corporation_tax_expense_nominal_id'] ?? 0);
        foreach ((array)(($profitAndLoss['dataset'] ?? null)?->rows ?? []) as $row) {
            $accountType = (string)($row['account_type'] ?? '');
            if (!in_array($accountType, ['income', 'cost_of_sales', 'expense'], true)) {
                continue;
            }
            $amount = $accountType === 'income'
                ? round((float)($row['total_credit'] ?? 0) - (float)($row['total_debit'] ?? 0), 2)
                : round((float)($row['total_debit'] ?? 0) - (float)($row['total_credit'] ?? 0), 2);
            $label = $this->ledgerSourceLabel($row);
            if ($accountType === 'expense'
                && $corporationTaxExpenseNominalId > 0
                && (int)($row['nominal_account_id'] ?? 0) === $corporationTaxExpenseNominalId) {
                $this->addSource(
                    $sources,
                    'tax_on_profit',
                    $label,
                    $amount,
                    [
                        'source_type' => 'posted_corporation_tax',
                        'nominal_account_id' => (int)($row['nominal_account_id'] ?? 0),
                    ]
                );
                continue;
            }
            $metadata = [
                'source_type' => 'posted_ledger',
                'nominal_account_id' => (int)($row['nominal_account_id'] ?? 0),
            ];
            if ($accountType === 'income') {
                $this->addSource(
                    $sources,
                    $this->isOtherIncomeRow($row) ? 'other_income' : 'turnover',
                    $label,
                    $amount,
                    $metadata
                );
                continue;
            }
            if ($accountType === 'cost_of_sales') {
                $this->addSource($sources, 'cost_of_sales', $label, $amount, $metadata);
                $this->addSource(
                    $sources,
                    $this->isRawMaterialsConsumablesRow($row) ? 'raw_materials_consumables' : 'other_charges',
                    $label,
                    $amount,
                    $metadata
                );
                continue;
            }
            $this->addSource($sources, 'administrative_expenses', $label, $amount, $metadata);
            $this->addSource($sources, $this->expenseMicroBucket($row), $label, $amount, $metadata);
        }
        foreach ((array)($profitAndLoss['prepayment_expense_rows'] ?? []) as $row) {
            $accountType = (string)($row['account_type'] ?? '');
            if (!in_array($accountType, ['cost_of_sales', 'expense'], true)) {
                continue;
            }
            $nominalLabel = trim((string)($row['code'] ?? '') . ' ' . (string)($row['name'] ?? ''));
            $journalDate = trim((string)($row['journal_date'] ?? ''));
            $label = 'Year End prepayment: ' . ($nominalLabel !== '' ? $nominalLabel : 'expense');
            if ($journalDate !== '') {
                $label .= ' (' . $journalDate . ')';
            }
            $amount = round((float)($row['amount'] ?? 0), 2);
            $metadata = [
                'source_type' => 'pending_prepayment',
                'nominal_account_id' => (int)($row['nominal_account_id'] ?? 0),
                'review_id' => (int)($row['review_id'] ?? 0),
                'schedule_id' => (int)($row['schedule_id'] ?? 0),
            ];
            if ($accountType === 'cost_of_sales') {
                $this->addSource($sources, 'cost_of_sales', $label, $amount, $metadata);
                $this->addSource(
                    $sources,
                    $this->isRawMaterialsConsumablesRow($row) ? 'raw_materials_consumables' : 'other_charges',
                    $label,
                    $amount,
                    $metadata
                );
                continue;
            }
            $this->addSource($sources, 'administrative_expenses', $label, $amount, $metadata);
            $this->addSource($sources, $this->expenseMicroBucket($row), $label, $amount, $metadata);
        }
        $depreciationExpense = round((float)($profitAndLoss['depreciation_expense'] ?? 0), 2);
        $depreciationRows = (array)($profitAndLoss['depreciation_expense_rows'] ?? []);
        foreach ($depreciationRows as $row) {
            $assetLabel = trim((string)($row['asset_code'] ?? ''));
            if ($assetLabel === '') {
                $assetId = (int)($row['asset_id'] ?? 0);
                $assetLabel = $assetId > 0 ? 'asset #' . $assetId : 'asset';
            }
            $this->addSource(
                $sources,
                'administrative_expenses',
                'Year End depreciation: ' . $assetLabel,
                round((float)($row['amount'] ?? 0), 2),
                [
                    'source_type' => !empty($row['is_pending']) ? 'pending_depreciation' : 'posted_depreciation',
                    'asset_id' => (int)($row['asset_id'] ?? 0),
                ]
            );
            $this->addSource(
                $sources,
                'depreciation_write_offs',
                'Year End depreciation: ' . $assetLabel,
                round((float)($row['amount'] ?? 0), 2),
                [
                    'source_type' => !empty($row['is_pending']) ? 'pending_depreciation' : 'posted_depreciation',
                    'asset_id' => (int)($row['asset_id'] ?? 0),
                ]
            );
        }
        if ($depreciationRows === [] && abs($depreciationExpense) >= 0.005) {
            $this->addSource(
                $sources,
                'administrative_expenses',
                'Year End depreciation expense',
                $depreciationExpense,
                ['source_type' => 'depreciation_summary']
            );
            $this->addSource(
                $sources,
                'depreciation_write_offs',
                'Year End depreciation expense',
                $depreciationExpense,
                ['source_type' => 'depreciation_summary']
            );
        }

        foreach (['fixed_assets', 'current_assets', 'prepayments_accrued_income', 'creditors_within_one_year', 'creditors_after_more_than_one_year', 'net_current_assets_liabilities', 'total_assets_less_current_liabilities', 'net_assets_liabilities', 'equity_capital_reserves', 'equity'] as $key) {
            $buckets[$key] = round((float)($closingBuckets[$key] ?? 0), 2);
        }
        $buckets['creditors_after_one_year'] = $buckets['creditors_after_more_than_one_year'];

        $turnover = $this->sourceTotal((array)($sources['turnover'] ?? []));
        $otherIncome = $this->sourceTotal((array)($sources['other_income'] ?? []));
        $rawMaterialsConsumables = $this->sourceTotal((array)($sources['raw_materials_consumables'] ?? []));
        $staffCosts = $this->sourceTotal((array)($sources['staff_costs'] ?? []));
        $depreciationWriteOffs = $this->sourceTotal((array)($sources['depreciation_write_offs'] ?? []));
        $otherCharges = $this->sourceTotal((array)($sources['other_charges'] ?? []));
        $this->assertComponentTotal('total income', $income, $turnover + $otherIncome);
        $this->assertComponentTotal(
            'total expenses',
            $costOfSales + $administrativeExpenses,
            $rawMaterialsConsumables + $staffCosts + $depreciationWriteOffs + $otherCharges
        );

        $buckets['turnover'] = $turnover;
        $buckets['other_income'] = $otherIncome;
        $buckets['raw_materials_consumables'] = $rawMaterialsConsumables;
        $buckets['staff_costs'] = $staffCosts;
        $buckets['depreciation_write_offs'] = $depreciationWriteOffs;
        $buckets['other_charges'] = $otherCharges;
        $buckets['cost_of_sales'] = $costOfSales;
        $buckets['gross_profit_loss'] = round($income - $costOfSales, 2);
        $buckets['administrative_expenses'] = $administrativeExpenses;
        $buckets['expenses'] = round($costOfSales + $administrativeExpenses, 2);
        $buckets['profit_loss_before_tax'] = $profitBeforeTax;
        $buckets['tax_on_profit'] = $taxOnProfit;
        $buckets['profit_loss'] = round($profitBeforeTax - $taxOnProfit, 2);
        $sources['tax_on_profit'] ??= [];
        $this->addFormulaSource($sources, 'expenses', 'Raw materials and consumables', $rawMaterialsConsumables, 'raw_materials_consumables');
        $this->addFormulaSource($sources, 'expenses', 'Staff costs', $staffCosts, 'staff_costs');
        $this->addFormulaSource($sources, 'expenses', 'Depreciation and other amounts written off assets', $depreciationWriteOffs, 'depreciation_write_offs');
        $this->addFormulaSource($sources, 'expenses', 'Other charges', $otherCharges, 'other_charges');
        $this->addFormulaSource($sources, 'gross_profit_loss', 'Turnover', $turnover, 'turnover');
        $this->addFormulaSource($sources, 'gross_profit_loss', 'Other income', $otherIncome, 'other_income');
        $this->addFormulaSource($sources, 'gross_profit_loss', 'Less: cost of sales', -$costOfSales, 'cost_of_sales');
        $this->addFormulaSource($sources, 'profit_loss_before_tax', 'Gross profit / loss', $buckets['gross_profit_loss'], 'gross_profit_loss');
        $this->addFormulaSource($sources, 'profit_loss_before_tax', 'Less: administrative expenses', -$administrativeExpenses, 'administrative_expenses');
        $this->addFormulaSource($sources, 'profit_loss', 'Turnover', $turnover, 'turnover');
        $this->addFormulaSource($sources, 'profit_loss', 'Other income', $otherIncome, 'other_income');
        $this->addFormulaSource($sources, 'profit_loss', 'Less: raw materials and consumables', -$rawMaterialsConsumables, 'raw_materials_consumables');
        $this->addFormulaSource($sources, 'profit_loss', 'Less: staff costs', -$staffCosts, 'staff_costs');
        $this->addFormulaSource($sources, 'profit_loss', 'Less: depreciation and other amounts written off assets', -$depreciationWriteOffs, 'depreciation_write_offs');
        $this->addFormulaSource($sources, 'profit_loss', 'Less: other charges', -$otherCharges, 'other_charges');
        $this->addFormulaSource($sources, 'profit_loss', 'Less: tax on profit / loss', -$taxOnProfit, 'tax_on_profit');
        $this->assertProfitAndLossSourcesReconcile($sources, $buckets);

        $assumptions = [
            'Balance sheet facts use closing posted-journal balances up to the period end, including opening and brought-forward journals, plus applicable pending Year End close-preview adjustments.',
            'Fixed assets require a fixed_asset nominal subtype; otherwise asset accounts are treated as current assets.',
            'Prepayment and accrued-income asset subtypes are shown separately from current assets and are added back in the balance-sheet subtotal formulas.',
            'Liability accounts are treated as due within one year unless an explicit long-term liability subtype or the period-specific Director Loan reporting presentation applies.',
            'Staff costs include only explicitly named staff-cost, wage, salary, payroll, employer-NI, and pension-cost nominals; staff welfare and subsistence remain in other charges.',
            'Raw materials and consumables include only explicitly classified or named materials, purchases, and consumables; remaining cost-of-sales nominals are included in other charges for the micro Format 2 presentation.',
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
            'other_income' => 0.0,
            'raw_materials_consumables' => 0.0,
            'staff_costs' => 0.0,
            'depreciation_write_offs' => 0.0,
            'other_charges' => 0.0,
            'cost_of_sales' => 0.0,
            'gross_profit_loss' => 0.0,
            'administrative_expenses' => 0.0,
            'expenses' => 0.0,
            'profit_loss_before_tax' => 0.0,
            'tax_on_profit' => 0.0,
            'profit_loss' => 0.0,
            'current_assets' => 0.0,
            'prepayments_accrued_income' => 0.0,
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

    private function ledgerSourceLabel(array $row): string
    {
        $label = trim((string)($row['code'] ?? '') . ' ' . (string)($row['name'] ?? ''));
        $month = trim((string)($row['month_start'] ?? ''));
        if ($month !== '') {
            $label .= ' (' . $month . ')';
        }
        return $label !== '' ? $label : 'Posted ledger movement';
    }

    private function isOtherIncomeRow(array $row): bool
    {
        $subtype = $this->rowSubtype($row);
        if (in_array($subtype, [
            'other_income',
            'interest_income',
            'finance_income',
            'grant_income',
            'rental_income',
            'asset_disposal_gain',
        ], true)) {
            return true;
        }

        $name = strtolower(trim((string)($row['name'] ?? '')));
        return preg_match(
            '/\b(other income|interest (?:income|received)|grant income|rental income|profit on (?:asset )?disposal)\b/',
            $name
        ) === 1;
    }

    private function isRawMaterialsConsumablesRow(array $row): bool
    {
        $subtype = $this->rowSubtype($row);
        if (in_array($subtype, [
            'material',
            'materials',
            'raw_material',
            'raw_materials',
            'raw_materials_consumables',
            'purchase',
            'purchases',
            'consumable',
            'consumables',
            'materials_purchases',
        ], true)) {
            return true;
        }

        $name = strtolower(trim((string)($row['name'] ?? '')));
        if (preg_match('/\b(subcontract(?:or|ors|ing)?|labou?r|services?)\b/', $name) === 1) {
            return false;
        }

        return preg_match('/\b(raw materials?|materials?|purchases?|consumables?)\b/', $name) === 1;
    }

    private function expenseMicroBucket(array $row): string
    {
        if ($this->isStaffCostRow($row)) {
            return 'staff_costs';
        }
        if ($this->isDepreciationWriteOffRow($row)) {
            return 'depreciation_write_offs';
        }
        return 'other_charges';
    }

    private function isStaffCostRow(array $row): bool
    {
        $subtype = $this->rowSubtype($row);
        if (in_array($subtype, [
            'staff_cost',
            'staff_costs',
            'wage',
            'wages',
            'salary',
            'salaries',
            'payroll',
            'employer_ni',
            'employers_ni',
            'employer_nic',
            'employers_nic',
            'pension_cost',
            'pension_costs',
            'pension_contributions',
        ], true)) {
            return true;
        }

        $name = strtolower(trim((string)($row['name'] ?? '')));
        return preg_match(
            '/\b(staff costs?|wages?|salar(?:y|ies)|payroll|employer(?:\'s|s)? (?:national insurance|ni|nic)|pension (?:costs?|contributions?))\b/',
            $name
        ) === 1;
    }

    private function isDepreciationWriteOffRow(array $row): bool
    {
        $subtype = $this->rowSubtype($row);
        if (in_array($subtype, [
            'depreciation',
            'depreciation_expense',
            'amortisation',
            'amortisation_expense',
            'asset_write_off',
            'asset_write_offs',
            'asset_impairment',
            'asset_disposal_loss',
        ], true)) {
            return true;
        }

        $name = strtolower(trim((string)($row['name'] ?? '')));
        return preg_match(
            '/\b(depreciation|amortisation|amortization|asset (?:impairment|write[ -]?offs?))\b/',
            $name
        ) === 1;
    }

    private function rowSubtype(array $row): string
    {
        return strtolower(trim((string)(
            $row['account_subtype_code']
                ?? $row['subtype_code']
                ?? ''
        )));
    }

    /** @param list<array<string, mixed>> $rows */
    private function sourceTotal(array $rows): float
    {
        return round(array_sum(array_map(
            static fn(array $row): float => (float)($row['amount'] ?? 0),
            $rows
        )), 2);
    }

    private function assertComponentTotal(string $label, float $expected, float $actual): void
    {
        $expected = round($expected, 2);
        $actual = round($actual, 2);
        if (abs($expected - $actual) >= 0.005) {
            throw new \LogicException(sprintf(
                'iXBRL %s components total %.2f but the canonical total is %.2f.',
                $label,
                $actual,
                $expected
            ));
        }
    }

    private function assertProfitAndLossSourcesReconcile(array $sources, array $buckets): void
    {
        foreach ([
            'turnover',
            'other_income',
            'raw_materials_consumables',
            'staff_costs',
            'depreciation_write_offs',
            'other_charges',
            'cost_of_sales',
            'gross_profit_loss',
            'administrative_expenses',
            'expenses',
            'profit_loss_before_tax',
            'tax_on_profit',
            'profit_loss',
        ] as $bucket) {
            $sourceTotal = round(array_sum(array_map(
                static fn(array $row): float => (float)($row['amount'] ?? 0),
                (array)($sources[$bucket] ?? [])
            )), 2);
            $bucketTotal = round((float)($buckets[$bucket] ?? 0), 2);
            if (abs($sourceTotal - $bucketTotal) >= 0.005) {
                throw new \LogicException(sprintf(
                    'iXBRL P&L source rows for %s total %.2f but the bucket total is %.2f.',
                    $bucket,
                    $sourceTotal,
                    $bucketTotal
                ));
            }
        }
    }
}
