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
$harness->run(CompaniesHouseAccountsAction::class, static function (
    GeneratedServiceClassTestHarness $harness,
    CompaniesHouseAccountsAction $unused
): void {
    $harness->check(CompaniesHouseAccountsAction::class, 'starts Action Progress before the narrow submission lookup', static function () use ($harness): void {
        $method = new ReflectionMethod(CompaniesHouseAccountsAction::class, 'submitRevision');
        $path = $method->getFileName();
        $source = is_string($path) ? file($path) : false;
        $harness->assertTrue(is_array($source));
        $body = implode('', array_slice(
            $source,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));
        $progressPosition = strpos($body, 'Submission request received. Starting Companies House transmission checks');
        $lookupPosition = strpos($body, 'submissionFilingKindForContext(');
        $harness->assertTrue($progressPosition !== false);
        $harness->assertTrue($lookupPosition !== false && $progressPosition < $lookupPosition);
        $harness->assertFalse(str_contains($body, 'fetchContext('));
        $harness->assertTrue(str_contains($body, 'nothing has been sent yet.'));
    });

    $harness->check(CompaniesHouseAccountsAction::class, 'requires an explicitly supplied CSRF token', static function () use ($harness): void {
        $service = new CompaniesHouseAccountsActionFakeService();
        $action = new CompaniesHouseAccountsAction($service);
        $result = $action->handle(
            companiesHouseAccountsActionRequest(['csrf_token' => '']),
            createTestPageServiceFramework()
        );

        $harness->assertSame(false, $result->isSuccess());
        $harness->assertSame(true, str_contains(companiesHouseAccountsActionFlash($result), 'security token'));
        $harness->assertCount(0, $service->calls);
    });

    $harness->check(CompaniesHouseAccountsAction::class, 'fails before service calls for non-admin, mismatched, and unlocked contexts', static function () use ($harness): void {
        $service = new CompaniesHouseAccountsActionFakeService();
        $nonAdmin = companiesHouseAccountsTestAction($service, 'Only administrators can use Companies House revised-accounts filing.');
        $nonAdminResult = $nonAdmin->handle(companiesHouseAccountsActionRequest(), createTestPageServiceFramework());
        $harness->assertSame(false, $nonAdminResult->isSuccess());
        $harness->assertSame(true, str_contains(companiesHouseAccountsActionFlash($nonAdminResult), 'Only administrators'));

        $mismatch = companiesHouseAccountsTestAction($service, null, [99, 34]);
        $mismatchResult = $mismatch->handle(companiesHouseAccountsActionRequest(), createTestPageServiceFramework());
        $harness->assertSame(false, $mismatchResult->isSuccess());
        $harness->assertSame(true, str_contains(companiesHouseAccountsActionFlash($mismatchResult), 'does not match'));

        $unlocked = companiesHouseAccountsTestAction($service, null, [12, 34], false);
        $unlockedResult = $unlocked->handle(companiesHouseAccountsActionRequest(), createTestPageServiceFramework());
        $harness->assertSame(false, $unlockedResult->isSuccess());
        $harness->assertSame(true, str_contains(companiesHouseAccountsActionFlash($unlockedResult), 'lock Year End'));
        $harness->assertCount(0, $service->calls);
    });

    $harness->check(CompaniesHouseAccountsAction::class, 'records an unlocked XML filing eligibility decision without separate evidence fields', static function () use ($harness): void {
        $service = new CompaniesHouseAccountsActionFakeService();
        $action = companiesHouseAccountsTestAction($service, null, [12, 34], false);
        $result = $action->handle(companiesHouseAccountsActionRequest([
            'intent' => 'record_gateway_eligibility',
            'original_document_id' => '56',
            'eligibility_decision' => 'eligible',
        ]), createTestPageServiceFramework());

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertCount(1, $service->calls);
        $harness->assertSame('recordEligibility', (string)($service->calls[0]['method'] ?? ''));
        $harness->assertSame(56, (int)($service->calls[0]['original_document_id'] ?? 0));
        $harness->assertSame('eligible', (string)($service->calls[0]['decision'] ?? ''));
        $harness->assertSame('user:test-admin', (string)($service->calls[0]['actor'] ?? ''));
    });

    $harness->check(CompaniesHouseAccountsAction::class, 'allows revision metadata actions after Year End lock', static function () use ($harness): void {
        $service = new CompaniesHouseAccountsActionFakeService();
        $action = companiesHouseAccountsTestAction($service, null, [12, 34], true);
        $result = $action->handle(companiesHouseAccountsActionRequest([
            'intent' => 'record_gateway_eligibility',
            'original_document_id' => '56',
            'eligibility_decision' => 'eligible',
        ]), createTestPageServiceFramework());

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertSame('recordEligibility', (string)($service->calls[0]['method'] ?? ''));
    });

    $harness->check(CompaniesHouseAccountsAction::class, 'saves the unlocked variance explanation separately from preparation', static function () use ($harness): void {
        $service = new CompaniesHouseAccountsActionFakeService();
        $action = companiesHouseAccountsTestAction($service, null, [12, 34], false);
        $result = $action->handle(companiesHouseAccountsActionRequest([
            'intent' => 'save_variance_explanation',
            'original_document_id' => '56',
            'variance_explanation' => 'The filed figures used the earlier P&L and tax treatment basis.',
        ]), createTestPageServiceFramework());

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertSame('saveVarianceExplanation', (string)($service->calls[0]['method'] ?? ''));
        $harness->assertSame(56, (int)($service->calls[0]['original_document_id'] ?? 0));
        $harness->assertSame('The filed figures used the earlier P&L and tax treatment basis.', (string)($service->calls[0]['variance_explanation'] ?? ''));
    });

    $harness->check(CompaniesHouseAccountsAction::class, 'routes the legacy revised preparation intent through generic accounts preparation', static function () use ($harness): void {
        $service = new CompaniesHouseAccountsActionFakeService();
        $action = companiesHouseAccountsTestAction($service);
        $prepared = $action->handle(companiesHouseAccountsActionRequest([
            'intent' => 'prepare_revised_accounts',
        ]), createTestPageServiceFramework());

        $harness->assertSame(true, $prepared->isSuccess());
        $preparationCalls = array_values(array_filter(
            $service->calls,
            static fn(array $call): bool => (string)($call['method'] ?? '') === 'prepareAccounts'
        ));
        $harness->assertCount(1, $preparationCalls);
        $harness->assertSame([
            'non_compliance_explanation' => '',
            'significant_amendments' => '',
            'revision_approval_date' => '',
            'original_software_filing_confirmed' => false,
        ], (array)($preparationCalls[0]['input'] ?? []));
    });

    $harness->check(CompaniesHouseAccountsAction::class, 'blocks an invalid revised approval date before Accounting generation', static function () use ($harness): void {
        $service = new CompaniesHouseAccountsActionFakeService();
        $accountingCalls = 0;
        $action = companiesHouseAccountsTestAction(
            $service,
            null,
            [12, 34],
            true,
            static function () use (&$accountingCalls): array {
                $accountingCalls++;
                return ['success' => true, 'errors' => []];
            },
            static fn(): array => [
                'applicable' => true,
                'ready' => false,
                'errors' => [
                    'The revision approval date must be later than the original accounts approval date (2025-06-28).',
                ],
            ]
        );
        $result = $action->handle(companiesHouseAccountsActionRequest([
            'intent' => 'prepare_accounts',
        ]), createTestPageServiceFramework());

        $harness->assertFalse($result->isSuccess());
        $harness->assertSame(0, $accountingCalls);
        $harness->assertSame([], array_values(array_filter(
            $service->calls,
            static fn(array $call): bool => (string)($call['method'] ?? '') === 'prepareAccounts'
        )));
        $harness->assertTrue(str_contains(
            companiesHouseAccountsActionFlash($result),
            'must be later than the original accounts approval date'
        ));
    });

    $harness->check(CompaniesHouseAccountsAction::class, 'keeps TEST submission separate from LIVE confirmation', static function () use ($harness): void {
        $service = new CompaniesHouseAccountsActionFakeService();
        $service->context['feature'] = ['mode' => 'TEST', 'enabled' => true, 'live_approved' => false];
        $service->context['submission'] = ['id' => 77, 'filing_kind' => 'revised'];
        $action = companiesHouseAccountsTestAction($service);
        $invalidCode = $action->handle(companiesHouseAccountsActionRequest([
            'intent' => 'submit_revised_accounts',
            'submission_id' => '77',
            'company_auth_code' => 'ABC12345',
        ]), createTestPageServiceFramework());
        $harness->assertSame(false, $invalidCode->isSuccess());
        $harness->assertSame(true, str_contains(companiesHouseAccountsActionFlash($invalidCode), 'exactly 6'));

        $forged = $action->handle(companiesHouseAccountsActionRequest([
            'intent' => 'submit_revised_accounts',
            'submission_id' => '999',
            'company_auth_code' => 'ABC123',
        ]), createTestPageServiceFramework());
        $harness->assertSame(false, $forged->isSuccess());
        $harness->assertSame(true, str_contains(companiesHouseAccountsActionFlash($forged), 'does not belong'));

        $result = $action->handle(companiesHouseAccountsActionRequest([
            'intent' => 'submit_revised_accounts',
            'submission_id' => '77',
            'company_auth_code' => 'ABC123',
        ]), createTestPageServiceFramework());

        $harness->assertSame(true, $result->isSuccess());
        $submitCalls = array_values(array_filter($service->calls, static fn(array $call): bool => ($call['method'] ?? '') === 'submitAccounts'));
        $harness->assertCount(1, $submitCalls);
        $harness->assertSame('ABC123', (string)($submitCalls[0]['company_auth_code'] ?? ''));
        $harness->assertSame(false, str_contains(companiesHouseAccountsActionFlash($result), 'ABC123'));
        $harness->assertSame([], array_values(array_filter(
            $service->calls,
            static fn(array $call): bool => ($call['method'] ?? '') === 'fetchContext'
        )));
    });

    $harness->check(CompaniesHouseAccountsAction::class, 'requires authority and the exact phrase for LIVE submission', static function () use ($harness): void {
        $service = new CompaniesHouseAccountsActionFakeService();
        $service->context['feature'] = ['mode' => 'LIVE', 'enabled' => true, 'live_approved' => true];
        $service->context['submission'] = ['id' => 78, 'filing_kind' => 'revised'];
        $action = companiesHouseAccountsTestAction($service);
        $base = [
            'intent' => 'submit_revised_accounts',
            'submission_id' => '78',
            'company_auth_code' => 'XYZ789',
        ];

        $missingAuthority = $action->handle(companiesHouseAccountsActionRequest($base), createTestPageServiceFramework());
        $harness->assertSame(false, $missingAuthority->isSuccess());
        $harness->assertSame(true, str_contains(companiesHouseAccountsActionFlash($missingAuthority), 'authorised'));

        $wrongPhrase = $action->handle(companiesHouseAccountsActionRequest(array_merge($base, [
            'authority_confirmed' => '1',
            'live_confirmation_phrase' => 'SUBMIT',
        ])), createTestPageServiceFramework());
        $harness->assertSame(false, $wrongPhrase->isSuccess());
        $harness->assertSame(true, str_contains(companiesHouseAccountsActionFlash($wrongPhrase), 'exact LIVE'));

        $wrongFilingKind = $action->handle(companiesHouseAccountsActionRequest(array_merge($base, [
            'authority_confirmed' => '1',
            'live_confirmation_phrase' => 'SUBMIT LIVE ORIGINAL ACCOUNTS',
        ])), createTestPageServiceFramework());
        $harness->assertSame(false, $wrongFilingKind->isSuccess());
        $harness->assertSame(true, str_contains(companiesHouseAccountsActionFlash($wrongFilingKind), 'exact LIVE'));

        $submitted = $action->handle(companiesHouseAccountsActionRequest(array_merge($base, [
            'authority_confirmed' => '1',
            'live_confirmation_phrase' => 'SUBMIT LIVE REVISED ACCOUNTS',
        ])), createTestPageServiceFramework());
        $harness->assertSame(true, $submitted->isSuccess());
        $submitCalls = array_values(array_filter($service->calls, static fn(array $call): bool => ($call['method'] ?? '') === 'submitAccounts'));
        $harness->assertCount(1, $submitCalls);
    });

    $harness->check(CompaniesHouseAccountsAction::class, 'refreshes only the identified existing submission', static function () use ($harness): void {
        $service = new CompaniesHouseAccountsActionFakeService();
        $service->context['submission'] = ['id' => 91];
        $action = companiesHouseAccountsTestAction($service);
        $result = $action->handle(companiesHouseAccountsActionRequest([
            'intent' => 'refresh_revised_accounts_status',
            'submission_id' => '91',
        ]), createTestPageServiceFramework());

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertSame('refreshStatus', (string)($service->calls[1]['method'] ?? ''));
        $harness->assertSame(91, (int)($service->calls[1]['submission_id'] ?? 0));
        $harness->assertSame(true, in_array('companies.house.accounts.submission', $result->changedFacts(), true));
    });

    $harness->check(
        CompaniesHouseAccountsAction::class,
        'gates granular CompanyData exchange controls behind developer options',
        static function () use ($harness): void {
            $service = new CompaniesHouseAccountsActionFakeService();
            $action = companiesHouseAccountsTestAction($service, locked: false);
            $previous = AppConfigurationStore::get('developer_options', false);
            try {
                AppConfigurationStore::set('developer_options', false);
                $blocked = $action->handle(companiesHouseAccountsActionRequest([
                    'intent' => 'preflight_revised_accounts',
                    'company_auth_code' => 'ABC123',
                ]), createTestPageServiceFramework());
                $harness->assertSame(false, $blocked->isSuccess());
                $harness->assertSame(true, str_contains(companiesHouseAccountsActionFlash($blocked), 'Developer options'));

                AppConfigurationStore::set('developer_options', true);
                $allowed = $action->handle(companiesHouseAccountsActionRequest([
                    'intent' => 'preflight_revised_accounts',
                    'company_auth_code' => 'ABC123',
                ]), createTestPageServiceFramework());
                $harness->assertSame(true, $allowed->isSuccess());
                $calls = array_values(array_filter(
                    $service->calls,
                    static fn(array $call): bool =>
                        ($call['method'] ?? '') === 'checkCompanyAuthentication'
                ));
                $harness->assertCount(1, $calls);
                $harness->assertSame(12, (int)$calls[0]['companyId']);
                $harness->assertSame(34, (int)$calls[0]['accountingPeriodId']);
                $harness->assertSame([], array_values(array_filter(
                    $service->calls,
                    static fn(array $call): bool => ($call['method'] ?? '') === 'fetchContext'
                )));
            } finally {
                AppConfigurationStore::set('developer_options', (bool)$previous);
            }
        }
    );

    $harness->check(
        CompaniesHouseAccountsAction::class,
        'does not require a CompanyData record for the legacy step-by-step submit intent',
        static function () use ($harness): void {
            $service = new CompaniesHouseAccountsActionFakeService();
            $service->context['feature'] = [
                'mode' => 'TEST',
                'enabled' => true,
                'live_approved' => false,
            ];
            $service->context['submission'] = ['id' => 77, 'filing_kind' => 'original'];
            $action = companiesHouseAccountsTestAction($service);
            $previous = AppConfigurationStore::get('developer_options', false);
            try {
                AppConfigurationStore::set('developer_options', true);
                $result = $action->handle(companiesHouseAccountsActionRequest([
                    'intent' => 'submit_preflighted_revised_accounts',
                    'submission_id' => '77',
                    'company_auth_code' => 'ABC123',
                ]), createTestPageServiceFramework());
                $harness->assertTrue($result->isSuccess());
                $submitCalls = array_values(array_filter(
                    $service->calls,
                    static fn(array $call): bool => ($call['method'] ?? '') === 'submitAccounts'
                ));
                $harness->assertCount(1, $submitCalls);
                $harness->assertSame(
                    null,
                    $submitCalls[0]['verifiedPreflightId'] ?? null
                );
            } finally {
                AppConfigurationStore::set('developer_options', (bool)$previous);
            }
        }
    );
});

final class CompaniesHouseAccountsActionFakeService
{
    /** @var list<array<string,mixed>> */
    public array $calls = [];

    public array $context = [
        'feature' => ['mode' => 'TEST', 'enabled' => true, 'live_approved' => false],
        'can_prepare' => true,
        'can_prepare_after_accounts_generation' => true,
    ];

    public function recordEligibility(
        int $companyId,
        int $accountingPeriodId,
        int $originalDocumentId,
        string $decision,
        string $actor
    ): array {
        $this->calls[] = compact('companyId', 'accountingPeriodId', 'originalDocumentId', 'decision', 'actor') + [
            'method' => 'recordEligibility',
            'original_document_id' => $originalDocumentId,
        ];

        return ['success' => true, 'messages' => ['Eligibility recorded.']];
    }

    public function prepareAccounts(int $companyId, int $accountingPeriodId, array $input, string $actor): array
    {
        $this->calls[] = compact('companyId', 'accountingPeriodId', 'input', 'actor') + ['method' => 'prepareAccounts'];

        return ['success' => true, 'messages' => ['Revision prepared.']];
    }

    public function saveVarianceExplanation(
        int $companyId,
        int $accountingPeriodId,
        int $originalDocumentId,
        string $varianceExplanation,
        string $actor
    ): array {
        $this->calls[] = compact('companyId', 'accountingPeriodId', 'originalDocumentId', 'varianceExplanation', 'actor') + [
            'method' => 'saveVarianceExplanation',
            'original_document_id' => $originalDocumentId,
            'variance_explanation' => $varianceExplanation,
        ];

        return ['success' => true, 'messages' => ['Variance explanation saved.']];
    }

    public function fetchContext(int $companyId, int $accountingPeriodId): array
    {
        $this->calls[] = compact('companyId', 'accountingPeriodId') + ['method' => 'fetchContext'];

        return $this->context;
    }

    public function submissionBelongsToContext(
        int $submissionId,
        int $companyId,
        int $accountingPeriodId
    ): bool {
        $this->calls[] = compact('submissionId', 'companyId', 'accountingPeriodId')
            + ['method' => 'submissionBelongsToContext'];

        return (int)($this->context['submission']['id'] ?? 0) === $submissionId;
    }

    public function submissionFilingKindForContext(
        int $submissionId,
        int $companyId,
        int $accountingPeriodId
    ): ?string {
        $this->calls[] = compact('submissionId', 'companyId', 'accountingPeriodId')
            + ['method' => 'submissionFilingKindForContext'];
        if ((int)($this->context['submission']['id'] ?? 0) !== $submissionId) {
            return null;
        }

        $filingKind = strtolower(trim((string)(
            $this->context['submission']['filing_kind']
            ?? $this->context['submission']['filing_type']
            ?? ''
        )));
        return in_array($filingKind, ['original', 'revised'], true) ? $filingKind : null;
    }

    public function submitAccounts(
        int $submissionId,
        string $companyAuthCode,
        string $actor,
        mixed $progress = null,
        ?int $verifiedPreflightId = null
    ): array
    {
        $this->calls[] = compact('submissionId', 'companyAuthCode', 'actor', 'verifiedPreflightId') + [
            'method' => 'submitAccounts',
            'submission_id' => $submissionId,
            'company_auth_code' => $companyAuthCode,
        ];

        return ['success' => true, 'messages' => ['Submission sent.']];
    }

    public function refreshStatus(int $submissionId, string $actor, mixed $progress = null): array
    {
        $this->calls[] = compact('submissionId', 'actor') + [
            'method' => 'refreshStatus',
            'submission_id' => $submissionId,
        ];

        return ['success' => true, 'messages' => ['Submission refreshed.']];
    }

    public function preflightRevision(
        int $submissionId,
        string $companyAuthCode,
        string $actor,
        mixed $progress = null
    ): array {
        $this->calls[] = compact('submissionId', 'companyAuthCode', 'actor') + [
            'method' => 'preflightRevision',
        ];
        return ['success' => true, 'messages' => ['Preflight verified.']];
    }

    public function checkCompanyAuthentication(
        int $companyId,
        int $accountingPeriodId,
        string $companyAuthCode,
        string $actor,
        mixed $progress = null
    ): array {
        $this->calls[] = compact(
            'companyId',
            'accountingPeriodId',
            'companyAuthCode',
            'actor'
        ) + ['method' => 'checkCompanyAuthentication'];
        return ['success' => true, 'messages' => ['Authentication code verified.']];
    }
}

function companiesHouseAccountsTestAction(
    CompaniesHouseAccountsActionFakeService $service,
    ?string $securityError = null,
    array $context = [12, 34],
    bool $locked = true,
    ?callable $accountingPrerequisite = null,
    ?callable $revisionPrerequisite = null
): CompaniesHouseAccountsAction {
    return new CompaniesHouseAccountsAction(
        $service,
        static fn(RequestFramework $request): ?string => $securityError,
        static fn(): array => $context,
        static fn(int $companyId, int $accountingPeriodId): bool => $locked,
        static fn(RequestFramework $request): string => 'user:test-admin',
        $accountingPrerequisite ?? static fn(int $companyId, int $accountingPeriodId, ActionProgressFramework $progress): array => [
                'success' => true,
                'errors' => [],
            ],
        $revisionPrerequisite ?? static fn(int $companyId, int $accountingPeriodId): array => [
                'applicable' => false,
                'ready' => true,
                'errors' => [],
            ],
        static fn(): string => (string)($service->context['feature']['mode'] ?? 'DISABLED'),
        static fn(): bool => !empty($service->context['feature']['live_approved']),
    );
}

function companiesHouseAccountsActionRequest(array $overrides = []): RequestFramework
{
    return new RequestFramework(
        [],
        array_merge([
            'card_action' => 'CompaniesHouseAccounts',
            'intent' => 'refresh_revised_accounts_status',
            'csrf_token' => 'test-csrf',
            'company_id' => '12',
            'accounting_period_id' => '34',
            'submission_id' => '90',
        ], $overrides),
        ['REQUEST_METHOD' => 'POST', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
        [],
        ['X-AntiFraud-Client-Device-ID' => testCurrentAntiFraudDeviceId()],
        null
    );
}

function companiesHouseAccountsActionFlash(ActionResultFramework $result): string
{
    return implode("\n", array_map(
        static fn(array $message): string => (string)($message['message'] ?? ''),
        $result->flashMessages()
    ));
}
