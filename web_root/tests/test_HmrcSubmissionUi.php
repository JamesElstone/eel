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
        $harness->assertSame(0, substr_count($html, 'name="intent" value="hmrc_submit_test"'));
        $harness->assertSame(2, substr_count($html, 'name="intent" value="hmrc_submit_live"'));
        $harness->assertTrue(str_contains(
            $html,
            '<section class="panel-soft summary-card warn hmrc-connection-summary-card hmrc-transmit-status-board">'
            . '<div class="status-head"><h3 class="card-title">Environment</h3>'
            . '<span class="badge warning">Live</span></div>'
        ));
        $harness->assertTrue(str_contains($html, '<a class="button" href="?page=settings&amp;show_card=api_mode">Configure HMRC XML environment</a>'));
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
        $harness->assertTrue(str_contains($html, '>Transmit Submission</button>'));
        $harness->assertFalse(str_contains($html, '>Test</button>'));
        $harness->assertTrue(str_contains($html, 'data-chicken-check="true"'));
        $harness->assertTrue(str_contains($html, 'data-chicken-confirm-text="Transmit Tax Return"'));
        $harness->assertTrue(str_contains($html, 'data-chicken-button-class="button danger"'));
        $harness->assertTrue(str_contains($html, 'sends tax return information outside EEL Accounts'));
        foreach (['declaration_name', 'declaration_status', 'original_unfiled_confirmed',
                  'authority_confirmed', 'declaration_confirmed'] as $field) {
            $harness->assertFalse(str_contains($html, 'name="' . $field . '"'));
        }
        $harness->assertSame(2, substr_count($html, '<h3>Transmit Submission</h3>'));
        $harness->assertFalse(str_contains($html, 'supplementary_scope_confirmed'));
        $harness->assertFalse(str_contains($html, 'A successful TIL result for the current body and source manifest is required before LIVE submission.'));
        $harness->assertSame(1, preg_match('/name="ct_period_id" value="6"[\s\S]*?<button class="button danger" type="submit" name="intent" value="hmrc_submit_live" disabled/', $html));
        $harness->assertSame(1, preg_match('/name="ct_period_id" value="7"[\s\S]*?<button class="button danger" type="submit" name="intent" value="hmrc_submit_live" disabled data-chicken-check/', $html));
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
                'name="intent" value="hmrc_generate_request">Generate Request File</button>'
            ));
            $harness->assertTrue(str_contains(
                $developer,
                'class="button danger" type="submit" name="intent" value="hmrc_submit_test"'
            ));
            $harness->assertTrue(str_contains($developer, 'data-chicken-check="true"'));
            $harness->assertTrue(str_contains($developer, 'to HMRC TEST?'));
            $harness->assertTrue(str_contains($developer, 'sends tax return information outside EEL Accounts'));
            $harness->assertTrue(str_contains($developer, 'data-chicken-button-class="button danger"'));

            $context['services']['hmrc_ct600_status']['environments']['TEST'] = [
                'ready' => false,
                'credentials_configured' => false,
                'blockers' => ['HMRC XML Sender ID is missing or invalid.'],
            ];
            $withoutCredentials = $card->render($context);
            $harness->assertTrue(str_contains(
                $withoutCredentials,
                'name="intent" value="hmrc_generate_request">Generate Request File</button>'
            ));
            $harness->assertTrue(str_contains(
                $withoutCredentials,
                'name="intent" value="hmrc_submit_test" disabled data-chicken-check="true"'
            ));
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
        $harness->assertTrue(str_contains($html, '<a class="button" href="?page=settings&amp;show_card=api_keys_editor">Configure HMRC XML credentials</a>'));
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
            $harness->assertTrue(str_contains($standard, 'HMRC Gateway rejection'));
            $harness->assertTrue(str_contains($standard, '1046: Authentication Failure'));
            $harness->assertTrue(str_contains($standard, 'HMRC / XML / CT600_XML / TEST'));
            $harness->assertTrue(str_contains($standard, 'Configure HMRC XML credentials'));
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
            '<div class="summary-card danger"><div class="summary-label">Submission blocker</div>'
            . '<div class="helper">The current CT600 XML artifact is not ready. Generate it from iXBRL Generation.</div></div>'
        ));
        $harness->assertTrue(str_contains(
            $html,
            '<div class="summary-card danger"><div class="summary-label">Submission blocker</div>'
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
        $harness->assertTrue(str_contains($html, 'name="intent" value="hmrc_submit_test" disabled data-chicken-check="true"'));
        $harness->assertFalse(str_contains($html, 'name="intent" value="hmrc_submit_live"'));
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
    });

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
            $adminUserId = (int)(InterfaceDB::fetchColumn(
                'SELECT id FROM users WHERE role_id = :role_id ORDER BY id LIMIT 1',
                ['role_id' => RoleAssignmentService::ADMIN_ROLE_ID]
            ) ?: 0);
            $period = InterfaceDB::fetchOne(
                'SELECT c.id AS company_id, c.company_name, c.company_number,
                        ap.id AS accounting_period_id, ctp.id AS ct_period_id
                 FROM corporation_tax_periods ctp
                 INNER JOIN companies c ON c.id = ctp.company_id
                 INNER JOIN accounting_periods ap ON ap.id = ctp.accounting_period_id
                 WHERE ctp.status <> :status
                 ORDER BY ctp.id
                 LIMIT 1',
                ['status' => 'superseded']
            );
            if ($adminUserId <= 0 || !is_array($period)) {
                $harness->skip('An administrator and CT period fixture are required.');
            }

            $previousDeveloperOptions = AppConfigurationStore::get('developer_options', false);
            $context = new \eel_accounts\Service\AccountingContextService();
            try {
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

                InterfaceDB::beginTransaction();
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
                $submissionId = (int)(InterfaceDB::fetchColumn('SELECT LAST_INSERT_ID()') ?: 0);
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
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
                AppConfigurationStore::set('developer_options', (bool)$previousDeveloperOptions);
                $context->clearPageContext();
                clearAuthenticatedTestSession();
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
            'Preparing the exact HMRC GovTalk request without transmitting it',
            'HMRC transmission processing is complete',
        ] as $progressMessage) {
            $harness->assertTrue(str_contains($source, $progressMessage));
        }
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
