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
            'taxable_loss' => 0,
            'estimated_corporation_tax' => 2375,
            'losses_carried_forward' => 0,
            'steps' => [
                ['label' => 'Accounting profit or loss', 'amount' => 12500],
            ],
            'schedule' => [
                [
                    'label' => 'FY 2025',
                    'loss_created' => 0,
                    'loss_brought_forward' => 0,
                    'loss_utilised' => 0,
                    'loss_carried_forward' => 0,
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
    });
});

function yearEndTaxReadinessCardContext(array $taxReadiness, array $review = []): array
{
    return [
        'company' => [
            'id' => 33,
            'name' => 'Tax Readiness Fixture Limited',
            'accounting_period_id' => 70,
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
