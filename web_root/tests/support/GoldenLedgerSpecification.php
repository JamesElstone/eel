<?php
/** Test-only immutable accounting facts. No application service or database access. */
declare(strict_types=1);

final class GoldenLedgerSpecification
{
    /** @return array<string, mixed> */
    public static function fourPeriodPrepaymentVariant(): array
    {
        return [
            'description' => 'Three-year service crossing four accounting periods, including the 2024 leap day.',
            'total_pence' => 109600,
            'service_start_date' => '2022-12-30',
            'service_end_date' => '2025-12-29',
            'periods' => [
                ['id' => 9111, 'period_start' => '2022-09-05', 'period_end' => '2023-09-30'],
                ['id' => 9112, 'period_start' => '2023-10-01', 'period_end' => '2024-09-30'],
                ['id' => 9113, 'period_start' => '2024-10-01', 'period_end' => '2025-09-30'],
                ['id' => 9114, 'period_start' => '2025-10-01', 'period_end' => '2026-09-30'],
            ],
            'expected' => [
                9111 => ['overlap_days' => 275, 'expense_pence' => 27500, 'closing_deferred_pence' => 82100, 'posting_type' => 'deferral', 'posting_pence' => 82100],
                9112 => ['overlap_days' => 366, 'expense_pence' => 36600, 'closing_deferred_pence' => 45500, 'posting_type' => 'release', 'posting_pence' => 36600],
                9113 => ['overlap_days' => 365, 'expense_pence' => 36500, 'closing_deferred_pence' => 9000, 'posting_type' => 'release', 'posting_pence' => 36500],
                9114 => ['overlap_days' => 90, 'expense_pence' => 9000, 'closing_deferred_pence' => 0, 'posting_type' => 'release', 'posting_pence' => 9000],
            ],
        ];
    }

    /** @return array<int, array<string, float|int>> */
    public static function yearEndAssetExpectations(): array
    {
        return [
            9111 => ['depreciation_entries' => 3, 'depreciation' => 2192.05, 'profit_before_tax' => 5032.95, 'capital_allowances' => 6300.00, 'taxable_profit' => 925.00, 'corporation_tax' => 175.75],
            9112 => ['depreciation_entries' => 4, 'depreciation' => 5031.78, 'profit_before_tax' => 1502.22, 'capital_allowances' => 9000.00, 'taxable_profit' => 0.00, 'corporation_tax' => 0.00],
            9113 => ['depreciation_entries' => 4, 'depreciation' => 4994.00, 'profit_before_tax' => 1959.00, 'capital_allowances' => 0.00, 'taxable_profit' => 5087.00, 'corporation_tax' => 966.53],
        ];
    }

    /** @return array<int, array<string, float|int|string>> */
    public static function hmrcTaxFacts(): array
    {
        return [
            9111 => ['period_start' => '2022-09-05', 'period_end' => '2023-09-30', 'accounting_profit' => 5032.95, 'disallowable_add_backs' => 0.00, 'depreciation_add_back' => 2192.05, 'capital_allowances' => 6300.00, 'associated_company_count' => 0],
            9112 => ['period_start' => '2023-10-01', 'period_end' => '2024-09-30', 'accounting_profit' => 1502.22, 'disallowable_add_backs' => 600.00, 'depreciation_add_back' => 5031.78, 'capital_allowances' => 9000.00, 'associated_company_count' => 0],
            9113 => ['period_start' => '2024-10-01', 'period_end' => '2025-09-30', 'accounting_profit' => 1959.00, 'disallowable_add_backs' => 0.00, 'depreciation_add_back' => 4994.00, 'capital_allowances' => 0.00, 'associated_company_count' => 0, 'hmrc_interest_amount' => 90.00, 'hmrc_interest_type' => 'corporation_tax_late_payment'],
            9114 => ['period_start' => '2025-10-01', 'period_end' => '2026-09-30', 'accounting_profit' => 7137.00, 'disallowable_add_backs' => 0.00, 'depreciation_add_back' => 0.00, 'capital_allowances' => 0.00, 'associated_company_count' => 0],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function periods(): array
    {
        return [
            9111 => self::period('2022-09-05', '2023-09-30', [
                ['date' => '2022-09-15', 'source' => 'asset_workshop_equipment', 'debit' => 'fixed_assets', 'credit' => 'bank', 'amount' => 3600.00],
                ['date' => '2022-09-15', 'source' => 'asset_installation_tools', 'debit' => 'fixed_assets', 'credit' => 'bank', 'amount' => 1800.00],
                ['date' => '2022-09-15', 'source' => 'asset_computer_equipment', 'debit' => 'fixed_assets', 'credit' => 'bank', 'amount' => 900.00],
                ['date' => '2022-12-30', 'source' => 'three_year_subscription', 'debit' => 'prepayment_expense', 'credit' => 'bank', 'amount' => 1096.00],
                ['date' => '2023-09-30', 'source' => 'prepayment_deferral_long', 'debit' => 'prepaid_expenses', 'credit' => 'prepayment_expense', 'amount' => 821.00],
            ], 0.00, 7, [
                ['code' => 'GOLDEN-FA-001', 'purchase_date' => '2022-09-15', 'cost' => 3600.00, 'life_years' => 3, 'residual_value' => 0.00, 'category' => 'tools_equipment'],
                ['code' => 'GOLDEN-FA-002', 'purchase_date' => '2022-09-15', 'cost' => 1800.00, 'life_years' => 3, 'residual_value' => 0.00, 'category' => 'tools_equipment'],
                ['code' => 'GOLDEN-FA-003', 'purchase_date' => '2022-09-15', 'cost' => 900.00, 'life_years' => 3, 'residual_value' => 0.00, 'category' => 'tools_equipment'],
            ]),
            9112 => self::period('2023-10-01', '2024-09-30', [
                ['date' => '2024-06-15', 'source' => 'hmrc_penalty', 'debit' => 'hmrc_penalty', 'credit' => 'hmrc_payable', 'amount' => 600.00],
                ['date' => '2023-10-11', 'source' => 'asset_electric_van', 'debit' => 'fixed_assets', 'credit' => 'bank', 'amount' => 9000.00],
                ['date' => '2023-10-01', 'source' => 'prepayment_release_long_2', 'debit' => 'prepayment_expense', 'credit' => 'prepaid_expenses', 'amount' => 366.00],
            ], 600.00, 4, [
                ['code' => 'GOLDEN-VAN-001', 'purchase_date' => '2023-10-11', 'cost' => 9000.00, 'life_years' => 3, 'residual_value' => 0.00, 'category' => 'van'],
            ]),
            9113 => self::period('2024-10-01', '2025-09-30', [
                ['date' => '2025-06-30', 'source' => 'hmrc_interest', 'debit' => 'hmrc_interest', 'credit' => 'hmrc_payable', 'amount' => 90.00],
                ['date' => '2025-07-01', 'source' => 'annual_subscription', 'debit' => 'prepayment_expense', 'credit' => 'bank', 'amount' => 365.00],
                ['date' => '2025-09-30', 'source' => 'prepayment_deferral', 'debit' => 'prepaid_expenses', 'credit' => 'prepayment_expense', 'amount' => 273.00],
                ['date' => '2024-10-01', 'source' => 'prepayment_release_long_3', 'debit' => 'prepayment_expense', 'credit' => 'prepaid_expenses', 'amount' => 365.00],
            ], 0.00, 4),
            9114 => self::period('2025-10-01', '2026-09-30', [
                ['date' => '2026-02-15', 'source' => 'hmrc_payment', 'debit' => 'hmrc_payable', 'credit' => 'bank', 'amount' => 690.00],
                ['date' => '2025-10-01', 'source' => 'prepayment_release', 'debit' => 'prepayment_expense', 'credit' => 'prepaid_expenses', 'amount' => 273.00],
                ['date' => '2025-10-01', 'source' => 'prepayment_release_long_4', 'debit' => 'prepayment_expense', 'credit' => 'prepaid_expenses', 'amount' => 90.00],
            ], 0.00, 4),
        ];
    }

    /** @return array<string, mixed> */
    private static function period(string $start, string $end, array $extraJournals = [], float $disallowableAddBack = 0.0, int $transactionCount = 3, array $assetPurchases = []): array
    {
        $date = (new DateTimeImmutable($start))->modify('+10 days')->format('Y-m-d');

        return [
            'start' => $start,
            'end' => $end,
            'journals' => array_merge([
                ['date' => $date, 'source' => 'sale', 'debit' => 'bank', 'credit' => 'sales', 'amount' => 12000.00],
                ['date' => $date, 'source' => 'materials', 'debit' => 'materials', 'credit' => 'bank', 'amount' => 3000.00],
                ['date' => $date, 'source' => 'overheads', 'debit' => 'overheads', 'credit' => 'bank', 'amount' => 1200.00],
                ['date' => $date, 'source' => 'expense_claim', 'debit' => 'overheads', 'credit' => 'director_loan', 'amount' => 300.00],
            ], $extraJournals),
            'transactions' => $transactionCount,
            'expense_claims' => 1,
            'tax_rate' => 0.19,
            'disallowable_add_back' => $disallowableAddBack,
            'asset_purchases' => $assetPurchases,
        ];
    }
}
