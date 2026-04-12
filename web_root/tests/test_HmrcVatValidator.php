<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(HmrcVatValidator::class, function (GeneratedServiceClassTestHarness $harness, HmrcVatValidator $validator): void {
    $harness->check(HmrcVatValidator::class, 'supports GB VAT lookups', function () use ($harness): void {
        $validator = new HmrcVatValidator(
            ['mode' => 'TEST', 'test_base_url' => 'https://example.test'],
            static fn(): array => ['status_code' => 404, 'headers' => [], 'body' => '{}']
        );

        $harness->assertTrue($validator->supports('GB'));
        $harness->assertTrue(!$validator->supports('FR'));
    });

    $harness->check(HmrcVatValidator::class, 'returns an error result for blank VAT numbers', function () use ($harness): void {
        $validator = new HmrcVatValidator(
            ['mode' => 'TEST', 'test_base_url' => 'https://example.test'],
            static fn(): array => ['status_code' => 404, 'headers' => [], 'body' => '{}']
        );

        $harness->assertSame('error', $validator->validate('GB', '')->status);
    });
});
