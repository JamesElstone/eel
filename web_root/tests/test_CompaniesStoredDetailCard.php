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
                        ['officer_role' => 'director', 'name' => 'Former Director', 'resigned_on' => '2025-01-01'],
                        ['officer_role' => 'secretary', 'name' => 'Example Secretary'],
                    ],
                ], JSON_UNESCAPED_SLASHES),
            ],
            'incorporation_document_status' => [
                'downloaded' => true,
                'downloaded_at' => '2026-07-04 12:34:56',
                'filename' => '12344321_newinc_2022-09-05.pdf',
            ],
        ],
    ]);

    $harness->check(_companies_stored_detailCard::class, 'renders stored director count and active director names', static function () use ($harness, $html): void {
        $harness->assertTrue(str_contains($html, 'Active directors'));
        $harness->assertTrue(str_contains($html, 'value="1" readonly'));
        $harness->assertTrue(str_contains($html, 'Director names'));
        $harness->assertTrue(str_contains($html, 'Example Director'));
        $harness->assertSame(false, str_contains($html, 'Former Director'));
        $harness->assertSame(false, str_contains($html, 'Example Secretary'));
    });

    $harness->check(_companies_stored_detailCard::class, 'renders incorporation document download status', static function () use ($harness, $html): void {
        $harness->assertTrue(str_contains($html, 'Incorporation Document downloaded'));
        $harness->assertTrue(str_contains($html, '<td>true</td>'));
        $harness->assertTrue(str_contains($html, 'Incorporation Document last successfully downloaded'));
        $harness->assertTrue(str_contains($html, 'Incorporation Document filename'));
        $harness->assertTrue(str_contains($html, '12344321_newinc_2022-09-05.pdf'));
    });
});
