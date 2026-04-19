<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _director_loan_stateCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'director_loan_state';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'directorLoanStatement',
                'service' => DirectorLoanService::class,
                'method' => 'fetchStatement',
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
        $directorLoanPageData = $context['services']['directorLoanStatement'] ?? [];
        $directorLoanBootstrapJson = json_encode($directorLoanPageData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($directorLoanBootstrapJson)) {
            $directorLoanBootstrapJson = '{}';
        }

        return '<section hidden aria-hidden="true">
            <div
                id="director-loan-page"
                class="director-loan-page"
                data-company-id="' . $selectedCompanyId . '"
                data-tax-year-id="' . $selectedTaxYearId . '"
            >
                <script type="application/json" id="director-loan-page-bootstrap">' . $directorLoanBootstrapJson . '</script>
            </div>
        </section>';
    }
}
