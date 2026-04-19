<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _companies_searchCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'companies_search';
    }

    public function services(): array
    {
        return [];
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
        $companySearchTerm = (string)($page['company_search_term'] ?? '');
        $companySearchResults = (array)($page['company_search_results'] ?? []);
        $resultsHtml = '';

        foreach ($companySearchResults as $result) {
            $resultsHtml .= '<div class="search-result">
                <div class="search-result-head">
                    <div>
                        <div class="search-result-title">' . HelperFramework::escape((string)($result['company_name'] ?? '')) . '</div>
                        <div class="search-result-meta">
                            Number: ' . HelperFramework::escape((string)($result['company_number'] ?? ''))
                            . (trim((string)($result['company_status'] ?? '')) !== '' ? ' | Status: ' . HelperFramework::escape((string)$result['company_status']) : '') . '
                        </div>
                    </div>
                    <span class="status-pill">' . HelperFramework::escape((string)($result['source'] ?? '') === 'profile' ? 'Direct lookup' : 'Search result') . '</span>
                </div>
                <form method="post" data-ajax-card-form="true" data-ajax-card-update="companies-search,companies-company-settings,companies-stored-detail,companies-accounting,companies-nominals,companies-danger,companies-empty-state,companies-setup-health">
                    <input type="hidden" name="global_action" value="add_company">
                    <input type="hidden" name="selected_company_name" value="' . HelperFramework::escape((string)($result['company_name'] ?? '')) . '">
                    <input type="hidden" name="selected_company_number" value="' . HelperFramework::escape((string)($result['company_number'] ?? '')) . '">
                    <input type="hidden" name="selected_incorporation_date" value="' . HelperFramework::escape((string)($result['incorporation_date'] ?? '')) . '">
                    <input type="hidden" name="selected_company_profile_payload" value="' . HelperFramework::escape((string)($result['profile_payload'] ?? '')) . '">
                    <button class="button primary" type="submit">Add Company</button>
                </form>
            </div>';
        }

        return '<div class="card">
            <div class="card-header">
                <h2 class="card-title">Add a Company</h2>
            </div>
            <div class="card-body">
                <form id="company-search-form" method="post" data-ajax-card-form="true" data-ajax-card-update="companies-search">
                    <input type="hidden" name="page" value="companies">
                    <input type="hidden" name="global_action" value="search_company">
                    <div class="mini-form">
                        <div class="mini-field">
                            <label for="company_search_term">Companies House Search</label>
                            <input class="input" id="company_search_term" name="company_search_term" placeholder="Company name or number" value="' . HelperFramework::escape($companySearchTerm) . '">
                        </div>
                        <button class="button primary" type="submit">Search</button>
                    </div>
                </form>
                <div class="helper">Filed accounts reference data is fetched only after you click Add Company.</div>'
                . ($resultsHtml !== '' ? '<div class="search-results">' . $resultsHtml . '</div>' : '') . '
            </div>
        </div>';
    }
}
