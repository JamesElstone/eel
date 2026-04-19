<?php
declare(strict_types=1);

final class _year_end_stateCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'year_end_state';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'yearEndChecklist',
                'service' => YearEndChecklistService::class,
                'method' => 'fetchChecklist',
                'params' => [
                    'companyId' => ':company_id',
                    'taxYearId' => ':tax_year_id',
                    'persist' => false,
                ],
            ],
            [
                'key' => 'yearEndTaxReadiness',
                'service' => YearEndTaxReadinessService::class,
                'method' => 'fetchSummary',
                'params' => [
                    'companyId' => ':company_id',
                    'taxYearId' => ':tax_year_id',
                ],
            ],
            [
                'key' => 'yearEndCompaniesHouseComparison',
                'service' => YearEndCompaniesHouseComparisonService::class,
                'method' => 'fetchComparison',
                'params' => [
                    'companyId' => ':company_id',
                    'taxYearId' => ':tax_year_id',
                ],
            ],
        ];
    }

    public function invalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $page = (array)($context['page'] ?? []);
        $selectedCompanyId = (int)($page['selected_company_id'] ?? $page['company_id'] ?? 0);
        $selectedTaxYearId = (int)($page['selected_tax_year_id'] ?? $page['tax_year_id'] ?? 0);

        $yearEndPageData = $page['year_end_page_data'] ?? [
            'checklist' => $context['services']['yearEndChecklist'] ?? null,
            'tax_readiness' => $context['services']['yearEndTaxReadiness'] ?? null,
            'companies_house_comparison' => $context['services']['yearEndCompaniesHouseComparison'] ?? null,
        ];

        $yearEndBootstrapJson = json_encode($yearEndPageData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($yearEndBootstrapJson)) {
            $yearEndBootstrapJson = '{}';
        }

        return '<section class="eel-card-fragment" data-card="year-end-state" hidden aria-hidden="true">
            <div
                id="year-end-page"
                class="year-end-page"
                data-company-id="' . $selectedCompanyId . '"
                data-tax-year-id="' . $selectedTaxYearId . '"
            >
                <script type="application/json" id="year-end-page-bootstrap">' . $yearEndBootstrapJson . '</script>
            </div>
        </section>';
    }
}
