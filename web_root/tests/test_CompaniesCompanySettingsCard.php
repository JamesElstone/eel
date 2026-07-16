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

$harness->run(_companies_company_settingsCard::class, static function (GeneratedServiceClassTestHarness $harness, _companies_company_settingsCard $card): void {
    $html = $card->render([
        'company' => [
            'id' => 27,
            'name' => 'Example Limited',
            'number' => '01234567',
            'settings' => [
                'utr' => '1234567890',
                'associated_company_count' => 0,
                'qualifying_activity_ceased_on' => '2026-06-30',
                'default_currency' => 'GBP',
                'default_currency_symbol' => '&#163;',
                'date_format' => 'Y-m-d',
            ],
        ],
        'services' => [
            'company_detail' => [
                'incorporation_date' => '2024-01-01',
                'companies_house_active_director_count' => 1,
            ],
            'vat_support_scope' => [
                'tax_year_end_read_only' => false,
                'message' => '',
            ],
        ],
    ]);

    $harness->check(_companies_company_settingsCard::class, 'renders stored Companies House active director count', static function () use ($harness, $html): void {
        $harness->assertTrue(str_contains($html, 'Companies House active directors'));
        $harness->assertTrue(str_contains($html, 'value="1" readonly'));
    });

    $harness->check(_companies_company_settingsCard::class, 'renders currency abbreviation with symbol', static function () use ($harness, $html): void {
        $harness->assertTrue(str_contains($html, '<option value="GBP" selected>GBP - £</option>'));
    });

    $harness->check(_companies_company_settingsCard::class, 'renders the qualifying activity cessation date as an editable tax setting', static function () use ($harness, $html): void {
        $harness->assertTrue(str_contains($html, 'name="qualifying_activity_ceased_on"'));
        $harness->assertTrue(str_contains($html, 'value="2026-06-30"'));
        $harness->assertFalse(str_contains($html, 'name="qualifying_activity_ceased_on" disabled'));
    });

    $harness->check(_companies_company_settingsCard::class, 'disables the cessation setting when Tax and Year End are read only', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'id' => 27,
                'name' => 'Example Limited',
                'number' => '01234567',
                'settings' => [
                    'utr' => '1234567890',
                    'associated_company_count' => 0,
                    'qualifying_activity_ceased_on' => '',
                    'default_currency' => 'GBP',
                    'default_currency_symbol' => '&#163;',
                    'date_format' => 'Y-m-d',
                ],
            ],
            'services' => [
                'company_detail' => [],
                'vat_support_scope' => [
                    'tax_year_end_read_only' => true,
                    'message' => 'Tax and Year End are read only.',
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'disabled title="Tax and Year End are read only."'));
    });
});
