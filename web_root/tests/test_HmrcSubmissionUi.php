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
    $harness->check(_hmrc::class, 'keeps CT600 filing in the existing HMRC Submit card', static function () use ($harness, $page): void {
        $harness->assertTrue(in_array('hmrc_submission_unavailable', $page->cards(), true));
        $cards = [];
        foreach ($page->cardLayout() as $tab) {
            $cards = array_merge($cards, (array)($tab['cards'] ?? []));
        }
        foreach (['hmrc_submission_overview', 'hmrc_submission_controls', 'hmrc_submission_log',
                  'hmrc_submission_history', 'hmrc_submission_supplementary'] as $removedCard) {
            $harness->assertFalse(in_array($removedCard, $cards, true));
        }
    });
});

$harness->run(_hmrc_submission_unavailableCard::class, static function (
    GeneratedServiceClassTestHarness $harness,
    _hmrc_submission_unavailableCard $card
): void {
    $harness->check(_hmrc_submission_unavailableCard::class, 'declares the accounting-period CT600 status read model', static function () use ($harness, $card): void {
        $services = $card->services();
        $harness->assertSame('hmrc_ct600_status', (string)($services[0]['key'] ?? ''));
        $harness->assertSame(\eel_accounts\Service\HmrcCorporationTaxSubmissionService::class, (string)($services[0]['service'] ?? ''));
        $harness->assertSame('status', (string)($services[0]['method'] ?? ''));
        $harness->assertSame(':company.id', (string)($services[0]['params']['companyId'] ?? ''));
        $harness->assertSame(':company.accounting_period_id', (string)($services[0]['params']['accountingPeriodId'] ?? ''));
    });

    $harness->check(_hmrc_submission_unavailableCard::class, 'renders one independently gated panel per CT period', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => ['id' => 49, 'accounting_period_id' => 79],
            'services' => ['hmrc_ct600_status' => [
                'success' => true,
                'test_environment' => 'TIL',
                'live_environment' => 'LIVE',
                'environments' => [
                    'TIL' => ['ready' => true, 'credentials_configured' => true, 'blockers' => []],
                    'LIVE' => ['ready' => true, 'credentials_configured' => true, 'blockers' => []],
                ],
                'periods' => [[
                    'ct_period_id' => 6,
                    'period_start' => '2022-09-05',
                    'period_end' => '2023-09-04',
                    'test_ready' => true,
                    'live_ready' => false,
                    'latest_test' => [],
                    'latest_live' => [],
                    'blockers' => [],
                    'live_blockers' => ['Run HMRC Test in Live for the current filing body.'],
                ], [
                    'ct_period_id' => 7,
                    'period_start' => '2023-09-05',
                    'period_end' => '2023-09-30',
                    'test_ready' => true,
                    'live_ready' => true,
                    'latest_test' => ['business_outcome' => 'accepted', 'irmark' => 'IRMARK-7'],
                    'latest_live' => [],
                    'blockers' => [],
                ]],
            ]],
        ]);

        $harness->assertSame(2, substr_count($html, '<h3 class="card-title">CT period '));
        $harness->assertTrue(str_contains($html, 'CT period 2022-09-05 to 2023-09-04'));
        $harness->assertTrue(str_contains($html, 'CT period 2023-09-05 to 2023-09-30'));
        $harness->assertSame(2, substr_count($html, 'name="intent" value="hmrc_submit_test"'));
        $harness->assertSame(2, substr_count($html, 'name="intent" value="hmrc_submit_live"'));
        $harness->assertTrue(str_contains($html, '<div class="summary-label">Test mode</div><div class="summary-value">TIL</div>'));
        $harness->assertTrue(str_contains($html, '<div class="summary-label">TIL credentials</div><div class="summary-value">Configured</div>'));
        $harness->assertTrue(str_contains($html, 'Run HMRC Test in Live for the current filing body.'));
        $harness->assertTrue(str_contains($html, 'IRMARK-7'));
        $harness->assertTrue(str_contains($html, 'data-chicken-check="true"'));
        $harness->assertTrue(str_contains($html, 'data-chicken-confirm-text="Submit Tax Return"'));
        foreach (['declaration_name', 'declaration_status', 'original_unfiled_confirmed',
                  'supplementary_scope_confirmed', 'authority_confirmed', 'declaration_confirmed'] as $field) {
            $harness->assertTrue(str_contains($html, 'name="' . $field . '"'));
        }
        $harness->assertSame(1, preg_match('/name="ct_period_id" value="6"[\s\S]*?<button class="button danger" type="submit" name="intent" value="hmrc_submit_live" disabled/', $html));
        $harness->assertSame(1, preg_match('/name="ct_period_id" value="7"[\s\S]*?<button class="button danger" type="submit" name="intent" value="hmrc_submit_live" data-chicken-check/', $html));
    });

    $harness->check(_hmrc_submission_unavailableCard::class, 'shows status polling only for a pending submission', static function () use ($harness, $card): void {
        $base = [
            'company' => ['id' => 49, 'accounting_period_id' => 79],
            'services' => ['hmrc_ct600_status' => [
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
                  'supplementary_scope_confirmed', 'original_unfiled_confirmed'] as $field) {
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
