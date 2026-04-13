<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(HmrcOutbound::class, function (GeneratedServiceClassTestHarness $harness, HmrcOutbound $outbound): void {
    $harness->check(HmrcOutbound::class, 'normalises VAT numbers and strips GB prefix', function () use ($harness): void {
        $outbound = new HmrcOutbound(
            ['mode' => 'TEST', 'test_base_url' => 'https://example.test'],
            static fn(): array => ['status_code' => 200, 'headers' => [], 'body' => '{}']
        );

        $harness->assertSame('123456789', $outbound->normaliseVatNumber(' GB 123 456 789 '));
    });

    $harness->check(HmrcOutbound::class, 'supports GB VAT lookups', function () use ($harness): void {
        $outbound = new HmrcOutbound(
            ['mode' => 'TEST', 'test_base_url' => 'https://example.test'],
            static fn(): array => ['status_code' => 404, 'headers' => [], 'body' => '{}']
        );

        $harness->assertTrue($outbound->supports('GB'));
        $harness->assertTrue(!$outbound->supports('FR'));
    });

    $harness->check(HmrcOutbound::class, 'returns an error result for blank VAT numbers', function () use ($harness): void {
        $outbound = new HmrcOutbound(
            ['mode' => 'TEST', 'test_base_url' => 'https://example.test'],
            static fn(): array => ['status_code' => 404, 'headers' => [], 'body' => '{}']
        );

        $harness->assertSame('error', $outbound->validate('GB', '')->status);
    });
});
