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
$harness->check(AccountingConfigurationStore::class, 'normalises runtime mode defaults', static function () use ($harness): void {
    $harness->assertSame('TEST', AccountingConfigurationStore::companiesHouseMode());
    $harness->assertSame('TEST', AccountingConfigurationStore::hmrcMode());
});

$harness->check(AccountingConfigurationStore::class, 'returns array-backed configuration sections', static function () use ($harness): void {
    $harness->assertTrue(is_array(AccountingConfigurationStore::uploads()));
    $harness->assertSame([], AccountingConfigurationStore::hmrcConfig('missing-service'));
});
