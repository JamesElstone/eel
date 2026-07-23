<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class CompaniesHouseAccountsAction implements ActionInterfaceFramework
{
    private const LIVE_CONFIRMATION_PHRASE = 'SUBMIT LIVE REVISED ACCOUNTS';

    /** @var list<string> */
    private const CHANGED_FACTS = [
        'companies.house.accounts.submission',
        'year.end.companies.house.comparison',
        'year.end.checklist',
        'ixbrl.readiness',
        'page.context',
    ];

    private ?Closure $securityCheck;
    private ?Closure $contextResolver;
    private ?Closure $lockChecker;
    private ?Closure $actorResolver;

    public function __construct(
        private ?object $submissionService = null,
        ?callable $securityCheck = null,
        ?callable $contextResolver = null,
        ?callable $lockChecker = null,
        ?callable $actorResolver = null,
    ) {
        $this->securityCheck = $securityCheck !== null ? Closure::fromCallable($securityCheck) : null;
        $this->contextResolver = $contextResolver !== null ? Closure::fromCallable($contextResolver) : null;
        $this->lockChecker = $lockChecker !== null ? Closure::fromCallable($lockChecker) : null;
        $this->actorResolver = $actorResolver !== null ? Closure::fromCallable($actorResolver) : null;
    }

    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $intent = trim((string)$request->input('intent', ''));
        $allowedIntents = [
            'record_gateway_eligibility',
            'prepare_revised_accounts',
            'submit_revised_accounts',
            'refresh_revised_accounts_status',
            'preflight_revised_accounts',
            'submit_preflighted_revised_accounts',
            'poll_revised_accounts_status',
            'ack_revised_accounts_status',
            'retrieve_revised_accounts_document',
            'download_protocol_evidence',
            'reconcile_revised_accounts_status',
        ];
        if (!in_array($intent, $allowedIntents, true)) {
            return $this->error('Unknown Companies House revised-accounts action.');
        }

        $securityError = $this->securityError($request);
        if ($securityError !== null) {
            return $this->error($securityError);
        }

        $companyId = (int)$request->input('company_id', 0);
        $accountingPeriodId = (int)$request->input('accounting_period_id', 0);
        $contextError = $this->contextError($companyId, $accountingPeriodId);
        if ($contextError !== null) {
            return $this->error($contextError);
        }

        if (!$this->isLocked($companyId, $accountingPeriodId)) {
            return $this->error('Complete and lock Year End before using Companies House revised-accounts filing.');
        }
        $developerIntent = in_array($intent, [
            'preflight_revised_accounts',
            'submit_preflighted_revised_accounts',
            'poll_revised_accounts_status',
            'ack_revised_accounts_status',
            'retrieve_revised_accounts_document',
            'download_protocol_evidence',
            'reconcile_revised_accounts_status',
        ], true);
        if ($developerIntent && !(bool)AppConfigurationStore::get('developer_options', false)) {
            return $this->error('Developer options must be enabled for step-by-step Companies House exchanges.');
        }
        if ($intent === 'download_protocol_evidence') {
            $this->downloadProtocolEvidence($request, $companyId, $accountingPeriodId);
        }

        try {
            $result = match ($intent) {
                'record_gateway_eligibility' => $this->recordEligibility($request, $companyId, $accountingPeriodId),
                'prepare_revised_accounts' => $this->prepareRevision($request, $companyId, $accountingPeriodId),
                'submit_revised_accounts' => $this->submitRevision($request, $companyId, $accountingPeriodId, $services->actionProgress()),
                'refresh_revised_accounts_status' => $this->refreshStatus($request, $companyId, $accountingPeriodId),
                'preflight_revised_accounts' => $this->preflightRevision(
                    $request,
                    $companyId,
                    $accountingPeriodId,
                    $services->actionProgress()
                ),
                'submit_preflighted_revised_accounts' => $this->submitRevision(
                    $request,
                    $companyId,
                    $accountingPeriodId,
                    $services->actionProgress(),
                    true
                ),
                'poll_revised_accounts_status' => $this->protocolStatusAction(
                    $request,
                    $companyId,
                    $accountingPeriodId,
                    'pollStatus'
                ),
                'ack_revised_accounts_status' => $this->protocolStatusAction(
                    $request,
                    $companyId,
                    $accountingPeriodId,
                    'acknowledgeStatus'
                ),
                'retrieve_revised_accounts_document' => $this->protocolStatusAction(
                    $request,
                    $companyId,
                    $accountingPeriodId,
                    'retrieveDocument'
                ),
                'reconcile_revised_accounts_status' => $this->reconcileStatus(
                    $request,
                    $companyId,
                    $accountingPeriodId
                ),
            };
        } catch (Throwable $exception) {
            $result = ['success' => false, 'errors' => [$exception->getMessage()]];
        }

        return $this->result($intent, $result);
    }

    private function recordEligibility(
        RequestFramework $request,
        int $companyId,
        int $accountingPeriodId
    ): array {
        $originalDocumentId = (int)$request->input('original_document_id', 0);
        $decision = strtolower(trim((string)$request->input('eligibility_decision', '')));
        $evidence = trim((string)$request->input('eligibility_evidence', ''));
        $responseReference = trim((string)$request->input('response_reference', ''));

        if ($originalDocumentId <= 0) {
            return ['success' => false, 'errors' => ['Select the exact original Companies House filing before recording eligibility.']];
        }
        if (!in_array($decision, ['eligible', 'ineligible'], true)) {
            return ['success' => false, 'errors' => ['Record whether Companies House confirmed the filing as eligible or ineligible.']];
        }
        if ($evidence === '') {
            return ['success' => false, 'errors' => ['Companies House written evidence is required.']];
        }
        if ($responseReference !== '') {
            $evidence = 'Response reference: ' . $responseReference . "\n" . $evidence;
        }

        return $this->service()->recordEligibility(
            $companyId,
            $accountingPeriodId,
            $originalDocumentId,
            $decision,
            $evidence,
            $this->actor($request)
        );
    }

    private function prepareRevision(
        RequestFramework $request,
        int $companyId,
        int $accountingPeriodId
    ): array {
        $originalDocumentId = (int)$request->input('original_document_id', 0);
        $nonCompliance = trim((string)$request->input('non_compliance_explanation', ''));
        $significantAmendments = trim((string)$request->input('significant_amendments', ''));
        $approvalDate = trim((string)$request->input('revision_approval_date', ''));
        $softwareFilingConfirmed = (string)$request->input('original_software_filing_confirmed', '') === '1';

        $errors = [];
        if ($originalDocumentId <= 0) {
            $errors[] = 'The exact original Companies House filing is required.';
        }
        if ($nonCompliance === '') {
            $errors[] = 'Explain how the original accounts did not comply with the Companies Act 2006.';
        }
        if ($significantAmendments === '') {
            $errors[] = 'Describe the significant amendments made in the revised accounts.';
        }
        if (!$this->isIsoDate($approvalDate)) {
            $errors[] = 'Enter a valid revision approval date.';
        }
        if (!$softwareFilingConfirmed) {
            $errors[] = 'Confirm that the Companies House eligibility evidence applies to this original filing.';
        }
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        return $this->service()->prepareRevision(
            $companyId,
            $accountingPeriodId,
            [
                'original_document_id' => $originalDocumentId,
                'non_compliance_explanation' => $nonCompliance,
                'original_non_compliance_explanation' => $nonCompliance,
                'significant_amendments' => $significantAmendments,
                'revision_approval_date' => $approvalDate,
                'original_software_filing_confirmed' => true,
            ],
            $this->actor($request)
        );
    }

    private function submitRevision(
        RequestFramework $request,
        int $companyId,
        int $accountingPeriodId,
        ActionProgressFramework $progress,
        bool $developerStep = false
    ): array {
        @set_time_limit(0);
        $submissionId = (int)$request->input('submission_id', 0);
        $companyAuthCode = trim((string)$request->input('company_auth_code', ''));
        if ($submissionId <= 0) {
            return ['success' => false, 'errors' => ['The prepared revised-accounts submission could not be identified.']];
        }
        if (preg_match('/^[A-Za-z0-9]{6}$/D', $companyAuthCode) !== 1) {
            return ['success' => false, 'errors' => ['The company authentication code must contain exactly 6 letters or numbers.']];
        }

        $context = (array)$this->service()->fetchContext($companyId, $accountingPeriodId);
        if ((int)(($context['submission'] ?? [])['id'] ?? 0) !== $submissionId) {
            return ['success' => false, 'errors' => ['The prepared submission does not belong to the selected company and accounting period.']];
        }
        $feature = (array)($context['feature'] ?? []);
        $mode = strtoupper(trim((string)($feature['mode'] ?? 'DISABLED')));
        if (empty($feature['enabled']) || !in_array($mode, ['TEST', 'LIVE'], true)) {
            return ['success' => false, 'errors' => ['Companies House accounts filing is disabled.']];
        }
        if ($mode === 'LIVE') {
            if (empty($feature['live_approved'])) {
                return ['success' => false, 'errors' => ['Companies House LIVE accounts filing has not been approved.']];
            }
            if ((string)$request->input('authority_confirmed', '') !== '1') {
                return ['success' => false, 'errors' => ['Confirm that you are authorised to file these revised statutory accounts.']];
            }
            if (trim((string)$request->input('live_confirmation_phrase', '')) !== self::LIVE_CONFIRMATION_PHRASE) {
                return ['success' => false, 'errors' => ['Type the exact LIVE submission confirmation phrase before filing.']];
            }
        }

        $preflightId = $developerStep ? (int)$request->input('preflight_id', 0) : null;
        if ($developerStep && $preflightId <= 0) {
            return ['success' => false, 'errors' => ['A successful developer CompanyData preflight is required.']];
        }

        return $this->service()->submitRevision(
            $submissionId,
            $companyAuthCode,
            $this->actor($request),
            $progress,
            $preflightId
        );
    }

    private function preflightRevision(
        RequestFramework $request,
        int $companyId,
        int $accountingPeriodId,
        ActionProgressFramework $progress
    ): array {
        $submissionId = (int)$request->input('submission_id', 0);
        $companyAuthCode = trim((string)$request->input('company_auth_code', ''));
        if (preg_match('/^[A-Za-z0-9]{6}$/D', $companyAuthCode) !== 1) {
            return ['success' => false, 'errors' => ['The company authentication code must contain exactly 6 letters or numbers.']];
        }
        $context = (array)$this->service()->fetchContext($companyId, $accountingPeriodId);
        if ((int)(($context['submission'] ?? [])['id'] ?? 0) !== $submissionId) {
            return ['success' => false, 'errors' => ['The prepared submission does not belong to this period.']];
        }
        return $this->service()->preflightRevision(
            $submissionId,
            $companyAuthCode,
            $this->actor($request),
            $progress
        );
    }

    private function protocolStatusAction(
        RequestFramework $request,
        int $companyId,
        int $accountingPeriodId,
        string $method
    ): array {
        $submissionId = (int)$request->input('submission_id', 0);
        $context = (array)$this->service()->fetchContext($companyId, $accountingPeriodId);
        if ($submissionId <= 0
            || (int)(($context['submission'] ?? [])['id'] ?? 0) !== $submissionId) {
            return ['success' => false, 'errors' => ['The Companies House submission could not be identified.']];
        }
        return $this->service()->{$method}($submissionId, $this->actor($request));
    }

    private function reconcileStatus(
        RequestFramework $request,
        int $companyId,
        int $accountingPeriodId
    ): array {
        if (trim((string)$request->input('reconciliation_phrase', ''))
            !== 'RECONCILE COMPANIES HOUSE') {
            return ['success' => false, 'errors' => [
                'Type the exact reconciliation phrase before changing an uncertain protocol state.',
            ]];
        }
        $submissionId = (int)$request->input('submission_id', 0);
        $context = (array)$this->service()->fetchContext($companyId, $accountingPeriodId);
        if ($submissionId <= 0
            || (int)(($context['submission'] ?? [])['id'] ?? 0) !== $submissionId) {
            return ['success' => false, 'errors' => ['The Companies House submission could not be identified.']];
        }
        return $this->service()->reconcileStatusExchange(
            $submissionId,
            (string)$request->input('resolution', ''),
            $this->actor($request)
        );
    }

    private function downloadProtocolEvidence(
        RequestFramework $request,
        int $companyId,
        int $accountingPeriodId
    ): never {
        $submissionId = (int)$request->input('submission_id', 0);
        $exchangeId = (int)$request->input('exchange_id', 0);
        $direction = (string)$request->input('direction', '');
        $context = (array)$this->service()->fetchContext($companyId, $accountingPeriodId);
        if ($submissionId <= 0
            || (int)(($context['submission'] ?? [])['id'] ?? 0) !== $submissionId) {
            header('Content-Type: text/plain; charset=utf-8', true, 403);
            echo 'The Companies House evidence is not available in this accounting context.';
            exit;
        }
        try {
            $file = $this->service()->protocolEvidenceFile($submissionId, $exchangeId, $direction);
        } catch (Throwable $exception) {
            header('Content-Type: text/plain; charset=utf-8', true, 404);
            echo $exception->getMessage();
            exit;
        }
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', (string)$file['filename']) . '"');
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
        $size = filesize((string)$file['path']);
        if (is_int($size)) {
            header('Content-Length: ' . $size);
        }
        readfile((string)$file['path']);
        exit;
    }

    private function refreshStatus(RequestFramework $request, int $companyId, int $accountingPeriodId): array
    {
        $submissionId = (int)$request->input('submission_id', 0);
        if ($submissionId <= 0) {
            return ['success' => false, 'errors' => ['The Companies House submission could not be identified.']];
        }
        $context = (array)$this->service()->fetchContext($companyId, $accountingPeriodId);
        if ((int)(($context['submission'] ?? [])['id'] ?? 0) !== $submissionId) {
            return ['success' => false, 'errors' => ['The submission does not belong to the selected company and accounting period.']];
        }

        return $this->service()->refreshStatus($submissionId, $this->actor($request));
    }

    private function result(string $intent, array $result): ActionResultFramework
    {
        $success = !empty($result['success']);
        $messages = $this->normaliseMessages($result[$success ? 'messages' : 'errors'] ?? []);
        if ($messages === []) {
            $messages = [$success ? $this->successMessage($intent) : 'The Companies House revised-accounts action failed.'];
        }

        $flash = [];
        foreach ($messages as $message) {
            $flash[] = ['type' => $success ? 'success' : 'error', 'message' => $message];
        }
        foreach ($this->normaliseMessages($result['warnings'] ?? []) as $warning) {
            $flash[] = ['type' => 'warning', 'message' => $warning];
        }

        return new ActionResultFramework($success, self::CHANGED_FACTS, $flash);
    }

    private function error(string $message): ActionResultFramework
    {
        return new ActionResultFramework(false, self::CHANGED_FACTS, [[
            'type' => 'error',
            'message' => $message,
        ]]);
    }

    private function successMessage(string $intent): string
    {
        return match ($intent) {
            'record_gateway_eligibility' => 'Companies House filing eligibility recorded.',
            'prepare_revised_accounts' => 'Revised accounts prepared for review.',
            'submit_revised_accounts' => 'Revised accounts sent to Companies House.',
            'refresh_revised_accounts_status' => 'Companies House submission status refreshed.',
            default => 'Companies House revised-accounts filing updated.',
        };
    }

    private function securityError(RequestFramework $request): ?string
    {
        if ($this->securityCheck !== null) {
            $error = ($this->securityCheck)($request);
            return $error !== null && trim((string)$error) !== '' ? trim((string)$error) : null;
        }

        $csrfToken = trim((string)$request->input('csrf_token', ''));
        if ($csrfToken === '') {
            return 'A valid security token is required for Companies House filing actions.';
        }

        try {
            $session = new SessionAuthenticationService();
            $session->startSession();
            if (!$session->isValidCsrfToken($csrfToken)) {
                return 'The security token expired. Refresh the page before trying again.';
            }

            $deviceId = trim((string)AntiFraudService::instance($request)->requestValue('Client-Device-ID'));
            $userId = $session->authenticatedUserId($deviceId !== '' ? $deviceId : null);
            if ($userId <= 0) {
                return 'Sign in before using Companies House filing actions.';
            }
            if ((new CardAccessFramework())->roleIdForUser($userId) !== RoleAssignmentService::ADMIN_ROLE_ID) {
                return 'Only administrators can use Companies House revised-accounts filing.';
            }
        } catch (Throwable) {
            return 'Companies House filing authorisation could not be verified.';
        }

        return null;
    }

    private function contextError(int $companyId, int $accountingPeriodId): ?string
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return 'Select a company and accounting period before using Companies House revised-accounts filing.';
        }

        if ($this->contextResolver !== null) {
            $resolved = (array)($this->contextResolver)();
            $authorisedCompanyId = (int)($resolved['company_id'] ?? $resolved[0] ?? 0);
            $authorisedAccountingPeriodId = (int)($resolved['accounting_period_id'] ?? $resolved[1] ?? 0);
        } else {
            $context = new \eel_accounts\Service\AccountingContextService();
            $authorisedCompanyId = $context->authCompanyId();
            $authorisedAccountingPeriodId = $context->authAccountingPeriodId();
        }

        if ($companyId !== $authorisedCompanyId || $accountingPeriodId !== $authorisedAccountingPeriodId) {
            return 'The submitted company or accounting period does not match the authenticated accounting context.';
        }

        return null;
    }

    private function isLocked(int $companyId, int $accountingPeriodId): bool
    {
        if ($this->lockChecker !== null) {
            return (bool)($this->lockChecker)($companyId, $accountingPeriodId);
        }

        return (new \eel_accounts\Service\YearEndLockService())->isLocked($companyId, $accountingPeriodId);
    }

    private function actor(RequestFramework $request): string
    {
        if ($this->actorResolver !== null) {
            $actor = trim((string)($this->actorResolver)($request));
            return $actor !== '' ? $actor : 'web_app';
        }

        try {
            $session = new SessionAuthenticationService();
            $session->startSession();
            $deviceId = trim((string)AntiFraudService::instance($request)->requestValue('Client-Device-ID'));
            $userId = $session->authenticatedUserId($deviceId !== '' ? $deviceId : null);
            if ($userId > 0) {
                return 'user:' . $userId;
            }
        } catch (Throwable) {
        }

        return 'web_app';
    }

    private function service(): object
    {
        if ($this->submissionService === null) {
            $this->submissionService = new \eel_accounts\Service\CompaniesHouseAccountsSubmissionService();
        }

        return $this->submissionService;
    }

    private function isIsoDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    private function normaliseMessages(mixed $messages): array
    {
        if (is_string($messages) || is_numeric($messages)) {
            $message = trim((string)$messages);
            return $message !== '' ? [$message] : [];
        }
        if (!is_array($messages)) {
            return [];
        }

        $normalised = [];
        foreach ($messages as $message) {
            if (is_array($message)) {
                $text = trim((string)($message['message'] ?? $message['description'] ?? $message['detail'] ?? ''));
            } elseif (is_scalar($message)) {
                $text = trim((string)$message);
            } else {
                $text = '';
            }
            if ($text !== '') {
                $normalised[] = $text;
            }
        }

        return $normalised;
    }
}
