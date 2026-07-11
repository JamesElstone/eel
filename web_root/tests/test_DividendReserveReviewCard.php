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

$harness->run(_dividend_reserve_reviewCard::class, static function (GeneratedServiceClassTestHarness $harness, _dividend_reserve_reviewCard $card): void {
    $harness->check(_dividend_reserve_reviewCard::class, 'declares shared focused capacity context service', static function () use ($harness, $card): void {
        $service = (array)($card->services()[0] ?? []);
        $params = (array)($service['params'] ?? []);

        $harness->assertSame('dividendContext', $service['key'] ?? null);
        $harness->assertSame(\eel_accounts\Service\DividendViewDataService::class, $service['service'] ?? null);
        $harness->assertSame('fetchCapacityContext', $service['method'] ?? null);
        $harness->assertSame(':company.id', $params['companyId'] ?? null);
        $harness->assertSame(':company.accounting_period_id', $params['accountingPeriodId'] ?? null);
    });

    $harness->check(_dividend_reserve_reviewCard::class, 'renders director-friendly reserve review guidance', static function () use ($harness, $card): void {
        $harness->assertSame('Distributable Profit Review', $card->title());

        $html = $card->render([
            'company' => [
                'id' => 7,
                'accounting_period_id' => 22,
                'settings' => [
                    'default_currency_symbol' => '&#163;',
                ],
            ],
            'dividends' => [
                'reserve_review' => [
                    'available' => true,
                    'status' => 'missing',
                    'status_label' => 'Reserve review missing',
                    'as_at_date' => '2026-07-04',
                    'summary' => [
                        'brought_forward_distributable_reserves' => 1000.00,
                        'distributable_current_profit' => 500.00,
                        'dividends_declared' => 100.00,
                        'closing_distributable_reserves' => 1400.00,
                        'ledger_profit_loss' => 750.00,
                        'realised_profit_amount' => 800.00,
                        'realised_loss_amount' => 50.00,
                        'unrealised_loss_amount' => 0.00,
                        'tax_charge_amount' => 0.00,
                        'dividend_distribution_amount' => 0.00,
                        'unknown_amount' => 25.00,
                    ],
                    'treatments' => [
                        'realised_profit',
                        'realised_loss',
                        'unrealised_gain',
                        'unrealised_loss',
                        'non_distributable',
                        'capital',
                        'tax_charge',
                        'dividend_distribution',
                        'unknown',
                    ],
                    'rows' => [
                        [
                            'nominal_account_id' => 101,
                            'nominal_code' => '4000',
                            'nominal_name' => 'Sales',
                            'profit_effect' => 800.00,
                            'default_treatment' => 'realised_profit',
                            'treatment' => 'realised_profit',
                        ],
                        [
                            'nominal_account_id' => 102,
                            'nominal_code' => '4999',
                            'nominal_name' => 'Unclear income',
                            'profit_effect' => 25.00,
                            'default_treatment' => 'unknown',
                            'treatment' => 'unknown',
                        ],
                        [
                            'nominal_account_id' => 103,
                            'nominal_code' => '7000',
                            'nominal_name' => 'Revaluation movement',
                            'profit_effect' => 100.00,
                            'default_treatment' => 'realised_profit',
                            'treatment' => 'unrealised_gain',
                        ],
                    ],
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'This review checks which parts of the company&#039;s profit can safely support dividends.'));
        $harness->assertTrue(str_contains($html, 'Most ordinary sales and expenses are classified automatically.'));
        $harness->assertTrue(str_contains($html, 'If unsure, leave as Unknown and ask your accountant.'));
        $harness->assertTrue(str_contains($html, 'Normal earned income that can usually support dividends.'));
        $harness->assertTrue(str_contains($html, 'Normal business cost that reduces dividend capacity.'));
        $harness->assertTrue(str_contains($html, 'Tax cost that reduces dividend capacity.'));
        $harness->assertTrue(str_contains($html, 'Paper gain, usually not counted for dividends.'));
        $harness->assertTrue(str_contains($html, 'Paper loss, treated cautiously and reduces dividend capacity.'));
        $harness->assertTrue(str_contains($html, 'Profit not available for dividends.'));
        $harness->assertTrue(str_contains($html, 'Capital, share, or balance-sheet item, not normal dividend profit.'));
        $harness->assertTrue(str_contains($html, 'Dividend already paid or declared, reduces reserves.'));
        $harness->assertTrue(str_contains($html, 'Not safe to rely on until reviewed.'));
        $harness->assertTrue(str_contains($html, '<span class="badge success">Auto-classified</span>'));
        $harness->assertTrue(str_contains($html, '<span class="badge danger">Ask accountant</span>'));
        $harness->assertTrue(str_contains($html, '<span class="badge warning">Needs review</span>'));
        $harness->assertTrue(str_contains($html, 'You cannot save this review while Unknown amounts remain.'));
        $harness->assertTrue(str_contains($html, 'Leave as Unknown if you are unsure; this amount cannot support dividends until reviewed.'));
        $harness->assertTrue(str_contains($html, 'Check this carefully before relying on it for dividend capacity.'));
        $harness->assertTrue(str_contains($html, 'name="card_action" value="Dividend"'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="save_dividend_reserve_review"'));
        $harness->assertTrue(str_contains($html, 'name="treatment[101]"'));
        $harness->assertTrue(str_contains($html, 'This records the current distributable profit review used to support dividend capacity. Only save if the classifications look right or have been checked.'));
    });
});
