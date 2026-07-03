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
    $harness->check(_year_end_tax_readinessCard::class, 'renders tax readiness acknowledgement control with chicken confirmation', static function () use ($harness, $card): void {
        $html = $card->render(yearEndTaxReadinessCardContext([
            'available' => true,
            'taxable_profit' => 12500,
            'taxable_loss' => 12.34,
            'estimated_corporation_tax' => 2375,
            'losses_carried_forward' => 45.67,
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
        ]));

        $harness->assertSame(true, str_contains($html, 'save_tax_readiness_acknowledgement'));
        $harness->assertSame(true, str_contains($html, 'I acknowledge that the corporation tax estimate, computation steps, and loss schedule have been reviewed'));
        $harness->assertSame(true, str_contains($html, 'data-chicken-check="true"'));
        $harness->assertSame(true, str_contains($html, 'data-chicken-button-class="button danger"'));
        $harness->assertSame(true, str_contains($html, '>I Agree</button>'));
        $harness->assertSame(true, str_contains($html, 'checked required'));
        $harness->assertSame(true, str_contains($html, '$12,500.00'));
        $harness->assertSame(true, str_contains($html, '$12.34'));
        $harness->assertSame(true, str_contains($html, '$2,375.00'));
        $harness->assertSame(true, str_contains($html, '$45.67'));
        $harness->assertSame(true, str_contains($html, '$100.00'));
        $harness->assertSame(true, str_contains($html, '$200.00'));
        $harness->assertSame(true, str_contains($html, '$300.00'));
        $harness->assertSame(true, str_contains($html, '$400.00'));
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
