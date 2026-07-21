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

    $harness->check(CompaniesHouseAccountsAction::class, 'records exact-filing eligibility with written evidence', static function () use ($harness): void {
        $service = new CompaniesHouseAccountsActionFakeService();
        $action = companiesHouseAccountsTestAction($service);
        $result = $action->handle(companiesHouseAccountsActionRequest([
            'intent' => 'record_gateway_eligibility',
            'original_document_id' => '56',
            'eligibility_decision' => 'eligible',
            'eligibility_evidence' => 'Confirmed by the Companies House XML Team.',
            'response_reference' => 'Email 17 July 2026',
        ]), createTestPageServiceFramework());

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertCount(1, $service->calls);
        $harness->assertSame('recordEligibility', (string)($service->calls[0]['method'] ?? ''));
        $harness->assertSame(56, (int)($service->calls[0]['original_document_id'] ?? 0));
        $harness->assertSame('eligible', (string)($service->calls[0]['decision'] ?? ''));
        $harness->assertSame(true, str_contains((string)($service->calls[0]['evidence'] ?? ''), 'Email 17 July 2026'));
        $harness->assertSame('user:test-admin', (string)($service->calls[0]['actor'] ?? ''));
    });

    $harness->check(CompaniesHouseAccountsAction::class, 'validates revision declarations before preparation', static function () use ($harness): void {
        $service = new CompaniesHouseAccountsActionFakeService();
        $action = companiesHouseAccountsTestAction($service);
        $input = [
            'intent' => 'prepare_revised_accounts',
            'original_document_id' => '56',
            'non_compliance_explanation' => 'The original balance sheet contained incorrect current asset figures.',
            'significant_amendments' => 'Corrected current assets and retained earnings.',
            'revision_approval_date' => '2026-07-17',
        ];

        $missingConfirmation = $action->handle(companiesHouseAccountsActionRequest($input), createTestPageServiceFramework());
        $harness->assertSame(false, $missingConfirmation->isSuccess());
        $harness->assertSame(true, str_contains(companiesHouseAccountsActionFlash($missingConfirmation), 'eligibility evidence'));
        $harness->assertCount(0, $service->calls);

        $input['original_software_filing_confirmed'] = '1';
        $prepared = $action->handle(companiesHouseAccountsActionRequest($input), createTestPageServiceFramework());
        $harness->assertSame(true, $prepared->isSuccess());
        $harness->assertSame('prepareRevision', (string)($service->calls[0]['method'] ?? ''));
        $harness->assertSame('2026-07-17', (string)($service->calls[0]['input']['revision_approval_date'] ?? ''));
        $harness->assertSame(true, (bool)($service->calls[0]['input']['original_software_filing_confirmed'] ?? false));
    });

    $harness->check(CompaniesHouseAccountsAction::class, 'keeps TEST submission separate from LIVE confirmation', static function () use ($harness): void {
        $service = new CompaniesHouseAccountsActionFakeService();
        $service->context['feature'] = ['mode' => 'TEST', 'enabled' => true, 'live_approved' => false];
        $service->context['submission'] = ['id' => 77];
        $action = companiesHouseAccountsTestAction($service);
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
        $submitCalls = array_values(array_filter($service->calls, static fn(array $call): bool => ($call['method'] ?? '') === 'submitRevision'));
        $harness->assertCount(1, $submitCalls);
        $harness->assertSame('ABC123', (string)($submitCalls[0]['company_auth_code'] ?? ''));
        $harness->assertSame(false, str_contains(companiesHouseAccountsActionFlash($result), 'ABC123'));
    });

    $harness->check(CompaniesHouseAccountsAction::class, 'requires authority and the exact phrase for LIVE submission', static function () use ($harness): void {
        $service = new CompaniesHouseAccountsActionFakeService();
        $service->context['feature'] = ['mode' => 'LIVE', 'enabled' => true, 'live_approved' => true];
        $service->context['submission'] = ['id' => 78];
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

        $submitted = $action->handle(companiesHouseAccountsActionRequest(array_merge($base, [
            'authority_confirmed' => '1',
            'live_confirmation_phrase' => 'SUBMIT LIVE REVISED ACCOUNTS',
        ])), createTestPageServiceFramework());
        $harness->assertSame(true, $submitted->isSuccess());
        $submitCalls = array_values(array_filter($service->calls, static fn(array $call): bool => ($call['method'] ?? '') === 'submitRevision'));
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
});

final class CompaniesHouseAccountsActionFakeService
{
    /** @var list<array<string,mixed>> */
    public array $calls = [];

    public array $context = [
        'feature' => ['mode' => 'TEST', 'enabled' => true, 'live_approved' => false],
    ];

    public function recordEligibility(
        int $companyId,
        int $accountingPeriodId,
        int $originalDocumentId,
        string $decision,
        string $evidence,
        string $actor
    ): array {
        $this->calls[] = compact('companyId', 'accountingPeriodId', 'originalDocumentId', 'decision', 'evidence', 'actor') + [
            'method' => 'recordEligibility',
            'original_document_id' => $originalDocumentId,
        ];

        return ['success' => true, 'messages' => ['Eligibility recorded.']];
    }

    public function prepareRevision(int $companyId, int $accountingPeriodId, array $input, string $actor): array
    {
        $this->calls[] = compact('companyId', 'accountingPeriodId', 'input', 'actor') + ['method' => 'prepareRevision'];

        return ['success' => true, 'messages' => ['Revision prepared.']];
    }

    public function fetchContext(int $companyId, int $accountingPeriodId): array
    {
        $this->calls[] = compact('companyId', 'accountingPeriodId') + ['method' => 'fetchContext'];

        return $this->context;
    }

    public function submitRevision(int $submissionId, string $companyAuthCode, string $actor, mixed $progress = null): array
    {
        $this->calls[] = compact('submissionId', 'companyAuthCode', 'actor') + [
            'method' => 'submitRevision',
            'submission_id' => $submissionId,
            'company_auth_code' => $companyAuthCode,
        ];

        return ['success' => true, 'messages' => ['Submission sent.']];
    }

    public function refreshStatus(int $submissionId, string $actor): array
    {
        $this->calls[] = compact('submissionId', 'actor') + [
            'method' => 'refreshStatus',
            'submission_id' => $submissionId,
        ];

        return ['success' => true, 'messages' => ['Submission refreshed.']];
    }
}

function companiesHouseAccountsTestAction(
    CompaniesHouseAccountsActionFakeService $service,
    ?string $securityError = null,
    array $context = [12, 34],
    bool $locked = true
): CompaniesHouseAccountsAction {
    return new CompaniesHouseAccountsAction(
        $service,
        static fn(RequestFramework $request): ?string => $securityError,
        static fn(): array => $context,
        static fn(int $companyId, int $accountingPeriodId): bool => $locked,
        static fn(RequestFramework $request): string => 'user:test-admin'
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
