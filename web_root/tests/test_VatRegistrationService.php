<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(VatRegistrationService::class, function (GeneratedServiceClassTestHarness $harness, VatRegistrationService $service): void {
    $harness->check(VatRegistrationService::class, 'resets validation state to unverified', function () use ($harness): void {
        $service = new VatRegistrationService();
        $reset = $service->resetValidationState(['vat_validation_status' => 'valid']);

        $harness->assertSame('unverified', $reset['vat_validation_status']);
    });

    $harness->check(VatRegistrationService::class, 'normalises VAT numbers to uppercase alphanumeric format', function () use ($harness): void {
        $service = new VatRegistrationService();
        $harness->assertSame('GB123456789', $service->normaliseVatNumber(' gb 123 456 789 '));
    });
});
