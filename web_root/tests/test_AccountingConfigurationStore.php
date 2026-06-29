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
$harness->check(\eel_accounts\Store\AccountingConfigurationStore::class, 'normalises runtime mode defaults', static function () use ($harness): void {
    $harness->assertSame('TEST', \eel_accounts\Store\AccountingConfigurationStore::companiesHouseMode());
    $harness->assertSame('TEST', \eel_accounts\Store\AccountingConfigurationStore::hmrcMode());
});

$harness->check(\eel_accounts\Store\AccountingConfigurationStore::class, 'returns array-backed configuration sections', static function () use ($harness): void {
    $harness->assertTrue(is_array(\eel_accounts\Store\AccountingConfigurationStore::uploads()));
    $harness->assertSame([], \eel_accounts\Store\AccountingConfigurationStore::hmrcConfig('missing-service'));
});
