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

$harness->check(_api_modeCard::class, 'separates HMRC REST and XML environments and fails XML closed', static function () use ($harness): void {
    $contents = (string)file_get_contents(__DIR__ . '/../content/cards/api_mode.php');
    $harness->assertTrue(str_contains($contents, 'HMRC REST Environment'));
    $harness->assertTrue(str_contains($contents, 'HMRC XML Environment'));
    $harness->assertTrue(str_contains($contents, 'name="hmrc_xml_mode"'));
    $harness->assertTrue(str_contains($contents, '$hmrcXmlMode === \'DISABLED\''));
    $action = (string)file_get_contents(__DIR__ . '/../content/actions/ApiModeAction.php');
    $harness->assertTrue(str_contains($action, "setHmrcXmlMode(\$requested_hmrcXmlMode)"));
});
