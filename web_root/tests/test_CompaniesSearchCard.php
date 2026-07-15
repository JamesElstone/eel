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

$harness->run(_companies_searchCard::class, static function (GeneratedServiceClassTestHarness $harness, _companies_searchCard $card): void {
    $eligibilityService = new \eel_accounts\Service\CompanyIncorporationEligibilityService();
    $html = $card->render([
        'company_search_results' => [
            [
                'company_name' => 'Supported Fixture Limited',
                'company_number' => '01234567',
                'company_status' => 'active',
                'incorporation_date' => '2011-01-05',
                'incorporation_eligibility' => $eligibilityService->evaluate('2011-01-05'),
                'source' => 'profile',
            ],
            [
                'company_name' => 'Unsupported Fixture Limited',
                'company_number' => '07654321',
                'company_status' => 'active',
                'incorporation_date' => '2011-01-04',
                'incorporation_eligibility' => $eligibilityService->evaluate('2011-01-04'),
                'source' => 'search',
            ],
            [
                'company_name' => 'Missing Date Fixture Limited',
                'company_number' => '01111111',
                'company_status' => 'active',
                'incorporation_date' => '',
                'incorporation_eligibility' => $eligibilityService->evaluate(null),
                'source' => 'search',
            ],
            [
                'company_name' => 'Invalid Date Fixture Limited',
                'company_number' => '02222222',
                'company_status' => 'active',
                'incorporation_date' => 'invalid',
                'incorporation_eligibility' => $eligibilityService->evaluate('invalid'),
                'source' => 'search',
            ],
        ],
    ]);

    $harness->check(_companies_searchCard::class, 'shows incorporation date and eligibility for search results', static function () use ($harness, $html): void {
        $harness->assertTrue(str_contains($html, 'Incorporation date: 5 January 2011'));
        $harness->assertTrue(str_contains($html, 'Eligibility: Eligible'));
        $harness->assertTrue(str_contains($html, 'Only companies incorporated on or after 5 January 2011 are supported.'));
    });

    $harness->check(_companies_searchCard::class, 'disables add for an ineligible result without posting a date or profile payload', static function () use ($harness, $html): void {
        $harness->assertSame(3, substr_count($html, 'aria-disabled="true"'));
        $harness->assertFalse(str_contains($html, 'name="selected_incorporation_date"'));
        $harness->assertFalse(str_contains($html, 'name="selected_company_profile_payload"'));
    });
});
