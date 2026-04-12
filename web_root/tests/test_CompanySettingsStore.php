<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(CompanySettingsStore::class, function (GeneratedServiceClassTestHarness $harness, CompanySettingsStore $store): void {
    $harness->check(CompanySettingsStore::class, 'exposes expected default settings', function () use ($harness): void {
        $defaults = CompanySettingsStore::defaults();

        $harness->assertSame('GBP', $defaults['default_currency'] ?? null);
        $harness->assertSame('/var/eel_accounts/uploads', $defaults['uploads_path'] ?? null);
    });

    $harness->check(CompanySettingsStore::class, 'includes duplicate row check in definitions', function () use ($harness): void {
        $definitions = CompanySettingsStore::definitions();
        $harness->assertTrue(isset($definitions['enable_duplicate_row_check']));
    });
});
