<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(VatRegistrationFactoryService::class, function (GeneratedServiceClassTestHarness $harness, VatRegistrationFactoryService $factory): void {
    $harness->check(VatRegistrationFactoryService::class, 'creates a VAT registration service from config', function () use ($harness): void {
        $harness->assertTrue(
            VatRegistrationFactoryService::createFromConfig(
                ['hmrc' => ['vat' => ['mode' => 'TEST', 'test_base_url' => 'https://example.test']]],
                'LIVE'
            ) instanceof VatRegistrationService
        );
    });
});
