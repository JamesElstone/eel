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

$harness->run(_dividend_declareCard::class, static function (GeneratedServiceClassTestHarness $harness, _dividend_declareCard $card): void {
    $harness->check(_dividend_declareCard::class, 'declares shared capacity and declaration participant services', static function () use ($harness, $card): void {
        $services = $card->services();
        $service = (array)($services[0] ?? []);
        $params = (array)($service['params'] ?? []);

        $harness->assertSame('dividendContext', $service['key'] ?? null);
        $harness->assertSame(\eel_accounts\Service\DividendViewDataService::class, $service['service'] ?? null);
        $harness->assertSame('fetchCapacityContext', $service['method'] ?? null);
        $harness->assertSame(':company.id', $params['companyId'] ?? null);
        $harness->assertSame(':company.accounting_period_id', $params['accountingPeriodId'] ?? null);
        $participants = (array)($services[1] ?? []);
        $harness->assertSame('dividendDeclarationParticipants', $participants['key'] ?? null);
        $harness->assertSame(\eel_accounts\Service\DividendService::class, $participants['service'] ?? null);
        $harness->assertSame('fetchDeclarationParticipants', $participants['method'] ?? null);
    });

    $harness->check(_dividend_declareCard::class, 'renders focused service results', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => ['id' => 7, 'accounting_period_id' => 22],
            'services' => [
                'dividendContext' => [
                    'capacity' => [
                        'available' => true,
                        'reserves_reliable' => true,
                        'as_at_date' => '2026-06-30',
                        'available_distributable_reserves' => 100,
                        'accounting_period' => ['period_start' => '2026-01-01', 'period_end' => '2026-12-31'],
                    ],
                    'is_locked' => false,
                ],
                'dividendDeclarationParticipants' => [
                    'shareholdings' => [[
                        'party_id' => 92,
                        'legal_name' => 'Focused shareholder',
                        'share_class' => 'ORDINARY',
                        'quantity' => 100,
                        'effective_from' => '2026-01-01',
                        'effective_to' => null,
                    ]],
                    'directors' => [[
                        'id' => 7,
                        'full_name' => 'Focused director',
                        'appointed_on' => '2026-01-01',
                        'resigned_on' => null,
                    ]],
                ],
            ],
        ]);
        $harness->assertTrue(str_contains($html, '<option value="92">'));
        $harness->assertTrue(str_contains($html, 'Focused shareholder'));
        $harness->assertTrue(str_contains($html, 'Focused director'));
    });

    $baseContext = [
        'company' => [
            'id' => 7,
            'accounting_period_id' => 22,
        ],
        'dividends' => [
            'capacity' => [
                'available' => true,
                'reserves_reliable' => true,
                'as_at_date' => '2026-06-30',
                'available_distributable_reserves' => 250.00,
                'accounting_period' => [
                    'period_start' => '2026-01-01',
                    'period_end' => '2026-06-30',
                ],
            ],
            'declaration_participants' => [
                'shareholdings' => [[
                    'party_id' => 91,
                    'legal_name' => 'Dividend shareholder',
                    'share_class' => 'ORDINARY',
                    'quantity' => 100,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                ]],
                'directors' => [[
                    'id' => 12,
                    'full_name' => 'Dividend director',
                    'appointed_on' => '2026-01-01',
                    'resigned_on' => null,
                ]],
            ],
        ],
    ];

    $harness->check(_dividend_declareCard::class, 'renders shareholder and director choices when enabled', static function () use ($harness, $card, $baseContext): void {
        $html = $card->render($baseContext);

        $harness->assertTrue(str_contains($html, 'Maximum currently available'));
        $harness->assertTrue(str_contains($html, 'name="shareholder_party_id" required'));
        $harness->assertTrue(str_contains($html, 'Dividend shareholder — 100 ORDINARY'));
        $harness->assertTrue(str_contains($html, 'name="director_id" required'));
        $harness->assertTrue(str_contains($html, 'Dividend director'));
        $harness->assertTrue(str_contains($html, 'name="settlement_target" value="unpaid_dividend_liability"'));
        $harness->assertFalse(str_contains($html, 'Form Disabled - Reason:'));
    });

    $harness->check(_dividend_declareCard::class, 'disables declaration controls when distributable reserves are negative', static function () use ($harness, $card, $baseContext): void {
        $context = $baseContext;
        $context['dividends']['capacity']['available_distributable_reserves'] = -12.34;
        $html = $card->render($context);

        $harness->assertTrue(str_contains($html, 'Form Disabled - Reason: Available distributable reserves are negative.'));
        $harness->assertTrue(str_contains($html, 'id="dividend_amount" type="number" name="amount" step="0.01" min="0.01" max="0.00" disabled'));
        $harness->assertTrue(str_contains($html, '<button class="button primary" type="submit" disabled>Declare Dividend</button>'));
    });

    $harness->check(_dividend_declareCard::class, 'disables declaration controls when reserve basis is unreliable', static function () use ($harness, $card, $baseContext): void {
        $context = $baseContext;
        $context['dividends']['capacity']['reserves_reliable'] = false;
        $context['dividends']['capacity']['reserve_basis_detail'] = 'Dividend declaration is blocked until current-year reserve movements are classified and reviewed.';
        $html = $card->render($context);

        $harness->assertTrue(str_contains($html, 'Form Disabled - Reason: Dividend declaration is blocked until current-year reserve movements are classified and reviewed.'));
        $harness->assertTrue(str_contains($html, 'id="dividend_declaration_date" type="date" name="declaration_date" value="2026-06-30" min="2026-01-01" max="2026-06-30" disabled'));
        $harness->assertTrue(str_contains($html, 'id="dividend_amount" type="number" name="amount" step="0.01" min="0.01" max="250.00" disabled'));
        $harness->assertTrue(str_contains($html, 'id="dividend_shareholder_party_id" name="shareholder_party_id" required disabled'));
        $harness->assertTrue(str_contains($html, 'id="dividend_director_id" name="director_id" required disabled'));
        $harness->assertTrue(str_contains($html, 'id="dividend_description" name="description" value="Interim dividend" disabled'));
        $harness->assertTrue(str_contains($html, '<button class="button primary" type="submit" disabled>Declare Dividend</button>'));
    });

    $harness->check(_dividend_declareCard::class, 'keeps declaration controls enabled when the period ends in the future and reserves are reliable', static function () use ($harness, $card, $baseContext): void {
        $context = $baseContext;
        $context['dividends']['capacity']['accounting_period']['period_end'] = (new DateTimeImmutable('today'))->modify('+1 day')->format('Y-m-d');
        $context['dividends']['capacity']['as_at_date'] = (new DateTimeImmutable('today'))->format('Y-m-d');
        $html = $card->render($context);

        $harness->assertFalse(str_contains($html, 'Form Disabled - Reason:'));
        $harness->assertFalse(str_contains($html, 'id="dividend_shareholder_party_id" name="shareholder_party_id" required disabled'));
    });
});
