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
$harness->run(\eel_accounts\Service\TaxWorkingsService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\TaxWorkingsService $service): void {
    $harness->check(\eel_accounts\Service\TaxWorkingsService::class, 'returns unavailable state without selected context', static function () use ($harness, $service): void {
        $result = $service->fetchWorkings(0, 0);

        $harness->assertSame(false, (bool)($result['available'] ?? true));
        $harness->assertTrue(str_contains((string)(($result['errors'] ?? [])[0] ?? ''), 'Select a company'));
    });

    $harness->check(\eel_accounts\Service\TaxGuidanceService::class, 'exposes expected HMRC guidance URLs', static function () use ($harness): void {
        $harness->assertSame('https://www.gov.uk/capital-allowances/annual-investment-allowance', \eel_accounts\Service\TaxGuidanceService::url('aia'));
        $harness->assertSame('https://www.gov.uk/capital-allowances/business-cars', \eel_accounts\Service\TaxGuidanceService::url('business_cars'));
        $harness->assertSame('https://www.gov.uk/guidance/corporation-tax-marginal-relief', \eel_accounts\Service\TaxGuidanceService::url('marginal_relief'));
    });
});
