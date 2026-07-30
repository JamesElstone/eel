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
            ['hmrc_transmit', 'companies_house_transmit', 'govtalk_transmission_history'],
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
            ['govtalk_transmission_history'],
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
        $harness->assertTrue(str_contains($html, '<div class="summary-card warn hmrc-connection-summary-card"><div class="summary-label">Environment</div><div class="summary-value">Live</div>'));
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
        $harness->assertSame(2, substr_count($html, '<div class="summary-label">Disclosures and filing basis</div><div class="summary-value">Present</div>'));
        $harness->assertSame(2, substr_count($html, '<div class="summary-label">CT-period filing basis</div><div class="summary-value">Present</div>'));
        $harness->assertSame(2, substr_count($html, '<div class="summary-label">CT600 source model</div><div class="summary-value">Present</div>'));
        $harness->assertTrue(str_contains($html, '<div class="summary-card danger"><div class="summary-label">Filing iXBRL artifacts</div><div class="summary-value">Not ready</div><div class="helper">The current filing iXBRL artifacts are not ready.</div><div class="helper">The computation artifact filing basis is stale.</div>'));
        $harness->assertTrue(str_contains($html, 'Run HMRC Test in Live for the current filing body.'));
        $harness->assertTrue(str_contains($html, 'IRMARK-7'));
        $harness->assertTrue(str_contains($html, '>Transmit Submission</button>'));
        $harness->assertFalse(str_contains($html, '>Test</button>'));
        $harness->assertTrue(str_contains($html, 'data-chicken-check="true"'));
        $harness->assertTrue(str_contains($html, 'data-chicken-confirm-text="Submit Tax Return"'));
        foreach (['declaration_name', 'declaration_status', 'original_unfiled_confirmed',
                  'authority_confirmed', 'declaration_confirmed'] as $field) {
            $harness->assertTrue(str_contains($html, 'name="' . $field . '"'));
        }
        $harness->assertFalse(str_contains($html, 'supplementary_scope_confirmed'));
        $harness->assertFalse(str_contains($html, 'A successful TIL result for the current body and source manifest is required before LIVE submission.'));
        $harness->assertSame(1, preg_match('/name="ct_period_id" value="6"[\s\S]*?<button class="button danger" type="submit" name="intent" value="hmrc_submit_live" disabled/', $html));
        $harness->assertSame(1, preg_match('/name="ct_period_id" value="7"[\s\S]*?<button class="button danger" type="submit" name="intent" value="hmrc_submit_live" disabled data-chicken-check/', $html));
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
        $harness->assertTrue(str_contains($html, '<div class="summary-card success hmrc-connection-summary-card"><div class="summary-label">Environment</div><div class="summary-value">Test</div>'));
        $harness->assertTrue(str_contains($html, '<div class="summary-card danger hmrc-credential-summary-card"><div class="summary-label">Credentials</div><div class="helper">HMRC / XML / CT600_XML / TEST Credentials Missing</div>'));
        $harness->assertTrue(str_contains($html, '<div class="actions-row actions-row-right hmrc-credential-summary-actions">'));
        $harness->assertTrue(str_contains($html, '<a class="button" href="?page=settings&amp;show_card=api_keys_editor">Configure HMRC XML credentials</a>'));
        $harness->assertFalse(str_contains($html, 'HMRC TEST does not file the return.'));
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
        $harness->assertTrue(str_contains($html, 'name="declaration_name" type="text" value="" required disabled'));
        $harness->assertTrue(str_contains($html, 'name="original_unfiled_confirmed" type="checkbox" value="1" required disabled'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="hmrc_submit_test" disabled>Transmit Submission</button>'));
        $harness->assertFalse(str_contains($html, 'name="intent" value="hmrc_submit_live"'));
        $harness->assertFalse(str_contains($html, 'name="intent" value="hmrc_poll"'));
    });

    $harness->check(_hmrc_transmitCard::class, 'shows status polling only for a pending submission', static function () use ($harness, $card): void {
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
        $harness->assertTrue(str_contains($pending, 'name="intent" value="hmrc_poll"'));
        $harness->assertTrue(str_contains($pending, 'name="submission_id" value="901"'));
        $harness->assertTrue(str_contains($pending, 'Check HMRC status (after 30s)'));

        $base['services']['hmrc_ct600_status']['periods'][0]['pending_submission']['protocol_state'] = 'transport_uncertain';
        $uncertain = $card->render($base);
        $harness->assertFalse(str_contains($uncertain, 'name="intent" value="hmrc_poll"'));
    });
});

$harness->run(HmrcSubmissionAction::class, static function (
    GeneratedServiceClassTestHarness $harness,
    HmrcSubmissionAction $action
): void {
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

    $harness->check(HmrcSubmissionAction::class, 'exposes only the Test LIVE and Poll command intents', static function () use ($harness): void {
        $source = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'content'
            . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'HmrcSubmissionAction.php');
        foreach (['hmrc_submit_test', 'hmrc_submit_live', 'hmrc_poll'] as $intent) {
            $harness->assertTrue(str_contains($source, "'" . $intent . "'"));
        }
        foreach (['->submitTest(', '->submitLive(', '->poll(', '->status('] as $call) {
            $harness->assertTrue(str_contains($source, $call));
        }
        foreach (['declaration_name', 'declaration_status', 'declaration_confirmed', 'authority_confirmed',
                  'original_unfiled_confirmed'] as $field) {
            $harness->assertTrue(str_contains($source, "'" . $field . "'"));
        }
        $harness->assertTrue(str_contains($source, 'submitTest($companyId, $ctPeriodId, $actor, $declaration)'));
        $harness->assertTrue(str_contains($source, '$submissionId !== $authorisedSubmissionId'));
        foreach (['$request->isPost()', 'isValidCsrfToken($csrfToken)', 'RoleAssignmentService::ADMIN_ROLE_ID'] as $securityGate) {
            $harness->assertTrue(str_contains($source, $securityGate));
        }
        $harness->assertFalse(str_contains($source, "return 'web_app';"));
        $harness->assertFalse((bool)preg_match('/GovTalk|stream_context_create|curl_exec|file_get_contents\s*\(\s*[\'\"]https?:/i', $source));
    });
});
