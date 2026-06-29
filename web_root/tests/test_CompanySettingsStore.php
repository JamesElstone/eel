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
$harness->run(\eel_accounts\Store\CompanySettingsStore::class, function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Store\CompanySettingsStore $store): void {
    $harness->check(\eel_accounts\Store\CompanySettingsStore::class, 'exposes expected default settings', function () use ($harness): void {
        $defaults = \eel_accounts\Store\CompanySettingsStore::defaults();

        $harness->assertSame('GBP', $defaults['default_currency'] ?? null);
        $harness->assertSame('d/m/Y', $defaults['date_format'] ?? null);
        $harness->assertSame('TEST', $defaults['hmrc_mode'] ?? null);
        $harness->assertSame('/var/eel_accounts/uploads', $defaults['uploads_path'] ?? null);
    });

    $harness->check(\eel_accounts\Store\CompanySettingsStore::class, 'includes duplicate row check in definitions', function () use ($harness): void {
        $definitions = \eel_accounts\Store\CompanySettingsStore::definitions();
        $harness->assertTrue(isset($definitions['enable_duplicate_row_check']));
        $harness->assertTrue(isset($definitions['hmrc_mode']));
    });
});
