<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    HmrcFraudPreventionHeaderService::class,
    static function (GeneratedServiceClassTestHarness $harness, HmrcFraudPreventionHeaderService $service): void {
        $harness->check(HmrcFraudPreventionHeaderService::class, 'redacts secret-like headers for storage', static function () use ($harness, $service): void {
            $redacted = $service->redactHeadersForStorage(['Authorization' => 'Bearer token', 'Gov-Vendor-Product-Name' => 'eel']);
            $harness->assertSame('[redacted]', $redacted['Authorization']);
            $harness->assertSame('eel', $redacted['Gov-Vendor-Product-Name']);
        });
    }
);
