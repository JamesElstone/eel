<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(MimeTypeHelper::class, function (GeneratedServiceClassTestHarness $harness, MimeTypeHelper $helper): void {
    $harness->check(MimeTypeHelper::class, 'returns null for a missing file', function () use ($harness): void {
        $harness->assertSame(null, MimeTypeHelper::detectFromFile(APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'missing-file.bin'));
    });
});
