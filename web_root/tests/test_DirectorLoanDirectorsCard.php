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
$harness->run(_director_loan_directorsCard::class, static function (GeneratedServiceClassTestHarness $harness, _director_loan_directorsCard $card): void {
    $harness->check(_director_loan_directorsCard::class, 'renders active and former Companies House facts read-only', static function () use ($harness, $card): void {
        $html = $card->render(['services' => ['directors' => [
            [
                'full_name' => 'James Example',
                'officer_role' => 'director',
                'appointed_on' => '2020-01-01',
                'resigned_on' => null,
                'last_synced_at' => '2026-07-16 12:00:00',
            ],
            [
                'full_name' => 'Brian Example',
                'officer_role' => 'director',
                'appointed_on' => '2018-01-01',
                'resigned_on' => '2021-12-31',
                'last_synced_at' => '2026-07-16 12:00:00',
            ],
        ]]]);

        $harness->assertTrue(str_contains($html, 'James Example'));
        $harness->assertTrue(str_contains($html, 'Brian Example'));
        $harness->assertTrue(str_contains($html, 'Active'));
        $harness->assertTrue(str_contains($html, 'Resigned / status'));
        $harness->assertSame(false, str_contains($html, '<form'));
    });
});
