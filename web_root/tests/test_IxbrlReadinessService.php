<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    IxbrlReadinessService::class,
    static function (GeneratedServiceClassTestHarness $harness, IxbrlReadinessService $service): void {
        $harness->check(IxbrlReadinessService::class, 'blocks generation when company and period are missing', static function () use ($harness, $service): void {
            $readiness = $service->getReadiness(0, 0);
            $harness->assertSame(false, $readiness['can_build_facts']);
            $harness->assertTrue(count($readiness['blocking_errors']) > 0);
        });
    }
);
