<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    IxbrlRenderService::class,
    static function (GeneratedServiceClassTestHarness $harness, IxbrlRenderService $service): void {
        $harness->check(IxbrlRenderService::class, 'refuses generation when no fact run exists', static function () use ($harness, $service): void {
            $result = $service->generatePreview(0, 0);
            $harness->assertSame(false, $result['success']);
        });
    }
);
