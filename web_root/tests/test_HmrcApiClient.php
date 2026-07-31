<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Client\HmrcApiClient::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Client\HmrcApiClient $client): void {
        $harness->check(\eel_accounts\Client\HmrcApiClient::class, 'missing submit path fails before any endpoint call', static function () use ($harness, $client): void {
            $result = $client->submitCorporationTaxReturn(['body' => '<xml/>'], 'TEST', []);
            $harness->assertSame(false, $result['success']);
            $harness->assertTrue(str_contains((string)$result['endpoint'], 'test-api.service.hmrc.gov.uk'));
        });

        $harness->check(\eel_accounts\Client\HmrcApiClient::class, 'reads only the renamed CT600 REST configuration section', static function () use ($harness): void {
            $source = (string)file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '..'
                . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'eel_accounts'
                . DIRECTORY_SEPARATOR . 'client' . DIRECTORY_SEPARATOR . 'HmrcApiClient.php');
            $harness->assertTrue(str_contains($source, "['hmrc']['ct600_rest']"));
            $harness->assertFalse(str_contains($source, "['hmrc']['ct600']"));
        });
    }
);
