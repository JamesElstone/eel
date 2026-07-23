<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->check(_api_modeCard::class, 'offers disabled as a safe Companies House XML environment', static function () use ($harness): void {
    $contents = (string)file_get_contents(__DIR__ . '/../content/cards/api_mode.php');
    $harness->assertSame(true, str_contains($contents, 'value="DISABLED"'));
    $harness->assertSame(true, str_contains($contents, '$companiesHouseAccountsFilingMode === \'DISABLED\''));
    $action = (string)file_get_contents(__DIR__ . '/../content/actions/ApiModeAction.php');
    $harness->assertSame(true, str_contains($action, "['DISABLED', 'TEST', 'LIVE']"));
    $harness->assertSame(false, str_contains($action, 'normaliseEnvironmentMode((string)$request->input(\'ch_accounts_filing_mode\'))'));
});
