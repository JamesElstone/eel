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

final class TestCompanyStorePage implements PageInterfaceFramework
{
    public function id(): string
    {
        return 'test-company-store';
    }

    public function title(): string
    {
        return 'Test Company Store';
    }

    public function subtitle(): string
    {
        return 'Exercises site-context generation.';
    }

    public function pageStackClass(): string
    {
        return '';
    }

    public function hiddenSiteContextSelectors(): array
    {
        return ['tax_year_id'];
    }

    public function services(): array
    {
        return [];
    }

    public function cards(): array
    {
        return [];
    }

    public function handle(RequestFramework $request, PageServiceFramework $services): ResponseFramework
    {
        throw new RuntimeException('TestCompanyStorePage::handle() should not be called during selector tests.');
    }
}

function testCompanyStoreRequest(int $companyId = 0, int $taxYearId = 0): RequestFramework
{
    return new RequestFramework(
        [],
        [
            'company_id' => (string)$companyId,
            'tax_year_id' => (string)$taxYearId,
        ],
        ['REQUEST_METHOD' => 'POST'],
        [],
        []
    );
}

function testCompanyStoreSiteContextActionRequest(string $key, string $inputName, int $value, int $companyId = 0): RequestFramework
{
    $post = [
        'action' => 'set-site-context',
        'site_context_key' => $key,
        'site_context_input_name' => $inputName,
        $inputName => (string)$value,
    ];

    if ($companyId > 0) {
        $post['company_id'] = (string)$companyId;
    }

    return new RequestFramework(
        [],
        $post,
        ['REQUEST_METHOD' => 'POST'],
        [],
        []
    );
}

function resetCompanyStoreSession(): void
{
    $service = new SessionAuthenticationService();
    $service->startSession();
    $_SESSION = [];
    $service->completeAuthentication(1, 'test-device');
}

function ensureCompanyStoreCompany(): int
{
    $companyNumber = 'TESTCTX001';
    $companyId = (int)(InterfaceDB::fetchColumn(
        'SELECT id FROM companies WHERE company_number = :company_number LIMIT 1',
        ['company_number' => $companyNumber]
    ) ?: 0);

    if ($companyId <= 0) {
        InterfaceDB::prepareExecute(
            'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
            ['company_name' => 'Test Context Company Limited', 'company_number' => $companyNumber]
        );
        $companyId = (int)(InterfaceDB::fetchColumn(
            'SELECT id FROM companies WHERE company_number = :company_number LIMIT 1',
            ['company_number' => $companyNumber]
        ) ?: 0);
    }

    if ($companyId <= 0) {
        throw new RuntimeException('Unable to create test company context fixture.');
    }

    $taxYearId = (int)(InterfaceDB::fetchColumn(
        'SELECT id FROM tax_years WHERE company_id = :company_id AND label = :label LIMIT 1',
        ['company_id' => $companyId, 'label' => 'Test FY 2026']
    ) ?: 0);

    if ($taxYearId <= 0) {
        InterfaceDB::prepareExecute(
            'INSERT INTO tax_years (company_id, label, period_start, period_end)
             VALUES (:company_id, :label, :period_start, :period_end)',
            [
                'company_id' => $companyId,
                'label' => 'Test FY 2026',
                'period_start' => '2026-01-01',
                'period_end' => '2026-12-31',
            ]
        );
    }

    return $companyId;
}

$harness = new GeneratedServiceClassTestHarness();
$harness->run(AccountingContextService::class, function (GeneratedServiceClassTestHarness $harness, AccountingContextService $service): void {
    $harness->check(AccountingContextService::class, 'returns empty selector options when no companies exist', function () use (
        $harness,
        $service
    ): void {
        resetCompanyStoreSession();
        $companiesCount = InterfaceDB::tableRowCount('companies');
        if ($companiesCount === InterfaceDB::TABLE_ROW_COUNT_ERROR) {
            $harness->skip('skipped, due to no data confirmation error');
        }
        if ($companiesCount === InterfaceDB::TABLE_ROW_COUNT_TABLE_MISSING) {
            $harness->skip('skipped, due to companies table not existing');
        }
        if ($companiesCount > 0) {
            $harness->skip('skipped, company data exists');
        }

        $result = $service->resolveSiteContext(
            testCompanyStoreRequest(),
            new TestCompanyStorePage(),
            createTestPageServiceFramework(),
            []
        );

        $context = $result->context();
        $selectors = $result->selectors();

        $harness->assertSame(0, $context['site_context']['company_id'] ?? null);
        $harness->assertSame(0, $context['site_context']['tax_year_id'] ?? null);
        $harness->assertSame('company_id', $selectors[0]['key'] ?? null);
        $harness->assertSame('sidebar', $selectors[0]['slot'] ?? null);
        $harness->assertSame(true, $selectors[0]['disabled'] ?? null);
        $harness->assertSame('tax_year_id', $selectors[1]['key'] ?? null);
        $harness->assertSame('topbar', $selectors[1]['slot'] ?? null);
        $harness->assertSame(true, $selectors[1]['disabled'] ?? null);
    });

    $harness->check(AccountingContextService::class, 'returns company and topbar tax-year selectors when companies exist', function () use (
        $harness,
        $service
    ): void {
        resetCompanyStoreSession();
        $companies = (new CompanyRepository())->fetchCompanySelectorRows();
        if ($companies === []) {
            ensureCompanyStoreCompany();
            $companies = (new CompanyRepository())->fetchCompanySelectorRows();
        }

        $requestedCompanyId = (int)($companies[0]['id'] ?? 0);
        if ($requestedCompanyId <= 0) {
            $harness->skip('skipped, due to no resolvable company id');
        }

        $result = $service->resolveSiteContext(
            testCompanyStoreRequest($requestedCompanyId),
            new TestCompanyStorePage(),
            createTestPageServiceFramework(),
            []
        );
        $context = $result->context();
        $selectors = $result->selectors();
        $expectedTaxYears = (new TaxYearRepository())->fetchTaxYears($requestedCompanyId);

        $harness->assertSame($requestedCompanyId, (int)($context['site_context']['company_id'] ?? 0));
        $harness->assertSame($requestedCompanyId, (int)($context['company']['id'] ?? 0));
        $harness->assertSame('company_id', $selectors[0]['input_name'] ?? null);
        $harness->assertSame('sidebar', $selectors[0]['slot'] ?? null);
        $harness->assertSame('tax_year_id', $selectors[1]['input_name'] ?? null);
        $harness->assertSame('topbar', $selectors[1]['slot'] ?? null);

        if ($expectedTaxYears !== []) {
            $harness->assertSame((int)($expectedTaxYears[0]['id'] ?? 0), (int)($context['site_context']['tax_year_id'] ?? 0));
        } else {
            $harness->assertSame(0, (int)($context['site_context']['tax_year_id'] ?? 0));
            $harness->assertSame(true, $selectors[1]['disabled'] ?? null);
        }
    });

    $harness->check(AccountingContextService::class, 'handles named site-context selector input values', function () use (
        $harness,
        $service
    ): void {
        resetCompanyStoreSession();
        $companies = (new CompanyRepository())->fetchCompanySelectorRows();
        if ($companies === []) {
            ensureCompanyStoreCompany();
            $companies = (new CompanyRepository())->fetchCompanySelectorRows();
        }

        $companyId = (int)($companies[0]['id'] ?? 0);
        if ($companyId <= 0) {
            $harness->skip('skipped, due to no resolvable company id');
        }

        $result = $service->handleSiteContextAction(
            testCompanyStoreSiteContextActionRequest('company_id', 'company_id', $companyId),
            new TestCompanyStorePage(),
            createTestPageServiceFramework()
        );

        $harness->assertTrue($result->isSuccess());
        $harness->assertSame($companyId, $service->companyId());

        $taxYears = (new TaxYearRepository())->fetchTaxYears($companyId);
        if ($taxYears === []) {
            ensureCompanyStoreCompany();
            $taxYears = (new TaxYearRepository())->fetchTaxYears($companyId);
        }

        $taxYearId = (int)($taxYears[0]['id'] ?? 0);
        if ($taxYearId <= 0) {
            $harness->skip('skipped, due to no resolvable tax year id');
        }

        $result = $service->handleSiteContextAction(
            testCompanyStoreSiteContextActionRequest('tax_year_id', 'tax_year_id', $taxYearId, $companyId),
            new TestCompanyStorePage(),
            createTestPageServiceFramework()
        );

        $harness->assertTrue($result->isSuccess());
        $harness->assertSame($taxYearId, $service->taxYearId());
    });
});
