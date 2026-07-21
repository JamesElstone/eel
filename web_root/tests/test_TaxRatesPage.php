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
$harness->run(_tax_artifacts::class, static function (GeneratedServiceClassTestHarness $harness, _tax_artifacts $page): void {
    $harness->check(_tax_artifacts::class, 'includes rate and treatment rule cards', static function () use ($harness, $page): void {
        $harness->assertSame('Rates / Thresholds / Artifacts', $page->title());
        $harness->assertSame(['tax_rates_ct', 'tax_rates_ct600_rim', 'tax_companies_house_accounts_schemas', 'tax_rates_vat', 'tax_thresholds_vat', 'tax_treatment_rules'], $page->cards());
    });
});
