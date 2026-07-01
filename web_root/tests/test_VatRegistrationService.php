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
$harness->run(\eel_accounts\Service\VatRegistrationService::class, function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\VatRegistrationService $service): void {
    $harness->check(\eel_accounts\Service\VatRegistrationService::class, 'resets validation state to unverified', function () use ($harness): void {
        $service = new \eel_accounts\Service\VatRegistrationService();
        $reset = $service->resetValidationState(['vat_validation_status' => 'valid']);

        $harness->assertSame('unverified', $reset['vat_validation_status']);
    });

    $harness->check(\eel_accounts\Service\VatRegistrationService::class, 'normalises VAT numbers to uppercase alphanumeric format', function () use ($harness): void {
        $service = new \eel_accounts\Service\VatRegistrationService();
        $harness->assertSame('GB123456789', $service->normaliseVatNumber(' gb 123 456 789 '));
    });

    $harness->check(\eel_accounts\Service\VatRegistrationService::class, 'requires exact company name match for HMRC VAT validation', function () use ($harness, $service): void {
        $matchingWarnings = $service->compareHmrcAndCompaniesHouse(
            ['company_name' => 'VAT Fixture Limited'],
            \eel_accounts\Service\VatValidationResultService::valid('hmrc', 'VAT Fixture Limited')
        );
        $mismatchWarnings = $service->compareHmrcAndCompaniesHouse(
            ['company_name' => 'VAT Fixture Limited'],
            \eel_accounts\Service\VatValidationResultService::valid('hmrc', 'VAT Fixture')
        );
        $missingNameWarnings = $service->compareHmrcAndCompaniesHouse(
            ['company_name' => 'VAT Fixture Limited'],
            \eel_accounts\Service\VatValidationResultService::valid('hmrc', '')
        );

        $harness->assertCount(0, $matchingWarnings);
        $harness->assertCount(1, $mismatchWarnings);
        $harness->assertCount(1, $missingNameWarnings);
        $harness->assertSame(true, str_contains($mismatchWarnings[0], 'exactly match'));
    });
});
