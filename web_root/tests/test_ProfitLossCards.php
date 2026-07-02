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
                'net_profit' => 700,
                'profit_margin_percent' => 58.3,
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
        $harness->assertTrue(str_contains($html, 'summary-card summary-card-fit'));
        $harness->assertTrue(str_contains($html, '<div class="summary-label">Profitability</div><div class="summary-value pl-profitability-value pl-profitability-value-profit">Profit</div>'));
        $harness->assertSame(false, str_contains($html, '<div class="summary-label">Result</div>'));
        $harness->assertSame(false, str_contains($html, 'class="badge'));
        $harness->assertSame(false, str_contains($html, 'panel-soft'));
        $harness->assertTrue(str_contains($html, 'Net profit / loss'));
        $harness->assertTrue(str_contains($html, 'Missing months'));
        $harness->assertTrue(str_contains($html, 'Uncategorised transactions'));
        $harness->assertSame(false, str_contains($html, 'Books health score'));
    });

    $harness->check(_pl_summaryCard::class, 'renders loss and nill result labels', static function () use ($harness, $lossHtml, $nillHtml): void {
        $harness->assertTrue(str_contains($lossHtml, '<div class="summary-label">Profitability</div><div class="summary-value pl-profitability-value pl-profitability-value-loss">Loss</div>'));
        $harness->assertTrue(str_contains($nillHtml, '<div class="summary-label">Profitability</div><div class="summary-value pl-profitability-value pl-profitability-value-nill">Nill</div>'));
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
        $harness->assertTrue(str_contains($nonIncomeReceiptHtml, '2100 - Director Loan Liability'));
        $harness->assertTrue(str_contains($nonIncomeReceiptHtml, 'are excluded from income'));
        $harness->assertTrue(str_contains($nonIncomeReceiptHtml, 'Director loans'));
        $harness->assertTrue(str_contains($nonIncomeReceiptHtml, 'not income'));
    });
});

$harness->run(_pl_monthly_trendCard::class, static function (GeneratedServiceClassTestHarness $harness, _pl_monthly_trendCard $card): void {
    $html = $card->render([
        'profit_loss' => [
            'monthly_trend' => [
                [
                    'month_label' => 'January 2026',
                    'income_total' => 1200,
                    'cost_of_sales_total' => 300,
                    'expense_total' => 200,
                    'net_profit' => 700,
                ],
                [
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
        $harness->assertTrue(str_contains($html, 'Income - January 2026'));
        $harness->assertTrue(str_contains($html, 'Cost of sales - January 2026'));
        $harness->assertTrue(str_contains($html, 'Expenses - January 2026'));
        $harness->assertTrue(str_contains($html, 'Net - January 2026'));
    });
});

$harness->run(_pl_month_status_gridCard::class, static function (GeneratedServiceClassTestHarness $harness, _pl_month_status_gridCard $card): void {
    $confirmHtml = $card->render([
        'company' => [
            'id' => 10,
            'accounting_period_id' => 20,
        ],
        'profit_loss' => [
            'month_status_grid' => [[
                'month_start' => '2026-01-01',
                'month_label' => 'January 2026',
                'status' => 'no_data',
                'transaction_count' => 0,
                'uncategorised_count' => 0,
                'upload_count' => 0,
                'can_confirm_empty_month' => true,
            ]],
        ],
    ]);

    $confirmedHtml = $card->render([
        'company' => [
            'id' => 10,
            'accounting_period_id' => 20,
        ],
        'profit_loss' => [
            'month_status_grid' => [[
                'month_start' => '2026-01-01',
                'month_label' => 'January 2026',
                'status' => 'confirmed_empty',
                'transaction_count' => 0,
                'uncategorised_count' => 0,
                'upload_count' => 0,
            ]],
        ],
    ]);

    $harness->check(_pl_month_status_gridCard::class, 'renders empty month confirmation actions', static function () use ($harness, $confirmHtml, $confirmedHtml): void {
        $harness->assertTrue(str_contains($confirmHtml, 'name="intent" value="confirm_empty_month"'));
        $harness->assertTrue(str_contains($confirmHtml, 'Confirm no activity'));
        $harness->assertTrue(str_contains($confirmedHtml, 'Confirmed Empty'));
        $harness->assertTrue(str_contains($confirmedHtml, 'name="intent" value="revoke_empty_month"'));
    });
});
