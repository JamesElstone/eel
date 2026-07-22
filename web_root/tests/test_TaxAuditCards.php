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

$harness->run(_tax_audit_areasCard::class, static function (GeneratedServiceClassTestHarness $harness, _tax_audit_areasCard $card): void {
    $harness->check(_tax_audit_areasCard::class, 'uses only the lightweight area index service', static function () use ($harness, $card): void {
        $services = $card->services();
        $definition = (array)($services[0] ?? []);
        $harness->assertSame('fetchAreaIndex', $definition['method'] ?? null);
        $harness->assertSame(':tax_audit.selected_ct_period_id', $definition['params']['ctPeriodId'] ?? null);
        $harness->assertSame(1, count($services));
    });

    $harness->check(_tax_audit_areasCard::class, 'renders source-card selection without a mutation action', static function () use ($harness, $card): void {
        $html = $card->render(taxAuditCardContext());
        $harness->assertTrue(str_contains($html, 'select-tax-audit-area'));
        $harness->assertTrue(str_contains($html, 'View details'));
        $harness->assertTrue(str_contains($html, 'On demand'));
        $harness->assertSame(false, str_contains($html, 'card_action'));
        $harness->assertSame(false, str_contains($html, 'adjustment_amount'));
    });
});

$harness->run(_tax_audit_detailCard::class, static function (GeneratedServiceClassTestHarness $harness, _tax_audit_detailCard $card): void {
    $harness->check(_tax_audit_detailCard::class, 'loads one selected area through shared page context', static function () use ($harness, $card): void {
        $definition = (array)($card->services()[0] ?? []);
        $harness->assertSame('fetchAreaDetail', $definition['method'] ?? null);
        $harness->assertSame(':tax_audit.selected_area', $definition['params']['areaCode'] ?? null);
        $harness->assertSame(':tax_audit.detail_page', $definition['params']['page'] ?? null);
    });

    $harness->check(_tax_audit_detailCard::class, 'renders an empty target until the source selects an area', static function () use ($harness, $card): void {
        $html = $card->render(['services' => ['taxAuditAreaDetail' => ['available' => false, 'empty_selection' => true, 'errors' => []]]]);
        $harness->assertTrue(str_contains($html, 'Select a tax area'));
    });

    $harness->check(_tax_audit_detailCard::class, 'renders source evidence and contextual handoff links read-only', static function () use ($harness, $card): void {
        $context = taxAuditCardContext();
        $context['services']['taxAuditAreaDetail'] = [
            'available' => true,
            'mode' => 'live',
            'mode_label' => 'Live audit preview',
            'amount' => 25.00,
            'expected_amount' => 25.00,
            'reconciliation_difference' => 0.00,
            'reconciliation_status' => 'reconciled',
            'rows' => [[
                'source_type' => 'transaction',
                'source_id' => 42,
                'source_date' => '2023-04-05',
                'label' => 'Staff welfare',
                'source_label' => 'Transaction #42 / journal #8',
                'nominal_code' => '7500',
                'nominal_name' => 'Staff welfare',
                'accounting_amount' => 25.00,
                'tax_adjustment_amount' => 25.00,
                'tax_treatment' => 'disallowable',
                'allocation_method' => 'actual_date',
                'rule_code' => 'staff_welfare_review',
                'rule_version' => '1',
                'rule_source_url' => 'https://www.gov.uk/hmrc-internal-manuals/business-income-manual/bim35500',
            ]],
            'pagination' => ['page' => 1, 'page_count' => 1, 'total_rows' => 1],
        ];
        $html = $card->render($context);
        $harness->assertTrue(str_contains($html, 'Open transaction'));
        $harness->assertTrue(str_contains($html, 'name="transaction_id" value="42"'));
        $harness->assertTrue(str_contains($html, 'href="https://www.gov.uk/hmrc-internal-manuals/business-income-manual/bim35500"'));
        $harness->assertTrue(str_contains($html, 'Reconciled'));
        $harness->assertSame(false, str_contains($html, '<input class="input"'));
        $harness->assertSame(false, str_contains($html, 'card_action'));
    });
});

$harness->run(_tax_audit::class, static function (GeneratedServiceClassTestHarness $harness, _tax_audit $page): void {
    $harness->check(_tax_audit::class, 'uses the eelKit source and target card pair', static function () use ($harness, $page): void {
        $harness->assertSame(['tax_audit_areas', 'tax_audit_detail'], $page->cards());
    });
});

/** @return array<string, mixed> */
function taxAuditCardContext(): array
{
    return [
        'company' => ['id' => 49, 'accounting_period_id' => 79, 'settings' => ['currency_symbol' => '£']],
        'page' => ['page_cards' => ['tax_audit_areas', 'tax_audit_detail']],
        'tax_audit' => [
            'selected_ct_period_id' => 6,
            'selected_area' => 'expense_treatments',
            'selected_area_label' => 'Expense Treatments and Add-Backs',
            'detail_page' => 1,
            'ct_periods' => [[
                'id' => 6,
                'sequence_no' => 1,
                'display_label' => 'Tax Period 1',
                'period_start' => '2022-09-05',
                'period_end' => '2023-09-04',
            ]],
        ],
        'services' => [
            'taxAuditAreaIndex' => [
                'available' => true,
                'mode' => 'live',
                'mode_label' => 'Live audit preview',
                'areas' => [[
                    'area_code' => 'expense_treatments',
                    'area_label' => 'Expense Treatments and Add-Backs',
                    'amount' => 0,
                    'reconciliation_status' => 'reconciled',
                    'source_count' => null,
                ]],
            ],
        ],
    ];
}
