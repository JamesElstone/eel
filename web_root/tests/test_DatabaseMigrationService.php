<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\DatabaseMigrationService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\DatabaseMigrationService $service
    ): void {
        $harness->check($service::class, 'runs the idempotent migration entry point and reports progress', static function () use ($harness, $service): void {
            $progress = [];
            $result = $service->runOutstanding(static function (string $message, int $percent) use (&$progress): void {
                $progress[] = [$message, $percent];
            });

            $harness->assertTrue((bool)($result['success'] ?? false));
            $harness->assertSame(0, (int)($result['exit_code'] ?? -1));
            $harness->assertSame(99, (int)($progress[0][1] ?? 0));
        });
    }
);
