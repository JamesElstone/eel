<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _companies_searchCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'companies_search';
    }

    public function services(): array
    {
        return [];
    }

    public function helper(array $context): string | array {
        return [
            'Filed accounts and reference data is only retrieved and stored after you click Add Company.'
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function title(): string
    {
        return 'Search for a Company';
    }

    public function render(array $context): string
    {
        $companySearchTerm = (string)($context['company_search_term'] ?? '');
        $companySearchResults = (array)($context['company_search_results'] ?? []);
        $resultsHtml = '';

        foreach ($companySearchResults as $result) {
            $resultsHtml .= '
            <div class="search-result">
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
                <form method="post" data-ajax="true" data-invalidate-page="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                    <input type="hidden" name="card_action" value="Company">
                    <input type="hidden" name="intent" value="add_company">
                    <input type="hidden" name="company_name" value="' . HelperFramework::escape((string)($result['company_name'] ?? '')) . '">
                    <input type="hidden" name="selected_company_number" value="' . HelperFramework::escape((string)($result['company_number'] ?? '')) . '">
                    <input type="hidden" name="selected_incorporation_date" value="' . HelperFramework::escape((string)($result['incorporation_date'] ?? '')) . '">
                    <input type="hidden" name="selected_company_profile_payload" value="' . HelperFramework::escape((string)($result['profile_payload'] ?? '')) . '">
                    <button class="button primary" data-processing-text="Adding Company..." data-processing-state="disabled" data-show-card="companies_company_settings" type="submit">Add Company</button>
                </form>
            </div>';
        }

        return '
            <form id="company-search-form" method="post" data-ajax="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Company">
                <input type="hidden" name="intent" value="search_company">
                <div class="mini-form">
                    <div class="mini-field">
                        <label for="company_search_term">Companies House Search</label>
                        <input class="input" id="company_search_term" name="company_search_term" placeholder="Company name or number" value="' . HelperFramework::escape($companySearchTerm) . '">
                    </div>
                    <button class="button primary" type="submit">Search</button>
                </div>
            </form>'
            . ($resultsHtml !== '' ? '<div class="search-results">' . $resultsHtml . '</div>' : '') . '
        ';
    }
}
