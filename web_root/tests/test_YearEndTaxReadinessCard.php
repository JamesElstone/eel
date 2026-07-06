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
    $harness->check(_year_end_tax_readinessCard::class, 'renders compact tax readiness status and acknowledgement control', static function () use ($harness, $card): void {
        $html = $card->render(yearEndTaxReadinessCardContext([
            'available' => true,
            'taxable_profit' => 12500,
            'taxable_loss' => 12.34,
            'estimated_corporation_tax' => 2375,
            'losses_carried_forward' => 45.67,
            'periods' => [
                [
                    'period_label' => '05/09/2022 to 04/09/2023',
                    'taxable_profit' => 10000,
                    'estimated_corporation_tax' => 1900,
                    'losses_carried_forward' => 20,
                    'warnings' => [],
                ],
                [
                    'period_label' => '05/09/2023 to 30/09/2023',
                    'taxable_profit' => 2500,
                    'estimated_corporation_tax' => 475,
                    'losses_carried_forward' => 25.67,
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
            'tax_readiness_acknowledged_at' => '2026-07-03 12:00:00',
            'tax_readiness_acknowledged_by' => 'Alex Example using the web_app',
        ]));

        $harness->assertSame(true, str_contains($html, 'save_tax_readiness_acknowledgement'));
        $harness->assertSame(true, str_contains($html, 'Open Tax Workflow'));
        $harness->assertSame(true, str_contains($html, '<form method="post" action="?page=tax" data-ajax="true"'));
        $harness->assertSame(true, str_contains($html, '<input type="hidden" name="company_id" value="33">'));
        $harness->assertSame(true, str_contains($html, '<input type="hidden" name="accounting_period_id" value="70">'));
        $harness->assertSame(false, str_contains($html, '?page=tax&amp;company_id=33'));
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
        $harness->assertSame(true, str_contains($html, 'Estimated CT: $ 1,900.00'));
        $harness->assertSame(true, str_contains($html, '1 warning'));
        $harness->assertSame(false, str_contains($html, 'Corporation Tax Computation'));
        $harness->assertSame(false, str_contains($html, 'Loss schedule'));
        $harness->assertSame(false, str_contains($html, 'Capital Allowances'));
        $harness->assertSame(false, str_contains($html, '$ 100.00'));
        $harness->assertSame(false, str_contains($html, '$ 200.00'));
        $harness->assertSame(false, str_contains($html, '$ 300.00'));
        $harness->assertSame(false, str_contains($html, '$ 400.00'));
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
                    'period_label' => '01/10/2025 to 30/09/2026',
                    'taxable_profit' => 8000,
                    'estimated_corporation_tax' => 1520,
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
        $harness->assertSame(true, str_contains($html, 'Ready for review'));
        $harness->assertSame(true, str_contains($html, 'Open Tax Workflow'));
    });
});

function yearEndTaxReadinessCardContext(array $taxReadiness, array $review = []): array
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
                'review' => $review,
            ],
        ],
        'services' => [
            'yearEndTaxReadiness' => $taxReadiness,
        ],
    ];
}

function yearEndTaxReadinessChecklistContext(array $taxReadiness, array $review = []): array
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
                'review' => $review,
                'tax_readiness' => $taxReadiness,
            ],
        ],
        'services' => [],
    ];
}
