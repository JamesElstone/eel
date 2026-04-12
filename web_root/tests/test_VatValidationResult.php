<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(VatValidationResult::class, function (GeneratedServiceClassTestHarness $harness, VatValidationResult $result): void {
    $harness->check(VatValidationResult::class, 'builds valid VAT validation results', function () use ($harness): void {
        $valid = VatValidationResult::valid('hmrc', 'Example Ltd', '1 High Street');

        $harness->assertSame('valid', $valid->status);
        $harness->assertSame('Example Ltd', $valid->name);
    });

    $harness->check(VatValidationResult::class, 'builds error VAT validation results', function () use ($harness): void {
        $error = VatValidationResult::error('hmrc', 'Boom');

        $harness->assertSame('error', $error->status);
        $harness->assertSame('Boom', $error->error);
    });
});
