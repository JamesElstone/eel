<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    Ct600BuilderService::class,
    static function (GeneratedServiceClassTestHarness $harness, Ct600BuilderService $service): void {
        $harness->check(Ct600BuilderService::class, 'missing company fails cleanly', static function () use ($harness, $service): void {
            $result = $service->buildCt600Xml(0, 0);
            $harness->assertSame(false, $result['ok']);
            $harness->assertTrue(count($result['errors']) > 0);
        });
    }
);
