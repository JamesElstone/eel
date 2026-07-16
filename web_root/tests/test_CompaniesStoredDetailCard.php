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
                'companies_house_officers_last_checked_at' => '2026-07-01 12:34:56',
            ],
            'company_directors' => [
                ['id' => 1, 'full_name' => 'Example Director', 'is_active' => 1],
                ['id' => 2, 'full_name' => 'Former Director', 'is_active' => 0, 'resigned_on' => '2025-01-01'],
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
