<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(VatRegistrationServiceFactory::class, function (GeneratedServiceClassTestHarness $harness, VatRegistrationServiceFactory $factory): void {
    $harness->check(VatRegistrationServiceFactory::class, 'creates a VAT registration service from config', function () use ($harness): void {
        $harness->assertTrue(
            VatRegistrationServiceFactory::createFromConfig(
                ['hmrc' => ['vat' => ['mode' => 'TEST', 'test_base_url' => 'https://example.test']]],
                'LIVE'
            ) instanceof VatRegistrationService
        );
    });
});
