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
$harness->run(\eel_accounts\Service\CompaniesHouseService::class, function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\CompaniesHouseService $service): void {
    $harness->check(\eel_accounts\Service\CompaniesHouseService::class, 'builds unique registered office address lines from company settings', function () use ($harness): void {
        $lines = \eel_accounts\Service\CompaniesHouseService::storedAddressLines([
            'registered_office_care_of' => 'Accounts Team',
            'registered_office_po_box' => 'PO Box 1',
            'registered_office_premises' => 'Unit 4',
            'registered_office_address_line_1' => 'High Street',
            'registered_office_address_line_2' => 'High Street',
            'registered_office_locality' => 'London',
            'registered_office_region' => 'Greater London',
            'registered_office_postal_code' => 'SW1A 1AA',
            'registered_office_country' => 'United Kingdom',
        ]);

        $harness->assertSame(
            [
                'Accounts Team',
                'PO Box 1',
                'Unit 4',
                'High Street',
                'London',
                'Greater London',
                'SW1A 1AA',
                'United Kingdom',
            ],
            $lines
        );
    });

    $harness->check(\eel_accounts\Service\CompaniesHouseService::class, 'single active director passes eligibility check', function () use ($harness): void {
        $service = companiesHouseServiceWithOfficerPages([
            [
                ['officer_role' => 'director', 'name' => 'Example Director'],
            ],
        ]);

        $result = $service->checkSingleActiveDirectorByNumber('01234567');

        $harness->assertSame(true, $result['success'] ?? false);
        $harness->assertSame(1, $result['director_count'] ?? 0);
    });

    $harness->check(\eel_accounts\Service\CompaniesHouseService::class, 'two active directors fail eligibility check', function () use ($harness): void {
        $service = companiesHouseServiceWithOfficerPages([
            [
                ['officer_role' => 'director', 'name' => 'First Director'],
                ['officer_role' => 'director', 'name' => 'Second Director'],
            ],
        ]);

        $result = $service->checkSingleActiveDirectorByNumber('01234567');

        $harness->assertSame(false, $result['success'] ?? true);
        $harness->assertSame(2, $result['director_count'] ?? 0);
        $harness->assertSame(true, str_contains((string)($result['errors'][0] ?? ''), 'exactly 1 active director'));
    });

    $harness->check(\eel_accounts\Service\CompaniesHouseService::class, 'resigned directors are ignored', function () use ($harness): void {
        $service = companiesHouseServiceWithOfficerPages([
            [
                ['officer_role' => 'director', 'name' => 'Current Director'],
                ['officer_role' => 'director', 'name' => 'Former Director', 'resigned_on' => '2024-01-31'],
                ['officer_role' => 'secretary', 'name' => 'Company Secretary'],
            ],
        ]);

        $result = $service->checkSingleActiveDirectorByNumber('01234567');

        $harness->assertSame(true, $result['success'] ?? false);
        $harness->assertSame(1, $result['director_count'] ?? 0);
    });

    $harness->check(\eel_accounts\Service\CompaniesHouseService::class, 'paginated officer results are counted', function () use ($harness): void {
        $service = companiesHouseServiceWithOfficerPages([
            [
                ['officer_role' => 'director', 'name' => 'First Director'],
            ],
            [
                ['officer_role' => 'director', 'name' => 'Second Director'],
            ],
        ]);

        $result = $service->checkSingleActiveDirectorByNumber('01234567');

        $harness->assertSame(false, $result['success'] ?? true);
        $harness->assertSame(2, $result['director_count'] ?? 0);
    });

    $harness->check(\eel_accounts\Service\CompaniesHouseService::class, 'invalid officers response blocks eligibility check', function () use ($harness): void {
        $service = new \eel_accounts\Service\CompaniesHouseService(
            'TEST',
            20,
            static fn(array $request): array => [
                'status_code' => 200,
                'headers' => [],
                'body' => json_encode(['kind' => 'officer-list'], JSON_UNESCAPED_SLASHES),
                'url' => 'https://example.test' . (string)($request['path'] ?? ''),
            ]
        );

        $result = $service->checkSingleActiveDirectorByNumber('01234567');

        $harness->assertSame(false, $result['success'] ?? true);
        $harness->assertSame(true, str_contains((string)($result['errors'][0] ?? ''), 'valid officers list'));
    });

    $harness->check(\eel_accounts\Service\CompaniesHouseService::class, 'officers API failure blocks eligibility check', function () use ($harness): void {
        $service = new \eel_accounts\Service\CompaniesHouseService(
            'TEST',
            20,
            static fn(array $request): array => [
                'status_code' => 500,
                'headers' => [],
                'body' => json_encode(['message' => 'temporary outage'], JSON_UNESCAPED_SLASHES),
                'url' => 'https://example.test' . (string)($request['path'] ?? ''),
            ]
        );

        $result = $service->checkSingleActiveDirectorByNumber('01234567');

        $harness->assertSame(false, $result['success'] ?? true);
        $harness->assertSame(true, str_contains((string)($result['errors'][0] ?? ''), 'could not be checked'));
    });
});

function companiesHouseServiceWithOfficerPages(array $pages): \eel_accounts\Service\CompaniesHouseService
{
    return new \eel_accounts\Service\CompaniesHouseService(
        'TEST',
        20,
        static function (array $request) use ($pages): array {
            $query = (array)($request['query'] ?? []);
            $startIndex = (int)($query['start_index'] ?? 0);
            $pageIndex = max(0, $startIndex);
            $items = (array)($pages[$pageIndex] ?? []);
            $totalResults = count($pages);

            return [
                'status_code' => 200,
                'headers' => [],
                'body' => json_encode([
                    'items' => $items,
                    'items_per_page' => 1,
                    'start_index' => $pageIndex,
                    'total_results' => $totalResults,
                ], JSON_UNESCAPED_SLASHES),
                'url' => 'https://example.test' . (string)($request['path'] ?? ''),
            ];
        }
    );
}
