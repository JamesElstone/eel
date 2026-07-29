<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlGenerationHistoryService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\IxbrlGenerationHistoryService $service
    ): void {
        $harness->check($service::class, 'returns no history without a valid scope', static function () use ($harness, $service): void {
            $harness->assertSame([], $service->fetch(0, 0));
            $harness->assertSame([], $service->fetch(-1, 1));
        });

        $harness->check($service::class, 'returns an empty ordered history for an unused scope', static function () use ($harness, $service): void {
            $harness->assertSame([], $service->fetch(PHP_INT_MAX - 10, PHP_INT_MAX - 11));
        });
    }
);
