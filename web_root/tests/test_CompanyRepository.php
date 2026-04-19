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
$harness->run(CompanyRepository::class, function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(CompanyRepository::class, 'normalises Companies House profile fields for storage', function () use ($harness): void {
        $repository = new CompanyRepository();
        $result = $repository->normaliseCompaniesHouseProfileForStorage([
            'company_status' => 'active',
            'can_file' => true,
            'registered_office_address' => [
                'address_line_1' => '1 Test Street',
                'postal_code' => 'AB1 2CD',
            ],
        ], 'LIVE');

        $harness->assertSame('active', $result['company_status'] ?? null);
        $harness->assertSame('1 Test Street', $result['registered_office_address_line_1'] ?? null);
        $harness->assertSame('AB1 2CD', $result['registered_office_postal_code'] ?? null);
        $harness->assertSame(1, $result['can_file'] ?? null);
        $harness->assertSame('LIVE', $result['companies_house_environment'] ?? null);
    });
});
