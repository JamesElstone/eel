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
$harness->run(_incorporation_share_allocationCard::class, static function (
    GeneratedServiceClassTestHarness $harness,
    _incorporation_share_allocationCard $card
): void {
    $harness->check(_incorporation_share_allocationCard::class, 'contains the shareholding allocation controls', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => ['id' => 7],
            'services' => ['ownership' => [
                'available' => true,
                'parties' => [[
                    'id' => 12,
                    'legal_name' => 'Example Owner',
                    'holdings' => [[
                        'id' => 31,
                        'quantity' => 100,
                        'share_class' => 'Ordinary',
                        'effective_from' => '2026-01-01',
                        'effective_to' => null,
                    ]],
                ]],
                'share_classes' => [[
                    'id' => 4,
                    'share_class' => 'Ordinary',
                    'quantity' => 200,
                    'issued_at' => '2026-01-01 00:00:00',
                ], [
                    'id' => 5,
                    'share_class' => 'Deferred',
                    'quantity' => 50,
                    'issued_at' => '2026-01-02 00:00:00',
                ]],
                'reconciliation' => ['rows' => [[
                    'share_class_id' => 4,
                    'held_quantity' => 100,
                ], [
                    'share_class_id' => 5,
                    'held_quantity' => 50,
                ]]],
            ]],
        ]);

        $harness->assertTrue(str_contains($html, 'Add shareholding'));
        $harness->assertTrue(str_contains($html, '<th>Manage</th>'));
        $harness->assertTrue(str_contains($html, 'Last effective date'));
        $harness->assertTrue(str_contains($html, 'save_shareholding'));
        $harness->assertTrue(str_contains($html, 'end_shareholding'));
        $harness->assertTrue(str_contains($html, 'Example Owner'));
        $harness->assertTrue(str_contains($html, 'Effective from'));
        $harness->assertTrue(str_contains($html, 'Current'));
        $harness->assertTrue(str_contains($html, 'Issued Shares'));
        $harness->assertTrue(str_contains($html, '100 of 200'));
        $harness->assertTrue(str_contains($html, 'data-remaining-shares="100"'));
        $harness->assertFalse(str_contains($html, 'Deferred 50 of 50'));
        $harness->assertFalse(str_contains($html, 'name="effective_from"'));
    });
});
