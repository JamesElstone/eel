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
    $harness->assertSame('DISABLED', \eel_accounts\Store\AccountingConfigurationStore::hmrcXmlMode());
});

$harness->check(\eel_accounts\Store\AccountingConfigurationStore::class, 'returns array-backed configuration sections', static function () use ($harness): void {
    $uploads = \eel_accounts\Store\AccountingConfigurationStore::uploads();
    $harness->assertTrue(is_array($uploads));
    $harness->assertSame(
        test_upload_base_directory(),
        (string)($uploads['upload_base_dir'] ?? '')
    );
    $harness->assertSame([], \eel_accounts\Store\AccountingConfigurationStore::hmrcConfig('missing-service'));
});

$harness->check(\eel_accounts\Store\AccountingConfigurationStore::class, 'limits protected test storage to the configured test temporary tree', static function () use ($harness): void {
    $harness->assertSame(
        true,
        \eel_accounts\Store\AccountingConfigurationStore::isConfiguredTestUploadPath(
            test_tmp_directory() . DIRECTORY_SEPARATOR . 'transmission'
        )
    );
    $harness->assertSame(
        false,
        \eel_accounts\Store\AccountingConfigurationStore::isConfiguredTestUploadPath(
            rtrim((string)APP_ROOT, '\\/') . DIRECTORY_SEPARATOR . 'content'
        )
    );
});
