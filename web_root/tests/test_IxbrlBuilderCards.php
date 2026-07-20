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
        ], $page->cards());

        $layoutCards = [];
        $overviewTab = [];
        $disclosuresTab = [];
        $mappingTab = [];
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
            }
        }

        $harness->assertFalse(in_array('ixbrl_trial_balance', $layoutCards, true));
        $harness->assertSame(['ixbrl_readiness'], $overviewTab);
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
                    'label' => 'Year End finalised',
                    'complete' => false,
                    'status' => 'danger',
                    'status_label' => 'Generation blocked',
                    'detail' => 'Complete and lock Year End.',
                ], [
                    'label' => 'Facts available',
                    'complete' => false,
                    'status' => 'danger',
                    'status_label' => 'Build Blocked',
                    'detail' => 'Build facts are not available.',
                ], [
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
        $harness->assertFalse(str_contains($html, 'panel-soft'));
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

$harness->run(_ixbrl_accounts_disclosuresCard::class, static function (GeneratedServiceClassTestHarness $harness, _ixbrl_accounts_disclosuresCard $card): void {
    $harness->check(_ixbrl_accounts_disclosuresCard::class, 'prefills source-labelled filed suggestions but still requires explicit save', static function () use ($harness, $card): void {
        $services = $card->services();
        $harness->assertSame('fetch', (string)($services[0]['method'] ?? ''));

        $html = $card->render([
            'company' => ['id' => 49, 'accounting_period_id' => 79, 'settings' => ['date_format' => 'Y-m-d']],
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
                'director_suggestions' => ['James Elstone'],
                'dormancy' => [
                    'calculated' => true,
                    'entity_dormant' => 0,
                    'gross_sales' => 125.00,
                    'sales_nominal_code' => '4000',
                    'sales_nominal_name' => 'Sales',
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
        $harness->assertTrue(str_contains($html, 'Was the company still trading on 2025-05-31?'));
        $harness->assertTrue(str_contains($html, 'If a company is marked as not trading on 2025-05-31, it automatically calculates Never Traded versus No Longer Trading status based on any historical Sales posted.'));
        $harness->assertFalse(str_contains($html, 'Previous trading is evidenced automatically'));
        $harness->assertFalse(str_contains($html, 'Trading status is calculated from these answers'));
        $harness->assertTrue(str_contains($html, 'name="is_still_trading" value="1" required checked'));
        $harness->assertTrue(str_contains($html, 'Has the company ever traded?'));
        $harness->assertFalse(str_contains($html, 'name="entity_trading_status"'));
        $harness->assertTrue(str_contains($html, '<select class="select" id="ixbrl_approving_director_name" name="approving_director_name" required data-state-default="James Elstone">'));
        $harness->assertTrue(str_contains($html, '<option value="James Elstone" selected>James Elstone</option>'));
        $harness->assertFalse(str_contains($html, '<datalist'));
        $harness->assertTrue(str_contains($html, 'Was the company dormant for this accounting period?'));
        $harness->assertTrue(str_contains($html, 'panel-soft ixbrl-dormancy-summary'));
        $harness->assertTrue(str_contains($html, 'Not Dormant during Accounting Period'));
        $harness->assertTrue(str_contains($html, 'gross posted sales of £125.00 on Nominal 4000 Sales'));
        $harness->assertFalse(str_contains($html, 'name="entity_dormant"'));
        $harness->assertTrue(str_contains($html, 'all three FRS 105 tests are required'));
        $harness->assertTrue(str_contains($html, 'class="ixbrl-small-companies-detail"'));
        $harness->assertFalse(str_contains($html, 'name="prepared_under_small_companies_regime"'));
        $harness->assertTrue(str_contains($html, 'value="James Elstone"'));
        $harness->assertTrue(str_contains($html, 'Required'));
        $harness->assertTrue(str_contains($html, 'Save Filling Statement'));
        $harness->assertTrue(str_contains($html, 'data-state-fields="ixbrl_average_number_employees,ixbrl_accounts_approval_date,ixbrl_approving_director_name"'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="save_ixbrl_core_details"'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="save_ixbrl_disclosure_field"'));
        $harness->assertTrue(str_contains($html, 'data-submit-on-change="true"'));
        $saveButtonPosition = strpos($html, 'Save Filling Statement');
        $corePanelEnd = strpos($html, "</form>\n                <div class=\"settings-stack\">");
        $harness->assertTrue($saveButtonPosition !== false && $corePanelEnd !== false && $saveButtonPosition < $corePanelEnd);
        $harness->assertTrue(str_contains($html, '<h3 class="card-title">Account Period Basic Information</h3>'));
        $harness->assertTrue(str_contains($html, '<th scope="row">Last updated on</th><td>Not yet saved</td>'));
        $harness->assertTrue(str_contains($html, '<th scope="row">Last updated by</th><td>Not yet saved</td>'));
        $harness->assertTrue(str_contains($html, '>Approving Director</label>'));
        $harness->assertTrue(str_contains($html, 'actions-row actions-row-nowrap ixbrl-core-details-actions'));
        $harness->assertTrue(str_contains($html, 'FRS 105 Notes'));
        $harness->assertTrue(str_contains($html, 'not inferred or prefilled from Companies House'));
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
    });
});

$harness->run(_ixbrl_generationCard::class, static function (GeneratedServiceClassTestHarness $harness, _ixbrl_generationCard $card): void {
    $harness->check(_ixbrl_generationCard::class, 'uses shared capabilities and withholds filing download until fully ready', static function () use ($harness, $card): void {
        $path = tempnam(sys_get_temp_dir(), 'ixbrl-card-');
        if ($path === false) {
            $harness->skip('Could not create a temporary iXBRL card artifact.');
        }
        file_put_contents($path, '<html></html>');
        try {
            $context = [
                'company' => ['id' => 49, 'accounting_period_id' => 79],
                'ixbrl' => [
                    'readiness' => [
                        'can_build_facts' => true,
                        'can_generate' => false,
                        'can_validate' => true,
                        'ready_for_filing' => false,
                        'arelle_status' => ['installed' => true],
                    ],
                    'latest_run' => [
                        'status' => 'generated',
                        'fact_count' => 25,
                        'generated_path' => $path,
                        'generated_filename' => 'accounts.xhtml',
                        'validation_status' => 'passed',
                        'external_validation_status' => 'failed',
                        'run_freshness' => ['state' => 'current'],
                    ],
                ],
            ];
            $draftHtml = $card->render($context);
            $harness->assertTrue(str_contains($draftHtml, 'Generate Filing Export</button>'));
            $harness->assertTrue(str_contains($draftHtml, 'Generate Filing Export</button>') && str_contains($draftHtml, 'disabled'));
            $harness->assertTrue(str_contains($draftHtml, 'Run External Validation'));
            $harness->assertTrue(str_contains($draftHtml, 'Arelle Status') && str_contains($draftHtml, 'Installed'));
            $harness->assertTrue(str_contains($draftHtml, 'Arelle Validation') && str_contains($draftHtml, 'Failed'));
            $harness->assertTrue(str_contains($draftHtml, 'Review draft only'));
            $harness->assertFalse(str_contains($draftHtml, 'Download Filing-ready File'));

            $context['ixbrl']['readiness']['can_generate'] = true;
            $context['ixbrl']['readiness']['ready_for_filing'] = true;
            $context['ixbrl']['latest_run']['external_validation_status'] = 'passed';
            $readyHtml = $card->render($context);
            $harness->assertTrue(str_contains($readyHtml, 'Filing Ready'));
            $harness->assertTrue(str_contains($readyHtml, 'Download Filing-ready File'));
        } finally {
            @unlink($path);
        }
    });
    $harness->check(_ixbrl_generationCard::class, 'shows each CT period and gates computation download on fileable status', static function () use ($harness, $card): void {
        $context = [
            'company' => ['id' => 49, 'accounting_period_id' => 79],
            'ixbrl' => [
                'readiness' => [],
                'latest_run' => [],
                'computation_periods' => [[
                    'ct_period' => ['id' => 6, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31'],
                    'status' => ['ready' => true, 'fresh' => true, 'fileable' => false, 'run' => ['generated_filename' => 'draft.xhtml']],
                ]],
            ],
        ];
        $draft = $card->render($context);
        $harness->assertTrue(str_contains($draft, 'CT period 2025-01-01 to 2025-12-31'));
        $harness->assertTrue(str_contains($draft, 'generate_computation_ixbrl'));
        $harness->assertTrue(str_contains($draft, 'validate_computation_ixbrl'));
        $harness->assertFalse(str_contains($draft, 'download_computation_ixbrl'));

        $context['ixbrl']['computation_periods'][0]['status']['fileable'] = true;
        $ready = $card->render($context);
        $harness->assertTrue(str_contains($ready, 'download_computation_ixbrl'));
    });
    $harness->check(_ixbrl_generationCard::class, 'offers one combined filing generation action only when every artifact can be built', static function () use ($harness, $card): void {
        $context = [
            'company' => ['id' => 49, 'accounting_period_id' => 79],
            'ixbrl' => [
                'readiness' => ['can_generate' => true],
                'latest_run' => [],
                'computation_periods' => [[
                    'ct_period' => ['id' => 6, 'period_start' => '2022-09-05', 'period_end' => '2023-09-04'],
                    'status' => ['ready' => true],
                ], [
                    'ct_period' => ['id' => 7, 'period_start' => '2023-09-05', 'period_end' => '2023-09-30'],
                    'status' => ['ready' => true],
                ]],
            ],
        ];

        $ready = $card->render($context);
        $harness->assertTrue(str_contains($ready, 'name="intent" value="generate_all_filing_ixbrl"'));
        $harness->assertTrue(str_contains($ready, '>Generate all filing iXBRLs</button>'));
        $harness->assertFalse(str_contains($ready, 'type="submit" disabled>Generate all filing iXBRLs</button>'));

        $context['ixbrl']['computation_periods'][1]['status']['ready'] = false;
        $blocked = $card->render($context);
        $harness->assertTrue(str_contains($blocked, 'type="submit" disabled>Generate all filing iXBRLs</button>'));
        $harness->assertTrue(str_contains($blocked, 'resolve every CT-period computation blocker'));
    });
});

$harness->run(IxbrlAction::class, static function (GeneratedServiceClassTestHarness $harness, IxbrlAction $action): void {
    $harness->check(IxbrlAction::class, 'delegates combined filing generation to the existing accounts and per-period generators', static function () use ($harness): void {
        $source = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'content'
            . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'IxbrlAction.php');
        $harness->assertTrue(str_contains($source, "\$intent === 'generate_all_filing_ixbrl'"));
        $harness->assertTrue(str_contains($source, '$this->generatePreview($companyId, $accountingPeriodId)'));
        $harness->assertTrue(str_contains($source, '$this->generateComputation($companyId, $accountingPeriodId, $ctPeriodId)'));
        $harness->assertTrue(str_contains($source, 'projectForAccountingPeriod($companyId, $accountingPeriodId)'));
    });
});
