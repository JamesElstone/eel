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
$harness->run(_incorporation::class, static function (
    GeneratedServiceClassTestHarness $harness,
    _incorporation $page
): void {
    $harness->check(_incorporation::class, 'places recorded share capital on the Status tab', static function () use ($harness, $page): void {
        $layout = $page->cardLayout();
        $statusCards = [];
        $sharesCards = [];

        foreach ($layout as $tab) {
            if (($tab['tab'] ?? '') === 'Status') {
                $statusCards = (array)($tab['cards'] ?? []);
            }
            if (($tab['tab'] ?? '') === 'Shares') {
                $sharesCards = (array)($tab['cards'] ?? []);
            }
        }

        $harness->assertSame(['incorporation_status', 'incorporation_share_capital'], $statusCards);
        $harness->assertSame(['incorporation_add_shares'], $sharesCards);
    });
});
