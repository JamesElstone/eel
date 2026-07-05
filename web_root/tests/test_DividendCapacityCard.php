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
    $harness->check(_dividend_capacityCard::class, 'renders reliability warning panels with related workflow action', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
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
                        'action_url' => '?page=uploads&company_id=7&accounting_period_id=22',
                    ]],
                ],
                'warnings' => [],
            ],
        ]);

        $harness->assertTrue(str_contains($html, '<section class="panel-soft settings-stack">'));
        $harness->assertTrue(str_contains($html, '<span class="badge warning">Warning</span>'));
        $harness->assertTrue(str_contains($html, 'Bank CSV coverage may be incomplete'));
        $harness->assertTrue(str_contains($html, 'Open Related Workflow'));
        $harness->assertTrue(str_contains($html, '?page=uploads&amp;company_id=7&amp;accounting_period_id=22'));
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
                    'estimated_corporation_tax' => 2475.00,
                    'unposted_corporation_tax_adjustment' => 2000.00,
                    'tax_periods' => [
                        ['estimated_corporation_tax' => 1900.00],
                        ['estimated_corporation_tax' => 575.00],
                    ],
                ],
                'warnings' => [],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'Estimated Corporation Tax'));
        $harness->assertTrue(str_contains($html, '&#163; 1,900.00 + &#163; 575.00 = &#163; 2,475.00'));
        $harness->assertTrue(str_contains($html, 'Unposted Corporation Tax deducted'));
        $harness->assertTrue(str_contains($html, 'Estimated Corporation Tax &#163; 2,475.00 - posted Corporation Tax &#163; 475.00 = &#163; 2,000.00'));
    });
});
