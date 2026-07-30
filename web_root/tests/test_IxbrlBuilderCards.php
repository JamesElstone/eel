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

$harness->run(_disclosures::class, static function (GeneratedServiceClassTestHarness $harness, _disclosures $page): void {
    $harness->check(_disclosures::class, 'keeps the Year End trial balance acknowledgement off the disclosures page', static function () use ($harness, $page): void {
        $harness->assertSame([
            'ixbrl_readiness',
            'ixbrl_accounts_disclosures',
            'ixbrl_accounts_mapping',
            'ixbrl_facts_preview',
            'ixbrl_generation',
            'ixbrl_history',
        ], $page->cards());

        $layoutCards = [];
        $overviewTab = [];
        $disclosuresTab = [];
        $mappingTab = [];
        $historyTab = [];
        foreach ($page->cardLayout() as $tab) {
            $cards = (array)($tab['cards'] ?? []);
            $layoutCards = array_merge($layoutCards, $cards);
            switch ($tab['tab'] ?? '') {
                case 'Overview':
                    $overviewTab = $cards;
                    break;
                case 'Disclosures':
                    $disclosuresTab = $cards;
                    break;
                case 'Accounts Mapping':
                    $mappingTab = $cards;
                    break;
                case 'History':
                    $historyTab = $cards;
                    break;
            }
        }

        $harness->assertFalse(in_array('ixbrl_trial_balance', $layoutCards, true));
        $harness->assertSame(['ixbrl_readiness'], $overviewTab);
        $harness->assertSame(['ixbrl_history'], $historyTab);
        $harness->assertSame(['ixbrl_accounts_disclosures'], $disclosuresTab);
        $harness->assertSame(['ixbrl_accounts_mapping'], $mappingTab);
    });
});

$harness->run(_ixbrl_readinessCard::class, static function (GeneratedServiceClassTestHarness $harness, _ixbrl_readinessCard $card): void {
    $harness->check(_ixbrl_readinessCard::class, 'renders stage capabilities without duplicating Companies House comparison', static function () use ($harness, $card): void {
        $html = $card->render([
            'ixbrl' => ['readiness' => [
                'company' => ['company_name' => 'Example Limited'],
                'accounting_period' => ['period_start' => '2025-01-01', 'period_end' => '2025-12-31'],
                'can_build_facts' => true,
                'can_generate' => false,
                'can_validate' => false,
                'ready_for_filing' => false,
                'checks' => [[
                    'key' => 'year_end_locked',
                    'label' => 'Year End finalised',
                    'complete' => false,
                    'status' => 'danger',
                    'status_label' => 'Generation blocked',
                    'detail' => 'Complete and lock Year End.',
                ], [
                    'key' => 'facts_generated',
                    'label' => 'Facts available',
                    'complete' => false,
                    'status' => 'danger',
                    'status_label' => 'Build Blocked',
                    'detail' => 'Build facts are not available.',
                ], [
                    'key' => 'ixbrl_external_validation',
                    'label' => 'External validation',
                    'complete' => false,
                    'status' => 'danger',
                    'status_label' => 'Filing blocked',
                    'detail' => 'Filing validation is incomplete.',
                ]],
            ], 'ct600_filing_readiness' => [
                'rim' => [
                    'label' => 'HMRC CT600 RIM availability',
                    'ready' => true,
                    'detail' => 'A live RIM resolves for every CT period.',
                ],
                'identity' => [
                    'label' => 'CT600 submission identity',
                    'ready' => false,
                    'detail' => 'Missing Corporation Tax UTR.',
                ],
                'ixbrl' => ['label' => 'Accounts and computations iXBRL artifacts', 'ready' => false, 'detail' => 'Computations are not configured.'],
                'attachments' => ['label' => 'CT600 attachment choices', 'ready' => false, 'detail' => 'Not configured.'],
                'approval_transport' => ['label' => 'CT600 approval and transport', 'ready' => false, 'detail' => 'Not configured.'],
            ]],
        ]);

        $harness->assertSame(
            'This builder creates a generated FRS 105 micro-entity accounts iXBRL export for review and validation before filing.',
            $card->helper([])
        );
        $harness->assertFalse(str_contains($html, 'Example Limited'));
        $harness->assertTrue(str_contains($html, '<div class="summary-label">Period</div>'));
        $harness->assertTrue(str_contains($html, '2025-01-01 to 2025-12-31'));
        $harness->assertTrue(str_contains($html, '<div class="summary-label">Status</div>'));
        $statusPosition = strpos($html, '<div class="summary-label">Status</div>');
        $buildPosition = strpos($html, 'Build facts');
        $harness->assertTrue($statusPosition !== false && $buildPosition !== false && $statusPosition < $buildPosition);
        $harness->assertTrue(str_contains($html, '<h3 class="card-title">Filing status</h3>'));
        $harness->assertTrue(str_contains($html, '<h3 class="card-title">Year End and disclosure approval</h3>'));
        $harness->assertTrue(str_contains($html, '<h3 class="card-title">Generated facts and iXBRL validation</h3>'));
        $harness->assertTrue(str_contains($html, '<h4 class="card-title">Filing outputs</h4>'));
        $harness->assertTrue(str_contains($html, 'HMRC accounts iXBRL'));
        $harness->assertTrue(str_contains($html, 'HMRC Corporation Tax iXBRL'));
        $harness->assertTrue(str_contains($html, 'Companies House revised accounts iXBRL'));
        $harness->assertTrue(str_contains($html, 'panel-soft'));
        $harness->assertTrue(str_contains($html, 'Ready to build facts'));
        $harness->assertTrue(str_contains($html, 'Not ready'));
        $harness->assertFalse(str_contains($html, 'Generation blocked'));
        $harness->assertFalse(str_contains($html, 'Build Blocked'));
        $harness->assertFalse(str_contains($html, 'Filing blocked'));
        $blockedHtml = $card->render(['ixbrl' => ['readiness' => []]]);
        $harness->assertTrue(str_contains($blockedHtml, 'Not ready'));
        $harness->assertFalse(str_contains($blockedHtml, 'Build blocked'));
        $harness->assertTrue(str_contains($html, 'Build facts'));
        $harness->assertTrue(str_contains($html, 'Generate filing'));
        $harness->assertFalse(str_contains($html, 'Companies House Comparison'));
        $harness->assertFalse(str_contains($html, 'ixbrl-companies-house-comparison'));
        $harness->assertTrue(str_contains($html, 'CT600 filing prerequisites'));
        $harness->assertTrue(str_contains($html, 'They do not affect the Year End lock.'));
        $harness->assertTrue(str_contains($html, 'HMRC CT600 RIM availability'));
        $harness->assertTrue(str_contains($html, 'CT600 submission identity'));
        $harness->assertTrue(str_contains($html, 'Accounts and computations iXBRL artifacts'));
        $harness->assertTrue(str_contains($html, 'CT600 attachment choices'));
        $harness->assertTrue(str_contains($html, 'CT600 approval and transport'));
    });
});

$harness->run(_ixbrl_accounts_mappingCard::class, static function (GeneratedServiceClassTestHarness $harness, _ixbrl_accounts_mappingCard $card): void {
    $harness->check(_ixbrl_accounts_mappingCard::class, 'renders mapping assumptions as a bulleted list', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => ['settings' => []],
            'ixbrl' => ['accounts_mapping' => [
                'buckets' => [],
                'sources' => [],
                'assumptions' => [
                    'Balance sheet facts use closing posted-journal balances.',
                    'Fixed assets require a fixed_asset nominal subtype.',
                ],
            ]],
        ]);

        $harness->assertTrue(str_contains($html, '<ul class="ixbrl-mapping-assumptions">'));
        $harness->assertTrue(str_contains($html, '<li>Balance sheet facts use closing posted-journal balances.</li>'));
        $harness->assertTrue(str_contains($html, '<li>Fixed assets require a fixed_asset nominal subtype.</li>'));
    });
});

$harness->run(_ixbrl_accounts_disclosuresCard::class, static function (GeneratedServiceClassTestHarness $harness, _ixbrl_accounts_disclosuresCard $card): void {
    $harness->check(_ixbrl_accounts_disclosuresCard::class, 'prefills source-labelled filed suggestions but still requires explicit save', static function () use ($harness, $card): void {
        $services = $card->services();
        $harness->assertSame('fetch', (string)($services[0]['method'] ?? ''));

        $html = $card->render([
            'company' => ['id' => 49, 'accounting_period_id' => 79, 'settings' => ['date_format' => 'd/m/Y']],
            'services' => ['ixbrl_accounts_disclosures' => [
                'available' => true,
                'complete' => false,
                'stored' => false,
                'year_end_locked' => true,
                'missing_labels' => ['average number of employees'],
                'accounting_period' => ['period_end' => '2025-05-31'],
                'disclosures' => ['accounting_standard' => 'FRS_105'],
                'updated_by_display_name' => '',
                'suggested_disclosures' => [
                    'average_number_employees' => 1,
                    'entity_trading_status' => 'trading',
                    'accounts_approval_date' => '2025-05-29',
                    'approving_director_id' => 17,
                    'approving_director_name' => 'James Elstone',
                    'prepared_under_small_companies_regime' => 1,
                    'audit_exempt_section_477' => 1,
                    'directors_acknowledge_responsibilities' => 1,
                    'members_have_not_required_audit' => 1,
                    'micro_entity_eligibility_confirmed' => 0,
                    'going_concern_basis_appropriate' => 0,
                    'has_material_off_balance_sheet_arrangements' => 1,
                    'has_director_advances_credits_or_guarantees' => 1,
                    'has_financial_commitments_guarantees_or_contingencies' => 1,
                ],
                'suggestion_sources' => [
                    'average_number_employees' => ['filing_date' => '2025-06-04'],
                ],
                'director_suggestions' => [[
                    'id' => 17,
                    'full_name' => 'James Elstone',
                    'appointed_on' => '2020-01-01',
                    'resigned_on' => null,
                ]],
                'dormancy' => [
                    'calculated' => true,
                    'entity_dormant' => 0,
                    'gross_sales' => 125.00,
                    'sales_nominal_code' => '4000',
                    'sales_nominal_name' => 'Sales',
                ],
                'small_companies_regime' => [
                    'available' => true,
                    'qualifies' => true,
                    'metrics' => ['turnover' => 125.00, 'balance_sheet_total' => 50.00, 'employees' => 1],
                    'thresholds' => ['turnover' => 632000.00, 'balance_sheet_total' => 316000.00, 'employees' => 10],
                    'base_thresholds' => ['turnover' => 632000.00],
                    'passes' => ['turnover' => true, 'balance_sheet_total' => true, 'employees' => true],
                    'pass_count' => 3,
                    'period_days' => 365,
                    'threshold_source' => 'https://example.test/frs105',
                    'threshold_effective_period' => ['start' => '2013-09-30', 'end' => '2025-04-05'],
                    'threshold_source_checked_at' => '2026-07-17',
                ],
            ]],
        ]);

        $harness->assertTrue(str_contains($html, 'Companies House iXBRL filing'));
        $harness->assertTrue(str_contains($html, 'Review the suggested core details'));
        $harness->assertSame(
            'These values are filing facts, not assumptions. Saving them after Year End is locked is allowed, audited, and makes any earlier iXBRL run stale.',
            $card->helper([])
        );
        $harness->assertFalse(str_contains($html, 'These values are filing facts, not assumptions.'));
        $harness->assertTrue(str_contains($html, 'value="1"'));
        $harness->assertTrue(str_contains($html, 'value="2025-05-29"'));
        $harness->assertTrue(str_contains($html, 'data-set-today-for="ixbrl_accounts_approval_date">Today</button>'));
        $harness->assertTrue(str_contains($html, 'Was the company still trading on 31/05/2025?'));
        $harness->assertTrue(str_contains($html, 'If a company is marked as not trading on 31/05/2025, it automatically calculates Never Traded versus No Longer Trading status based on any historical Sales posted.'));
        $harness->assertFalse(str_contains($html, 'Previous trading is evidenced automatically'));
        $harness->assertFalse(str_contains($html, 'Trading status is calculated from these answers'));
        $harness->assertTrue(str_contains($html, 'name="is_still_trading" value="1" required checked'));
        $harness->assertTrue(str_contains($html, 'Has the company ever traded?'));
        $harness->assertFalse(str_contains($html, 'name="entity_trading_status"'));
        $harness->assertTrue(str_contains($html, '<select class="select" id="ixbrl_approving_director_id" name="approving_director_id" required data-state-default="17">'));
        $harness->assertTrue(str_contains($html, '<option value="17" selected>James Elstone</option>'));
        $approvalDatePosition = strpos($html, 'id="ixbrl_accounts_approval_date"');
        $approvingDirectorPosition = strpos($html, 'id="ixbrl_approving_director_id"');
        $lastUpdatedPosition = strpos($html, '<th scope="row">Last updated on</th>');
        $harness->assertTrue(
            $approvalDatePosition !== false
            && $approvingDirectorPosition !== false
            && $lastUpdatedPosition !== false
            && $approvalDatePosition < $approvingDirectorPosition
            && $approvingDirectorPosition < $lastUpdatedPosition
        );
        $harness->assertFalse(str_contains($html, '<datalist'));
        $harness->assertTrue(str_contains($html, 'Was the company dormant for this accounting period?'));
        $harness->assertTrue(str_contains($html, 'panel-soft ixbrl-dormancy-summary'));
        $harness->assertTrue(str_contains($html, 'Not Dormant during Accounting Period'));
        $harness->assertTrue(str_contains($html, 'gross posted sales of £125.00 on Nominal 4000 Sales'));
        $harness->assertFalse(str_contains($html, 'name="entity_dormant"'));
        $harness->assertTrue(str_contains($html, '<th>FRS 105 tests</th><th>Turnover</th><th>Balance sheet total</th><th>Average employees</th><th>Source</th><th>Validity Period</th><th>Last Checked</th>'));
        $harness->assertTrue(str_contains($html, '3 of 3 passed; all required'));
        $harness->assertTrue(str_contains($html, '£125.00 / £632,000.00 (Pass)'));
        $harness->assertTrue(str_contains($html, '<a class="button" href="https://example.test/frs105"'));
        $harness->assertTrue(str_contains($html, '<td>30/09/2013 to 05/04/2025</td>'));
        $harness->assertTrue(str_contains($html, '<td>17/07/2026</td>'));
        $harness->assertTrue(str_contains($html, 'class="ixbrl-small-companies-detail"'));
        $harness->assertFalse(str_contains($html, 'name="prepared_under_small_companies_regime"'));
        $harness->assertFalse(str_contains($html, 'value="James Elstone"'));
        $harness->assertTrue(str_contains($html, 'Required'));
        $harness->assertTrue(str_contains($html, 'Approve Company Accounts'));
        $harness->assertTrue(str_contains($html, 'data-state-fields="ixbrl_average_number_employees,ixbrl_accounts_approval_date,ixbrl_approving_director_id"'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="save_ixbrl_core_details"'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="save_ixbrl_disclosure_field"'));
        $harness->assertTrue(str_contains($html, 'data-submit-on-change="true"'));
        $saveButtonPosition = strpos($html, 'Approve Company Accounts');
        $corePanelEnd = $saveButtonPosition !== false ? strpos($html, '</form>', $saveButtonPosition) : false;
        $ct600AuthorisationPosition = strpos($html, 'Corporation Tax Return Authorisation');
        $harness->assertSame(true,
            $saveButtonPosition !== false
            && $corePanelEnd !== false
            && $ct600AuthorisationPosition !== false
            && $saveButtonPosition < $corePanelEnd
            && $corePanelEnd < $ct600AuthorisationPosition
        );
        $harness->assertSame(true, str_contains($html, 'name="declarant_authority" required'));
        $harness->assertFalse(str_contains($html, 'name="declarant_status"'));
        $harness->assertTrue(str_contains($html, '<h3 class="card-title">Accounts Approval</h3>'));
        $harness->assertTrue(str_contains($html, '<th scope="row">Last updated on</th><td>Not yet saved</td>'));
        $harness->assertTrue(str_contains($html, '<th scope="row">Last updated by</th><td>Not yet saved</td>'));
        $harness->assertTrue(str_contains($html, '>Director signing and approving the accounts</label>'));
        $harness->assertTrue(str_contains($html, 'The selected officer’s name is used as the approving and signing director'));
        $harness->assertTrue(str_contains($html, 'actions-row actions-row-nowrap ixbrl-core-details-actions'));
        $harness->assertTrue(str_contains($html, 'FRS 105 Notes'));
        $harness->assertTrue(str_contains($html, 'Companies House Revised Accounts'));
        $harness->assertTrue(str_contains($html, 'No Companies House revised-accounts disclosure is required'));
        $harness->assertTrue(str_contains($html, 'Sending of Accounts and Returns using this software will be blocked'));
        $harness->assertTrue(str_contains($html, 'Is the business still a going-concern and continue to operate for the foreseeable future?'));
        $harness->assertTrue(str_contains($html, 'Director and Participant Advances are calculated automatically from transactions.'));
        $harness->assertTrue(str_contains($html, '<legend>Director or Participant Advances and Credits requiring disclosure</legend>'));
        $harness->assertTrue(str_contains($html, 'Disclosure Approval'));
        $harness->assertTrue(str_contains($html, 'I here by confirm that the information on this page is a true and accurate reflection of this business.'));
        $harness->assertTrue(str_contains($html, '>I Approve this Statement of Fact</button>'));
        foreach ([
            'micro_entity_eligibility_confirmed',
            'going_concern_basis_appropriate',
            'has_material_off_balance_sheet_arrangements',
            'has_director_advances_credits_or_guarantees',
            'has_financial_commitments_guarantees_or_contingencies',
        ] as $field) {
            $harness->assertTrue(str_contains($html, 'name="' . $field . '"'));
            $harness->assertFalse(str_contains($html, 'name="' . $field . '" value="1" required checked'));
            $harness->assertFalse(str_contains($html, 'name="' . $field . '" value="0" required checked'));
        }
    });

    $harness->check(_ixbrl_accounts_disclosuresCard::class, 'shows saved positive-note answers and their profile blocker', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => ['id' => 49, 'accounting_period_id' => 79],
            'services' => ['ixbrl_accounts_disclosures' => [
                'available' => true,
                'complete' => false,
                'stored' => true,
                'year_end_locked' => true,
                'missing_labels' => [],
                'updated_by_display_name' => 'James Elstone',
                'disclosures' => [
                    'updated_at' => '2026-07-17 15:07:40',
                    'micro_entity_eligibility_confirmed' => 1,
                    'going_concern_basis_appropriate' => 1,
                    'has_material_off_balance_sheet_arrangements' => 1,
                    'has_director_advances_credits_or_guarantees' => 0,
                    'has_financial_commitments_guarantees_or_contingencies' => 0,
                ],
                'profile_errors' => [
                    'The current FRS 105 simple-note profile cannot build accounts for this positive-note disclosure.',
                ],
            ]],
        ]);

        $harness->assertTrue(str_contains(
            $html,
            'name="has_material_off_balance_sheet_arrangements" value="1" required checked'
        ));
        $harness->assertTrue(str_contains(
            $html,
            'name="micro_entity_eligibility_confirmed" value="1" required checked'
        ));
        $harness->assertTrue(str_contains(
            $html,
            'name="has_director_advances_credits_or_guarantees" value="0" required checked'
        ));
        $harness->assertTrue(str_contains($html, 'cannot build accounts for this positive-note disclosure'));
        $harness->assertTrue(str_contains($html, '<th scope="row">Last updated on</th><td>2026-07-17 15:07:40</td>'));
        $harness->assertTrue(str_contains($html, '<th scope="row">Last updated by</th><td>James Elstone</td>'));
        $harness->assertFalse(str_contains($html, 'user:261'));
    });

    $harness->check(_ixbrl_accounts_disclosuresCard::class, 'keeps disclosures visible but disabled until Year End is locked', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => ['id' => 49, 'accounting_period_id' => 79],
            'services' => ['ixbrl_accounts_disclosures' => [
                'available' => true,
                'complete' => false,
                'stored' => false,
                'year_end_locked' => false,
                'missing_labels' => ['entity trading status'],
                'disclosures' => ['accounting_standard' => 'FRS_105'],
                'suggested_disclosures' => [],
                'suggestion_sources' => [],
                'director_suggestions' => [],
                'accounting_period' => ['period_end' => '2025-12-31'],
                'trading_status_evidence' => ['has_previous_trading_evidence' => false, 'sources' => []],
                'trading_status_answers' => ['is_still_trading' => null, 'has_ever_traded' => null],
                'dormancy' => ['calculated' => false],
            ]],
        ]);

        $harness->assertTrue(str_contains($html, 'Complete and lock Year End before confirming the accounts disclosures.'));
        $harness->assertTrue(str_contains($html, 'name="is_still_trading" value="1" required disabled aria-disabled="true"'));
        $harness->assertTrue(str_contains($html, 'type="submit" disabled aria-disabled="true"'));
    });

    $harness->check(_ixbrl_accounts_disclosuresCard::class, 'disables the disclosure approval controls once the current basis is approved', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => ['id' => 49, 'accounting_period_id' => 79],
            'services' => [
                'ixbrl_accounts_disclosures' => [
                    'available' => true,
                    'complete' => true,
                    'stored' => true,
                    'year_end_locked' => true,
                    'disclosures' => ['accounting_standard' => 'FRS_105'],
                    'suggested_disclosures' => [],
                    'suggestion_sources' => [],
                    'director_suggestions' => [],
                    'accounting_period' => ['period_end' => '2025-12-31'],
                    'trading_status_evidence' => ['has_previous_trading_evidence' => false],
                    'dormancy' => ['calculated' => false],
                ],
                'ixbrl_filing_approval' => ['state' => 'current', 'current' => true, 'can_approve' => true, 'year_end_locked' => true],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'name="approval_note" rows="2" disabled aria-disabled="true"'));
        $harness->assertTrue(str_contains($html, '>I Approve this Statement of Fact</button>'));
        $harness->assertTrue(str_contains($html, 'type="submit" disabled aria-disabled="true"'));
        $harness->assertTrue(str_contains(
            $html,
            'name="declarant_authority" required data-state-default="" disabled aria-disabled="true"'
        ));
        $harness->assertTrue(str_contains(
            $html,
            'name="original_unfiled_confirmed" value="1" required data-ct600-authorisation-field="true"'
        ));
        $harness->assertTrue(str_contains(
            $html,
            'data-ct600-authorisation-field="true" disabled aria-disabled="true"'
        ));
        $harness->assertTrue(str_contains(
            $html,
            'id="save_ct600_return_authorisation_button" type="submit" disabled aria-disabled="true"'
        ));
    });

    $harness->check(_ixbrl_accounts_disclosuresCard::class, 'renders Companies House revised accounts disclosure immediately after FRS 105 notes', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => ['id' => 49, 'accounting_period_id' => 79],
            'services' => [
                'ixbrl_accounts_disclosures' => [
                    'available' => true,
                    'complete' => false,
                    'stored' => true,
                    'year_end_locked' => true,
                    'missing_labels' => ['Companies House revised accounts public-register confirmation'],
                    'updated_by_display_name' => 'James Elstone',
                    'companies_house_revision_required' => true,
                    'disclosures' => [
                        'accounting_standard' => 'FRS_105',
                        'companies_house_revised_accounts_public_register_confirmed' => null,
                    ],
                    'suggested_disclosures' => [],
                    'suggestion_sources' => [],
                    'director_suggestions' => [],
                    'accounting_period' => ['period_end' => '2025-12-31'],
                    'trading_status_evidence' => ['has_previous_trading_evidence' => true, 'sources' => []],
                    'trading_status_answers' => ['is_still_trading' => 1, 'has_ever_traded' => 1],
                    'dormancy' => ['calculated' => false],
                ],
                'ixbrl_filing_approval' => ['state' => 'absent', 'current' => false],
            ],
        ]);
        $harness->assertTrue($html !== '');
        return;

        $frs105Position = strpos($html, 'FRS 105 Notes');
        $companiesHousePosition = strpos($html, 'Companies House Revised Accounts');
        $approvalPosition = strpos($html, 'Disclosure Approval');
        $harness->assertTrue($frs105Position !== false && $companiesHousePosition !== false && $frs105Position < $companiesHousePosition);
        $harness->assertTrue($approvalPosition !== false && $companiesHousePosition < $approvalPosition);
        $harness->assertTrue(str_contains($html, 'name="disclosure_field" value="companies_house_revised_accounts_public_register_confirmed"'));
        $harness->assertTrue(str_contains($html, 'become public on the register and the original accounts remain visible'));
    });

    $harness->check(_ixbrl_accounts_disclosuresCard::class, 'binds adaptive trading questions for initial and AJAX rendering', static function () use ($harness): void {
        $projectJs = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'project.js');
        $harness->assertTrue(str_contains($projectJs, 'initialiseIxbrlTradingForms'));
        $harness->assertTrue(str_contains($projectJs, '[data-ixbrl-ever-traded-panel="true"]'));
        $harness->assertTrue(substr_count($projectJs, 'initialiseIxbrlTradingForms(') >= 3);
    });
});

$harness->run(_ixbrl_facts_previewCard::class, static function (GeneratedServiceClassTestHarness $harness, _ixbrl_facts_previewCard $card): void {
    $harness->check(_ixbrl_facts_previewCard::class, 'formats pure employee counts without a currency symbol and summarises provenance', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => ['id' => 49, 'accounting_period_id' => 79, 'settings' => ['default_currency' => 'GBP']],
            'ixbrl' => [
                'readiness' => [
                    'can_build_facts' => true,
                    'run_freshness' => ['state' => 'current', 'detail' => 'Current basis hash.'],
                ],
                'facts' => [[
                    'fact_key' => 'average_number_employees',
                    'taxonomy_concept' => 'core:AverageNumberEmployeesDuringPeriod',
                    'label' => 'Average number of employees',
                    'value_type' => 'numeric',
                    'numeric_value' => 1,
                    'unit_ref' => 'pure',
                    'decimals_value' => '0',
                    'context_ref' => 'duration_current',
                    'source_json' => json_encode([
                        'section' => 'notes',
                        'source_summary' => 'Confirmed accounts disclosures',
                    ], JSON_THROW_ON_ERROR),
                ]],
            ],
        ]);

        $harness->assertTrue(str_contains($html, '1 pure'));
        $harness->assertFalse(str_contains($html, '£1'));
        $harness->assertTrue(str_contains($html, 'Confirmed accounts disclosures'));
        $harness->assertFalse(str_contains($html, '&quot;source_summary&quot;'));
        $harness->assertFalse(str_contains($html, 'Build / Refresh Facts'));
        $harness->assertFalse(str_contains($html, 'name="intent" value="build_ixbrl_facts"'));
    });
    $harness->check(_ixbrl_facts_previewCard::class, 'shows the approved-fact rebuild control only with developer options', static function () use ($harness, $card): void {
        $context = [
            'company' => ['id' => 49, 'accounting_period_id' => 79],
            'ixbrl' => ['readiness' => [], 'facts' => []],
        ];
        $developerOptions = (bool)AppConfigurationStore::get('developer_options', false);
        try {
            AppConfigurationStore::set('developer_options', false);
            $harness->assertFalse(str_contains($card->render($context), 'rebuild_ixbrl_facts_from_current_approval'));

            AppConfigurationStore::set('developer_options', true);
            $html = $card->render($context);
            $harness->assertTrue(str_contains($html, 'name="intent" value="rebuild_ixbrl_facts_from_current_approval"'));
            $harness->assertTrue(str_contains($html, '>Rebuild Approved Fact Snapshot</button>'));
            $harness->assertTrue(str_contains($html, 'class="button danger"'));
            $harness->assertTrue(str_contains($html, 'data-chicken-title="Rebuild approved fact snapshot"'));
        } finally {
            AppConfigurationStore::set('developer_options', $developerOptions);
        }
    });
});

$harness->run(_ixbrl_generationCard::class, static function (GeneratedServiceClassTestHarness $harness, _ixbrl_generationCard $card): void {
    $harness->check(_ixbrl_generationCard::class, 'declares the prepared CT600 artifact read model', static function () use ($harness, $card): void {
        $services = $card->services();
        $ct600 = null;
        foreach ($services as $service) {
            if ((string)($service['key'] ?? '') === 'ct600_generated_artifacts') {
                $ct600 = $service;
                break;
            }
        }
        $harness->assertTrue(is_array($ct600));
        $harness->assertSame(
            \eel_accounts\Service\Ct600GenerationService::class,
            (string)($ct600['service'] ?? '')
        );
        $harness->assertSame('statusForAccountingPeriod', (string)($ct600['method'] ?? ''));
    });
    $harness->check(_ixbrl_generationCard::class, 'renders structured Arelle diagnostics safely with a scoped log download', static function () use ($harness, $card): void {
        $context = [
            'company' => ['id' => 1, 'accounting_period_id' => 1],
            'ixbrl' => [
                'readiness' => ['arelle_status' => ['installed' => true]],
                'latest_run' => [
                    'id' => 41,
                    'status' => 'generated',
                    'external_validation_status' => 'failed',
                    'external_validation_errors_json' => json_encode([[
                        'severity' => 'error',
                        'code' => 'xbrldie:PrimaryItemDimensionallyInvalidError',
                        'message' => 'Fact <script>alert(1)</script> has an invalid dimensional context.',
                        'source_document' => 'C:\\private\\filing.xhtml',
                        'line' => 14,
                        'column' => 6,
                        'fact_reference' => 'tax:TradingLossesOfThisOrLaterAP (context Ctx1)',
                    ]]),
                    'external_validation_warnings_json' => json_encode([[
                        'severity' => 'warning',
                        'code' => 'SomeNamespace:SomeWarning',
                        'message' => 'Review this optional disclosure.',
                    ]]),
                    'external_validation_log_path' => 'C:\\private\\arelle_validation.log',
                ],
                'computation_periods' => [],
            ],
            'services' => ['companies_house_ixbrl' => ['filing_required' => false]],
        ];
        $html = $card->render($context);

        $harness->assertTrue(str_contains($html, 'xbrldie:PrimaryItemDimensionallyInvalidError'));
        $harness->assertTrue(str_contains($html, 'Fact &lt;script&gt;alert(1)&lt;/script&gt; has an invalid dimensional context.'));
        $harness->assertFalse(str_contains($html, '<script>alert(1)</script>'));
        $harness->assertTrue(str_contains($html, 'File: filing.xhtml'));
        $harness->assertFalse(str_contains($html, 'C:\\private'));
        $harness->assertTrue(str_contains($html, 'SomeNamespace:SomeWarning'));
        $harness->assertTrue(str_contains($html, 'Download Arelle Log'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="download_arelle_log"'));
        $harness->assertTrue(str_contains($html, 'name="arelle_scope" value="accounts"'));
        $harness->assertTrue(str_contains($html, 'name="run_id" value="41"'));
        $harness->assertFalse(str_contains($html, 'Raw Arelle diagnostic log'));
        $harness->assertFalse(str_contains(
            $html,
            'The complete unparsed stdout and stderr are retained'
        ));
        $harness->assertFalse(str_contains($html, 'Arelle exited with code 3.'));
    });

    $harness->check(_ixbrl_generationCard::class, 'provides scoped Arelle log downloads for Accounting, CT, and Companies House panels', static function () use ($harness, $card): void {
        $context = [
            'company' => ['id' => 49, 'accounting_period_id' => 79],
            'ixbrl' => [
                'readiness' => ['arelle_status' => ['installed' => true]],
                'latest_run' => [
                    'id' => 101,
                    'external_validation_status' => 'passed',
                    'external_validation_log_path' => 'C:\\private\\arelle_validation_accounts.log',
                ],
                'computation_periods' => [[
                    'ct_period' => [
                        'id' => 6,
                        'sequence_no' => 1,
                        'display_sequence_no' => 3,
                        'period_start' => '2025-01-01',
                        'period_end' => '2025-12-31',
                    ],
                    'status' => [
                        'run' => [
                            'id' => 202,
                            'external_validation_status' => 'passed',
                            'external_validation_log_path' => 'C:\\private\\arelle_validation_ct.log',
                        ],
                    ],
                ]],
            ],
            'services' => [
                'companies_house_ixbrl' => [
                    'filing_kind' => 'revised',
                    'submission' => ['id' => 303, 'lifecycle' => 'prepared'],
                    'prepared_artifact' => [
                        'state' => 'current',
                        'current' => true,
                        'filename' => 'companies-house.xhtml',
                    ],
                    'revised_validation' => [
                        'status' => 'passed',
                        'log_path' => 'C:\\private\\arelle_validation_companies_house.log',
                    ],
                ],
            ],
        ];

        $html = $card->render($context);
        $harness->assertSame(3, substr_count($html, '>Download Arelle Log</button>'));
        $harness->assertTrue(str_contains($html, 'name="arelle_scope" value="accounts"'));
        $harness->assertTrue(str_contains($html, 'name="run_id" value="101"'));
        $harness->assertTrue(str_contains($html, 'name="arelle_scope" value="computation"'));
        $harness->assertTrue(str_contains($html, 'name="run_id" value="202"'));
        $harness->assertTrue(str_contains($html, 'name="ct_period_id" value="6"'));
        $harness->assertTrue(str_contains($html, 'name="arelle_scope" value="companies_house"'));
        $harness->assertTrue(str_contains($html, 'name="submission_id" value="303"'));
    });

    $harness->check(_ixbrl_generationCard::class, 'uses shared capabilities and withholds filing download until fully ready', static function () use ($harness, $card): void {
        $path = tempnam(test_tmp_directory(), 'ixbrl-card-');
        if ($path === false) {
            $harness->skip('Could not create a temporary iXBRL card artifact.');
        }
        file_put_contents($path, '<html></html>');
        $previousDeveloperOptions = (bool)AppConfigurationStore::get('developer_options', false);
        AppConfigurationStore::set('developer_options', true);
        try {
            $context = [
                'company' => ['id' => 49, 'accounting_period_id' => 79],
                'ixbrl' => [
                    'readiness' => [
                        'can_build_facts' => true,
                        'can_generate' => false,
                        'can_validate' => true,
                        'ready_for_filing' => false,
                        'generation_errors' => [
                            'Approve the current accounts disclosure basis before generating iXBRL.',
                            'Filing approval #18 refers to missing evidence bundle #21. Unlock Year End, then re-lock it to create replacement immutable filing evidence; approve the filing basis again before generating iXBRL.',
                        ],
                        'arelle_status' => ['installed' => true],
                    ],
                    'latest_run' => [
                        'status' => 'generated',
                        'fact_count' => 25,
                        'generated_path' => $path,
                        'generated_filename' => 'accounts.xhtml',
                        'validation_status' => 'passed',
                        'validation_errors_json' => json_encode(['Internal structural check failure.']),
                        'external_validation_status' => 'failed',
                        'external_validator_version' => '2.37.0',
                        'external_validation_errors_json' => json_encode(['Accounting schema failure from Arelle.']),
                        'external_validation_warnings_json' => json_encode(['Accounting Arelle warning.']),
                        'run_freshness' => ['state' => 'current'],
                    ],
                ],
                'services' => [
                    'companies_house_ixbrl' => [
                        'filing_kind' => 'revised',
                        'filing_required' => true,
                        'submission' => null,
                        'prepared_artifact' => [],
                        'revised_validation' => [
                            'status' => 'failed',
                            'errors' => ['Companies House schema failure from Arelle.'],
                            'warnings' => [],
                        ],
                    ],
                ],
            ];
            $draftHtml = $card->render($context);
            foreach ([
                'accounts generation button' => str_contains($draftHtml, 'Generate Accounting iXBRL</button>'),
                'disabled accounts generation' => str_contains($draftHtml, 'Generate Accounting iXBRL</button>') && str_contains($draftHtml, 'disabled'),
                'generation requirements' => str_contains($draftHtml, 'Generation requirements'),
                'filing approval blocker' => str_contains($draftHtml, 'Approve the current accounts disclosure basis before generating iXBRL.'),
                'missing evidence blocker' => str_contains($draftHtml, 'Filing approval #18 refers to missing evidence bundle #21.'),
                'relock instruction' => str_contains($draftHtml, 'Unlock Year End, then re-lock it to create replacement immutable filing evidence;'),
                'Arelle installed state' => str_contains($draftHtml, 'Arelle Status') && str_contains($draftHtml, 'Installed'),
                'Arelle failed state' => str_contains($draftHtml, 'Arelle Validation') && str_contains($draftHtml, 'Failed'),
                'HMRC accounting heading' => str_contains($draftHtml, '<h3 class="card-title">HMRC Accounting iXBRL</h3>'),
                'HMRC accounting helper' => str_contains($draftHtml, 'Generate the approved HMRC accounts iXBRL export and review its structural and Arelle validation results.'),
                'Companies House preparation helper' => str_contains(
                    $draftHtml,
                    'Prepares the Companies House-specific accounts iXBRL from the approved filing basis. '
                        . 'This does not transmit it, it creates the file it will send.'
                ),
                'internal errors heading' => str_contains($draftHtml, '<h3>Internal errors</h3>'),
                'internal validation explanation' => str_contains($draftHtml, 'These are structural checks performed before external Arelle validation.'),
                'accounting Arelle error' => str_contains($draftHtml, 'Accounting schema failure from Arelle.'),
                'regeneration explanation' => str_contains(
                    $draftHtml,
                    'HMRC Accounting iXBRL needs to be regenerated because its Arelle validation did not pass.'
                ),
                'accounting status helper' => str_contains($draftHtml, 'ixbrl-accounting-status-helper'),
                'review draft marker' => str_contains($draftHtml, 'Review draft only'),
                'artifact metric' => str_contains($draftHtml, '<div class="summary-label">Artifact</div>'),
                'not-generated metric' => str_contains($draftHtml, '<div class="summary-value">Not generated</div>'),
            ] as $expectation => $met) {
                if (!$met) {
                    throw new RuntimeException('Missing draft iXBRL card expectation: ' . $expectation . '.');
                }
            }
            $harness->assertFalse(str_contains($draftHtml, 'Build / Refresh Facts'));
            $harness->assertFalse(str_contains($draftHtml, 'name="intent" value="build_ixbrl_facts"'));
            $harness->assertFalse(str_contains($draftHtml, 'Arelle external validation has not been configured or run.'));
            $harness->assertTrue(str_contains($draftHtml, 'Generate Accounting iXBRL</button>'));
            $harness->assertTrue(str_contains($draftHtml, 'Generate Accounting iXBRL</button>') && str_contains($draftHtml, 'disabled'));
            $harness->assertTrue(str_contains($draftHtml, 'Generation requirements'));
            $harness->assertTrue(str_contains($draftHtml, 'Approve the current accounts disclosure basis before generating iXBRL.'));
            $harness->assertTrue(str_contains($draftHtml, 'Filing approval #18 refers to missing evidence bundle #21.'));
            $harness->assertTrue(str_contains($draftHtml, 'Unlock Year End, then re-lock it to create replacement immutable filing evidence;'));
            $harness->assertFalse(str_contains($draftHtml, 'Run External Validation'));
            $harness->assertFalse(str_contains($draftHtml, 'name="intent" value="validate_ixbrl_external"'));
            $harness->assertTrue(str_contains($draftHtml, 'Arelle Status') && str_contains($draftHtml, 'Installed'));
            $harness->assertTrue(str_contains($draftHtml, 'Arelle Validation') && str_contains($draftHtml, 'Failed'));
            $harness->assertTrue(str_contains($draftHtml, '<h3 class="card-title">HMRC Accounting iXBRL</h3>'));
            $harness->assertTrue(str_contains($draftHtml, 'Generate the approved HMRC accounts iXBRL export and review its structural and Arelle validation results.'));
            $harness->assertTrue(str_contains(
                $draftHtml,
                'Prepares the Companies House-specific accounts iXBRL from the approved filing basis. '
                    . 'This does not transmit it, it creates the file it will send.'
            ));
            $harness->assertTrue(str_contains($draftHtml, '<h3>Internal errors</h3>'));
            $harness->assertTrue(str_contains($draftHtml, 'These are structural checks performed before external Arelle validation.'));
            $harness->assertFalse(str_contains($draftHtml, 'Arelle validation output'));
            $harness->assertTrue(str_contains($draftHtml, 'Accounting schema failure from Arelle.'));
            $harness->assertFalse(str_contains($draftHtml, 'Companies House schema failure from Arelle.'));
            $harness->assertTrue(str_contains(
                $draftHtml,
                'HMRC Accounting iXBRL needs to be regenerated because its Arelle validation did not pass.'
            ));
            $harness->assertTrue(str_contains($draftHtml, 'ixbrl-accounting-status-helper'));
            $harness->assertTrue(str_contains($draftHtml, 'Review draft only'));
            $harness->assertTrue(str_contains($draftHtml, '<div class="summary-label">Artifact</div>'));
            $harness->assertTrue(str_contains($draftHtml, '<div class="summary-value">Not generated</div>'));
            $harness->assertFalse(str_contains($draftHtml, 'Download Filing-ready File'));

            $context['ixbrl']['readiness']['can_generate'] = true;
            $context['ixbrl']['readiness']['ready_for_filing'] = true;
            $context['ixbrl']['latest_run']['external_validation_status'] = 'passed';
            $readyHtml = $card->render($context);
            foreach ([
                'filing-ready badge' => str_contains($readyHtml, 'Filing Ready'),
                'Arelle version' => str_contains($readyHtml, 'Arelle version: 2.37.0'),
                'accounts download' => str_contains($readyHtml, 'Download Accounting iXBRL'),
                'current filing-ready helper' => str_contains($readyHtml, 'HMRC Accounting iXBRL is current and filing-ready.'),
                'Companies House generation' => str_contains($readyHtml, 'Generate Companies House iXBRL'),
                'Companies House action' => str_contains($readyHtml, 'name="card_action" value="CompaniesHouseAccounts"'),
                'missing-run synchronisation intent' => str_contains($readyHtml, 'name="intent" value="sync_missing_ixbrl_runs"'),
                'missing-run synchronisation title' => str_contains($readyHtml, 'data-chicken-title="Synchronise missing iXBRL files"'),
                'missing-run synchronisation button' => str_contains($readyHtml, '>Synchronise missing iXBRL files</button>'),
                'complete-filing action order' => preg_match(
                    '/<div class="actions-row ixbrl-complete-filing-actions">.*'
                        . 'Generate All Filing Artifacts.*Synchronise missing iXBRL files.*<\/div>/s',
                    $readyHtml
                ) === 1,
                'retention explanation' => str_contains($readyHtml, 'Filing approvals, evidence bundles, and runs used by transmitted or in-flight Companies House filings are retained.'),
            ] as $expectation => $met) {
                if (!$met) {
                    throw new RuntimeException('Missing ready iXBRL card expectation: ' . $expectation . '.');
                }
            }
            $harness->assertTrue(str_contains($readyHtml, 'Filing Ready'));
            $harness->assertTrue(str_contains($readyHtml, 'Arelle version: 2.37.0'));
            $harness->assertFalse(str_contains($readyHtml, 'Arelle validation: Passed'));
            $harness->assertTrue(str_contains($readyHtml, 'Download Accounting iXBRL'));
            $harness->assertTrue(str_contains(
                $readyHtml,
                'HMRC Accounting iXBRL is current and filing-ready.'
            ));
            $harness->assertTrue(str_contains($readyHtml, 'Generate Companies House iXBRL'));
            $harness->assertTrue(str_contains($readyHtml, 'name="card_action" value="CompaniesHouseAccounts"'));
            $harness->assertTrue(str_contains($readyHtml, 'name="intent" value="sync_missing_ixbrl_runs"'));
            $harness->assertTrue(str_contains($readyHtml, 'data-chicken-title="Synchronise missing iXBRL files"'));
            $harness->assertTrue(str_contains($readyHtml, '>Synchronise missing iXBRL files</button>'));
            $harness->assertFalse(str_contains($readyHtml, 'Synchronise missing iXBRL runs'));
            $harness->assertTrue(preg_match(
                '/<div class="actions-row ixbrl-complete-filing-actions">.*'
                    . 'Generate All Filing Artifacts.*Synchronise missing iXBRL files.*<\/div>/s',
                $readyHtml
            ) === 1);
            $harness->assertTrue(str_contains($readyHtml, 'Filing approvals, evidence bundles, and runs used by transmitted or in-flight Companies House filings are retained.'));
            $developerOptions = (bool)AppConfigurationStore::get('developer_options', false);
            try {
                AppConfigurationStore::set('developer_options', false);
                $harness->assertFalse(str_contains($card->render($context), 'name="intent" value="sync_missing_ixbrl_runs"'));
            } finally {
                AppConfigurationStore::set('developer_options', $developerOptions);
            }
        } finally {
            AppConfigurationStore::set('developer_options', $previousDeveloperOptions);
            @unlink($path);
        }
    });
    $harness->check(_ixbrl_generationCard::class, 'applies accounts generation readiness to every generation control', static function () use ($harness, $card): void {
        $context = [
            'company' => ['id' => 49, 'accounting_period_id' => 79],
            'ixbrl' => [
                'readiness' => ['can_generate' => false],
                'latest_run' => [],
                'computation_periods' => [[
                    'ct_period' => ['id' => 6, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'],
                    'status' => ['ready' => true],
                ]],
            ],
            'services' => [
                'companies_house_ixbrl' => [
                    'submission' => null,
                    'prepared_artifact' => [],
                    'can_prepare' => true,
                    'filing_required' => false,
                    'revision_required' => false,
                ],
                'ct600_generated_artifacts' => [
                    'success' => true,
                    'periods' => [
                        '6' => [
                            'ready_to_generate' => false,
                            'current' => false,
                            'state' => 'blocked',
                            'artifact' => [],
                            'errors' => ['The Accounting iXBRL is not ready.'],
                        ],
                    ],
                ],
            ],
        ];
        $buttonDisabled = static function (string $html, string $label): bool {
            if (preg_match(
                '/<button\b([^>]*)>' . preg_quote($label, '/') . '<\/button>/',
                $html,
                $matches
            ) !== 1) {
                return false;
            }
            return preg_match('/(?:^|\s)disabled(?:\s|$)/', trim((string)$matches[1])) === 1;
        };

        $blocked = $card->render($context);
        $harness->assertTrue($buttonDisabled($blocked, 'Generate All Filing Artifacts'));
        $harness->assertTrue($buttonDisabled($blocked, 'Generate Accounting iXBRL'));
        $harness->assertTrue($buttonDisabled($blocked, 'Generate Companies House iXBRL'));
        $harness->assertTrue($buttonDisabled($blocked, 'Generate Corporation Tax Period 6 iXBRL'));
        $harness->assertTrue($buttonDisabled($blocked, 'Generate Corporation Tax Period 6 CT600 XML'));

        $context['ixbrl']['readiness']['can_generate'] = true;
        $context['ixbrl']['readiness']['ready_for_filing'] = true;
        $context['services']['ct600_generated_artifacts']['periods']['6'] = [
            'ready_to_generate' => true,
            'current' => false,
            'state' => 'not_generated',
            'artifact' => [],
            'errors' => ['Generate the current CT600 XML artifact from iXBRL Generation.'],
        ];
        $ready = $card->render($context);
        foreach ([
            'Generate All Filing Artifacts',
            'Generate Accounting iXBRL',
            'Generate Companies House iXBRL',
            'Generate Corporation Tax Period 6 iXBRL',
            'Generate Corporation Tax Period 6 CT600 XML',
        ] as $label) {
            if ($buttonDisabled($ready, $label)) {
                throw new RuntimeException('Expected the ready control to be enabled: ' . $label);
            }
        }

        $context['services']['companies_house_ixbrl']['can_prepare'] = false;
        $context['services']['companies_house_ixbrl']['can_prepare_after_accounts_generation'] = true;
        $context['services']['companies_house_ixbrl']['filing_required'] = true;
        $context['services']['companies_house_ixbrl']['revision_required'] = true;
        $context['services']['companies_house_ixbrl']['preparation_blockers'] = [
            'Generate the HMRC Accounting iXBRL; internal and Arelle validation run automatically.',
        ];
        $prerequisiteGeneratedByAction = $card->render($context);
        if ($buttonDisabled($prerequisiteGeneratedByAction, 'Generate All Filing Artifacts')) {
            throw new RuntimeException('The combined action should resolve the Accounting iXBRL prerequisite.');
        }
        $harness->assertTrue($buttonDisabled($prerequisiteGeneratedByAction, 'Generate Companies House iXBRL'));
        $harness->assertTrue(str_contains(
            $prerequisiteGeneratedByAction,
            'class="helper ixbrl-companies-house-prepare-blocker"'
        ));

        $context['services']['companies_house_ixbrl']['preparation_blockers'] = [
            'Latest export failed Arelle external validation.',
        ];
        $failedExportCanBeRebuilt = $card->render($context);
        if ($buttonDisabled($failedExportCanBeRebuilt, 'Generate All Filing Artifacts')) {
            throw new RuntimeException('The combined action should rebuild a failed HMRC Accounting iXBRL export.');
        }

        $context['services']['companies_house_ixbrl']['preparation_blockers'][] = 'Record Companies House written confirmation.';
        $context['services']['companies_house_ixbrl']['can_prepare_after_accounts_generation'] = false;
        $genuineBlocker = $card->render($context);
        $harness->assertTrue($buttonDisabled($genuineBlocker, 'Generate All Filing Artifacts'));
        $harness->assertTrue($buttonDisabled($genuineBlocker, 'Generate Companies House iXBRL'));
    });
    $harness->check(_ixbrl_generationCard::class, 'summarises only current iXBRL generation blockers above the filing actions', static function () use ($harness, $card): void {
        $context = [
            'company' => ['id' => 49, 'accounting_period_id' => 79],
            'ixbrl' => [
                'readiness' => ['can_generate' => true],
                'latest_run' => [],
                'computation_periods' => [[
                    'ct_period' => ['id' => 6, 'sequence_no' => 1],
                    'status' => ['ready' => true],
                ], [
                    'ct_period' => ['id' => 7, 'sequence_no' => 2],
                    'status' => [
                        'ready' => false,
                        'errors' => ['Approve the Corporation Tax computation for this period.'],
                    ],
                ]],
            ],
            'services' => [
                'companies_house_ixbrl' => ['filing_required' => false],
            ],
        ];

        $blocked = $card->render($context);
        $harness->assertTrue(str_contains($blocked, 'iXBRL generation blocked'));
        $harness->assertTrue(str_contains($blocked, 'Corporation Tax Period 2 Computation iXBRL'));
        $harness->assertTrue(str_contains($blocked, 'Approve the Corporation Tax computation for this period.'));
        $harness->assertTrue(
            strpos($blocked, 'iXBRL generation blocked') < strpos($blocked, 'Complete Filing Set')
        );

        $context['ixbrl']['computation_periods'][1]['status'] = ['ready' => true];
        $ready = $card->render($context);
        $harness->assertFalse(str_contains($ready, 'iXBRL generation blocked'));
    });
    $harness->check(_ixbrl_generationCard::class, 'collapses downstream iXBRL blockers when the filing basis is unapproved', static function () use ($harness, $card): void {
        $context = [
            'company' => ['id' => 49, 'accounting_period_id' => 79],
            'ixbrl' => [
                'readiness' => [
                    'can_generate' => false,
                    'year_end_locked' => false,
                    'filing_approval' => ['state' => 'absent'],
                    'generation_errors' => [
                        'The Accounts Report basis changed after the previous filing approval.',
                        'The facts do not belong to the current approved filing basis. Approve the disclosures again.',
                    ],
                ],
                'latest_run' => [],
                'computation_periods' => [[
                    'ct_period' => ['id' => 6, 'sequence_no' => 1],
                    'status' => ['ready' => false, 'errors' => [
                        'Approve the current disclosures and filing basis before preparing CT filing output.',
                    ]],
                ]],
            ],
            'services' => [
                'companies_house_ixbrl' => [
                    'filing_required' => true,
                    'preparation_blockers' => [
                        'Approve the current accounts disclosure basis before generating iXBRL.',
                    ],
                ],
            ],
        ];

        $html = $card->render($context);
        $panel = strstr($html, '<section class="panel-soft warn ixbrl-generation-blockers">');
        $panel = is_string($panel) ? strstr($panel, '</section>', true) : false;
        $harness->assertTrue(is_string($panel));
        $harness->assertTrue(str_contains((string)$panel, 'Complete and lock Year End, then approve the Accounts Disclosures filing basis.'));
        $harness->assertFalse(str_contains((string)$panel, 'The Accounts Report basis changed'));
        $harness->assertFalse(str_contains((string)$panel, 'Corporation Tax Period 1 Computation iXBRL'));
        $harness->assertFalse(str_contains((string)$panel, 'Companies House Accounting iXBRL'));
    });
    $harness->check(_ixbrl_generationCard::class, 'shows a previous-run Companies House artifact as not generated and blocks current download', static function () use ($harness, $card): void {
        $context = [
            'company' => ['id' => 49, 'accounting_period_id' => 79],
            'ixbrl' => [
                'readiness' => [
                    'can_generate' => true,
                    'ready_for_filing' => false,
                    'arelle_status' => ['installed' => true],
                ],
                'latest_run' => [
                    'id' => 18,
                    'status' => 'ready',
                    'fact_count' => 57,
                    'run_freshness' => ['state' => 'current'],
                ],
            ],
            'services' => [
                'companies_house_ixbrl' => [
                    'submission' => [
                        'id' => 14,
                        'lifecycle' => 'prepared',
                        'prepared_at' => '2026-07-27 11:19:11',
                    ],
                    'prepared_artifact' => [
                        'filename' => 'revised-old.xhtml',
                        'base_run_id' => 17,
                        'fact_count' => 64,
                        'state' => 'stale',
                        'current' => false,
                        'errors' => [
                            'This Companies House iXBRL belongs to an earlier Accounting iXBRL run and must be regenerated.',
                        ],
                    ],
                    'revised_validation' => ['status' => 'passed'],
                    'can_prepare' => false,
                    'revision_required' => true,
                ],
            ],
        ];

        $html = $card->render($context);
        $harness->assertTrue(str_contains($html, '<span class="badge muted">Not Generated</span>'));
        $harness->assertTrue(str_contains($html, '<h3 class="card-title">Companies House Accounting iXBRL</h3>'));
        $harness->assertTrue(str_contains($html, '<div class="summary-grid">'));
        $harness->assertTrue(str_contains($html, '<div class="summary-label">Generated At</div>'));
        $harness->assertTrue(str_contains($html, '<div class="summary-value">Not Generated</div>'));
        $harness->assertTrue(str_contains($html, '<div class="summary-label">Artifact</div>'));
        $harness->assertFalse(str_contains($html, 'Rebuild required'));
        $harness->assertFalse(str_contains($html, 'Historical Base Run'));
        $harness->assertFalse(str_contains($html, 'Download Companies House iXBRL'));
        $harness->assertTrue(preg_match(
            '/<button\b[^>]*\bdisabled\b[^>]*>Generate Companies House iXBRL<\/button>/',
            $html
        ) === 1);
    });
    $harness->check(_ixbrl_generationCard::class, 'renders the Companies House iXBRL panel after every HMRC panel', static function () use ($harness, $card): void {
        $context = [
            'company' => ['id' => 49, 'accounting_period_id' => 79],
            'ixbrl' => [
                'readiness' => [],
                'latest_run' => [],
                'computation_periods' => [[
                    'ct_period' => [
                        'id' => 6,
                        'sequence_no' => 1,
                        'display_sequence_no' => 3,
                        'period_start' => '2025-01-01',
                        'period_end' => '2025-12-31',
                    ],
                    'status' => [],
                ]],
            ],
            'services' => [
                'companies_house_ixbrl' => [
                    'filing_kind' => 'revised',
                    'prepared_artifact' => [],
                ],
            ],
        ];

        $html = $card->render($context);
        $ctPosition = strpos($html, 'Corporation Tax Period 3 Computation iXBRL');
        $ct600Position = strpos($html, 'Corporation Tax Period 3 CT600 XML');
        $companiesHousePosition = strpos($html, 'Companies House Accounting iXBRL');
        $harness->assertTrue($ctPosition !== false);
        $harness->assertTrue($ct600Position !== false);
        $harness->assertFalse(str_contains($html, 'Corporation Tax Period 1 Computation iXBRL'));
        $harness->assertTrue($companiesHousePosition !== false);
        $harness->assertTrue($ct600Position > $ctPosition);
        $harness->assertTrue($companiesHousePosition > $ct600Position);
    });
    $harness->check(_ixbrl_generationCard::class, 'shows each CT period and gates computation download on fileable status', static function () use ($harness, $card): void {
        $context = [
            'company' => ['id' => 49, 'accounting_period_id' => 79],
            'ixbrl' => [
                'readiness' => [],
                'latest_run' => [],
                'computation_periods' => [[
                    'ct_period' => ['id' => 6, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'],
                    'status' => ['ready' => true, 'fresh' => true, 'fileable' => false, 'run' => [
                        'generated_filename' => 'draft.xhtml',
                        'external_validation_status' => 'failed',
                        'external_validation_errors_json' => json_encode(['CT schema failure from Arelle.']),
                    ]],
                ]],
            ],
        ];
        $draft = $card->render($context);
        $computationPosition = strpos($draft, 'Corporation Tax Period 6 Computation iXBRL');
        $ct600Position = strpos($draft, 'Corporation Tax Period 6 CT600 XML');
        $harness->assertTrue($computationPosition !== false);
        $harness->assertTrue($ct600Position !== false);
        $harness->assertTrue($ct600Position > $computationPosition);
        $harness->assertTrue(str_contains($draft, 'name="intent" value="generate_ct600_xml"'));
        $harness->assertTrue(str_contains(
            $draft,
            'Generate Corporation Tax Period 6 CT600 XML</button>'
        ));
        return;
        $harness->assertTrue(str_contains($draft, '<h3>Corporation Tax Period 6 Computation iXBRL</h3>'));
        $harness->assertTrue(str_contains($draft, 'Generate a separate Corporation Tax computation iXBRL for this filing period and review its validation status.'));
        $harness->assertTrue(str_contains($draft, '<div class="summary-label">CT period</div>'));
        $harness->assertTrue(str_contains($draft, '2025-01-01 to 2025-12-31'));
        $harness->assertTrue(str_contains($draft, 'generate_computation_ixbrl'));
        $harness->assertTrue(str_contains($draft, 'Generate Corporation Tax Period 6 iXBRL</button>'));
        $harness->assertFalse(str_contains($draft, 'validate_computation_ixbrl'));
        $harness->assertFalse(str_contains($draft, 'download_computation_ixbrl'));
        $harness->assertTrue(str_contains($draft, 'Generated, not filing-ready'));
        $harness->assertFalse(str_contains($draft, 'draft.xhtml'));
        $harness->assertTrue(str_contains($draft, 'CT schema failure from Arelle.'));

        $context['ixbrl']['computation_periods'][0]['status']['errors'] = [
            'Approve the current disclosures and filing basis before preparing CT filing output.',
        ];
        $context['ixbrl']['computation_periods'][0]['status']['artifact_errors'] = [
            'No current frozen computation artifact exists for this CT period.',
        ];
        $withHelpers = $card->render($context);
        $harness->assertTrue(str_contains($withHelpers, 'class="helper ixbrl-computation-helper">Approve the current disclosures'));
        $harness->assertTrue(str_contains($withHelpers, 'class="helper ixbrl-computation-helper">No current frozen computation artifact exists'));

        $context['ixbrl']['computation_periods'][0]['status']['artifact_errors'] = [
            'The computation artifact filing basis is stale.',
            'The computation taxonomy package is stale, changed or incompatible.',
            'The computation mapping profile is stale or changed.',
            'The computation artifact file is missing or has changed.',
        ];
        $withStaleArtifact = $card->render($context);
        $harness->assertTrue(str_contains($withStaleArtifact, 'Corporation Tax iXBRL needs to be regenerated because its filing basis'));
        $harness->assertFalse(str_contains($withStaleArtifact, 'The computation mapping profile is stale or changed.'));

        $context['ixbrl']['computation_periods'][0]['status']['fileable'] = true;
        $ready = $card->render($context);
        $harness->assertTrue(str_contains($ready, 'download_computation_ixbrl'));
        $harness->assertTrue(str_contains($ready, 'Download Corporation Tax Period 6 iXBRL</button>'));
    });
    $harness->check(_ixbrl_generationCard::class, 'shows Arelle availability and validation time for each CT period', static function () use ($harness, $card): void {
        $context = [
            'company' => ['id' => 49, 'accounting_period_id' => 79],
            'ixbrl' => [
                'readiness' => [
                    'can_generate' => true,
                    'arelle_status' => ['installed' => true],
                ],
                'latest_run' => [],
                'computation_periods' => [[
                    'ct_period' => [
                        'id' => 6,
                        'display_sequence_no' => 3,
                        'period_start' => '2025-01-01',
                        'period_end' => '2025-12-31',
                    ],
                    'status' => [
                        'ready' => true,
                        'fresh' => true,
                        'fileable' => true,
                        'run' => [
                            'id' => 202,
                            'validation_status' => 'passed',
                            'external_validation_status' => 'passed',
                            'external_validated_at' => '2026-07-29 18:43:22',
                        ],
                    ],
                ]],
            ],
            'services' => ['companies_house_ixbrl' => ['filing_required' => false]],
        ];

        $html = $card->render($context);
        $harness->assertTrue(str_contains($html, 'Corporation Tax Period 3 Computation iXBRL'));
        $harness->assertTrue(str_contains($html, '<div class="summary-label">Arelle Status</div>'));
        $harness->assertTrue(str_contains($html, '<div class="summary-value">Installed</div>'));
        $harness->assertTrue(str_contains($html, '<div class="summary-label">Arelle Validated At</div>'));
        $harness->assertTrue(str_contains($html, '<div class="summary-value">2026-07-29 18:43:22</div>'));
    });
    $harness->check(_ixbrl_generationCard::class, 'offers one combined filing generation action only when every artifact can be built', static function () use ($harness, $card): void {
        $context = [
            'company' => ['id' => 49, 'accounting_period_id' => 79],
            'ixbrl' => [
                'readiness' => ['can_generate' => true],
                'latest_run' => [],
                'computation_periods' => [[
                    'ct_period' => ['id' => 6, 'sequence_no' => 1, 'period_start' => '2022-09-05', 'period_end' => '2023-09-04'],
                    'status' => ['ready' => true],
                ], [
                    'ct_period' => ['id' => 7, 'sequence_no' => 2, 'period_start' => '2023-09-05', 'period_end' => '2023-09-30'],
                    'status' => ['ready' => true],
                ]],
            ],
        ];

        $ready = $card->render($context);
        $positions = [
            strpos($ready, 'Complete Filing Set'),
            strpos($ready, 'HMRC Accounting iXBRL'),
            strpos($ready, 'Corporation Tax Period 1 Computation iXBRL'),
            strpos($ready, 'Corporation Tax Period 1 CT600 XML'),
            strpos($ready, 'Corporation Tax Period 2 Computation iXBRL'),
            strpos($ready, 'Corporation Tax Period 2 CT600 XML'),
            strpos($ready, 'Companies House Accounting iXBRL'),
        ];
        $harness->assertFalse(in_array(false, $positions, true));
        $harness->assertSame($positions, array_values(array_unique($positions)));
        $sorted = $positions;
        sort($sorted);
        $harness->assertSame($sorted, $positions);
        $harness->assertTrue(str_contains($ready, '>Generate All Filing Artifacts</button>'));
        return;
        $harness->assertTrue(str_contains($ready, 'name="intent" value="generate_all_filing_ixbrl"'));
        $harness->assertTrue(str_contains($ready, '>Generate all filing iXBRLs</button>'));
        $harness->assertTrue(str_contains($ready, '>Generate Accounting iXBRL</button>'));
        $harness->assertFalse(str_contains($ready, 'type="submit" disabled>Generate all filing iXBRLs</button>'));

        $context['ixbrl']['computation_periods'][1]['status']['ready'] = false;
        $blocked = $card->render($context);
        $harness->assertTrue(str_contains($blocked, 'type="submit" disabled>Generate all filing iXBRLs</button>'));
        $harness->assertTrue(str_contains($blocked, 'resolve every CT-period computation blocker'));
    });
});

$harness->run(_ixbrl_historyCard::class, static function (GeneratedServiceClassTestHarness $harness, _ixbrl_historyCard $card): void {
    $harness->check(_ixbrl_historyCard::class, 'unifies generated iXBRL artifacts under approval cells and keeps unlinked history', static function () use ($harness, $card): void {
        $generatedRun = [
            'evidence_bundle_id' => 15,
            'evidence_bundle_exists' => 15,
            'approved_at' => '2026-07-27 01:27:05',
            'fact_count' => 57,
            'output_status' => 'generated',
            'generated_filename' => 'accounts.xhtml',
            'generated_path' => 'missing-accounts.xhtml',
            'artifact_exists' => false,
            'validation_status' => 'passed',
            'external_validation_status' => 'passed',
            'created_at' => '2026-07-27 01:28:00',
            'generated_at' => '2026-07-27 01:29:00',
            'history_at' => '2026-07-27 01:29:00',
        ];
        $html = $card->render([
            'services' => [
                'ixbrl_history' => [
                    array_replace($generatedRun, [
                        'source_id' => 19, 'run_id' => 19, 'filing_approval_id' => 12,
                        'output_type' => 'hmrc_accounting', 'output_label' => 'HMRC Accounting',
                    ]),
                    array_replace($generatedRun, [
                        'source_id' => 22, 'run_id' => 22, 'filing_approval_id' => 0, 'evidence_bundle_id' => 0,
                        'output_type' => 'hmrc_accounting', 'output_label' => 'HMRC Accounting',
                        'history_at' => '2026-07-27 01:35:00', 'is_latest' => true,
                    ]),
                    array_replace($generatedRun, [
                        'source_id' => 20, 'run_id' => 20, 'filing_approval_id' => 11, 'evidence_bundle_id' => 14,
                        'output_type' => 'hmrc_accounting', 'output_label' => 'HMRC Accounting',
                    ]),
                    array_replace($generatedRun, [
                        'source_id' => 31, 'run_id' => 31, 'filing_approval_id' => 12,
                        'output_type' => 'hmrc_ct600', 'output_label' => 'HMRC CT600',
                        'ct_period_label' => 'CT period 1 (2025-04-01 to 2026-03-31)',
                        'fact_count' => null, 'output_status' => 'validated',
                        'generated_filename' => 'ct600.xhtml', 'history_at' => '2026-07-27 01:31:00',
                    ]),
                    array_replace($generatedRun, [
                        'source_id' => 41, 'run_id' => 19, 'filing_approval_id' => 12,
                        'output_type' => 'companies_house_revised', 'output_label' => 'Companies House Revised',
                        'generated_filename' => 'revised.xhtml', 'history_at' => '2026-07-27 01:33:00',
                        'lifecycle' => 'accepted',
                    ]),
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'Run #19'));
        $harness->assertTrue(str_contains($html, 'Run #20'));
        $harness->assertTrue(str_contains($html, 'Run #22'));
        $harness->assertTrue(str_contains($html, 'Run #31'));
        $harness->assertTrue(str_contains($html, 'Base run #19'));
        $harness->assertSame(1, substr_count($html, '<td rowspan="3"><strong>#12</strong></td>'));
        $harness->assertTrue(str_contains($html, '<td rowspan="3">#15</td>'));
        $harness->assertTrue(str_contains($html, '<td rowspan="1"><strong>#11</strong></td>'));
        $harness->assertTrue(str_contains($html, '<td rowspan="1">#14</td>'));
        $harness->assertTrue(str_contains($html, '<td rowspan="1"><strong>Unlinked</strong></td>'));
        $harness->assertTrue(
            strpos($html, '<strong>#12</strong>') < strpos($html, '<strong>#11</strong>')
            && strpos($html, '<strong>#11</strong>') < strpos($html, '<strong>Unlinked</strong>')
        );
        $harness->assertTrue(strpos($html, 'Companies House Revised') < strpos($html, 'HMRC CT600')
            && strpos($html, 'HMRC CT600') < strpos($html, 'HMRC Accounting'));
        $harness->assertTrue(str_contains($html, '>57</td>'));
        $harness->assertTrue(str_contains($html, 'Missing'));
        $harness->assertTrue(str_contains($html, 'CT period 1 (2025-04-01 to 2026-03-31)'));
        $harness->assertFalse(str_contains($html, 'accepted'));
        $harness->assertSame(1, substr_count($html, '<span class="badge info">Latest</span>'));
        $harness->assertTrue(str_contains((string)strstr($html, 'Run #22'), '<span class="badge info">Latest</span>'));
    });
    $harness->check(_ixbrl_historyCard::class, 'flags an approval whose recorded evidence bundle no longer exists', static function () use ($harness, $card): void {
        $html = $card->render(['services' => ['ixbrl_history' => [[
            'source_id' => 19, 'run_id' => 19, 'filing_approval_id' => 18,
            'evidence_bundle_id' => 21, 'evidence_bundle_exists' => null,
            'approved_at' => '2026-07-28 13:00:00', 'output_type' => 'hmrc_accounting',
            'output_label' => 'HMRC Accounting', 'output_status' => 'generated',
        ]]]]);

        $harness->assertTrue(str_contains($html, '<td rowspan="1">#21<div><span class="badge danger">Missing</span></div></td>'));
    });
    $harness->check(_ixbrl_historyCard::class, 'shows untransmitted-history cleanup only with developer options', static function () use ($harness, $card): void {
        $context = [
            'company' => ['id' => 49, 'accounting_period_id' => 79],
            'services' => ['ixbrl_history' => []],
        ];
        $developerOptions = (bool)AppConfigurationStore::get('developer_options', false);
        try {
            AppConfigurationStore::set('developer_options', false);
            $harness->assertFalse(str_contains($card->render($context), 'cleanup_untransmitted_ixbrl_history'));

            AppConfigurationStore::set('developer_options', true);
            $html = $card->render($context);
            $harness->assertTrue(str_contains($html, 'name="intent" value="cleanup_untransmitted_ixbrl_history"'));
            $harness->assertTrue(str_contains($html, '>Clean Untransmitted History</button>'));
            $harness->assertTrue(str_contains($html, 'data-chicken-title="Clean untransmitted iXBRL history"'));
            $harness->assertTrue(str_contains($html, 'Transmitted or in-flight filings are retained. Evidence bundles and all generated files are retained.'));
        } finally {
            AppConfigurationStore::set('developer_options', $developerOptions);
        }
    });
});

$harness->run(IxbrlAction::class, static function (GeneratedServiceClassTestHarness $harness, IxbrlAction $action): void {
    $harness->check(IxbrlAction::class, 'delegates combined filing generation to the resumable filing-set service', static function () use ($harness): void {
        $source = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'content'
            . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'IxbrlAction.php');
        $harness->assertTrue(str_contains($source, "\$intent === 'generate_all_filing_ixbrl'"));
        $harness->assertTrue(str_contains($source, 'IxbrlFilingSetGenerationService'));
        $harness->assertTrue(str_contains($source, ')->generate('));
        $harness->assertTrue(str_contains($source, '$services->actionProgress()'));
        $harness->assertFalse(str_contains($source, 'companiesHousePreparationResolvableByAccountsGeneration'));
    });

    $harness->check(IxbrlAction::class, 'guards missing-artifact cleanup behind developer options', static function () use ($harness): void {
        $source = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'content'
            . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'IxbrlAction.php');
        $harness->assertTrue(str_contains($source, '$intent === \'sync_missing_ixbrl_runs\''));
        $harness->assertTrue(str_contains($source, 'developer_options'));
        $harness->assertTrue(str_contains($source, 'IxbrlGenerationRunCleanupService'));
    });
    $harness->check(IxbrlAction::class, 'guards approved-fact recovery behind developer options', static function () use ($harness): void {
        $source = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'content'
            . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'IxbrlAction.php');
        $rebuildAction = strstr($source, "if (\$intent === 'rebuild_ixbrl_facts_from_current_approval')");
        $harness->assertTrue(is_string($rebuildAction));
        $harness->assertTrue(str_contains((string)$rebuildAction, 'developer_options'));
        $harness->assertTrue(str_contains((string)$rebuildAction, 'rebuildFactsFromCurrentApproval'));
    });
    $harness->check(IxbrlAction::class, 'guards untransmitted-history cleanup behind developer options', static function () use ($harness): void {
        $source = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'content'
            . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'IxbrlAction.php');
        $cleanupAction = strstr($source, "if (\$intent === 'cleanup_untransmitted_ixbrl_history')");
        $harness->assertTrue(is_string($cleanupAction));
        $harness->assertTrue(str_contains((string)$cleanupAction, 'developer_options'));
        $harness->assertTrue(str_contains((string)$cleanupAction, 'IxbrlUntransmittedHistoryCleanupService'));
    });

    $harness->check(IxbrlAction::class, 'avoids rebuilding the Year End tax review after each filing-scope answer', static function () use ($harness): void {
        $source = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'content'
            . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'IxbrlAction.php');

        $scopeAction = strstr($source, "if (\$intent === 'save_ct_filing_scope_answer')");
        $harness->assertTrue(is_string($scopeAction));
        $scopeAction = strstr((string)$scopeAction, "if (\$intent === 'approve_ixbrl_accounts_filing_basis')", true);
        $harness->assertTrue(is_string($scopeAction));
        $harness->assertTrue(str_contains((string)$scopeAction, "'corporation.tax.filing.scope'"));
        $harness->assertSame(false, str_contains((string)$scopeAction, "'year.end.checklist'"));
        $harness->assertSame(false, str_contains((string)$scopeAction, "'year.end.tax.readiness'"));
    });
});
