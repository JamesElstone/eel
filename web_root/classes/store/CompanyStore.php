<?php
declare(strict_types=1);

final class CompanyStore
{
    public function buildSelectorContext(RequestFramework $request, PageInterfaceFramework $page): array
    {
        $companies = $this->fetchCompanies();
        $selectedCompanyId = $this->resolveSelectedCompanyId($companies, $request->companyId());
        $taxYears = $selectedCompanyId > 0 ? $this->fetchTaxYears($selectedCompanyId) : [];
        $selectedTaxYearId = $this->resolveSelectedTaxYearId($taxYears, $request->taxYearId());

        return [
            'companies' => $this->buildCompanyOptions($companies),
            'tax_years' => $this->buildTaxYearOptions($taxYears),
            'selected_company_id' => $selectedCompanyId,
            'selected_tax_year_id' => $selectedTaxYearId,
            'show_tax_year_selector' => $page->showsTaxYearSelector(),
            'company_selector_disabled' => $companies === [],
            'tax_year_selector_disabled' => $taxYears === [],
        ];
    }

    private function fetchCompanies(): array
    {
        return (new CompanyRepository())->fetchCompanies();
    }

    private function fetchTaxYears(int $companyId): array
    {
        return (new TaxYearRepository())->fetchTaxYears($companyId);
    }

    private function resolveSelectedCompanyId(array $companies, int $requestedCompanyId): int
    {
        if ($requestedCompanyId > 0) {
            foreach ($companies as $company) {
                if ((int)($company['id'] ?? 0) === $requestedCompanyId) {
                    return $requestedCompanyId;
                }
            }
        }

        if ($companies === []) {
            return 0;
        }

        return (int)($companies[0]['id'] ?? 0);
    }

    private function resolveSelectedTaxYearId(array $taxYears, int $requestedTaxYearId): int
    {
        if ($requestedTaxYearId > 0) {
            foreach ($taxYears as $taxYear) {
                if ((int)($taxYear['id'] ?? 0) === $requestedTaxYearId) {
                    return $requestedTaxYearId;
                }
            }
        }

        if ($taxYears === []) {
            return 0;
        }

        return (int)($taxYears[0]['id'] ?? 0);
    }

    private function buildCompanyOptions(array $companies): array
    {
        if ($companies === []) {
            return [[
                'value' => '',
                'label' => 'No Company Added Yet',
                'short_label' => 'No Company Added Yet',
                'disabled' => true,
            ]];
        }

        return array_map(static function (array $company): array {
            $name = trim((string)($company['company_name'] ?? ''));
            $number = trim((string)($company['company_number'] ?? ''));
            $label = $name !== '' ? $name . ' (' . $number . ')' : $number;

            return [
                'value' => (string)((int)($company['id'] ?? 0)),
                'label' => $label,
                'short_label' => $number !== '' ? $number : $label,
                'disabled' => false,
            ];
        }, $companies);
    }

    private function buildTaxYearOptions(array $taxYears): array
    {
        if ($taxYears === []) {
            return [[
                'value' => '',
                'label' => 'No Tax Periods Defined Yet',
                'disabled' => true,
            ]];
        }

        return array_map(static function (array $taxYear): array {
            return [
                'value' => (string)((int)($taxYear['id'] ?? 0)),
                'label' => trim((string)($taxYear['label'] ?? '')),
                'disabled' => false,
            ];
        }, $taxYears);
    }
}

