<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(\eel_accounts\Service\HmrcCt600VersionService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\HmrcCt600VersionService $service): void {
    $harness->check(\eel_accounts\Service\HmrcCt600VersionService::class, 'rejects invalid period dates', static function () use ($harness, $service): void {
        $result = $service->resolveForCtPeriod('2025-02-30', '2026-01-31');
        $harness->assertSame(false, $result['ok']);
        $harness->assertSame('CT period dates must use YYYY-MM-DD.', $result['errors'][0]);
    });

    $harness->check(\eel_accounts\Service\HmrcCt600VersionService::class, 'rejects periods over twelve months', static function () use ($harness, $service): void {
        $result = $service->resolveForCtPeriod('2024-01-01', '2025-01-01');
        $harness->assertSame(false, $result['ok']);
        $harness->assertSame('The CT period exceeds 12 months.', $result['errors'][0]);
    });
});
