<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\S455ReviewService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\S455ReviewService $service): void {
        $harness->check(get_class($service), 'rejects an invalid accounting-period context', static function () use ($harness, $service): void {
            $result = $service->fetchForAccountingPeriod(0, 0);
            $harness->assertSame(false, (bool)($result['available'] ?? true));
            $harness->assertSame([], $result['periods'] ?? null);
        });
    }
);
