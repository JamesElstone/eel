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
$harness->run(_tax_rates::class, static function (GeneratedServiceClassTestHarness $harness, _tax_rates $page): void {
    $harness->check(_tax_rates::class, 'includes rate and treatment rule cards', static function () use ($harness, $page): void {
        $harness->assertSame('Rates / Thresholds', $page->title());
        $harness->assertSame(['tax_rates_ct', 'tax_rates_vat', 'tax_thresholds_vat', 'tax_treatment_rules'], $page->cards());
    });
});
