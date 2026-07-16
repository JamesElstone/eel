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

$harness->run(\eel_accounts\Service\CompanyDirectorEligibilityService::class, function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(\eel_accounts\Service\CompanyDirectorEligibilityService::class, 'requires a Companies House number', function () use ($harness): void {
        $service = new \eel_accounts\Service\CompanyDirectorEligibilityService(companyDirectorEligibilityTestCompaniesHouseService(1));

        $result = $service->assertSingleActiveDirectorByNumber('');

        $harness->assertSame(false, $result['success'] ?? true);
        $harness->assertSame(true, str_contains((string)($result['errors'][0] ?? ''), 'company number is required'));
    });

    $harness->check(\eel_accounts\Service\CompanyDirectorEligibilityService::class, 'passes a company with one active director', function () use ($harness): void {
        $service = new \eel_accounts\Service\CompanyDirectorEligibilityService(companyDirectorEligibilityTestCompaniesHouseService(1));

        $result = $service->assertSingleActiveDirectorByNumber('01234567');

        $harness->assertSame(true, $result['success'] ?? false);
        $harness->assertSame(1, $result['director_count'] ?? 0);
    });

    $harness->check(\eel_accounts\Service\CompanyDirectorEligibilityService::class, 'supports a company with more than one active director', function () use ($harness): void {
        $service = new \eel_accounts\Service\CompanyDirectorEligibilityService(companyDirectorEligibilityTestCompaniesHouseService(2));

        $result = $service->assertSingleActiveDirectorByNumber('01234567');

        $harness->assertSame(true, $result['success'] ?? false);
        $harness->assertSame(2, $result['director_count'] ?? 0);
    });

    $harness->check(\eel_accounts\Service\CompanyDirectorEligibilityService::class, 'loads company number from the selected company row', function () use ($harness): void {
        if (!InterfaceDB::tableExists('companies')) {
            $harness->skip('Companies table is not available on the default InterfaceDB connection.');
        }

        InterfaceDB::beginTransaction();
        try {
            $companyNumber = 'CDE' . strtoupper(substr(hash('sha256', __FILE__ . microtime(true)), 0, 8));
            InterfaceDB::prepareExecute(
                'INSERT INTO companies (company_name, company_number, companies_house_environment) VALUES (:company_name, :company_number, :environment)',
                [
                    'company_name' => 'Director Eligibility Fixture Limited',
                    'company_number' => $companyNumber,
                    'environment' => 'TEST',
                ]
            );
            $companyId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM companies WHERE company_number = :company_number',
                ['company_number' => $companyNumber]
            );

            $service = new \eel_accounts\Service\CompanyDirectorEligibilityService(companyDirectorEligibilityTestCompaniesHouseService(1));
            $result = $service->assertSingleActiveDirector($companyId);

            $harness->assertSame(true, $result['success'] ?? false);
            $harness->assertSame(1, $result['director_count'] ?? 0);
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});

function companyDirectorEligibilityTestCompaniesHouseService(int $directorCount): \eel_accounts\Service\CompaniesHouseService
{
    return new \eel_accounts\Service\CompaniesHouseService(
        'TEST',
        20,
        static function (array $request) use ($directorCount): array {
            $items = [];
            for ($index = 0; $index < $directorCount; $index++) {
                $items[] = ['officer_role' => 'director', 'name' => 'Director ' . ($index + 1)];
            }

            return [
                'status_code' => 200,
                'headers' => [],
                'body' => json_encode([
                    'items' => $items,
                    'items_per_page' => 100,
                    'start_index' => 0,
                    'total_results' => count($items),
                ], JSON_UNESCAPED_SLASHES),
                'url' => 'https://example.test' . (string)($request['path'] ?? ''),
            ];
        }
    );
}
