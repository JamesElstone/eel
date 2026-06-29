<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(\eel_accounts\Service\VatRegistrationViewDataService::class, function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\VatRegistrationViewDataService $viewData): void {
    $harness->check(\eel_accounts\Service\VatRegistrationViewDataService::class, 'builds a stable VAT validation hash', function () use ($harness): void {
        $hash = \eel_accounts\Service\VatRegistrationViewDataService::validationHash(
            new \eel_accounts\Service\VatRegistrationService(),
            [
                'vat_country_code' => 'gb',
                'vat_number' => '123 456 789',
            ]
        );

        $harness->assertSame('GB:123456789', $hash);
    });

    $harness->check(\eel_accounts\Service\VatRegistrationViewDataService::class, 'normalises UK country aliases to GB', function () use ($harness): void {
        $harness->assertSame('GB', \eel_accounts\Service\VatRegistrationViewDataService::comparisonCountryValue('United Kingdom'));
    });

    $harness->check(\eel_accounts\Service\VatRegistrationViewDataService::class, 'extracts address table values from a multiline address', function () use ($harness): void {
        $address = \eel_accounts\Service\VatRegistrationViewDataService::addressTableValues(
            'Example Ltd',
            "1 High Street\nLondon\nSW1A 1AA\nUnited Kingdom"
        );

        $harness->assertSame('1 High Street', $address['line1']);
        $harness->assertSame('SW1A 1AA', $address['postcode']);
    });
});
