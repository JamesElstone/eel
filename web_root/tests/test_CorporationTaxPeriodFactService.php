<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\CorporationTaxPeriodFactService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\CorporationTaxPeriodFactService $service): void {
        $harness->check(get_class($service), 'rejects an invalid CT-period context', static function () use ($harness, $service): void {
            $result = $service->fetchForCtPeriod(0, 0);
            $harness->assertSame(false, (bool)($result['available'] ?? true));
            $harness->assertSame(false, (bool)($result['confirmed'] ?? true));
        });
    }
);
