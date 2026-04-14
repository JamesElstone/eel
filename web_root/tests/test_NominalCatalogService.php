<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(NominalCatalogService::class, function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(NominalCatalogService::class, 'normalises subtype and account rows from decoded payloads', function () use ($harness): void {
        $service = new NominalCatalogService();
        $payload = [
            'subtypes' => [['code' => 'bank'], 'ignore me'],
            'accounts' => [['code' => '1200'], 123],
        ];

        $harness->assertCount(1, $service->normaliseSubtypeRows($payload));
        $harness->assertCount(1, $service->normaliseAccountRows($payload));
    });
});
