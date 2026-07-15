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
                'total_expense_pence' => 42945,
                'total_closing_deferred_pence' => 14055,
                'schedules' => [[
                    'id' => 1,
                    'source_type' => 'transaction',
                    'source_id' => 42,
                    'source_description' => 'Annual service',
                    'source_date' => '2022-12-30',
                    'source_amount_pence' => 57000,
                    'expense_nominal_code' => '6100',
                    'expense_nominal_name' => 'Subscriptions',
                    'service_start_date' => '2022-12-30',
                    'service_end_date' => '2023-12-29',
                    'total_days' => 365,
                    'unallocated_pence' => 14055,
                    'selected_allocation' => [
                        'expense_pence' => 42945,
                        'closing_deferred_pence' => 14055,
                        'recognised_through_pence' => 42945,
                        'overlap_days' => 275,
                        'overlap_start' => '2022-12-30',
                        'overlap_end' => '2023-09-30',
                        'journal_state' => 'not_posted',
                        'posting_role' => 'deferral',
                        'posting_target_pence' => 14055,
                    ],
                ]],
            ]],
        ]);

        $harness->assertTrue(str_contains($html, '275 of 365 inclusive days'));
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
    });

    $harness->check(_tax_prepayment_treatmentCard::class, 'labels an unposted open-period calculation as a preview', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => ['settings' => ['default_currency' => 'GBP']],
            'services' => ['prepayment_period_context' => [
                'available' => true,
                'errors' => [],
                'total_expense_pence' => 2500,
                'total_closing_deferred_pence' => 7500,
                'schedules' => [[
                    'source_type' => 'transaction',
                    'source_id' => 42,
                    'source_description' => 'Annual cover',
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

        $harness->assertTrue(str_contains($html, 'Preview Only'));
        $harness->assertSame(false, str_contains($html, 'Run the automated prepayment schedules migration'));
    });
});
