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
                'default_currency' => 'GBP',
                'date_format' => 'Y-m-d',
            ],
        ],
        'services' => [
            'company_detail' => [
                'incorporation_date' => '2024-01-01',
                'companies_house_active_director_count' => 1,
            ],
        ],
    ]);

    $harness->check(_companies_company_settingsCard::class, 'renders stored Companies House active director count', static function () use ($harness, $html): void {
        $harness->assertTrue(str_contains($html, 'Companies House active directors'));
        $harness->assertTrue(str_contains($html, 'value="1" readonly'));
    });
});
