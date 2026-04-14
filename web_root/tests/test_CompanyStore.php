<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

final class TestCompanyStorePage implements PageInterfaceFramework
{
    public function __construct(private readonly bool $showTaxYearSelector)
    {
    }

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
        return 'Exercises selector context generation.';
    }

    public function showsTaxYearSelector(): bool
    {
        return $this->showTaxYearSelector;
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
        throw new RuntimeException('TestCompanyStorePage::handle() should not be called during CompanyStore tests.');
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

$harness = new GeneratedServiceClassTestHarness();
$harness->run(CompanyStore::class, function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(CompanyStore::class, 'returns selector placeholders when no companies exist', function () use ($harness): void {
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

        $store = new CompanyStore();
        $context = $store->buildSelectorContext(testCompanyStoreRequest(), new TestCompanyStorePage(false));

        $harness->assertSame(0, $context['selected_company_id'] ?? null);
        $harness->assertSame(0, $context['selected_tax_year_id'] ?? null);
        $harness->assertSame(false, $context['show_tax_year_selector'] ?? null);
        $harness->assertSame(true, $context['company_selector_disabled'] ?? null);
        $harness->assertSame(true, $context['tax_year_selector_disabled'] ?? null);
        $harness->assertSame('No Company Added Yet', $context['companies'][0]['label'] ?? null);
        $harness->assertSame('No Tax Periods Defined Yet', $context['tax_years'][0]['label'] ?? null);
    });

    $harness->check(CompanyStore::class, 'returns company options when companies exist and resolves a selected company', function () use ($harness): void {
        $companiesCount = InterfaceDB::tableRowCount('companies');
        if ($companiesCount <= 0) {
            $harness->skip('skipped, due to no companies data');
        }

        $requestedCompanyId = (int)(InterfaceDB::fetchColumn(
            'SELECT id
             FROM companies
             ORDER BY company_name, id
             LIMIT 1'
        ) ?: 0);

        if ($requestedCompanyId <= 0) {
            $harness->skip('skipped, due to no resolvable company id');
        }

        $store = new CompanyStore();
        $context = $store->buildSelectorContext(testCompanyStoreRequest($requestedCompanyId, 0), new TestCompanyStorePage(true));

        $harness->assertTrue(is_array($context['companies'] ?? null));
        $harness->assertTrue(($context['companies'] ?? []) !== []);
        $harness->assertSame(false, $context['company_selector_disabled'] ?? null);
        $harness->assertSame($requestedCompanyId, (int)($context['selected_company_id'] ?? 0));
        $harness->assertTrue(trim((string)($context['companies'][0]['label'] ?? '')) !== '');

        $taxYearsForSelectedCompany = (int)(InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM tax_years
             WHERE company_id = :company_id',
            ['company_id' => $requestedCompanyId]
        ) ?: 0);

        if ($taxYearsForSelectedCompany > 0) {
            $harness->assertSame(false, $context['tax_year_selector_disabled'] ?? null);
            $harness->assertTrue((int)($context['selected_tax_year_id'] ?? 0) > 0);
            $harness->assertTrue(is_array($context['tax_years'] ?? null));
            $harness->assertTrue(($context['tax_years'] ?? []) !== []);
        } else {
            $harness->assertSame(true, $context['tax_year_selector_disabled'] ?? null);
            $harness->assertSame(0, $context['selected_tax_year_id'] ?? null);
            $harness->assertSame('No Tax Periods Defined Yet', $context['tax_years'][0]['label'] ?? null);
        }
    });

    $harness->check(CompanyStore::class, 'falls back from invalid requested ids to available live data', function () use ($harness): void {
        $companiesCount = InterfaceDB::tableRowCount('companies');
        if ($companiesCount <= 0) {
            $harness->skip('skipped, due to no companies data');
        }

        $expectedCompanyId = (int)(InterfaceDB::fetchColumn(
            'SELECT id
             FROM companies
             ORDER BY company_name, id
             LIMIT 1'
        ) ?: 0);

        if ($expectedCompanyId <= 0) {
            $harness->skip('skipped, due to no resolvable company id');
        }

        $expectedTaxYearId = (int)(InterfaceDB::fetchColumn(
            'SELECT id
             FROM tax_years
             WHERE company_id = :company_id
             ORDER BY period_start DESC, id DESC
             LIMIT 1',
            ['company_id' => $expectedCompanyId]
        ) ?: 0);

        $store = new CompanyStore();
        $context = $store->buildSelectorContext(testCompanyStoreRequest(999999999, 999999999), new TestCompanyStorePage(true));

        $harness->assertSame($expectedCompanyId, (int)($context['selected_company_id'] ?? 0));

        if ($expectedTaxYearId > 0) {
            $harness->assertSame($expectedTaxYearId, (int)($context['selected_tax_year_id'] ?? 0));
            $harness->assertSame(false, $context['tax_year_selector_disabled'] ?? null);
        } else {
            $harness->assertSame(0, (int)($context['selected_tax_year_id'] ?? 0));
            $harness->assertSame(true, $context['tax_year_selector_disabled'] ?? null);
        }
    });
});
