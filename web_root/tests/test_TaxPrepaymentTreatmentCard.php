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

$harness->run(_tax_prepayment_treatmentCard::class, static function (
    GeneratedServiceClassTestHarness $harness,
    _tax_prepayment_treatmentCard $card
): void {
    $harness->check(_tax_prepayment_treatmentCard::class, 'declares the AP prepayment read model', static function () use ($harness, $card): void {
        $service = (array)($card->services()[0] ?? []);
        $harness->assertSame(\eel_accounts\Service\PrepaymentScheduleService::class, $service['service'] ?? null);
        $harness->assertSame('fetchPeriodContext', $service['method'] ?? null);
        $harness->assertSame(':company.id', $service['params']['companyId'] ?? null);
        $harness->assertSame(':company.accounting_period_id', $service['params']['accountingPeriodId'] ?? null);
    });

    $harness->check(_tax_prepayment_treatmentCard::class, 'renders the inclusive calculation, balances, journal state and correct guidance', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => ['settings' => ['default_currency' => 'GBP']],
            'services' => ['prepayment_period_context' => [
                'available' => true,
                'errors' => [],
                'total_expense_pence' => 55000,
                'total_closing_deferred_pence' => 18000,
                'schedules' => [[
                    'id' => 1,
                    'source_type' => 'transaction',
                    'source_id' => 9307,
                    'source_description' => 'Synthetic annual service contract',
                    'source_date' => '2022-12-30',
                    'source_amount_pence' => 73000,
                    'expense_nominal_code' => '6100',
                    'expense_nominal_name' => 'Subscriptions',
                    'service_start_date' => '2022-12-30',
                    'service_end_date' => '2023-12-29',
                    'total_days' => 365,
                    'unallocated_pence' => 18000,
                    'selected_allocation' => [
                        'expense_pence' => 55000,
                        'closing_deferred_pence' => 18000,
                        'recognised_through_pence' => 55000,
                        'overlap_days' => 275,
                        'overlap_start' => '2022-12-30',
                        'overlap_end' => '2023-09-30',
                        'journal_state' => 'not_posted',
                        'posting_role' => 'deferral',
                        'posting_target_pence' => 18000,
                    ],
                ]],
            ]],
        ]);

        $harness->assertTrue(str_contains($html, '275 of 365 inclusive days'));
        $harness->assertTrue(str_contains($html, 'Accounting Period Expense'));
        $harness->assertSame(false, str_contains($html, 'Selected-AP expense'));
        $harness->assertTrue(str_contains($html, 'Closing Prepayments asset'));
        $harness->assertTrue(str_contains($html, 'Not Posted'));
        $harness->assertTrue(str_contains($html, 'later accounting period has not been created'));
        $harness->assertTrue(str_contains($html, 'bim42201'));
        $harness->assertTrue(str_contains($html, 'bim70066'));
        $harness->assertTrue(str_contains($html, 'frs-105'));
        $harness->assertSame(3, substr_count($html, 'class="button button-inline"'));
        $harness->assertTrue(str_contains($html, 'HMRC - BIM42201'));
        $harness->assertTrue(str_contains($html, 'HMRC - BIM70066'));
        $harness->assertTrue(str_contains($html, 'FRC - FRS 105'));
        $harness->assertSame(false, str_contains($html, 'Accounting and tax guidance:'));
        $harness->assertSame(false, str_contains($html, 'FRS 103'));
        $harness->assertTrue(str_contains($html, '<section class="panel-soft">'));
        $harness->assertTrue(str_contains($html, 'class="table-scroll"'));
        $harness->assertSame(false, str_contains($html, 'class="table-scroll panel-soft"'));
        $harness->assertTrue(str_contains($html, 'name="_table_export_prepare" value="csv"'));
        $panelStart = strpos($html, '<section class="panel-soft">');
        $panelEnd = $panelStart === false ? false : strpos($html, '</section>', $panelStart);
        $exportPosition = strpos($html, 'name="_table_export_prepare" value="csv"');
        $tablePosition = strpos($html, '<table');
        $paginationPosition = strpos($html, 'class="card-toolbar table-footer"');
        $harness->assertTrue($panelStart !== false);
        $harness->assertTrue($panelEnd !== false);
        $harness->assertTrue($exportPosition !== false && $exportPosition > $panelStart && $exportPosition < $panelEnd);
        $harness->assertTrue($tablePosition !== false && $tablePosition > $panelStart && $tablePosition < $panelEnd);
        $harness->assertTrue($paginationPosition !== false && $paginationPosition > $tablePosition && $paginationPosition < $panelEnd);
        $harness->assertTrue(strpos($html, 'HMRC - BIM42201') < strpos($html, 'Amounts use cumulative half-up rounding'));
        $harness->assertTrue(strpos($html, 'Amounts use cumulative half-up rounding') < strpos($html, 'Accounting Period Expense'));
        $harness->assertSame(1, count($card->tables([
            'company' => ['settings' => ['default_currency' => 'GBP']],
            'services' => ['prepayment_period_context' => ['schedules' => []]],
        ])));
    });

    $harness->check(_tax_prepayment_treatmentCard::class, 'renders an open-period calculation without a preview pill', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => ['settings' => ['default_currency' => 'GBP']],
            'services' => ['prepayment_period_context' => [
                'available' => true,
                'errors' => [],
                'total_expense_pence' => 2500,
                'total_closing_deferred_pence' => 7500,
                'schedules' => [[
                    'source_type' => 'transaction',
                    'source_id' => 9309,
                    'source_description' => 'Synthetic open-period cover',
                    'source_date' => '2024-01-01',
                    'source_amount_pence' => 10000,
                    'expense_nominal_code' => '6100',
                    'expense_nominal_name' => 'Insurance',
                    'service_start_date' => '2024-01-01',
                    'service_end_date' => '2024-12-31',
                    'total_days' => 366,
                    'selected_allocation' => [
                        'expense_pence' => 2500,
                        'closing_deferred_pence' => 7500,
                        'recognised_through_pence' => 2500,
                        'overlap_days' => 91,
                        'overlap_start' => '2024-01-01',
                        'overlap_end' => '2024-03-31',
                        'journal_state' => 'preview_only',
                        'posting_role' => 'deferral',
                        'posting_target_pence' => 7500,
                    ],
                ]],
            ]],
        ]);

        $harness->assertSame(false, str_contains($html, 'Preview Only'));
        $harness->assertTrue(str_contains($html, 'Deferral target'));
        $harness->assertSame(false, str_contains($html, 'Run the automated prepayment schedules migration'));
    });

    $harness->check(_tax_prepayment_treatmentCard::class, 'paginates five schedules and opts into condensed view by default', static function () use ($harness, $card): void {
        $schedules = [];
        for ($index = 1; $index <= 6; $index++) {
            $schedules[] = [
                'source_type' => 'transaction',
                'source_id' => $index,
                'source_description' => 'Schedule ' . $index,
                'source_date' => '2024-01-01',
                'source_amount_pence' => 10000,
                'expense_nominal_code' => '6100',
                'expense_nominal_name' => 'Insurance',
                'service_start_date' => '2024-01-01',
                'service_end_date' => '2024-12-31',
                'total_days' => 366,
                'selected_allocation' => [
                    'expense_pence' => 2500,
                    'closing_deferred_pence' => 7500,
                    'recognised_through_pence' => 2500,
                    'overlap_days' => 91,
                    'overlap_start' => '2024-01-01',
                    'overlap_end' => '2024-03-31',
                    'journal_state' => 'not_posted',
                    'posting_role' => 'deferral',
                    'posting_target_pence' => 7500,
                ],
            ];
        }
        $context = [
            'page' => ['page_id' => 'corporation_tax', 'page_cards' => ['tax_prepayment_treatment'], 'csrf_token' => 'test-token'],
            'company' => ['settings' => ['default_currency' => 'GBP']],
            'services' => ['prepayment_period_context' => [
                'available' => true,
                'errors' => [],
                'total_expense_pence' => 15000,
                'total_closing_deferred_pence' => 45000,
                'schedules' => $schedules,
            ]],
        ];

        $html = $card->render($context);
        $harness->assertTrue(str_contains($html, 'Prepayment schedules 1-5 of 6'));
        $harness->assertTrue(str_contains($html, 'Schedule 5'));
        $harness->assertSame(false, str_contains($html, 'Schedule 6'));
        $harness->assertTrue(str_contains($html, 'name="tax_prepayment_treatment_page" value="2"'));
        $harness->assertTrue(str_contains($html, 'data-table-key="tax_prepayment_treatment"'));
        $tables = $card->tables($context);
        $harness->assertSame(1, count($tables));
        $harness->assertTrue(str_contains($tables[0]->exportCsv(), 'Schedule 6'));

        $projectJs = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'project.js');
        $harness->assertTrue(str_contains($projectJs, 'data-table-key="tax_prepayment_treatment"'));
    });
});
