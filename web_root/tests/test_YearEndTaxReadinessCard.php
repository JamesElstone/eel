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

$harness->run(_year_end_tax_readinessCard::class, static function (GeneratedServiceClassTestHarness $harness, _year_end_tax_readinessCard $card): void {
    $harness->check(_year_end_tax_readinessCard::class, 'declares the year-end checklist service needed on the Tax page', static function () use ($harness, $card): void {
        $services = $card->services();

        $harness->assertSame('yearEndChecklist', (string)($services[0]['key'] ?? ''));
        $harness->assertSame(\eel_accounts\Service\YearEndChecklistService::class, (string)($services[0]['service'] ?? ''));
        $harness->assertSame('fetchChecklist', (string)($services[0]['method'] ?? ''));
        $harness->assertSame(':company.id', (string)($services[0]['params']['companyId'] ?? ''));
        $harness->assertSame(':company.accounting_period_id', (string)($services[0]['params']['accountingPeriodId'] ?? ''));
    });

    $harness->check(_year_end_tax_readinessCard::class, 'renders compact tax readiness status and acknowledgement control', static function () use ($harness, $card): void {
        $html = $card->render(yearEndTaxReadinessCardContext([
            'available' => true,
            'taxable_profit' => 12500,
            'taxable_loss' => 12.34,
            'estimated_corporation_tax' => 2375,
            'losses_carried_forward' => 45.67,
            'ct_period_count' => 2,
            'provision' => [
                'available' => true,
                'status' => 'not_posted',
                'estimated_corporation_tax' => 2375,
                'posted_corporation_tax_charge' => 0,
                'unposted_corporation_tax_adjustment' => 2375,
            ],
            'periods' => [
                [
                    'ct_period_id' => 501,
                    'ct_period_display_sequence_no' => 1,
                    'period_label' => '05/09/2022 to 04/09/2023',
                    'accounting_profit' => 9000,
                    'disallowable_add_backs' => 400,
                    'depreciation_add_back' => 700,
                    'capital_add_backs' => 250,
                    'capital_allowances' => 100,
                    'taxable_before_losses' => 10000,
                    'taxable_profit' => 10000,
                    'estimated_corporation_tax' => 1900,
                    'estimated_rate' => 0.19,
                    'loss_brought_forward' => 0,
                    'loss_created' => 0,
                    'loss_utilised' => 0,
                    'losses_carried_forward' => 20,
                    'ct_rate_bands' => [
                        [
                            'financial_year' => 'FY2023',
                            'taxable_profit' => 10000,
                            'main_rate' => 0.19,
                            'small_profits_rate' => 0.19,
                            'marginal_relief' => 0,
                            'liability' => 1900,
                            'basis' => 'small_profits_rate',
                        ],
                    ],
                    'warnings' => [],
                ],
                [
                    'ct_period_id' => 502,
                    'ct_period_display_sequence_no' => 2,
                    'period_label' => '05/09/2023 to 30/09/2023',
                    'accounting_profit' => 2600,
                    'disallowable_add_backs' => 50,
                    'depreciation_add_back' => 0,
                    'capital_add_backs' => 0,
                    'capital_allowances' => 150,
                    'taxable_before_losses' => 2500,
                    'taxable_profit' => 2500,
                    'estimated_corporation_tax' => 475,
                    'estimated_rate' => 0.19,
                    'loss_brought_forward' => 20,
                    'loss_created' => 0,
                    'loss_utilised' => 20,
                    'losses_carried_forward' => 25.67,
                    'ct_rate_bands' => [],
                    'warnings' => ['Review final period'],
                ],
            ],
            'steps' => [
                ['label' => 'Accounting profit or loss', 'amount' => 12500],
            ],
            'schedule' => [
                [
                    'label' => 'FY 2025',
                    'loss_created' => 100,
                    'loss_brought_forward' => 200,
                    'loss_utilised' => 300,
                    'loss_carried_forward' => 400,
                ],
            ],
        ], [
            'acknowledged_at' => '2026-07-03 12:00:00',
            'acknowledged_by' => 'Alex Example using the web_app',
        ]));

        $harness->assertSame(true, str_contains($html, 'save_tax_readiness_acknowledgement'));
        $harness->assertSame(false, str_contains($html, 'Open Tax Workflow'));
        $harness->assertSame(true, str_contains($html, 'Overall Tax Position'));
        $harness->assertSame(true, str_contains($html, 'CT Periods In This Accounting Period'));
        $harness->assertSame(true, str_contains($html, 'CT Period 1: 05/09/2022 to 04/09/2023'));
        $harness->assertSame(true, str_contains($html, 'CT Period 2: 05/09/2023 to 30/09/2023'));
        $harness->assertSame(true, str_contains($html, 'Taxable Profit Bridge'));
        $harness->assertSame(true, str_contains($html, 'Accounting profit or loss'));
        $harness->assertSame(true, str_contains($html, 'Add back capital expenditure'));
        $harness->assertSame(true, str_contains($html, 'Deduct capital allowances'));
        $harness->assertSame(true, str_contains($html, 'Loss Movement'));
        $harness->assertSame(true, str_contains($html, 'Rate Bands'));
        $harness->assertSame(true, str_contains($html, 'Financial Year (FY)'));
        $harness->assertSame(true, str_contains($html, 'FY2023'));
        $harness->assertSame(true, str_contains($html, 'Open this CT period in Tax Workflow'));
        $harness->assertSame(true, str_contains($html, 'name="ct_period_id" value="501"'));
        $harness->assertSame(true, str_contains($html, 'name="ct_period_id" value="502"'));
        $harness->assertSame(true, str_contains($html, 'summary-grid four'));
        $harness->assertSame(false, str_contains($html, 'Tax Readiness Snapshot'));
        $harness->assertSame(false, str_contains($html, 'Post / Update CT Provisions'));
        $harness->assertSame(false, str_contains($html, 'post_ct_provisions'));
        $harness->assertSame(true, str_contains($html, '<form method="post" action="?page=corporation_tax" data-ajax="true"'));
        $harness->assertSame(true, str_contains($html, '<input type="hidden" name="company_id" value="33">'));
        $harness->assertSame(true, str_contains($html, '<input type="hidden" name="accounting_period_id" value="70">'));
        $harness->assertSame(false, str_contains($html, '?page=corporation_tax&amp;company_id=33'));
        $harness->assertSame(true, str_contains($html, 'Approved at 2026-07-03 12:00:00 by Alex Example using the web_app.'));
        $harness->assertSame(true, str_contains($html, 'name="tax_readiness_acknowledgement" value="0"'));
        $harness->assertSame(true, str_contains($html, 'Revoke approval'));
        $harness->assertSame(false, str_contains($html, 'data-chicken-check="true"'));
        $harness->assertSame(false, str_contains($html, 'checked required'));
        $harness->assertSame(true, str_contains($html, '$ 12,500.00'));
        $harness->assertSame(true, str_contains($html, '$ 2,375.00'));
        $harness->assertSame(true, str_contains($html, '$ 45.67'));
        $harness->assertSame(true, str_contains($html, '05/09/2022 to 04/09/2023'));
        $harness->assertSame(true, str_contains($html, '05/09/2023 to 30/09/2023'));
        $harness->assertSame(true, str_contains($html, '1 warning'));
        $harness->assertSame(false, str_contains($html, 'Corporation Tax Computation'));
        $harness->assertSame(false, str_contains($html, 'Loss schedule'));
    });

    $harness->check(_year_end_tax_readinessCard::class, 'renders tax readiness from year-end checklist context without a card service payload', static function () use ($harness, $card): void {
        $html = $card->render(yearEndTaxReadinessChecklistContext([
            'available' => true,
            'taxable_profit' => 8000,
            'estimated_corporation_tax' => 1520,
            'losses_carried_forward' => 12.50,
            'confidence_status' => 'ready_for_review',
            'confidence_label' => 'Ready for review',
            'periods' => [
                [
                    'ct_period_id' => 601,
                    'ct_period_display_sequence_no' => 1,
                    'period_label' => '01/10/2025 to 30/09/2026',
                    'accounting_profit' => 8000,
                    'disallowable_add_backs' => 0,
                    'depreciation_add_back' => 0,
                    'capital_allowances' => 0,
                    'taxable_before_losses' => 8000,
                    'taxable_profit' => 8000,
                    'estimated_corporation_tax' => 1520,
                    'estimated_rate' => 0.19,
                    'losses_carried_forward' => 12.50,
                    'warnings' => [],
                ],
            ],
            'warnings' => [],
        ]));

        $harness->assertSame(true, str_contains($html, '$ 8,000.00'));
        $harness->assertSame(true, str_contains($html, '$ 1,520.00'));
        $harness->assertSame(true, str_contains($html, '$ 12.50'));
        $harness->assertSame(true, str_contains($html, '01/10/2025 to 30/09/2026'));
        $harness->assertSame(false, str_contains($html, 'Open Tax Workflow'));
        $harness->assertSame(true, str_contains($html, 'Overall Tax Position'));
        $harness->assertSame(true, str_contains($html, 'CT Period 1: 01/10/2025 to 30/09/2026'));
        $harness->assertSame(true, str_contains($html, 'summary-grid four'));
        $harness->assertSame(false, str_contains($html, 'Post / Update CT Provisions'));
    });

    $harness->check(_year_end_tax_readinessCard::class, 'renders tax readiness from its declared checklist service payload', static function () use ($harness, $card): void {
        $context = yearEndTaxReadinessChecklistContext([
            'available' => true,
            'taxable_profit' => 4000,
            'estimated_corporation_tax' => 760,
            'losses_carried_forward' => 0,
            'periods' => [],
            'warnings' => [],
        ]);
        $context['services']['yearEndChecklist'] = $context['year_end']['checklist'];
        unset($context['year_end']);

        $html = $card->render($context);

        $harness->assertSame(true, str_contains($html, '$ 4,000.00'));
        $harness->assertSame(true, str_contains($html, '$ 760.00'));
        $harness->assertSame(false, str_contains($html, 'Open Tax Workflow'));
    });
});

function yearEndTaxReadinessCardContext(array $taxReadiness, array $acknowledgement = []): array
{
    return [
        'company' => [
            'id' => 33,
            'name' => 'Tax Readiness Fixture Limited',
            'accounting_period_id' => 70,
            'settings' => [
                'default_currency_symbol' => '&#36;',
            ],
        ],
        'year_end' => [
            'checklist' => [
                'checks_flat' => $acknowledgement === [] ? [] : [[
                    'check_code' => 'tax_readiness_acknowledgement',
                    'acknowledgement_state' => 'current',
                    'acknowledgement_current' => true,
                    'review_acknowledgement' => $acknowledgement,
                ]],
            ],
        ],
        'services' => [
            'yearEndTaxReadiness' => $taxReadiness,
        ],
    ];
}

function yearEndTaxReadinessChecklistContext(array $taxReadiness, array $acknowledgement = []): array
{
    return [
        'company' => [
            'id' => 33,
            'name' => 'Tax Readiness Fixture Limited',
            'accounting_period_id' => 70,
            'settings' => [
                'default_currency_symbol' => '&#36;',
            ],
        ],
        'year_end' => [
            'checklist' => [
                'checks_flat' => $acknowledgement === [] ? [] : [[
                    'check_code' => 'tax_readiness_acknowledgement',
                    'acknowledgement_state' => 'current',
                    'acknowledgement_current' => true,
                    'review_acknowledgement' => $acknowledgement,
                ]],
                'tax_readiness' => $taxReadiness,
            ],
        ],
        'services' => [],
    ];
}
