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

$harness->run(_dividend_capacityCard::class, static function (GeneratedServiceClassTestHarness $harness, _dividend_capacityCard $card): void {
    $harness->check(_dividend_capacityCard::class, 'declares shared dividend context service', static function () use ($harness, $card): void {
        dividend_card_assert_context_service($harness, $card);
    });

    $harness->check(_dividend_capacityCard::class, 'renders reliability warnings as summary cards with related workflow actions', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'id' => 7,
                'accounting_period_id' => 22,
                'settings' => [],
            ],
            'dividends' => [
                'capacity' => [
                    'available' => true,
                    'as_at_date' => '2026-07-04',
                    'reserves_reliable' => true,
                    'reserve_basis_detail' => 'Reserve basis is based on reviewed as-at distributable reserves.',
                    'distributable_reserves_brought_forward' => 100.00,
                    'current_year_profit_loss_after_tax' => 50.00,
                    'dividends_declared' => 10.00,
                    'available_distributable_reserves' => 140.00,
                    'ledger_current_year_profit_loss' => 50.00,
                    'classified_current_year_profit_loss' => 50.00,
                    'estimated_corporation_tax' => 0.00,
                    'unposted_corporation_tax_adjustment' => 0.00,
                    'reliability_warnings' => [[
                        'severity' => 'warning',
                        'title' => 'Bank CSV coverage may be incomplete',
                        'detail' => 'Upload the latest bank CSV before relying on the dividend figure.',
                        'action_label' => 'Open Related Workflow',
                        'action_url' => '?page=uploads',
                        'workflow_page' => 'uploads',
                        'workflow_fields' => [
                            'company_id' => 7,
                            'accounting_period_id' => 22,
                        ],
                    ]],
                ],
                'warnings' => [[
                    'severity' => 'info',
                    'title' => 'Dividend review scope',
                    'detail' => 'Capacity is based on reviewed as-at distributable reserve snapshots.',
                ]],
            ],
        ]);

        $harness->assertTrue(str_contains($html, '<div class="summary-card dividend-capacity-summary-card">'));
        $harness->assertTrue(str_contains($html, '<div class="summary-value">Reserve basis verified</div>'));
        $harness->assertTrue(!str_contains($html, '<div class="summary-label">Distributable reserves</div>'));
        $harness->assertTrue(str_contains($html, '<div class="summary-label">Dividend review scope</div>'));
        $harness->assertTrue(str_contains($html, '<table class="table dividend-capacity-summary">'));
        $harness->assertTrue(str_contains($html, '<th>Title</th><th>Description</th><th class="numeric">Value</th>'));
        $harness->assertTrue(str_contains($html, '<div class="summary-card dividend-capacity-summary-card"><div class="summary-label">Available distributable reserves</div><div class="summary-value">£ 140.00</div></div>'));
        $harness->assertTrue(str_contains($html, '<td>Distributable Reserves B/F</td><td>Distributable Reserves from the previous accounting period</td><td class="numeric">£ 100.00</td>'));
        $harness->assertTrue(str_contains($html, '<td>Profit after Tax</td><td>The reviewed, distributable portion of current-period profit after deducting any unposted Corporation Tax charge.</td><td class="numeric">£ 50.00</td>'));
        $harness->assertTrue(str_contains($html, '<td>Classified Realised Profit</td><td>The current-period profit accepted by the reserve review as realised/distributable, before the remaining unposted CT adjustment.</td><td class="numeric">£ 50.00</td>'));
        $harness->assertTrue(str_contains($html, '<td>L2P relief receivable</td><td>Relief receivable for qualifying later repayments; it reduces the tax charge but does not rewrite the accepted CT600A A80 amount.</td><td class="numeric">£ 0.00</td>'));
        $harness->assertTrue(str_contains($html, '<td>Net estimated tax charge</td><td>Corporation Tax payable less any L2P relief receivable. This is the tax charge used in profit and reserves.</td><td class="numeric">£ 0.00</td>'));
        $harness->assertTrue(str_contains($html, '<div class="summary-value">Warning</div>'));
        $harness->assertTrue(str_contains($html, 'Bank CSV coverage may be incomplete'));
        $harness->assertTrue(str_contains($html, 'Open Related Workflow'));
        $harness->assertTrue(str_contains($html, '<form method="post" action="?page=uploads" data-ajax="true"'));
        $harness->assertTrue(str_contains($html, '<input type="hidden" name="company_id" value="7">'));
        $harness->assertTrue(str_contains($html, '<input type="hidden" name="accounting_period_id" value="22">'));
        $harness->assertTrue(!str_contains($html, '?page=uploads&amp;company_id=7'));
        $harness->assertTrue(
            strpos($html, '<div class="summary-label">Capacity date</div>')
            < strpos($html, '<div class="summary-label">Dividend review scope</div>')
        );
        $harness->assertTrue(
            strpos($html, '<div class="summary-label">Dividend review scope</div>')
            < strpos($html, '<div class="summary-label">Available distributable reserves</div>')
        );
        $harness->assertTrue(!str_contains($html, 'dividend-capacity-overview'));
    });

    $harness->check(_dividend_capacityCard::class, 'renders corporation tax period arithmetic helpers', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'settings' => [
                    'default_currency_symbol' => '&#163;',
                ],
            ],
            'dividends' => [
                'capacity' => [
                    'available' => true,
                    'as_at_date' => '2026-07-04',
                    'reserves_reliable' => true,
                    'reserve_basis_detail' => 'Reserve basis is based on reviewed as-at distributable reserves.',
                    'distributable_reserves_brought_forward' => 5000.00,
                    'current_year_profit_loss_after_tax' => 2025.00,
                    'dividends_declared' => 1000.00,
                    'available_distributable_reserves' => 6025.00,
                    'ledger_current_year_profit_loss' => 4500.00,
                    'classified_current_year_profit_loss' => 4500.00,
                    'posted_corporation_tax_charge' => 475.00,
                    'ordinary_corporation_tax' => 1900.00,
                    'ct600a_tax' => 575.00,
                    'estimated_corporation_tax' => 2475.00,
                    'estimated_tax_charge' => 2475.00,
                    'unposted_corporation_tax_adjustment' => 2000.00,
                    'tax_periods' => [
                        ['estimated_corporation_tax' => 1900.00],
                        ['estimated_corporation_tax' => 575.00],
                    ],
                ],
                'warnings' => [],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'Corporation Tax payable'));
        $harness->assertTrue(str_contains($html, '<td>Corporation Tax payable</td><td>Ordinary CT £ 1,900.00 + CT600A A80 £ 575.00 = £ 2,475.00. CT periods: £ 1,900.00 + £ 575.00 = £ 2,475.00</td><td class="numeric">£ 2,475.00</td>'));
        $harness->assertTrue(str_contains($html, 'Ordinary CT £ 1,900.00 + CT600A A80 £ 575.00 = £ 2,475.00'));
        $harness->assertTrue(str_contains($html, 'Unposted tax charge deducted'));
        $harness->assertTrue(str_contains($html, 'Estimated total tax charge £ 2,475.00 - posted tax charge £ 475.00 = £ 2,000.00'));
    });

    $harness->check(_dividend_capacityCard::class, 'renders warning metric values in the unified summary-card layout', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'settings' => [],
            ],
            'dividends' => [
                'capacity' => [
                    'available' => true,
                    'as_at_date' => '2026-07-04',
                    'reserves_reliable' => false,
                    'reserve_basis_detail' => 'Reserve basis is blocked.',
                    'distributable_reserves_brought_forward' => 100.00,
                    'current_year_profit_loss_after_tax' => 50.00,
                    'dividends_declared' => 10.00,
                    'available_distributable_reserves' => 140.00,
                    'ledger_current_year_profit_loss' => 50.00,
                    'classified_current_year_profit_loss' => 50.00,
                    'estimated_corporation_tax' => 0.00,
                    'unposted_corporation_tax_adjustment' => 0.00,
                ],
                'warnings' => [[
                    'severity' => 'warning',
                    'title' => 'Uncategorised transactions affect capacity',
                    'metric_value' => '12 transaction(s)',
                    'detail' => 'Transactions dated on or before the capacity date are uncategorised or missing a nominal account.',
                ]],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'Uncategorised transactions affect capacity'));
        $harness->assertTrue(str_contains($html, '<div class="summary-label">Uncategorised transactions affect capacity</div>'));
        $harness->assertTrue(str_contains($html, '<div class="summary-value">12 transaction(s)</div>'));
        $harness->assertTrue(str_contains($html, 'Transactions dated on or before the capacity date are uncategorised or missing a nominal account.'));
        $harness->assertTrue(!str_contains($html, '12 transaction(s) dated on or before'));
    });
});

function dividend_card_assert_context_service(GeneratedServiceClassTestHarness $harness, CardInterfaceFramework $card): void
{
    $service = (array)($card->services()[0] ?? []);
    $params = (array)($service['params'] ?? []);

    $harness->assertSame('dividendContext', $service['key'] ?? null);
    $harness->assertSame(\eel_accounts\Service\DividendViewDataService::class, $service['service'] ?? null);
    $harness->assertSame('fetchCapacityContext', $service['method'] ?? null);
    $harness->assertSame(':company.id', $params['companyId'] ?? null);
    $harness->assertSame(':company.accounting_period_id', $params['accountingPeriodId'] ?? null);
}
