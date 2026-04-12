<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(HmrcVatApiClient::class, function (GeneratedServiceClassTestHarness $harness, HmrcVatApiClient $client): void {
    $harness->check(HmrcVatApiClient::class, 'normalises VAT numbers and strips GB prefix', function () use ($harness): void {
        $client = new HmrcVatApiClient(
            ['mode' => 'TEST', 'test_base_url' => 'https://example.test'],
            static fn(): array => ['status_code' => 200, 'headers' => [], 'body' => '{}']
        );

        $harness->assertSame('123456789', $client->normaliseVatNumber(' GB 123 456 789 '));
    });
});
