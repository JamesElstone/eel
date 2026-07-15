<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'PageServiceTestFactory.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->run(CompanyAction::class, function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof CompanyAction) {
        throw new RuntimeException('Unexpected CompanyAction instance.');
    }

    $normaliseSearchTerm = new ReflectionMethod(CompanyAction::class, 'normaliseSearchTerm');
    $normaliseSearchTerm->setAccessible(true);
    $decodeProfilePayload = new ReflectionMethod(CompanyAction::class, 'decodeProfilePayload');
    $decodeProfilePayload->setAccessible(true);
    $mapProfileResult = new ReflectionMethod(CompanyAction::class, 'mapProfileResult');
    $mapProfileResult->setAccessible(true);
    $companiesHouseEnvironment = new ReflectionMethod(CompanyAction::class, 'companiesHouseEnvironment');
    $companiesHouseEnvironment->setAccessible(true);
    $validateAccountingPeriodPayload = new ReflectionMethod(CompanyAction::class, 'validateAccountingPeriodPayload');
    $validateAccountingPeriodPayload->setAccessible(true);

    $harness->check('CompanyAction', 'implements the action interface', function () use ($harness, $instance): void {
        $harness->assertSame(true, $instance instanceof ActionInterfaceFramework);
    });

    $harness->check('CompanyAction', 'normaliseSearchTerm trims and collapses whitespace', function () use ($harness, $instance, $normaliseSearchTerm): void {
        $harness->assertSame('ACME LTD 123', $normaliseSearchTerm->invoke($instance, "  ACME \n LTD   123  "));
    });

    $harness->check('CompanyAction', 'decodeProfilePayload returns null for invalid json', function () use ($harness, $instance, $decodeProfilePayload): void {
        $harness->assertSame(null, $decodeProfilePayload->invoke($instance, '{invalid'));
    });

    $harness->check('CompanyAction', 'mapProfileResult preserves core Companies House fields and payload', function () use ($harness, $instance, $mapProfileResult, $decodeProfilePayload): void {
        $profile = [
            'company_name' => 'Example Limited',
            'company_number' => '01234567',
            'company_status' => 'active',
            'date_of_creation' => '2020-01-31',
        ];

        $result = $mapProfileResult->invoke($instance, $profile, 'profile');

        $harness->assertSame('Example Limited', $result['company_name'] ?? '');
        $harness->assertSame('01234567', $result['company_number'] ?? '');
        $harness->assertSame('active', $result['company_status'] ?? '');
        $harness->assertSame('2020-01-31', $result['incorporation_date'] ?? '');
        $harness->assertSame('supported', ($result['incorporation_eligibility'] ?? [])['status'] ?? '');
        $harness->assertSame(true, ($result['incorporation_eligibility'] ?? [])['is_supported'] ?? null);
        $harness->assertSame('profile', $result['source'] ?? '');
        $harness->assertSame($profile, $decodeProfilePayload->invoke($instance, (string)($result['profile_payload'] ?? '')));
    });

    $harness->check('CompanyAction', 'companiesHouseEnvironment normalises runtime mode', function () use ($harness, $instance, $companiesHouseEnvironment): void {
        $environment = (string)$companiesHouseEnvironment->invoke($instance);
        $harness->assertSame(true, in_array($environment, ['TEST', 'LIVE'], true));
    });

    $harness->check('CompanyAction', 'validateAccountingPeriodPayload rejects update requests without a selected accounting period', function () use ($harness, $instance, $validateAccountingPeriodPayload): void {
        $errors = $validateAccountingPeriodPayload->invoke($instance, 27, 0, '2024-10-01', '2025-09-30', false);
        $harness->assertSame(true, in_array('Select an existing accounting period before saving changes.', $errors, true));
    });

    $harness->check('CompanyAction', 'add_company blocks companies with more than one active director before creating a company row', function () use ($harness): void {
        if (!InterfaceDB::tableExists('companies')) {
            $harness->skip('Companies table is not available on the default InterfaceDB connection.');
        }

        $companyNumber = 'DIR' . strtoupper(substr(hash('sha256', __FILE__ . microtime(true)), 0, 8));
        $profile = [
            'company_name' => 'Multi Director Fixture Limited',
            'company_number' => $companyNumber,
            'company_status' => 'active',
            'date_of_creation' => '2024-01-01',
        ];
        $companiesHouseService = companyActionCompaniesHouseService($profile, 2);
        $action = new CompanyAction(
            new \eel_accounts\Service\CompanyDirectorEligibilityService($companiesHouseService),
            $companiesHouseService
        );
        $request = new RequestFramework(
            [],
            [
                'card_action' => 'Company',
                'intent' => 'add_company',
                'company_name' => 'Multi Director Fixture Limited',
                'selected_company_number' => $companyNumber,
                'selected_incorporation_date' => '2024-01-01',
                'selected_company_profile_payload' => json_encode($profile, JSON_UNESCAPED_SLASHES),
            ],
            ['REQUEST_METHOD' => 'POST', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
            [],
            [],
            null
        );

        $result = $action->handle($request, createTestPageServiceFramework());

        $harness->assertSame(false, $result->isSuccess());
        $harness->assertSame(true, str_contains((string)($result->flashMessages()[0]['message'] ?? ''), 'exactly 1 active director'));
        $harness->assertSame(0, InterfaceDB::countWhere('companies', ['company_number' => $companyNumber]));
    });

    $harness->check('CompanyAction', 'add_company trusts the refreshed profile date and rejects unsupported dates before director or database work', function () use ($harness): void {
        if (!InterfaceDB::tableExists('companies')) {
            $harness->skip('Companies table is not available on the default InterfaceDB connection.');
        }

        $cases = [
            'before_cutoff' => '2011-01-04',
            'missing' => null,
            'invalid' => 'not-a-date',
        ];

        foreach ($cases as $case => $authoritativeDate) {
            $companyNumber = 'EL' . strtoupper(substr(hash('sha256', $case . __FILE__ . microtime(true)), 0, 8));
            $profile = [
                'company_name' => 'Eligibility ' . $case . ' Fixture Limited',
                'company_number' => $companyNumber,
                'company_status' => 'active',
            ];
            if ($authoritativeDate !== null) {
                $profile['date_of_creation'] = $authoritativeDate;
            }

            $calls = (object)['profile' => 0, 'officers' => 0];
            $companiesHouseService = companyActionCompaniesHouseService($profile, 1, $calls);
            $action = new CompanyAction(
                new \eel_accounts\Service\CompanyDirectorEligibilityService($companiesHouseService),
                $companiesHouseService
            );
            $request = new RequestFramework(
                [],
                [
                    'card_action' => 'Company',
                    'intent' => 'add_company',
                    'company_name' => 'Tampered Eligible Name Limited',
                    'selected_company_number' => $companyNumber,
                    'selected_incorporation_date' => '2024-01-01',
                    'selected_company_profile_payload' => json_encode([
                        'company_name' => 'Tampered Eligible Name Limited',
                        'company_number' => $companyNumber,
                        'date_of_creation' => '2024-01-01',
                    ], JSON_UNESCAPED_SLASHES),
                ],
                ['REQUEST_METHOD' => 'POST', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
                [],
                [],
                null
            );

            $result = $action->handle($request, createTestPageServiceFramework());

            $harness->assertSame(false, $result->isSuccess());
            $harness->assertSame(1, $calls->profile);
            $harness->assertSame(0, $calls->officers);
            $harness->assertSame(0, InterfaceDB::countWhere('companies', ['company_number' => $companyNumber]));
        }
    });

    $harness->check('CompanyAction', 'add_accounting_period requires a selected company', function () use ($harness, $instance): void {
        (new \eel_accounts\Service\AccountingContextService())->clearPageContext();

        $request = new RequestFramework(
            [],
            ['card_action' => 'Company', 'intent' => 'add_accounting_period'],
            ['REQUEST_METHOD' => 'POST', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
            [],
            [],
            null
        );

        $result = $instance->handle($request, createTestPageServiceFramework());

        $harness->assertSame(false, $result->isSuccess());
        $harness->assertSame('Select a company before saving an accounting period.', (string)($result->flashMessages()[0]['message'] ?? ''));
    });

    $harness->check('CompanyAction', 'clear_imported_accounting_data requires a selected company', function () use ($harness, $instance): void {
        (new \eel_accounts\Service\AccountingContextService())->clearPageContext();

        $request = new RequestFramework(
            [],
            ['card_action' => 'Company', 'intent' => 'clear_imported_accounting_data'],
            ['REQUEST_METHOD' => 'POST', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
            [],
            [],
            null
        );

        $result = $instance->handle($request, createTestPageServiceFramework());

        $harness->assertSame(false, $result->isSuccess());
        $harness->assertSame('A company must be selected before imported accounting data can be cleared.', (string)($result->flashMessages()[0]['message'] ?? ''));
    });

    $harness->check('CompanyAction', 'delete_company requires a selected company', function () use ($harness, $instance): void {
        (new \eel_accounts\Service\AccountingContextService())->clearPageContext();

        $request = new RequestFramework(
            [],
            ['card_action' => 'Company', 'intent' => 'delete_company'],
            ['REQUEST_METHOD' => 'POST', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
            [],
            [],
            null
        );

        $result = $instance->handle($request, createTestPageServiceFramework());

        $harness->assertSame(false, $result->isSuccess());
        $harness->assertSame('A company must be selected before deletion.', (string)($result->flashMessages()[0]['message'] ?? ''));
    });

    $harness->check('CompanyAction', 'delete_company refreshes setup health after deleting the selected company', function () use ($harness, $instance): void {
        $companyNumber = 'DEL' . substr(hash('sha256', __FILE__ . microtime(true)), 0, 9);

        try {
            authenticateTestSession();

            InterfaceDB::prepareExecute(
                'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
                ['company_name' => 'Delete Fixture Limited', 'company_number' => $companyNumber]
            );

            $companyId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM companies WHERE company_number = :company_number',
                ['company_number' => $companyNumber]
            );

            (new \eel_accounts\Service\AccountingContextService())->setPageContext(
                $companyId,
                'Delete Fixture Limited',
                $companyNumber,
                0
            );

            $request = new RequestFramework(
                [],
                [
                    'card_action' => 'Company',
                    'intent' => 'delete_company',
                    'delete_company_confirm' => '1',
                    'delete_company_confirm_value' => $companyNumber,
                ],
                ['REQUEST_METHOD' => 'POST', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
                [],
                [],
                null
            );

            $result = $instance->handle($request, createTestPageServiceFramework());

            $harness->assertSame(true, $result->isSuccess());
            $harness->assertSame(true, in_array('settings_setup_health', $result->changedFacts(), true));
        } finally {
            clearAuthenticatedTestSession();
        }
    });
});

function companyActionCompaniesHouseService(
    array $profile,
    int $directorCount,
    ?stdClass $calls = null
): \eel_accounts\Service\CompaniesHouseService
{
    $calls ??= (object)['profile' => 0, 'officers' => 0];

    return new \eel_accounts\Service\CompaniesHouseService(
        'TEST',
        20,
        static function (array $request) use ($profile, $directorCount, $calls): array {
            $path = (string)($request['path'] ?? '');
            if (!str_ends_with($path, '/officers')) {
                $calls->profile++;

                return [
                    'status_code' => 200,
                    'headers' => [],
                    'body' => json_encode($profile, JSON_UNESCAPED_SLASHES),
                    'url' => 'https://example.test' . $path,
                ];
            }

            $calls->officers++;
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
                'url' => 'https://example.test' . $path,
            ];
        }
    );
}
