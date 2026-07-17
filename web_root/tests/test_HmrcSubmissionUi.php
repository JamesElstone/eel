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

$harness->run(_hmrc_submission::class, static function (GeneratedServiceClassTestHarness $harness, _hmrc_submission $page): void {
    $harness->check(_hmrc_submission::class, 'keeps page loads read-only and delegates data to cards', static function () use ($harness, $page): void {
        $source = (string)file_get_contents((new ReflectionClass($page))->getFileName());
        $harness->assertFalse(str_contains($source, 'ensureSchema'));
        $harness->assertFalse(str_contains($source, 'syncForAccountingPeriod'));
        $harness->assertTrue(str_contains($source, 'hmrc_submission_selection'));
        $harness->assertSame([
            'hmrc_submission_overview',
            'hmrc_submission_controls',
            'hmrc_submission_log',
            'hmrc_submission_history',
        ], $page->cards());
    });
});

foreach ([
    _hmrc_submission_overviewCard::class,
    _hmrc_submission_controlsCard::class,
    _hmrc_submission_logCard::class,
    _hmrc_submission_historyCard::class,
] as $cardClass) {
    $harness->run($cardClass, static function (GeneratedServiceClassTestHarness $harness, object $card) use ($cardClass): void {
        $harness->check($cardClass, 'declares the shared read-only submission read model', static function () use ($harness, $card): void {
            $definition = (array)($card->services()[0] ?? []);
            $harness->assertSame(\eel_accounts\Service\HmrcCtSubmissionReadModel::class, (string)($definition['service'] ?? ''));
            $harness->assertSame('pageState', (string)($definition['method'] ?? ''));
            $harness->assertSame(':hmrc_submission_selection.selected_ct_period_id', (string)($definition['params']['selectedCtPeriodId'] ?? ''));
        });
    });
}

$harness->check(_hmrc_submission_controlsCard::class, 'renders one CT selector and normal JSON card actions', static function () use ($harness): void {
    $html = (new _hmrc_submission_controlsCard())->render(hmrcSubmissionCardContext());

    $harness->assertSame(1, substr_count($html, '<select class="select" id="hmrc_ct_period_id" name="ct_period_id">'));
    $harness->assertTrue(str_contains($html, 'name="csrf_token" value="csrf-test"'));
    foreach (['prepare_ct600', 'approve_ct600', 'submit_ct600', 'poll_ct600', 'delete_ct600_response'] as $intent) {
        $harness->assertTrue(str_contains($html, 'name="intent" value="' . $intent . '"'));
    }
    $harness->assertTrue(str_contains($html, 'data-ajax="true"'));
    $harness->assertFalse(str_contains($html, 'data-hmrc-stream-form'));
    $harness->assertFalse(str_contains($html, 'stream_log'));
    $harness->assertFalse(str_contains($html, 'name="mode"'));
    $harness->assertFalse(str_contains($html, 'name="company_id"'));
    $harness->assertFalse(str_contains($html, 'name="accounting_period_id"'));
    $harness->assertTrue(str_contains($html, 'no CT600 supplementary page, claim, election'));
    $harness->assertTrue(str_contains($html, 'has not already been filed with HMRC'));
    $harness->assertTrue(str_contains($html, 'correct and complete'));
});

$harness->check(_hmrc_submission_overviewCard::class, 'shows exact readiness blockers and two-return progress', static function () use ($harness): void {
    $html = (new _hmrc_submission_overviewCard())->render(hmrcSubmissionCardContext());

    $harness->assertSame('CT600 Filing Readiness', (new _hmrc_submission_overviewCard())->title());
    $harness->assertTrue(str_contains($html, 'Year End locked'));
    $harness->assertTrue(str_contains($html, 'Locked at 2026-07-16 22:35:08'));
    $harness->assertTrue(str_contains($html, 'HMRC CT XML credentials'));
    $harness->assertTrue(str_contains($html, 'four-digit Vendor ID is missing'));
    $harness->assertTrue(str_contains($html, 'CT Period 1'));
    $harness->assertTrue(str_contains($html, 'CT Period 2'));
    $harness->assertTrue(str_contains($html, 'Til Validated'));
    $harness->assertTrue(str_contains($html, 'Filed'));
});

$harness->check(_hmrc_submission_logCard::class, 'renders persisted events as escaped rows', static function () use ($harness): void {
    $html = (new _hmrc_submission_logCard())->render(hmrcSubmissionCardContext());
    $harness->assertTrue(str_contains($html, 'Package prepared &lt;safely&gt;.'));
    $harness->assertFalse(str_contains($html, 'Package prepared <safely>.'));
    $harness->assertTrue(str_contains($html, 'Correlation ID'));
    $harness->assertTrue(str_contains($html, 'raw secrets'));
});

$harness->check(_hmrc_submission_historyCard::class, 'distinguishes assurance results from LIVE filing', static function () use ($harness): void {
    $html = (new _hmrc_submission_historyCard())->render(hmrcSubmissionCardContext());
    $harness->assertTrue(str_contains($html, '>TIL<'));
    $harness->assertTrue(str_contains($html, 'Til Validated'));
    $harness->assertTrue(str_contains($html, '>LIVE<'));
    $harness->assertTrue(str_contains($html, 'Live Accepted'));
    $harness->assertTrue(str_contains($html, 'never count as statutory filing'));
    $harness->assertFalse(str_contains($html, 'request_body_path'));
});

$harness->run(\eel_accounts\Service\HmrcCtSubmissionReadModel::class, static function (
    GeneratedServiceClassTestHarness $harness,
    \eel_accounts\Service\HmrcCtSubmissionReadModel $unused
): void {
    $harness->check(\eel_accounts\Service\HmrcCtSubmissionReadModel::class, 'keeps TIL non-statutory and selects the next unfiled period', static function () use ($harness): void {
        $history = hmrcSubmissionHistoryFixture();
        $readModel = new \eel_accounts\Service\HmrcCtSubmissionReadModel(
            static fn(int $companyId, int $accountingPeriodId): array => hmrcSubmissionPeriodsFixture(),
            static fn(int $companyId, int $accountingPeriodId, int $ctPeriodId, string $environment): array => hmrcSubmissionReadinessFixture(),
            static fn(int $companyId, int $accountingPeriodId): array => $history,
            static fn(int $submissionId): array => [[
                'id' => 1,
                'submission_id' => $submissionId,
                'event_level' => 'success',
                'event_message' => 'TIL validation completed.',
                'created_at' => '2026-07-17 10:00:00',
            ]],
            static fn(): string => 'TIL'
        );

        $state = $readModel->pageState(49, 79);
        $harness->assertSame(6, (int)$state['selected_ct_period_id']);
        $harness->assertSame('til_validated', (string)$state['progress'][0]['state']);
        $harness->assertSame(false, (bool)$state['progress'][0]['live_accepted']);
        $harness->assertSame('filed', (string)$state['progress'][1]['state']);
        $harness->assertSame(true, (bool)$state['progress'][1]['live_accepted']);
        $harness->assertSame('TIL', (string)$state['environment']);
        $harness->assertCount(1, (array)$state['events']);
    });
});

$harness->run(HmrcSubmissionAction::class, static function (
    GeneratedServiceClassTestHarness $harness,
    HmrcSubmissionAction $unused
): void {
    $harness->check(HmrcSubmissionAction::class, 'rejects security, context, ownership, and lock failures before orchestration', static function () use ($harness): void {
        $service = new HmrcSubmissionActionFakeOrchestrator();

        $security = hmrcSubmissionTestAction($service, 'Only administrators can use HMRC CT600 filing.');
        $securityResult = $security->handle(hmrcSubmissionActionRequest(), createTestPageServiceFramework());
        $harness->assertSame(false, $securityResult->isSuccess());

        $context = hmrcSubmissionTestAction($service, null, [0, 0]);
        $contextResult = $context->handle(hmrcSubmissionActionRequest(), createTestPageServiceFramework());
        $harness->assertSame(false, $contextResult->isSuccess());

        $period = hmrcSubmissionTestAction($service, null, [49, 79], true, false);
        $periodResult = $period->handle(hmrcSubmissionActionRequest(), createTestPageServiceFramework());
        $harness->assertSame(false, $periodResult->isSuccess());

        $unlocked = hmrcSubmissionTestAction($service, null, [49, 79], false);
        $unlockedResult = $unlocked->handle(hmrcSubmissionActionRequest(), createTestPageServiceFramework());
        $harness->assertSame(false, $unlockedResult->isSuccess());
        $harness->assertTrue(str_contains(hmrcSubmissionActionFlash($unlockedResult), 'lock Year End'));
        $harness->assertCount(0, $service->calls);
    });

    $harness->check(HmrcSubmissionAction::class, 'prepares from authenticated context and ignores posted mode and company fields', static function () use ($harness): void {
        $service = new HmrcSubmissionActionFakeOrchestrator();
        $action = hmrcSubmissionTestAction($service);
        $result = $action->handle(hmrcSubmissionActionRequest([
            'mode' => 'LIVE',
            'company_id' => '999',
            'accounting_period_id' => '999',
        ]), createTestPageServiceFramework());

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertSame('prepare', (string)($service->calls[0]['method'] ?? ''));
        $harness->assertSame(49, (int)($service->calls[0]['company_id'] ?? 0));
        $harness->assertSame(79, (int)($service->calls[0]['accounting_period_id'] ?? 0));
        $harness->assertSame(6, (int)($service->calls[0]['ct_period_id'] ?? 0));
        $harness->assertSame('user:test-admin', (string)($service->calls[0]['actor'] ?? ''));
        $harness->assertSame(false, array_key_exists('mode', $service->calls[0]));
        $harness->assertTrue(in_array('hmrc.submission.history', $result->changedFacts(), true));
        $harness->assertSame('6', (string)($result->query()['ct_period_id'] ?? ''));
    });

    $harness->check(HmrcSubmissionAction::class, 'binds all phase-one declarations to approval', static function () use ($harness): void {
        $service = new HmrcSubmissionActionFakeOrchestrator();
        $action = hmrcSubmissionTestAction($service);
        $base = [
            'intent' => 'approve_ct600',
            'submission_id' => '91',
            'declarant_name' => 'James Elstone',
            'declarant_status' => 'proper_officer',
            'declaration_confirmed' => '1',
        ];

        $missingScope = $action->handle(hmrcSubmissionActionRequest($base), createTestPageServiceFramework());
        $harness->assertSame(false, $missingScope->isSuccess());
        $harness->assertTrue(str_contains(hmrcSubmissionActionFlash($missingScope), 'supplementary page'));

        $approved = $action->handle(hmrcSubmissionActionRequest(array_merge($base, [
            'scope_confirmed' => '1',
            'original_unfiled_confirmed' => '1',
        ])), createTestPageServiceFramework());
        $harness->assertSame(true, $approved->isSuccess());
        $call = $service->calls[0] ?? [];
        $harness->assertSame('approve', (string)($call['method'] ?? ''));
        $harness->assertSame(true, (bool)($call['declaration']['confirmed'] ?? false));
        $harness->assertSame(true, (bool)($call['declaration']['scope_confirmed'] ?? false));
        $harness->assertSame(true, (bool)($call['declaration']['original_unfiled_confirmed'] ?? false));
    });

    $harness->check(HmrcSubmissionAction::class, 'uses the server environment and guards only LIVE with the exact phrase', static function () use ($harness): void {
        $service = new HmrcSubmissionActionFakeOrchestrator();
        $action = hmrcSubmissionTestAction($service);
        $testResult = $action->handle(hmrcSubmissionActionRequest([
            'intent' => 'submit_ct600',
            'submission_id' => '91',
            'authority_confirmed' => '1',
            'mode' => 'LIVE',
        ]), createTestPageServiceFramework());
        $harness->assertSame(true, $testResult->isSuccess());

        $service->environment = 'LIVE';
        $missingPhrase = $action->handle(hmrcSubmissionActionRequest([
            'intent' => 'submit_ct600',
            'submission_id' => '91',
            'authority_confirmed' => '1',
        ]), createTestPageServiceFramework());
        $harness->assertSame(false, $missingPhrase->isSuccess());
        $harness->assertTrue(str_contains(hmrcSubmissionActionFlash($missingPhrase), 'exact LIVE'));

        $live = $action->handle(hmrcSubmissionActionRequest([
            'intent' => 'submit_ct600',
            'submission_id' => '91',
            'authority_confirmed' => '1',
            'live_confirmation' => 'SUBMIT LIVE CT600',
        ]), createTestPageServiceFramework());
        $harness->assertSame(true, $live->isSuccess());
        $submitCalls = array_values(array_filter($service->calls, static fn(array $call): bool => ($call['method'] ?? '') === 'submit'));
        $harness->assertCount(2, $submitCalls);
    });

    $harness->check(HmrcSubmissionAction::class, 'continues poll and delete recovery without requiring a lock', static function () use ($harness): void {
        $service = new HmrcSubmissionActionFakeOrchestrator();
        $action = hmrcSubmissionTestAction($service, null, [49, 79], false);

        $poll = $action->handle(hmrcSubmissionActionRequest([
            'intent' => 'poll_ct600',
            'submission_id' => '91',
        ]), createTestPageServiceFramework());
        $delete = $action->handle(hmrcSubmissionActionRequest([
            'intent' => 'delete_ct600_response',
            'submission_id' => '91',
        ]), createTestPageServiceFramework());

        $harness->assertSame(true, $poll->isSuccess());
        $harness->assertSame(true, $delete->isSuccess());
        $harness->assertSame(['poll', 'deleteResponse'], array_column($service->calls, 'method'));
    });
});

final class HmrcSubmissionActionFakeOrchestrator
{
    /** @var list<array<string,mixed>> */
    public array $calls = [];
    public string $environment = 'TEST';

    public function environment(): string { return $this->environment; }

    public function prepare(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        string $actor,
        array $declaration = [],
    ): array
    {
        $this->calls[] = [
            'method' => 'prepare',
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'ct_period_id' => $ctPeriodId,
            'actor' => $actor,
            'declaration' => $declaration,
        ];
        return ['success' => true, 'messages' => ['Prepared.']];
    }

    public function approve(int $submissionId, array $declaration, string $actor): array
    {
        $this->calls[] = compact('submissionId', 'declaration', 'actor') + ['method' => 'approve'];
        return ['success' => true, 'messages' => ['Approved.']];
    }

    public function submit(int $submissionId, string $actor): array
    {
        $this->calls[] = compact('submissionId', 'actor') + ['method' => 'submit'];
        return ['success' => true, 'messages' => ['Submitted.']];
    }

    public function poll(int $submissionId, string $actor): array
    {
        $this->calls[] = compact('submissionId', 'actor') + ['method' => 'poll'];
        return ['success' => true, 'messages' => ['Polled.']];
    }

    public function deleteResponse(int $submissionId, string $actor): array
    {
        $this->calls[] = compact('submissionId', 'actor') + ['method' => 'deleteResponse'];
        return ['success' => true, 'messages' => ['Deleted.']];
    }
}

function hmrcSubmissionTestAction(
    HmrcSubmissionActionFakeOrchestrator $service,
    ?string $securityError = null,
    array $context = [49, 79],
    bool $locked = true,
    bool $ownsPeriod = true,
    bool $ownsSubmission = true
): HmrcSubmissionAction {
    return new HmrcSubmissionAction(
        $service,
        static fn(RequestFramework $request): ?string => $securityError,
        static fn(): array => $context,
        static fn(int $companyId, int $accountingPeriodId): bool => $locked,
        static fn(int $companyId, int $accountingPeriodId, int $ctPeriodId): bool => $ownsPeriod && $ctPeriodId === 6,
        static fn(int $companyId, int $accountingPeriodId, int $ctPeriodId, int $submissionId): bool => $ownsSubmission && $ctPeriodId === 6 && $submissionId === 91,
        static fn(RequestFramework $request): string => 'user:test-admin'
    );
}

function hmrcSubmissionActionRequest(array $overrides = []): RequestFramework
{
    return new RequestFramework(
        [],
        array_merge([
            'card_action' => 'HmrcSubmission',
            'intent' => 'prepare_ct600',
            'csrf_token' => 'test-csrf',
            'ct_period_id' => '6',
            'submission_id' => '0',
        ], $overrides),
        ['REQUEST_METHOD' => 'POST', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
        [],
        ['X-AntiFraud-Client-Device-ID' => testCurrentAntiFraudDeviceId()],
        null
    );
}

function hmrcSubmissionActionFlash(ActionResultFramework $result): string
{
    return implode("\n", array_map(
        static fn(array $message): string => (string)($message['message'] ?? ''),
        $result->flashMessages()
    ));
}

function hmrcSubmissionCardContext(): array
{
    return [
        'company' => [
            'id' => 49,
            'name' => 'Elstone Electricals Limited',
            'accounting_period_id' => 79,
            'accounting_period_label' => '05/09/2022 to 30/09/2023',
        ],
        'page' => ['csrf_token' => 'csrf-test'],
        'services' => ['hmrc_submission' => [
            'environment' => 'TIL',
            'environment_label' => 'Test in Live',
            'environment_notice' => 'TIL validates without statutory filing.',
            'ct_periods' => hmrcSubmissionPeriodsFixture(),
            'selected_ct_period_id' => 6,
            'selected_period' => hmrcSubmissionPeriodsFixture()[0],
            'readiness' => hmrcSubmissionReadinessFixture(),
            'latest_submission' => [
                'id' => 91,
                'protocol_state' => 'ready',
                'business_outcome' => 'none',
                'declaration_approved_at' => '2026-07-17 09:00:00',
                'transaction_id' => 'TX-91',
                'hmrc_correlation_id' => 'CORR-91',
                'next_poll_at' => '2026-07-17 09:05:00',
            ],
            'history' => hmrcSubmissionHistoryFixture(),
            'events' => [[
                'id' => 1,
                'submission_id' => 91,
                'event_level' => 'success',
                'event_message' => 'Package prepared <safely>.',
                'created_at' => '2026-07-17 09:00:00',
            ]],
            'progress' => [[
                'ct_period_id' => 6,
                'label' => 'CT Period 1',
                'period_start' => '2022-09-05',
                'period_end' => '2023-09-04',
                'selected' => true,
                'state' => 'til_validated',
            ], [
                'ct_period_id' => 7,
                'label' => 'CT Period 2',
                'period_start' => '2023-09-05',
                'period_end' => '2023-09-30',
                'selected' => false,
                'state' => 'filed',
            ]],
            'capabilities' => [
                'can_prepare' => true,
                'can_approve' => true,
                'can_submit' => true,
                'can_poll' => true,
                'can_delete' => true,
                'approved' => true,
            ],
        ]],
    ];
}

function hmrcSubmissionReadinessFixture(): array
{
    return [
        'ok' => true,
        'can_prepare' => true,
        'can_submit' => false,
        'lock' => ['is_locked' => 1, 'locked_at' => '2026-07-16 22:35:08'],
        'accounts' => ['ok' => true, 'filename' => 'accounts.xhtml'],
        'computations' => ['ok' => true, 'filename' => 'computations-ct6.xhtml'],
        'checks' => [[
            'key' => 'year_end_locked',
            'label' => 'Year End locked',
            'passed' => true,
            'detail' => 'Locked at 2026-07-16 22:35:08.',
        ], [
            'key' => 'xml_credentials',
            'label' => 'HMRC CT XML credentials',
            'passed' => false,
            'detail' => 'The four-digit Vendor ID is missing.',
            'stages' => ['submit'],
        ]],
        'blockers' => [],
        'warnings' => ['The four-digit Vendor ID is missing.'],
    ];
}

function hmrcSubmissionPeriodsFixture(): array
{
    return [[
        'id' => 6,
        'sequence_no' => 1,
        'display_sequence_no' => 1,
        'display_label' => 'CT Period 1',
        'period_start' => '2022-09-05',
        'period_end' => '2023-09-04',
        'status' => 'computed',
    ], [
        'id' => 7,
        'sequence_no' => 2,
        'display_sequence_no' => 2,
        'display_label' => 'CT Period 2',
        'period_start' => '2023-09-05',
        'period_end' => '2023-09-30',
        'status' => 'computed',
    ]];
}

function hmrcSubmissionHistoryFixture(): array
{
    return [[
        'id' => 91,
        'ct_period_id' => 6,
        'ct_period_sequence_no' => 1,
        'period_start' => '2022-09-05',
        'period_end' => '2023-09-04',
        'environment' => 'TIL',
        'protocol_state' => 'closed',
        'business_outcome' => 'til_validated',
        'hmrc_submission_reference' => 'TIL-91',
        'submitted_by' => 'user:test-admin',
        'created_at' => '2026-07-17 09:00:00',
    ], [
        'id' => 92,
        'ct_period_id' => 7,
        'ct_period_sequence_no' => 2,
        'period_start' => '2023-09-05',
        'period_end' => '2023-09-30',
        'environment' => 'LIVE',
        'protocol_state' => 'closed',
        'business_outcome' => 'live_accepted',
        'hmrc_submission_reference' => 'LIVE-92',
        'submitted_by' => 'user:test-admin',
        'created_at' => '2026-07-17 10:00:00',
    ]];
}
