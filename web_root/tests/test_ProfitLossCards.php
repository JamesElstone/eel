<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->run(_pl_summaryCard::class, static function (GeneratedServiceClassTestHarness $harness, _pl_summaryCard $card): void {
    $html = $card->render([
        'company' => [
            'settings' => [
                'default_currency_symbol' => '&#36;',
            ],
        ],
        'profit_loss' => [
            'summary' => [
                'available' => true,
                'period_label' => '05/09/2022 to 30/09/2023',
                'has_journals' => true,
                'has_transactions' => true,
                'income_total' => 1200,
                'cost_of_sales_total' => 300,
                'gross_profit' => 900,
                'expense_total' => 200,
                'profit_before_tax' => 700,
                'net_profit' => 700,
                'profit_margin_percent' => 58.3,
            ],
            'ct_period_reconciliation' => [
                'available' => true,
                'ct_periods' => [
                    [
                        'display_label' => 'CT Period 1',
                        'period_start' => '2022-09-05',
                        'period_end' => '2023-09-04',
                        'profit_before_tax' => 720,
                    ],
                    [
                        'display_label' => 'CT Period 2',
                        'period_start' => '2023-09-05',
                        'period_end' => '2023-09-30',
                        'profit_before_tax' => -20,
                    ],
                ],
            ],
            'health' => [
                'available' => true,
                'books_health_score' => 95,
                'categorised_percent' => 100,
                'uncategorised_transactions' => 0,
                'missing_month_count' => 1,
                'uploaded_month_count' => 11,
                'committed_month_count' => 11,
                'upload_in_progress_count' => 0,
            ],
            'breakdown' => [
                'income' => [[
                    'nominal_account_id' => 1,
                    'code' => '4000',
                    'name' => 'Sales',
                    'amount' => 1200,
                ]],
                'cost_of_sales' => [[
                    'nominal_account_id' => 2,
                    'code' => '5000',
                    'name' => 'Materials',
                    'amount' => 300,
                ]],
                'expense' => [
                    [
                        'nominal_account_id' => 3,
                        'code' => '7000',
                        'name' => 'Software',
                        'amount' => 200,
                    ],
                    [
                        'nominal_account_id' => 4,
                        'code' => '7100',
                        'name' => 'Rent',
                        'amount' => 150,
                    ],
                    [
                        'nominal_account_id' => 5,
                        'code' => '6001',
                        'name' => 'Small Expense 1',
                        'amount' => 20,
                    ],
                    [
                        'nominal_account_id' => 6,
                        'code' => '6002',
                        'name' => 'Small Expense 2',
                        'amount' => 18,
                    ],
                    [
                        'nominal_account_id' => 7,
                        'code' => '6003',
                        'name' => 'Small Expense 3',
                        'amount' => 16,
                    ],
                    [
                        'nominal_account_id' => 8,
                        'code' => '6004',
                        'name' => 'Small Expense 4',
                        'amount' => 14,
                    ],
                    [
                        'nominal_account_id' => 9,
                        'code' => '6005',
                        'name' => 'Small Expense 5',
                        'amount' => 12,
                    ],
                    [
                        'nominal_account_id' => 10,
                        'code' => '6006',
                        'name' => 'Small Expense 6',
                        'amount' => 10,
                    ],
                    [
                        'nominal_account_id' => 11,
                        'code' => '6007',
                        'name' => 'Small Expense 7',
                        'amount' => 8,
                    ],
                    [
                        'nominal_account_id' => 12,
                        'code' => '6008',
                        'name' => 'Small Expense 8',
                        'amount' => 6,
                    ],
                    [
                        'nominal_account_id' => 13,
                        'code' => '6009',
                        'name' => 'Small Expense 9',
                        'amount' => 4,
                    ],
                    [
                        'nominal_account_id' => 14,
                        'code' => '6010',
                        'name' => 'Small Expense 10',
                        'amount' => 2,
                    ],
                ],
            ],
        ],
    ]);
    $singlePeriodHtml = $card->render([
        'company' => [
            'settings' => [
                'default_currency_symbol' => '&#36;',
            ],
        ],
        'profit_loss' => [
            'summary' => [
                'available' => true,
                'has_journals' => true,
                'profit_before_tax' => 100,
                'net_profit' => 100,
                'profit_margin_percent' => 10,
            ],
            'ct_period_reconciliation' => [
                'available' => true,
                'ct_periods' => [[
                    'display_label' => 'CT Period 1',
                    'period_start' => '2024-10-01',
                    'period_end' => '2025-09-30',
                    'profit_before_tax' => 100,
                ]],
            ],
            'breakdown' => [],
        ],
    ]);

    $lossHtml = $card->render([
        'profit_loss' => [
            'summary' => [
                'available' => true,
                'has_journals' => true,
                'net_profit' => -50,
            ],
        ],
    ]);
    $lossFlowHtml = $card->render([
        'profit_loss' => [
            'summary' => [
                'available' => true,
                'has_journals' => true,
                'net_profit' => -50,
            ],
            'breakdown' => [
                'income' => [[
                    'nominal_account_id' => 1,
                    'code' => '4000',
                    'name' => 'Sales',
                    'amount' => 100,
                ]],
                'cost_of_sales' => [[
                    'nominal_account_id' => 2,
                    'code' => '5000',
                    'name' => 'Materials',
                    'amount' => 150,
                ]],
                'expense' => [],
            ],
        ],
    ]);
    $nillHtml = $card->render([
        'profit_loss' => [
            'summary' => [
                'available' => true,
                'has_journals' => true,
                'net_profit' => 0,
            ],
        ],
    ]);

    $harness->check(_pl_summaryCard::class, 'renders summary and health metrics without selected period label', static function () use ($harness, $html): void {
        $harness->assertSame(false, str_contains($html, '05/09/2022 to 30/09/2023'));
        $harness->assertTrue(str_contains($html, 'pl-summary-topline'));
        $harness->assertTrue(str_contains($html, 'summary-card summary-card-fit'));
        $harness->assertTrue(str_contains($html, '<div class="summary-label">Profitability</div><div class="summary-value pl-profitability-value pl-profitability-value-profit">Profit</div>'));
        $harness->assertSame(false, str_contains($html, 'page-card-tabs'));
        $harness->assertSame(false, str_contains($html, 'page-card-tab'));
        $harness->assertSame(false, str_contains($html, '<div class="summary-label">Result</div>'));
        $harness->assertSame(false, str_contains($html, 'class="badge'));
        $harness->assertSame(false, str_contains($html, 'panel-soft'));
        $harness->assertTrue(str_contains($html, 'P&amp;L for CT Period 1'));
        $harness->assertTrue(str_contains($html, 'P&amp;L for CT Period 2'));
        $harness->assertTrue(str_contains($html, '05/09/2022 to 04/09/2023'));
        $harness->assertTrue(str_contains($html, '05/09/2023 to 30/09/2023'));
        $harness->assertTrue(str_contains($html, '$ -20.00'));
        $harness->assertSame(1, substr_count($html, '<div class="summary-label">Profit before tax</div>'));
        $harness->assertSame(1, substr_count($html, '<div class="summary-label">Profit margin</div>'));
        $headlineGridPosition = strpos($html, '<div class="summary-grid four">');
        $profitBeforeTaxPosition = strpos($html, '<div class="summary-label">Profit before tax</div>');
        $profitMarginPosition = strpos($html, '<div class="summary-label">Profit margin</div>');
        $incomePosition = strpos($html, '<div class="summary-label">Income</div>');
        $harness->assertTrue($headlineGridPosition !== false);
        $harness->assertTrue($profitBeforeTaxPosition !== false && $headlineGridPosition < $profitBeforeTaxPosition);
        $harness->assertTrue($profitMarginPosition !== false && $profitBeforeTaxPosition < $profitMarginPosition);
        $harness->assertTrue($incomePosition !== false && $profitMarginPosition < $incomePosition);
        $harness->assertTrue(str_contains($html, '<div class="summary-label">Income</div><div class="summary-value">$ 1,200.00</div>'));
        $harness->assertTrue(str_contains($html, '<div class="summary-card pl-profit-before-tax-positive"><div class="summary-label">Profit before tax</div><div class="summary-value">$ 700.00</div></div>'));
        $harness->assertTrue(str_contains($html, '<div class="summary-label">Profit before tax</div><div class="summary-value">$ 700.00</div>'));
        $harness->assertTrue(str_contains($html, 'Missing months'));
        $harness->assertTrue(str_contains($html, 'Uncategorised transactions'));
        $harness->assertSame(false, str_contains($html, 'Books health score'));
    });

    $harness->check(_pl_summaryCard::class, 'renders dynamic CT-period headline cards even without Sankey data', static function () use ($harness, $singlePeriodHtml): void {
        $harness->assertTrue(str_contains($singlePeriodHtml, '<div class="pl-summary-income-flow">'));
        $harness->assertTrue(str_contains($singlePeriodHtml, 'No incoming or outgoing nominal flow is available for the selected period.'));
        $harness->assertTrue(str_contains($singlePeriodHtml, 'P&amp;L for CT Period 1'));
        $harness->assertTrue(str_contains($singlePeriodHtml, '01/10/2024 to 30/09/2025'));
        $harness->assertTrue(str_contains($singlePeriodHtml, '<div class="summary-label">Profit before tax</div><div class="summary-value">$ 100.00</div>'));
        $harness->assertTrue(str_contains($singlePeriodHtml, '<div class="summary-label">Profit margin</div><div class="summary-value">10.0%</div>'));
        $harness->assertSame(false, str_contains($singlePeriodHtml, 'summary-grid four'));
    });

    $harness->check(_pl_summaryCard::class, 'renders income flow Sankey chart next to profitability', static function () use ($harness, $html): void {
        $profitabilityPosition = strpos($html, '<div class="summary-label">Profitability</div>');
        $chartPosition = strpos($html, 'pl-summary-income-flow');
        $summaryGridPosition = strpos($html, '<div class="summary-grid">');

        $harness->assertTrue($profitabilityPosition !== false);
        $harness->assertTrue($chartPosition !== false);
        $harness->assertTrue($summaryGridPosition !== false);
        $harness->assertTrue($profitabilityPosition < $chartPosition);
        $harness->assertTrue($chartPosition < $summaryGridPosition);
        $harness->assertTrue(str_contains($html, 'Income Flow'));
        $harness->assertTrue(str_contains($html, 'pl-summary-income-flow'));
        $harness->assertTrue(str_contains($html, 'chart chart-sankey'));
        $harness->assertTrue(str_contains($html, '4000 Sales to Income Flow'));
        $harness->assertTrue(str_contains($html, 'Income Flow to 5000 Materials'));
        $harness->assertTrue(str_contains($html, 'Income Flow to 7000 Software'));
        $harness->assertTrue(str_contains($html, 'Income Flow to 7100 Rent'));
        $harness->assertTrue(str_contains($html, 'Income Flow to Other Expenses'));
        $harness->assertTrue(str_contains($html, 'Income Flow to Profit'));
        $harness->assertSame(false, str_contains($html, 'Small Expense 1'));
    });

    $harness->check(_pl_summaryCard::class, 'renders loss and nill result labels', static function () use ($harness, $lossHtml, $nillHtml): void {
        $harness->assertTrue(str_contains($lossHtml, '<div class="summary-label">Profitability</div><div class="summary-value pl-profitability-value pl-profitability-value-loss">Loss</div>'));
        $harness->assertTrue(str_contains($lossHtml, '<div class="summary-card pl-profit-before-tax-negative"><div class="summary-label">Profit before tax</div>'));
        $harness->assertTrue(str_contains($nillHtml, '<div class="summary-label">Profitability</div><div class="summary-value pl-profitability-value pl-profitability-value-nill">Nill</div>'));
        $harness->assertTrue(str_contains($nillHtml, '<div class="summary-card"><div class="summary-label">Profit before tax</div>'));
    });

    $harness->check(_pl_summaryCard::class, 'renders loss as an incoming balancing Sankey flow', static function () use ($harness, $lossFlowHtml): void {
        $harness->assertTrue(str_contains($lossFlowHtml, 'Loss to Income Flow'));
        $harness->assertTrue(str_contains($lossFlowHtml, 'Income Flow to 5000 Materials'));
    });
});

$harness->run(_pl_income_breakdownCard::class, static function (GeneratedServiceClassTestHarness $harness, _pl_income_breakdownCard $card): void {
    $html = $card->render([
        'profit_loss' => [
            'breakdown' => [
                'income' => [
                    [
                        'code' => '4000',
                        'name' => 'Sales',
                        'account_subtype_code' => 'turnover',
                        'amount' => 1200,
                    ],
                    [
                        'code' => '4200',
                        'name' => 'Profit on Disposal',
                        'account_subtype_code' => 'asset_disposal_gain',
                        'amount' => 75,
                    ],
                ],
            ],
        ],
    ]);
    $nonIncomeReceiptHtml = $card->render([
        'profit_loss' => [
            'breakdown' => [
                'income' => [
                    [
                        'code' => '4000',
                        'name' => 'Sales',
                        'account_subtype_code' => 'turnover',
                        'amount' => 1200,
                    ],
                ],
                'positive_non_income_receipts' => [
                    [
                        'code' => '2100',
                        'name' => 'Director Loan Liability',
                        'account_type' => 'liability',
                        'amount' => 500,
                    ],
                ],
            ],
        ],
    ]);

    $harness->check(_pl_income_breakdownCard::class, 'splits sales from other income sources', static function () use ($harness, $html): void {
        $salesPosition = strpos($html, '<h3 class="card-title">Sales</h3>');
        $otherIncomePosition = strpos($html, '<h3 class="card-title">Other income sources</h3>');
        $salesRowPosition = strpos($html, '<td>4000</td>');
        $otherIncomeRowPosition = strpos($html, '<td>4200</td>');

        $harness->assertTrue($salesPosition !== false);
        $harness->assertTrue($otherIncomePosition !== false);
        $harness->assertTrue($salesRowPosition !== false);
        $harness->assertTrue($otherIncomeRowPosition !== false);
        $harness->assertTrue($salesPosition < $salesRowPosition);
        $harness->assertTrue($salesRowPosition < $otherIncomePosition);
        $harness->assertTrue($otherIncomePosition < $otherIncomeRowPosition);
    });

    $harness->check(_pl_income_breakdownCard::class, 'explains positive non-income receipts when other income is empty', static function () use ($harness, $nonIncomeReceiptHtml): void {
        $harness->assertTrue(str_contains($nonIncomeReceiptHtml, 'No other income journals have been posted for this period.'));
        $harness->assertTrue(str_contains($nonIncomeReceiptHtml, 'Monies posted to Director Loan Liability are excluded from income'));
        $harness->assertSame(false, str_contains($nonIncomeReceiptHtml, '2100 - Director Loan Liability'));
        $harness->assertSame(false, str_contains($nonIncomeReceiptHtml, '(500.00)'));
        $harness->assertTrue(str_contains($nonIncomeReceiptHtml, 'are excluded from income'));
        $harness->assertTrue(str_contains($nonIncomeReceiptHtml, '<br>Director loans'));
        $harness->assertTrue(str_contains($nonIncomeReceiptHtml, 'Director loans'));
        $harness->assertTrue(str_contains($nonIncomeReceiptHtml, 'not income'));
    });
});

$harness->run(_pl_monthly_trendCard::class, static function (GeneratedServiceClassTestHarness $harness, _pl_monthly_trendCard $card): void {
    $html = $card->render([
        'company' => [
            'settings' => [
                'default_currency_symbol' => '&#36;',
            ],
        ],
        'profit_loss' => [
            'monthly_trend' => [
                [
                    'month_start' => '2026-01-01',
                    'month_label' => 'January 2026',
                    'income_total' => 1200,
                    'cost_of_sales_total' => 300,
                    'expense_total' => 200,
                    'net_profit' => 700,
                ],
                [
                    'month_start' => '2026-02-01',
                    'month_label' => 'February 2026',
                    'income_total' => 1500,
                    'cost_of_sales_total' => 400,
                    'expense_total' => 250,
                    'net_profit' => 850,
                ],
            ],
        ],
    ]);

    $harness->check(_pl_monthly_trendCard::class, 'renders monthly table beside multi-line chart', static function () use ($harness, $html): void {
        $harness->assertTrue(str_contains($html, 'pl-monthly-trend-layout'));
        $harness->assertTrue(str_contains($html, 'pl-monthly-trend-table'));
        $harness->assertTrue(str_contains($html, 'pl-monthly-trend-chart'));
        $harness->assertTrue(str_contains($html, 'chart chart-line'));
        $harness->assertTrue(str_contains($html, '>January 2026</td>'));
        $harness->assertTrue(str_contains($html, '<td>$ 1,200.00</td>'));
        $harness->assertTrue(str_contains($html, '<td>$ 300.00</td>'));
        $harness->assertTrue(str_contains($html, '<td>$ 200.00</td>'));
        $harness->assertTrue(str_contains($html, '<span class="badge success">$ 700.00</span>'));
        $harness->assertTrue(str_contains($html, 'Income - 1'));
        $harness->assertTrue(str_contains($html, 'Cost of sales - 1'));
        $harness->assertTrue(str_contains($html, 'Expenses - 1'));
        $harness->assertTrue(str_contains($html, 'Net - 1'));
        $harness->assertSame(false, str_contains($html, 'Income - January 2026'));
    });
});
