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
$harness->run(AppConfigurationStore::class, function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(AppConfigurationStore::class, 'loads the application config array', function () use ($harness): void {
        $config = AppConfigurationStore::config();

        $harness->assertTrue(is_array($config));
    });

    $harness->check(AppConfigurationStore::class, 'merges anti-fraud and HMRC validator defaults', function () use ($harness): void {
        $config = AppConfigurationStore::config();

        $harness->assertSame('FPH_VALIDATOR', $config['hmrc']['fraud_prevention_validator']['credential_tag'] ?? null);
        $harness->assertSame('GET', $config['hmrc']['fraud_prevention_validator']['validate_method'] ?? null);
    });
});
