<?php
declare(strict_types=1);

final class _trial_balance_stateCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'trial_balance_state';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'trialBalancePageData',
                'service' => TrialBalanceService::class,
                'method' => 'fetchTrialBalance',
                'params' => [
                    'companyId' => ':company_id',
                    'taxYearId' => ':tax_year_id',
                    'includeZero' => ':trial_balance_include_zero',
                    'includeUnposted' => ':trial_balance_include_unposted',
                    'filters' => ':trial_balance_filters',
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

        $trialBalancePageData = $context['services']['trialBalancePageData'] ?? ($page['trial_balance_page_data'] ?? [
            'available' => false,
            'errors' => [],
            'rows' => [],
            'totals' => [],
            'summary' => [],
            'filters' => [
                'search' => '',
                'account_type' => 'all',
                'focus' => 'all',
            ],
            'include_zero' => false,
            'include_unposted' => false,
        ]);

        $trialBalanceBootstrapJson = json_encode($trialBalancePageData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($trialBalanceBootstrapJson)) {
            $trialBalanceBootstrapJson = '{}';
        }

        return '<section class="eel-card-fragment" data-card="trial-balance-state" hidden aria-hidden="true">
            <div
                id="trial-balance-page"
                class="trial-balance-page"
                data-company-id="' . $selectedCompanyId . '"
                data-tax-year-id="' . $selectedTaxYearId . '"
            >
                <script type="application/json" id="trial-balance-page-bootstrap">' . $trialBalanceBootstrapJson . '</script>
            </div>
        </section>';
    }
}
