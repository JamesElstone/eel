<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'PageServiceTestFactory.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->run(_hmrc::class, static function (GeneratedServiceClassTestHarness $harness, _hmrc $page): void {
    $harness->check(_hmrc::class, 'keeps HMRC filing controls off the obligations page', static function () use ($harness, $page): void {
        $harness->assertFalse(in_array('hmrc_transmit', $page->cards(), true));
    });
});

$harness->run(_transmit::class, static function (GeneratedServiceClassTestHarness $harness, _transmit $page): void {
    $harness->check(_transmit::class, 'separates HMRC and Companies House transmission cards', static function () use ($harness, $page): void {
        $harness->assertSame(
            ['hmrc_transmit', 'companies_house_transmit', 'govtalk_transmission_history', 'govtalk_exchanges'],
            $page->cards()
        );
        $harness->assertSame('HMRC', (string)($page->cardLayout()[0]['tab'] ?? ''));
        $harness->assertSame(
            ['hmrc_transmit'],
            (array)($page->cardLayout()[0]['cards'] ?? [])
        );
        $harness->assertSame('Companies House', (string)($page->cardLayout()[1]['tab'] ?? ''));
        $harness->assertSame(
            ['companies_house_transmit'],
            (array)($page->cardLayout()[1]['cards'] ?? [])
        );
        $harness->assertSame('History', (string)($page->cardLayout()[2]['tab'] ?? ''));
        $harness->assertSame(
            ['govtalk_transmission_history', 'govtalk_exchanges'],
            (array)($page->cardLayout()[2]['cards'] ?? [])
        );
    });
});

$harness->run(_hmrc_transmitCard::class, static function (
    GeneratedServiceClassTestHarness $harness,
    _hmrc_transmitCard $card
): void {
    $harness->check(_hmrc_transmitCard::class, 'declares the accounting-period CT600 status read model', static function () use ($harness, $card): void {
        $services = $card->services();
        $harness->assertSame('hmrc_ct600_status', (string)($services[0]['key'] ?? ''));
        $harness->assertSame(\eel_accounts\Service\HmrcCorporationTaxSubmissionService::class, (string)($services[0]['service'] ?? ''));
        $harness->assertSame('status', (string)($services[0]['method'] ?? ''));
        $harness->assertSame(':company.id', (string)($services[0]['params']['companyId'] ?? ''));
        $harness->assertSame(':company.accounting_period_id', (string)($services[0]['params']['accountingPeriodId'] ?? ''));
    });

    $harness->check(_hmrc_transmitCard::class, 'renders one independently gated panel per CT period', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => ['id' => 49, 'accounting_period_id' => 79],
            'services' => ['hmrc_ct600_status' => [
                'success' => true,
                'xml_environment' => 'LIVE',
                'test_environment' => 'TIL',
                'live_environment' => 'LIVE',
                'environments' => [
                    'TIL' => ['ready' => true, 'credentials_configured' => true, 'blockers' => []],
                    'LIVE' => ['ready' => true, 'credentials_configured' => true, 'blockers' => []],
                ],
                'periods' => [[
                    'ct_period_id' => 6,
                    'xml_environment' => 'LIVE',
                    'sequence_no' => 1,
                    'period_start' => '2022-09-05',
                    'period_end' => '2023-09-04',
                    'test_ready' => true,
                    'live_ready' => false,
                    'latest_test' => [],
                    'latest_test_attempt' => [],
                    'latest_til_attempt' => [],
                    'latest_live_attempt' => [],
                    'latest_live' => [],
                    'filing_dependencies' => [
                        ['label' => 'Disclosures and filing basis', 'ready' => true, 'message' => ''],
                        ['label' => 'CT-period filing basis', 'ready' => true, 'message' => ''],
                        ['label' => 'CT600 source model', 'ready' => true, 'message' => ''],
                        ['label' => 'Filing iXBRL artifacts', 'ready' => true, 'message' => ''],
                    ],
                    'blockers' => [],
                    'live_blockers' => ['Run HMRC Test in Live for the current filing body.'],
                ], [
                    'ct_period_id' => 7,
                    'xml_environment' => 'LIVE',
                    'sequence_no' => 2,
                    'display_sequence_no' => 3,
                    'period_start' => '2023-09-05',
                    'period_end' => '2023-09-30',
                    'test_ready' => true,
                    'live_ready' => true,
                    'latest_test' => ['business_outcome' => 'accepted', 'irmark' => 'IRMARK-7'],
                    'latest_live' => [],
                    'latest_test_attempt' => ['business_outcome' => 'sandbox_passed', 'hmrc_submission_reference' => 'TEST-7'],
                    'latest_til_attempt' => ['business_outcome' => 'til_validated', 'hmrc_submission_reference' => 'TIL-7', 'irmark' => 'IRMARK-7'],
                    'latest_live_attempt' => [],
                    'filing_dependencies' => [
                        ['label' => 'Disclosures and filing basis', 'ready' => true, 'message' => ''],
                        ['label' => 'CT-period filing basis', 'ready' => true, 'message' => ''],
                        ['label' => 'CT600 source model', 'ready' => true, 'message' => ''],
                        [
                            'label' => 'Filing iXBRL artifacts',
                            'ready' => false,
                            'message' => 'The current filing iXBRL artifacts are not ready.',
                            'detail' => 'The computation artifact filing basis is stale.',
                        ],
                    ],
                    'blockers' => [],
                ]],
            ]],
        ]);

        $harness->assertSame(2, substr_count($html, '<h3 class="card-title">CT Period '));
        $harness->assertTrue(str_contains($html, 'CT Period 1 (2022-09-05 to 2023-09-04):'));
        $harness->assertTrue(str_contains($html, 'CT Period 3 (2023-09-05 to 2023-09-30):'));
        $harness->assertSame(2, substr_count($html, 'name="intent" value="hmrc_submit_test"'));
        $harness->assertSame(2, substr_count($html, 'name="intent" value="hmrc_submit_live"'));
        $harness->assertTrue(str_contains(
            $html,
            '<section class="panel-soft summary-card warn hmrc-connection-summary-card hmrc-transmit-status-board">'
            . '<div class="status-head"><h3 class="card-title">Environment</h3>'
            . '<span class="badge warning">Live</span></div>'
        ));
        $harness->assertTrue(str_contains($html, '<a class="button" href="?page=settings&amp;show_card=api_mode">Configure HMRC XML Environment</a>'));
        $harness->assertTrue(str_contains($html, '<div class="summary-card success"><div class="summary-label">Credentials</div><div class="summary-value">Configured</div>'));
        $harness->assertFalse(str_contains($html, 'Test path'));
        $harness->assertFalse(str_contains($html, 'Live path'));
        $harness->assertFalse(str_contains($html, 'Connection blocker'));
        $harness->assertTrue(str_contains($html, '<div class="summary-label">Test In Live State</div><div class="summary-value">Successful</div>'));
        $harness->assertTrue(str_contains($html, '<div class="summary-label">Submission Result</div><div class="summary-value">Not attempted</div>'));
        $harness->assertTrue(str_contains($html, '<div class="summary-card primary"><div class="summary-label">Test Result</div><div class="summary-value">Successful</div>'));
        $harness->assertTrue(str_contains($html, 'HMRC TIL Reference'));
        $harness->assertTrue(str_contains($html, 'HMRC Live Reference'));
        $harness->assertTrue(str_contains($html, 'Test Reference'));
        $harness->assertTrue(str_contains($html, 'This shows the current Status for this Corporation Tax Return'));
        $harness->assertTrue(str_contains($html, 'HMRC Submission Evidence'));
        $harness->assertTrue(str_contains($html, '<div class="summary-grid four">'));
        $harness->assertSame(2, substr_count($html, '<h3 class="card-title">Disclosures and filing basis</h3><span class="badge success">Present</span>'));
        $harness->assertSame(2, substr_count($html, '<h3 class="card-title">CT-period filing basis</h3><span class="badge success">Present</span>'));
        $harness->assertSame(2, substr_count($html, '<h3 class="card-title">CT600 source model</h3><span class="badge success">Present</span>'));
        $harness->assertTrue(str_contains($html, '<section class="panel-soft summary-card danger hmrc-transmit-status-board"><div class="status-head"><h3 class="card-title">Filing iXBRL artifacts</h3><span class="badge danger">Not ready</span></div><div class="helper">The current filing iXBRL artifacts are not ready.</div><div class="helper">The computation artifact filing basis is stale.</div></section>'));
        $harness->assertTrue(str_contains($html, 'Run HMRC Test in Live for the current filing body.'));
        $harness->assertTrue(str_contains($html, 'IRMARK-7'));
        $harness->assertSame(2, substr_count($html, '>Transmit Submission to Test-In-Live</button>'));
        $harness->assertSame(2, substr_count($html, '>Transmit Submission to Live</button>'));
        $harness->assertTrue(str_contains($html, 'Ready for Test-In-Live'));
        $harness->assertTrue(str_contains($html, 'data-chicken-check="true"'));
        $harness->assertTrue(str_contains($html, 'data-chicken-confirm-text="Run Test-In-Live"'));
        $harness->assertTrue(str_contains($html, 'data-chicken-confirm-text="Transmit LIVE Tax Return"'));
        $harness->assertTrue(str_contains($html, 'data-chicken-button-class="button danger"'));
        $harness->assertTrue(str_contains($html, 'does not file the return or send it to HMRC back-end systems'));
        $harness->assertTrue(str_contains($html, 'sends tax return information outside EEL Accounts'));
        foreach (['declaration_name', 'declaration_status', 'original_unfiled_confirmed',
                  'authority_confirmed', 'declaration_confirmed'] as $field) {
            $harness->assertFalse(str_contains($html, 'name="' . $field . '"'));
        }
        $harness->assertSame(2, substr_count($html, '<h3>Transmit Submission</h3>'));
        $harness->assertFalse(str_contains($html, 'supplementary_scope_confirmed'));
        $harness->assertFalse(str_contains($html, 'A successful TIL result for the current body and source manifest is required before LIVE submission.'));
        $harness->assertSame(1, preg_match('/name="ct_period_id" value="6"[\s\S]*?<button class="button primary" type="submit" name="intent" value="hmrc_submit_test" data-chicken-check/', $html));
        $harness->assertSame(1, preg_match('/name="ct_period_id" value="6"[\s\S]*?<button class="button danger" type="submit" name="intent" value="hmrc_submit_live" disabled/', $html));
        $harness->assertSame(1, preg_match('/name="ct_period_id" value="7"[\s\S]*?<button class="button danger" type="submit" name="intent" value="hmrc_submit_live" disabled data-chicken-check/', $html));
    });

    $harness->check(_hmrc_transmitCard::class, 'moves from Test-In-Live readiness to LIVE readiness for the matching body', static function () use ($harness, $card): void {
        $dependencies = [
            ['label' => 'Disclosures and filing basis', 'ready' => true],
            ['label' => 'CT-period filing basis', 'ready' => true],
            ['label' => 'CT600 source model', 'ready' => true],
            ['label' => 'Filing iXBRL artifacts', 'ready' => true],
        ];
        $html = $card->render([
            'company' => ['id' => 49, 'accounting_period_id' => 79],
            'services' => ['hmrc_ct600_status' => [
                'success' => true,
                'xml_environment' => 'LIVE',
                'test_environment' => 'TIL',
                'live_environment' => 'LIVE',
                'environments' => [
                    'TIL' => ['ready' => true, 'credentials_configured' => true, 'blockers' => []],
                    'LIVE' => ['ready' => true, 'credentials_configured' => true, 'blockers' => []],
                ],
                'periods' => [[
                    'ct_period_id' => 6,
                    'xml_environment' => 'LIVE',
                    'sequence_no' => 1,
                    'period_start' => '2022-09-05',
                    'period_end' => '2023-09-04',
                    'test_ready' => true,
                    'live_ready' => false,
                    'filing_dependencies' => $dependencies,
                    'test_blockers' => [],
                    'live_blockers' => ['The exact current filing body must pass HMRC Test in Live before LIVE filing.'],
                ], [
                    'ct_period_id' => 7,
                    'xml_environment' => 'LIVE',
                    'sequence_no' => 2,
                    'period_start' => '2023-09-05',
                    'period_end' => '2023-09-30',
                    'test_ready' => false,
                    'live_ready' => true,
                    'latest_til_attempt' => ['business_outcome' => 'til_validated'],
                    'filing_dependencies' => $dependencies,
                    'test_blockers' => ['This exact filing body has already passed HMRC Test in Live.'],
                    'live_blockers' => [],
                ]],
            ]],
        ]);

        $secondStart = strpos($html, 'CT Period 2 (2023-09-05 to 2023-09-30):');
        $harness->assertTrue(is_int($secondStart) && $secondStart > 0);
        $first = substr($html, 0, (int)$secondStart);
        $second = substr($html, (int)$secondStart);
        $harness->assertTrue(str_contains($first, 'Ready for Test-In-Live'));
        $harness->assertTrue(str_contains($first, 'name="intent" value="hmrc_submit_test" data-chicken-check'));
        $harness->assertTrue(str_contains($first, 'name="intent" value="hmrc_submit_live" disabled'));
        $harness->assertTrue(str_contains($second, 'Ready for LIVE'));
        $harness->assertTrue(str_contains($second, 'name="intent" value="hmrc_submit_test" disabled'));
        $harness->assertTrue(str_contains($second, 'name="intent" value="hmrc_submit_live" data-chicken-check'));
        $harness->assertFalse(str_contains($second, 'Test-In-Live blocker</div><div class="helper">This exact filing body has already passed'));
    });

    $harness->check(_hmrc_transmitCard::class, 'shows request-file generation only with developer options enabled', static function () use ($harness, $card): void {
        $context = [
            'company' => ['id' => 49, 'accounting_period_id' => 79],
            'services' => ['hmrc_ct600_status' => [
                'success' => true,
                'xml_environment' => 'TEST',
                'test_environment' => 'TEST',
                'live_environment' => 'DISABLED',
                'environments' => [
                    'TEST' => [
                        'ready' => true,
                        'credentials_configured' => true,
                        'blockers' => [],
                    ],
                ],
                'periods' => [[
                    'ct_period_id' => 6,
                    'xml_environment' => 'TEST',
                    'period_start' => '2025-01-01',
                    'period_end' => '2025-12-31',
                    'test_ready' => true,
                    'live_ready' => false,
                    'filing_dependencies' => [
                        ['label' => 'Disclosures and filing basis', 'ready' => true],
                        ['label' => 'CT-period filing basis', 'ready' => true],
                        ['label' => 'CT600 source model', 'ready' => true],
                        ['label' => 'Filing iXBRL artifacts', 'ready' => true],
                    ],
                    'blockers' => [],
                ]],
            ]],
        ];
        $previous = AppConfigurationStore::get('developer_options', false);
        try {
            AppConfigurationStore::set('developer_options', false);
            $standard = $card->render($context);
            $harness->assertFalse(str_contains($standard, 'hmrc_generate_request'));
            $harness->assertFalse(str_contains($standard, 'Generate Request File'));

            AppConfigurationStore::set('developer_options', true);
            $developer = $card->render($context);
            $harness->assertTrue(str_contains(
                $developer,
                'name="request_environment" value="TEST"'
            ));
            $harness->assertTrue(str_contains($developer, '>Generate TEST Request File</button>'));
            $harness->assertFalse(str_contains($developer, '>Generate Test-In-Live Request File</button>'));
            $harness->assertFalse(str_contains($developer, '>Generate LIVE Request File</button>'));
            $harness->assertTrue(str_contains(
                $developer,
                'class="button primary" type="submit" name="intent" value="hmrc_submit_test"'
            ));
            $harness->assertTrue(str_contains($developer, '>Transmit Submission to Test</button>'));
            $harness->assertFalse(str_contains($developer, 'name="intent" value="hmrc_submit_live"'));
            $harness->assertFalse(str_contains($developer, '>Transmit Submission to Test-In-Live</button>'));
            $harness->assertTrue(str_contains($developer, 'data-chicken-check="true"'));
            $harness->assertTrue(str_contains($developer, 'to HMRC TEST?'));
            $harness->assertTrue(str_contains($developer, 'does not file the return'));
            $harness->assertTrue(str_contains($developer, 'data-chicken-button-class="button primary"'));

            $context['services']['hmrc_ct600_status']['environments']['TEST'] = [
                'ready' => false,
                'credentials_configured' => false,
                'blockers' => ['HMRC XML Sender ID is missing or invalid.'],
            ];
            $withoutCredentials = $card->render($context);
            $harness->assertTrue(str_contains(
                $withoutCredentials,
                '>Generate TEST Request File</button>'
            ));
            $harness->assertTrue(str_contains(
                $withoutCredentials,
                'name="intent" value="hmrc_submit_test" disabled data-chicken-check="true"'
            ));
        } finally {
            AppConfigurationStore::set('developer_options', (bool)$previous);
        }
    });

    $harness->check(_hmrc_transmitCard::class, 'renders explicit TEST TIL and LIVE request-file controls in LIVE mode', static function () use ($harness, $card): void {
        $context = [
            'company' => ['id' => 49, 'accounting_period_id' => 79],
            'services' => ['hmrc_ct600_status' => [
                'success' => true,
                'xml_environment' => 'LIVE',
                'test_environment' => 'TIL',
                'live_environment' => 'LIVE',
                'environments' => [
                    'TIL' => ['ready' => true, 'credentials_configured' => true, 'blockers' => []],
                    'LIVE' => ['ready' => true, 'credentials_configured' => true, 'blockers' => []],
                ],
                'periods' => [[
                    'ct_period_id' => 6,
                    'xml_environment' => 'LIVE',
                    'period_start' => '2022-09-05',
                    'period_end' => '2023-09-04',
                    'test_ready' => true,
                    'live_ready' => false,
                    'filing_dependencies' => [
                        ['label' => 'Disclosures and filing basis', 'ready' => true],
                        ['label' => 'CT-period filing basis', 'ready' => true],
                        ['label' => 'CT600 source model', 'ready' => true],
                        ['label' => 'Filing iXBRL artifacts', 'ready' => true],
                    ],
                    'test_blockers' => [],
                    'live_blockers' => ['The exact current filing body must pass HMRC Test in Live before LIVE filing.'],
                ]],
            ]],
        ];
        $previous = AppConfigurationStore::get('developer_options', false);
        try {
            AppConfigurationStore::set('developer_options', true);
            $html = $card->render($context);
            foreach ([
                'TEST' => 'Generate TEST Request File',
                'TIL' => 'Generate Test-In-Live Request File',
                'LIVE' => 'Generate LIVE Request File',
            ] as $mode => $label) {
                $harness->assertTrue(str_contains($html, 'name="request_environment" value="' . $mode . '"'));
                $harness->assertTrue(str_contains($html, '>' . $label . '</button>'));
            }
            $harness->assertSame(3, substr_count($html, 'name="intent" value="hmrc_generate_request"'));
        } finally {
            AppConfigurationStore::set('developer_options', (bool)$previous);
        }
    });

    $harness->check(_hmrc_transmitCard::class, 'shows missing selected-profile credentials as danger helper text', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => ['id' => 49, 'accounting_period_id' => 79],
            'services' => ['hmrc_ct600_status' => [
                'xml_environment' => 'TEST',
                'test_environment' => 'TEST',
                'live_environment' => 'DISABLED',
                'environments' => ['TEST' => ['credentials_configured' => false, 'blockers' => []]],
                'periods' => [],
            ]],
        ]);
        $harness->assertTrue(str_contains($html, '<section class="panel-soft summary-card success hmrc-connection-summary-card hmrc-transmit-status-board"><div class="status-head"><h3 class="card-title">Environment</h3><span class="badge success">Test</span></div>'));
        $harness->assertTrue(str_contains($html, '<div class="summary-card danger hmrc-credential-summary-card"><div class="summary-label">Credentials</div><div class="helper">HMRC / XML / CT600_XML / TEST Credentials Missing</div>'));
        $harness->assertTrue(str_contains($html, '<div class="actions-row actions-row-right hmrc-credential-summary-actions">'));
        $harness->assertTrue(str_contains($html, '<a class="button" href="?page=settings&amp;show_card=api_keys_editor">Configure HMRC XML Credentials</a>'));
        $harness->assertFalse(str_contains($html, 'HMRC TEST does not file the return.'));
    });

    $harness->check(_hmrc_transmitCard::class, 'shows actionable Gateway authentication errors and developer-only retry', static function () use ($harness, $card): void {
        $context = [
            'company' => ['id' => 49, 'accounting_period_id' => 79],
            'services' => ['hmrc_ct600_status' => [
                'success' => true,
                'xml_environment' => 'TEST',
                'test_environment' => 'TEST',
                'live_environment' => 'DISABLED',
                'environments' => ['TEST' => [
                    'ready' => true,
                    'credentials_configured' => true,
                    'blockers' => [],
                ]],
                'periods' => [[
                    'ct_period_id' => 6,
                    'xml_environment' => 'TEST',
                    'period_start' => '2025-01-01',
                    'period_end' => '2025-12-31',
                    'test_ready' => false,
                    'live_ready' => false,
                    'test_gateway_retry_ready' => true,
                    'test_gateway_rejection' => [
                        'id' => 17,
                        'protocol_state' => 'gateway_rejected',
                        'hmrc_response_summary' => '1046: Authentication Failure. The supplied user credentials failed validation for the requested service.',
                    ],
                    'filing_dependencies' => [
                        ['label' => 'Disclosures and filing basis', 'ready' => true],
                        ['label' => 'CT-period filing basis', 'ready' => true],
                        ['label' => 'CT600 source model', 'ready' => true],
                        ['label' => 'Filing iXBRL artifacts', 'ready' => true],
                    ],
                    'blockers' => ['HMRC definitively rejected this exact filing body before opening a conversation. Ordinary resubmission is blocked.'],
                ]],
            ]],
        ];
        $previous = AppConfigurationStore::get('developer_options', false);
        try {
            AppConfigurationStore::set('developer_options', false);
            $standard = $card->render($context);
            $harness->assertTrue(str_contains($standard, 'HMRC TEST Gateway rejection'));
            $harness->assertTrue(str_contains($standard, '1046: Authentication Failure'));
            $harness->assertTrue(str_contains($standard, 'HMRC / XML / CT600_XML / TEST'));
            $harness->assertTrue(str_contains($standard, 'Configure HMRC XML Credentials'));
            $harness->assertFalse(str_contains($standard, 'name="intent" value="hmrc_retry_test"'));

            AppConfigurationStore::set('developer_options', true);
            $developer = $card->render($context);
            $harness->assertTrue(str_contains(
                $developer,
                'class="button danger" type="submit" name="intent" value="hmrc_retry_test"'
            ));
            $harness->assertTrue(str_contains($developer, 'data-chicken-title="Retry HMRC transmission"'));
            $harness->assertTrue(str_contains($developer, 'data-chicken-confirm-text="Retry Transmission"'));
            $harness->assertTrue(str_contains($developer, 'Create a new audited HMRC submission'));
        } finally {
            AppConfigurationStore::set('developer_options', (bool)$previous);
        }
    });

    $harness->check(_hmrc_transmitCard::class, 'keeps Test-In-Live and LIVE Gateway retries independently reachable', static function () use ($harness, $card): void {
        $dependencies = [
            ['label' => 'Disclosures and filing basis', 'ready' => true],
            ['label' => 'CT-period filing basis', 'ready' => true],
            ['label' => 'CT600 source model', 'ready' => true],
            ['label' => 'Filing iXBRL artifacts', 'ready' => true],
        ];
        $context = [
            'company' => ['id' => 49, 'accounting_period_id' => 79],
            'services' => ['hmrc_ct600_status' => [
                'success' => true,
                'xml_environment' => 'LIVE',
                'test_environment' => 'TIL',
                'live_environment' => 'LIVE',
                'environments' => [
                    'TIL' => ['ready' => true, 'credentials_configured' => true, 'blockers' => []],
                    'LIVE' => ['ready' => true, 'credentials_configured' => true, 'blockers' => []],
                ],
                'periods' => [[
                    'ct_period_id' => 6,
                    'xml_environment' => 'LIVE',
                    'period_start' => '2022-09-05',
                    'period_end' => '2023-09-04',
                    'test_ready' => false,
                    'live_ready' => false,
                    'test_gateway_retry_ready' => true,
                    'test_gateway_rejection' => [
                        'id' => 18,
                        'hmrc_response_summary' => '1046: Test-In-Live authentication failed.',
                    ],
                    'filing_dependencies' => $dependencies,
                    'test_blockers' => ['HMRC definitively rejected this exact filing body.'],
                    'live_blockers' => ['The exact current filing body must pass HMRC Test in Live before LIVE filing.'],
                ], [
                    'ct_period_id' => 7,
                    'xml_environment' => 'LIVE',
                    'period_start' => '2023-09-05',
                    'period_end' => '2023-09-30',
                    'test_ready' => false,
                    'live_ready' => false,
                    'live_gateway_retry_ready' => true,
                    'live_gateway_rejection' => [
                        'id' => 19,
                        'hmrc_response_summary' => 'LIVE Gateway rejected the submission.',
                    ],
                    'filing_dependencies' => $dependencies,
                    'test_blockers' => ['This exact filing body has already passed HMRC Test in Live.'],
                    'live_blockers' => ['HMRC definitively rejected this exact filing body.'],
                ]],
            ]],
        ];
        $previous = AppConfigurationStore::get('developer_options', false);
        try {
            AppConfigurationStore::set('developer_options', true);
            $html = $card->render($context);
            $harness->assertTrue(str_contains($html, 'HMRC Test-In-Live Gateway rejection'));
            $harness->assertTrue(str_contains($html, 'HMRC LIVE Gateway rejection'));
            $harness->assertTrue(str_contains($html, 'name="intent" value="hmrc_retry_test"'));
            $harness->assertTrue(str_contains($html, '>Retry Test-In-Live Transmission</button>'));
            $harness->assertTrue(str_contains($html, 'name="intent" value="hmrc_retry_live"'));
            $harness->assertTrue(str_contains($html, '>Retry LIVE Transmission</button>'));
        } finally {
            AppConfigurationStore::set('developer_options', (bool)$previous);
        }
    });

    $harness->check(_hmrc_transmitCard::class, 'blocks transmission until the current prepared CT600 artifact exists', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => ['id' => 49, 'accounting_period_id' => 79],
            'services' => ['hmrc_ct600_status' => [
                'success' => true,
                'xml_environment' => 'TEST',
                'test_environment' => 'TEST',
                'live_environment' => 'DISABLED',
                'environments' => [
                    'TEST' => [
                        'ready' => true,
                        'credentials_configured' => true,
                        'blockers' => [],
                    ],
                ],
                'periods' => [[
                    'ct_period_id' => 6,
                    'xml_environment' => 'TEST',
                    'period_start' => '2025-01-01',
                    'period_end' => '2025-12-31',
                    'test_ready' => false,
                    'live_ready' => false,
                    'filing_dependencies' => [
                        ['label' => 'Disclosures and filing basis', 'ready' => true],
                        ['label' => 'CT-period filing basis', 'ready' => true],
                        ['label' => 'CT600 source model', 'ready' => true],
                        ['label' => 'Filing iXBRL artifacts', 'ready' => true],
                    ],
                    'blockers' => [
                        'The current CT600 XML artifact is not ready. Generate it from iXBRL Generation.',
                        'The prepared CT600 XML has a stale or mismatched accounts iXBRL hash.',
                    ],
                ]],
            ]],
        ]);

        $harness->assertTrue(str_contains(
            $html,
            'The current CT600 XML artifact is not ready. Generate it from iXBRL Generation.'
        ));
        $harness->assertTrue(str_contains(
            $html,
            '<div class="summary-card danger"><div class="summary-label">TEST blocker</div>'
            . '<div class="helper">The current CT600 XML artifact is not ready. Generate it from iXBRL Generation.</div></div>'
        ));
        $harness->assertTrue(str_contains(
            $html,
            '<div class="summary-card danger"><div class="summary-label">TEST blocker</div>'
            . '<div class="helper">The prepared CT600 XML has a stale or mismatched accounts iXBRL hash.</div></div>'
        ));
        $harness->assertFalse(str_contains(
            $html,
            '<div class="notice warning">The current CT600 XML artifact is not ready.'
        ));
        $harness->assertTrue(str_contains(
            $html,
            'name="intent" value="hmrc_submit_test" disabled data-chicken-check="true"'
        ));
    });

    $harness->check(_hmrc_transmitCard::class, 'disables every filing control when HMRC XML is disabled', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => ['id' => 49, 'accounting_period_id' => 79],
            'services' => ['hmrc_ct600_status' => [
                'xml_environment' => 'DISABLED',
                'test_environment' => 'DISABLED',
                'live_environment' => 'DISABLED',
                'environments' => [
                    'DISABLED' => [
                        'ready' => false,
                        'credentials_configured' => false,
                        'blockers' => ['HMRC XML transmission is disabled in Application API Credentials.'],
                    ],
                ],
                'periods' => [[
                    'ct_period_id' => 6,
                    'period_start' => '2025-01-01',
                    'period_end' => '2025-12-31',
                    'test_ready' => false,
                    'live_ready' => false,
                    'blockers' => ['HMRC XML transmission is disabled in Application API Credentials.'],
                ]],
            ]],
        ]);

        $harness->assertTrue(str_contains($html, '<strong>HMRC XML transmission is disabled.</strong>'));
        $harness->assertFalse(str_contains($html, 'name="declaration_name"'));
        $harness->assertFalse(str_contains($html, 'name="original_unfiled_confirmed"'));
        $harness->assertTrue(str_contains($html, '<h3>Transmit Submission</h3>'));
        $harness->assertFalse(str_contains($html, 'name="intent" value="hmrc_submit_test"'));
        $harness->assertFalse(str_contains($html, 'name="intent" value="hmrc_submit_live"'));
        $harness->assertFalse(str_contains($html, 'name="intent" value="hmrc_generate_request"'));
        $harness->assertFalse(str_contains($html, 'name="intent" value="hmrc_poll"'));
    });

    $harness->check(_hmrc_transmitCard::class, 'leaves pending submission status actions to transmission history', static function () use ($harness, $card): void {
        $base = [
            'company' => ['id' => 49, 'accounting_period_id' => 79],
            'services' => ['hmrc_ct600_status' => [
                'xml_environment' => 'LIVE',
                'test_environment' => 'TIL',
                'live_environment' => 'LIVE',
                'environments' => [],
                'periods' => [[
                    'ct_period_id' => 6,
                    'xml_environment' => 'LIVE',
                    'period_start' => '2025-01-01',
                    'period_end' => '2025-12-31',
                    'test_ready' => false,
                    'live_ready' => false,
                ]],
            ]],
        ];
        $harness->assertFalse(str_contains($card->render($base), 'name="intent" value="hmrc_poll"'));

        $base['services']['hmrc_ct600_status']['periods'][0]['pending_submission'] = [
            'submission_id' => 901,
            'protocol_state' => 'awaiting_poll',
            'poll_after_seconds' => 30,
        ];
        $pending = $card->render($base);
        $harness->assertFalse(str_contains($pending, 'name="intent" value="hmrc_poll"'));
        $harness->assertFalse(str_contains($pending, 'Check HMRC status'));
        $harness->assertFalse(str_contains($pending, 'Check Submission Status'));

        $cleanupBlocker = 'HMRC rejected this submission, but GovTalk cleanup is still pending. '
            . 'In the History tab, select Check Submission Status before transmitting the revised return.';
        $base['services']['hmrc_ct600_status']['periods'][0]['pending_submission'] = [
            'submission_id' => 4,
            'protocol_state' => 'delete_pending',
            'business_outcome' => 'rejected',
        ];
        $base['services']['hmrc_ct600_status']['periods'][0]['blockers'] = [$cleanupBlocker];
        $cleanupPending = $card->render($base);
        $harness->assertTrue(str_contains($cleanupPending, $cleanupBlocker));
        $harness->assertTrue(str_contains(
            $cleanupPending,
            'name="intent" value="hmrc_submit_test" disabled data-chicken-check="true"'
        ));
        $harness->assertFalse(str_contains($cleanupPending, 'name="intent" value="hmrc_poll"'));
        $harness->assertFalse(str_contains($cleanupPending, '>Check Submission Status<'));

        $base['services']['hmrc_ct600_status']['periods'][0]['pending_submission']['protocol_state'] = 'transport_uncertain';
        $uncertain = $card->render($base);
        $harness->assertFalse(str_contains($uncertain, 'name="intent" value="hmrc_poll"'));
        $harness->assertFalse(str_contains($uncertain, 'name="intent" value="hmrc_reprocess_response"'));

        $base['services']['hmrc_ct600_status']['periods'][0]['pending_submission']['response_reprocess_available'] = true;
        $base['services']['hmrc_ct600_status']['periods'][0]['pending_submission']['response_reprocess_exchange_id'] = 25;
        $previous = AppConfigurationStore::get('developer_options', false);
        try {
            AppConfigurationStore::set('developer_options', true);
            $recoverable = $card->render($base);
            $harness->assertFalse(str_contains($recoverable, 'Reprocess Response'));
            $harness->assertFalse(str_contains(
                $recoverable,
                'name="intent" value="hmrc_reprocess_response"'
            ));
            $harness->assertFalse(str_contains($recoverable, 'name="intent" value="hmrc_poll"'));
        } finally {
            AppConfigurationStore::set('developer_options', (bool)$previous);
        }
    });
});

$harness->run(HmrcSubmissionAction::class, static function (
    GeneratedServiceClassTestHarness $harness,
    HmrcSubmissionAction $action
): void {
    $harness->check(HmrcSubmissionAction::class, 'labels TEST TIL and LIVE acceptance independently', static function () use ($harness, $action): void {
        $message = new ReflectionMethod($action, 'successMessage');
        $message->setAccessible(true);
        $harness->assertSame(
            'HMRC Test accepted this filing body.',
            $message->invoke($action, 'hmrc_poll', [
                'business_outcome' => 'sandbox_passed',
                'submission' => ['environment' => 'TEST'],
            ])
        );
        $harness->assertSame(
            'HMRC Test in Live accepted this filing body.',
            $message->invoke($action, 'hmrc_poll', [
                'business_outcome' => 'til_validated',
                'submission' => ['environment' => 'TIL'],
            ])
        );
        $harness->assertSame(
            'HMRC accepted the Corporation Tax return.',
            $message->invoke($action, 'hmrc_poll', [
                'business_outcome' => 'live_accepted',
                'submission' => ['environment' => 'LIVE'],
            ])
        );
        $harness->assertSame(
            'HMRC Test in Live accepted this filing body. Conversation cleanup remains pending.',
            $message->invoke($action, 'hmrc_poll', [
                'business_outcome' => 'til_validated',
                'protocol_state' => 'delete_pending',
                'needs_poll' => true,
                'submission' => ['environment' => 'TIL'],
            ])
        );
    });

    $harness->check(
        HmrcSubmissionAction::class,
        'automatically completes accepted TEST TIL and LIVE cleanup after the required wait',
        static function () use ($harness): void {
            $workflow = new ReflectionMethod(HmrcSubmissionAction::class, 'pollWithAutomaticCleanup');
            $workflow->setAccessible(true);
            $outcomes = [
                'TEST' => 'sandbox_passed',
                'TIL' => 'til_validated',
                'LIVE' => 'live_accepted',
            ];
            foreach ($outcomes as $environment => $outcome) {
                $slept = [];
                $reports = [];
                $calls = 0;
                $queue = [[
                    'success' => true,
                    'protocol_state' => 'delete_pending',
                    'business_outcome' => $outcome,
                    'needs_poll' => true,
                    'poll_after_seconds' => 10,
                    'errors' => [],
                    'warnings' => [],
                    'submission' => ['environment' => $environment],
                ], [
                    'success' => true,
                    'protocol_state' => 'closed',
                    'business_outcome' => $outcome,
                    'needs_poll' => false,
                    'poll_after_seconds' => null,
                    'errors' => [],
                    'warnings' => [],
                    'submission' => [
                        'environment' => $environment,
                        'protocol_state' => 'closed',
                        'business_outcome' => $outcome,
                    ],
                ]];
                $poll = static function (callable $phaseReport) use (&$calls, &$queue): array {
                    $calls++;
                    $phaseReport('Fake HMRC protocol phase.', 50);
                    return (array)array_shift($queue);
                };
                $report = static function (string $message, int $percent) use (&$reports): void {
                    $reports[] = ['message' => $message, 'percent' => $percent];
                };
                $subject = new HmrcSubmissionAction(
                    static function (int $seconds) use (&$slept): void {
                        $slept[] = $seconds;
                    }
                );

                $result = $workflow->invoke($subject, $poll, $report, 'awaiting_poll');

                $harness->assertTrue((bool)$result['success']);
                $harness->assertSame('closed', (string)$result['protocol_state']);
                $harness->assertSame($outcome, (string)$result['business_outcome']);
                $harness->assertSame(2, $calls);
                $harness->assertSame([5, 5], $slept);
                $harness->assertTrue(str_contains(
                    implode(' ', array_column($reports, 'message')),
                    'Sending the required HMRC conversation cleanup request'
                ));
            }
        }
    );

    $harness->check(
        HmrcSubmissionAction::class,
        'preserves rejection and stops after one failed automatic cleanup attempt',
        static function () use ($harness): void {
            $workflow = new ReflectionMethod(HmrcSubmissionAction::class, 'pollWithAutomaticCleanup');
            $workflow->setAccessible(true);
            $sleeper = static function (int $seconds): void {
                unset($seconds);
            };
            $report = static function (string $message, int $percent): void {
                unset($message, $percent);
            };

            $rejectionCalls = 0;
            $rejectionQueue = [[
                'success' => false,
                'protocol_state' => 'delete_pending',
                'business_outcome' => 'rejected',
                'needs_poll' => true,
                'poll_after_seconds' => 0,
                'errors' => ['HMRC rejected the filing body.'],
                'warnings' => [],
            ], [
                'success' => true,
                'protocol_state' => 'closed',
                'business_outcome' => 'rejected',
                'needs_poll' => false,
                'errors' => [],
                'warnings' => [],
            ]];
            $rejected = $workflow->invoke(
                new HmrcSubmissionAction($sleeper),
                static function (callable $phaseReport) use (&$rejectionCalls, &$rejectionQueue): array {
                    unset($phaseReport);
                    $rejectionCalls++;
                    return (array)array_shift($rejectionQueue);
                },
                $report,
                'awaiting_poll'
            );
            $harness->assertFalse((bool)$rejected['success']);
            $harness->assertSame('closed', (string)$rejected['protocol_state']);
            $harness->assertSame('rejected', (string)$rejected['business_outcome']);
            $harness->assertSame(['HMRC rejected the filing body.'], (array)$rejected['errors']);
            $harness->assertSame(2, $rejectionCalls);

            $failureCalls = 0;
            $failureQueue = [[
                'success' => true,
                'protocol_state' => 'delete_pending',
                'business_outcome' => 'til_validated',
                'needs_poll' => true,
                'poll_after_seconds' => 0,
                'errors' => [],
                'warnings' => [],
            ], [
                'success' => false,
                'protocol_state' => 'delete_pending',
                'business_outcome' => 'til_validated',
                'needs_poll' => true,
                'poll_after_seconds' => 10,
                'errors' => ['HMRC cleanup failed.'],
                'warnings' => [],
            ]];
            $cleanupFailed = $workflow->invoke(
                new HmrcSubmissionAction($sleeper),
                static function (callable $phaseReport) use (&$failureCalls, &$failureQueue): array {
                    unset($phaseReport);
                    $failureCalls++;
                    return (array)array_shift($failureQueue);
                },
                $report,
                'awaiting_poll'
            );
            $harness->assertFalse((bool)$cleanupFailed['success']);
            $harness->assertSame('delete_pending', (string)$cleanupFailed['protocol_state']);
            $harness->assertSame('til_validated', (string)$cleanupFailed['business_outcome']);
            $harness->assertSame(2, $failureCalls);
        }
    );

    $harness->check(
        HmrcSubmissionAction::class,
        'does not loop ordinary polls existing cleanup attempts or waits over sixty seconds',
        static function () use ($harness): void {
            $workflow = new ReflectionMethod(HmrcSubmissionAction::class, 'pollWithAutomaticCleanup');
            $workflow->setAccessible(true);
            $slept = [];
            $subject = new HmrcSubmissionAction(
                static function (int $seconds) use (&$slept): void {
                    $slept[] = $seconds;
                }
            );
            $report = static function (string $message, int $percent): void {
                unset($message, $percent);
            };

            foreach ([
                ['initial' => 'awaiting_poll', 'state' => 'awaiting_poll', 'wait' => 10],
                ['initial' => 'delete_pending', 'state' => 'delete_pending', 'wait' => 10],
                ['initial' => 'awaiting_poll', 'state' => 'closed', 'wait' => 0],
            ] as $case) {
                $calls = 0;
                $single = $workflow->invoke(
                    $subject,
                    static function (callable $phaseReport) use (&$calls, $case): array {
                        unset($phaseReport);
                        $calls++;
                        return [
                            'success' => true,
                            'protocol_state' => $case['state'],
                            'business_outcome' => '',
                            'needs_poll' => $case['state'] !== 'closed',
                            'poll_after_seconds' => $case['wait'],
                            'errors' => [],
                            'warnings' => [],
                        ];
                    },
                    $report,
                    $case['initial']
                );
                unset($single);
                $harness->assertSame(1, $calls);
            }

            $longWaitCalls = 0;
            $longWait = $workflow->invoke(
                $subject,
                static function (callable $phaseReport) use (&$longWaitCalls): array {
                    unset($phaseReport);
                    $longWaitCalls++;
                    return [
                        'success' => true,
                        'protocol_state' => 'delete_pending',
                        'business_outcome' => 'live_accepted',
                        'needs_poll' => true,
                        'poll_after_seconds' => 61,
                        'errors' => [],
                        'warnings' => [],
                    ];
                },
                $report,
                'awaiting_poll'
            );
            $harness->assertSame(1, $longWaitCalls);
            $harness->assertSame([], $slept);
            $harness->assertTrue(str_contains(
                implode(' ', (array)$longWait['warnings']),
                'Use Complete HMRC Cleanup'
            ));
        }
    );

    $harness->check(
        HmrcSubmissionAction::class,
        'states that archived response reprocessing applies a recorded result without transmission',
        static function () use ($harness, $action): void {
            $message = new ReflectionMethod($action, 'successMessage');
            $message->setAccessible(true);
            $reprocessed = (string)$message->invoke($action, 'hmrc_reprocess_response', []);
            $harness->assertTrue(str_contains($reprocessed, 'recorded result was applied'));
            $harness->assertTrue(str_contains($reprocessed, 'No request was sent to HMRC'));
        }
    );

    $harness->check(HmrcSubmissionAction::class, 'rejects GET and tokenless POST requests before dispatch', static function () use ($harness, $action): void {
        $get = new RequestFramework(
            [
                'card_action' => 'HmrcSubmission',
                'intent' => 'hmrc_submit_live',
                'company_id' => '49',
                'accounting_period_id' => '79',
                'ct_period_id' => '6',
            ],
            [],
            ['REQUEST_METHOD' => 'GET'],
            [],
            []
        );
        $getResult = $action->handle($get, createTestPageServiceFramework());
        $harness->assertFalse($getResult->isSuccess());
        $harness->assertTrue(str_contains(
            strtolower((string)($getResult->flashMessages()[0]['message'] ?? '')),
            'post request'
        ));

        $tokenlessPost = new RequestFramework(
            [],
            [
                'card_action' => 'HmrcSubmission',
                'intent' => 'hmrc_submit_live',
                'company_id' => '49',
                'accounting_period_id' => '79',
                'ct_period_id' => '6',
            ],
            ['REQUEST_METHOD' => 'POST'],
            [],
            []
        );
        $postResult = $action->handle($tokenlessPost, createTestPageServiceFramework());
        $harness->assertFalse($postResult->isSuccess());
        $harness->assertTrue(str_contains(
            strtolower((string)($postResult->flashMessages()[0]['message'] ?? '')),
            'security token'
        ));

        $invalidTokenPost = new RequestFramework(
            [],
            [
                'card_action' => 'HmrcSubmission',
                'intent' => 'hmrc_submit_live',
                'csrf_token' => 'invalid-hmrc-token',
                'company_id' => '49',
                'accounting_period_id' => '79',
                'ct_period_id' => '6',
            ],
            ['REQUEST_METHOD' => 'POST'],
            [],
            []
        );
        $invalidResult = $action->handle($invalidTokenPost, createTestPageServiceFramework());
        $harness->assertFalse($invalidResult->isSuccess());
        $harness->assertTrue(str_contains(
            strtolower((string)($invalidResult->flashMessages()[0]['message'] ?? '')),
            'security token expired'
        ));
    });

    $harness->check(
        HmrcSubmissionAction::class,
        'enforces developer mode and an explicitly bound exchange for archived response reprocessing',
        static function () use ($harness, $action): void {
            $previousDeveloperOptions = AppConfigurationStore::get('developer_options', false);
            $context = new \eel_accounts\Service\AccountingContextService();
            InterfaceDB::beginTransaction();
            try {
                $marker = strtoupper(substr(hash('sha256', __FILE__ . microtime(true)), 0, 10));
                $companyNumber = 'HRP' . $marker;
                $adminEmail = 'hmrc-reprocess-' . strtolower($marker) . '@example.test';

                InterfaceDB::prepareExecute(
                    'INSERT INTO users (display_name, email_address, role_id)
                     VALUES (:display_name, :email_address, :role_id)',
                    [
                        'display_name' => 'HMRC Reprocess Administrator',
                        'email_address' => $adminEmail,
                        'role_id' => RoleAssignmentService::ADMIN_ROLE_ID,
                    ]
                );
                $adminUserId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM users WHERE email_address = :email_address',
                    ['email_address' => $adminEmail]
                );
                $harness->assertTrue($adminUserId > 0);

                InterfaceDB::prepareExecute(
                    'INSERT INTO companies (company_name, company_number, is_active)
                     VALUES (:company_name, :company_number, 1)',
                    [
                        'company_name' => 'HMRC Reprocess Fixture ' . $marker,
                        'company_number' => $companyNumber,
                    ]
                );
                $companyId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM companies WHERE company_number = :company_number',
                    ['company_number' => $companyNumber]
                );
                $harness->assertTrue($companyId > 0);

                InterfaceDB::prepareExecute(
                    'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
                     VALUES (:company_id, :label, :period_start, :period_end)',
                    [
                        'company_id' => $companyId,
                        'label' => 'HMRC reprocess fixture',
                        'period_start' => '2025-01-01',
                        'period_end' => '2025-12-31',
                    ]
                );
                $accountingPeriodId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM accounting_periods WHERE company_id = :company_id ORDER BY id DESC LIMIT 1',
                    ['company_id' => $companyId]
                );
                $harness->assertTrue($accountingPeriodId > 0);

                InterfaceDB::prepareExecute(
                    'INSERT INTO corporation_tax_periods (
                        company_id, accounting_period_id, sequence_no,
                        period_start, period_end, status
                     ) VALUES (
                        :company_id, :accounting_period_id, 1,
                        :period_start, :period_end, :status
                     )',
                    [
                        'company_id' => $companyId,
                        'accounting_period_id' => $accountingPeriodId,
                        'period_start' => '2025-01-01',
                        'period_end' => '2025-12-31',
                        'status' => 'pending',
                    ]
                );
                $ctPeriodId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM corporation_tax_periods
                     WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id
                     ORDER BY id DESC LIMIT 1',
                    [
                        'company_id' => $companyId,
                        'accounting_period_id' => $accountingPeriodId,
                    ]
                );
                $harness->assertTrue($ctPeriodId > 0);
                $period = [
                    'company_id' => $companyId,
                    'company_name' => 'HMRC Reprocess Fixture ' . $marker,
                    'company_number' => $companyNumber,
                    'accounting_period_id' => $accountingPeriodId,
                    'ct_period_id' => $ctPeriodId,
                ];

                authenticateTestSession($adminUserId);
                $context->setPageContext(
                    (int)$period['company_id'],
                    (string)$period['company_name'],
                    (string)$period['company_number'],
                    (int)$period['accounting_period_id']
                );
                $session = new SessionAuthenticationService();
                $session->startSession();
                $csrfToken = $session->csrfToken();
                $request = static function (array $overrides) use ($period, $csrfToken): RequestFramework {
                    return new RequestFramework(
                        [],
                        array_merge([
                            'card_action' => 'HmrcSubmission',
                            'intent' => 'hmrc_reprocess_response',
                            'csrf_token' => $csrfToken,
                            'company_id' => (string)$period['company_id'],
                            'accounting_period_id' => (string)$period['accounting_period_id'],
                            'ct_period_id' => (string)$period['ct_period_id'],
                            'submission_id' => '999999',
                            'exchange_id' => '25',
                        ], $overrides),
                        [
                            'REQUEST_METHOD' => 'POST',
                            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
                            'HTTP_ACCEPT' => 'application/json',
                        ],
                        [],
                        ['X-AntiFraud-Client-Device-ID' => testCurrentAntiFraudDeviceId()],
                        null
                    );
                };

                AppConfigurationStore::set('developer_options', false);
                $disabled = $action->handle($request([]), createTestPageServiceFramework());
                $harness->assertFalse($disabled->isSuccess());
                $harness->assertTrue(str_contains(
                    (string)($disabled->flashMessages()[0]['message'] ?? ''),
                    'Developer Options must be enabled'
                ));

                InterfaceDB::prepareExecute(
                    'INSERT INTO hmrc_ct600_submissions
                        (company_id, accounting_period_id, ct_period_id, mode, environment,
                         status, protocol_state, business_outcome, submission_type)
                     VALUES
                        (:company_id, :accounting_period_id, :ct_period_id, :mode, :environment,
                         :status, :protocol_state, :business_outcome, :submission_type)',
                    [
                        'company_id' => (int)$period['company_id'],
                        'accounting_period_id' => (int)$period['accounting_period_id'],
                        'ct_period_id' => (int)$period['ct_period_id'],
                        'mode' => 'TEST',
                        'environment' => 'TEST',
                        'status' => 'submitting',
                        'protocol_state' => 'awaiting_poll',
                        'business_outcome' => 'none',
                        'submission_type' => 'original',
                    ]
                );
                $submissionId = (int)(InterfaceDB::fetchColumn(
                    InterfaceDB::driverName() === 'sqlite'
                        ? 'SELECT last_insert_rowid()'
                        : 'SELECT LAST_INSERT_ID()'
                ) ?: 0);
                $harness->assertTrue($submissionId > 0);

                AppConfigurationStore::set('developer_options', true);
                foreach (['', 'not-an-exchange'] as $exchangeId) {
                    $missing = $action->handle($request([
                        'submission_id' => (string)$submissionId,
                        'exchange_id' => $exchangeId,
                    ]), createTestPageServiceFramework());
                    $harness->assertFalse($missing->isSuccess());
                    $harness->assertSame(
                        'Select the archived HMRC exchange to reprocess.',
                        (string)($missing->flashMessages()[0]['message'] ?? '')
                    );
                }

                $unbound = $action->handle($request([
                    'submission_id' => (string)$submissionId,
                    'exchange_id' => '999999',
                ]), createTestPageServiceFramework());
                $harness->assertFalse($unbound->isSuccess());
                $harness->assertSame(
                    'The selected HMRC exchange does not belong to this submission.',
                    (string)($unbound->flashMessages()[0]['message'] ?? '')
                );
            } finally {
                AppConfigurationStore::set('developer_options', (bool)$previousDeveloperOptions);
                $context->clearPageContext();
                clearAuthenticatedTestSession();
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        }
    );

    $harness->check(HmrcSubmissionAction::class, 'exposes only the Test LIVE request-file response-reprocessing and Poll command intents', static function () use ($harness): void {
        $source = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'content'
            . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'HmrcSubmissionAction.php');
        foreach ([
            'hmrc_submit_test', 'hmrc_submit_live',
            'hmrc_retry_test', 'hmrc_retry_live',
            'hmrc_generate_request', 'hmrc_reprocess_response', 'hmrc_poll',
        ] as $intent) {
            $harness->assertTrue(str_contains($source, "'" . $intent . "'"));
        }
        foreach (['->submitTest(', '->submitLive(', '->generateRequestFile(', '->reprocessArchivedResponse(', '->poll(', '->status('] as $call) {
            $harness->assertTrue(str_contains($source, $call));
        }
        foreach (['declaration_name', 'declaration_status', 'declaration_confirmed', 'authority_confirmed',
                  'original_unfiled_confirmed'] as $field) {
            $harness->assertFalse(str_contains($source, "'" . $field . "'"));
        }
        $harness->assertTrue(str_contains(
            $source,
            'submitTest($companyId, $ctPeriodId, $actor, $report, $retry)'
        ));
        foreach ([
            'Checking the selected HMRC transmission and CT Period',
            'Preparing the approved return for LIVE HMRC transmission',
            'Preparing the exact',
            'HMRC GovTalk request without transmitting it',
            'Local HMRC response reprocessing is complete; no request was sent to HMRC',
            'HMRC transmission processing is complete',
        ] as $progressMessage) {
            $harness->assertTrue(str_contains($source, $progressMessage));
        }
        $harness->assertTrue(str_contains(
            $source,
            '$requestEnvironment = strtoupper(trim((string)$request->input(\'request_environment\', \'\')))'
        ));
        $harness->assertTrue(str_contains(
            $source,
            '$requestEnvironment !== \'\' ? $requestEnvironment : null'
        ));
        $harness->assertTrue(str_contains($source, '$submissionId !== $authorisedSubmissionId'));
        foreach (['$request->isPost()', 'isValidCsrfToken($csrfToken)', 'RoleAssignmentService::ADMIN_ROLE_ID'] as $securityGate) {
            $harness->assertTrue(str_contains($source, $securityGate));
        }
        $harness->assertTrue(str_contains($source, "AppConfigurationStore::get('developer_options', false)"));
        $harness->assertTrue(str_contains(
            $source,
            'Developer Options must be enabled to reprocess an archived HMRC response.'
        ));
        $harness->assertTrue(str_contains($source, '$exchangeId = (int)$request->input(\'exchange_id\', 0)'));
        $harness->assertTrue((bool)preg_match(
            '/reprocessArchivedResponse\s*\(\s*\$companyId\s*,\s*\$accountingPeriodId\s*,\s*\$ctPeriodId\s*,\s*\$submissionId\s*,\s*\$exchangeId\s*,/',
            $source
        ));
        $harness->assertFalse(str_contains($source, "return 'web_app';"));
        $harness->assertFalse((bool)preg_match('/stream_context_create|curl_exec|file_get_contents\s*\(\s*[\'\"]https?:/i', $source));
    });
});
