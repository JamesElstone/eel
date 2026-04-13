<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(StatementUploadService::class, function (GeneratedServiceClassTestHarness $harness, StatementUploadService $service): void {
    $harness->check(StatementUploadService::class, 'returns null for a missing file MIME detection', function () use ($harness, $service): void {
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('detectMimeType');
        $method->setAccessible(true);

        $harness->assertSame(null, $method->invoke($service, APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'missing-file.bin'));
    });
});
