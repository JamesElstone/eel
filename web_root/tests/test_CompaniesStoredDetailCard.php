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

$harness->run(_companies_stored_detailCard::class, static function (GeneratedServiceClassTestHarness $harness, _companies_stored_detailCard $card): void {
    $html = $card->render([
        'company' => [
            'id' => 27,
        ],
        'services' => [
            'company_detail' => [
                'company_status' => 'active',
                'companies_house_environment' => 'TEST',
                'companies_house_active_director_count' => 1,
                'companies_house_officers_last_checked_at' => '2026-07-01 12:34:56',
                'companies_house_officers_json' => json_encode([
                    'active_director_count' => 1,
                    'items' => [
                        ['officer_role' => 'director', 'name' => 'Example Director'],
                    ],
                ], JSON_UNESCAPED_SLASHES),
            ],
        ],
    ]);

    $harness->check(_companies_stored_detailCard::class, 'renders stored director count and officers payload', static function () use ($harness, $html): void {
        $harness->assertTrue(str_contains($html, 'Active directors'));
        $harness->assertTrue(str_contains($html, 'value="1" readonly'));
        $harness->assertTrue(str_contains($html, 'Officers API response'));
        $harness->assertTrue(str_contains($html, 'Example Director'));
    });
});
