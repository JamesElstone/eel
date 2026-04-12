<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(VatRegistrationViewData::class, function (GeneratedServiceClassTestHarness $harness, VatRegistrationViewData $viewData): void {
    $harness->check(VatRegistrationViewData::class, 'builds a stable VAT validation hash', function () use ($harness): void {
        $hash = VatRegistrationViewData::validationHash(
            new VatRegistrationService(),
            [
                'vat_country_code' => 'gb',
                'vat_number' => '123 456 789',
            ]
        );

        $harness->assertSame('GB:123456789', $hash);
    });

    $harness->check(VatRegistrationViewData::class, 'normalises UK country aliases to GB', function () use ($harness): void {
        $harness->assertSame('GB', VatRegistrationViewData::comparisonCountryValue('United Kingdom'));
    });

    $harness->check(VatRegistrationViewData::class, 'extracts address table values from a multiline address', function () use ($harness): void {
        $address = VatRegistrationViewData::addressTableValues(
            'Example Ltd',
            "1 High Street\nLondon\nSW1A 1AA\nUnited Kingdom"
        );

        $harness->assertSame('1 High Street', $address['line1']);
        $harness->assertSame('SW1A 1AA', $address['postcode']);
    });
});
