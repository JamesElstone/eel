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
    /** @var list<string> */
    private const CHANGED_FACTS = [
        'companies.house.accounts.submission',
        'year.end.companies.house.comparison',
        'year.end.checklist',
        'ixbrl.readiness',
        'ixbrl.generation',
        'ixbrl.disclosures',
        'page.context',
    ];

    private ?Closure $securityCheck;
    private ?Closure $contextResolver;
    private ?Closure $lockChecker;
    private ?Closure $actorResolver;
    private ?Closure $accountingPrerequisite;
    private ?Closure $revisionPrerequisite;
    private ?Closure $filingModeResolver;
    private ?Closure $liveApprovalResolver;

    public function __construct(
        private ?object $submissionService = null,
        ?callable $securityCheck = null,
        ?callable $contextResolver = null,
        ?callable $lockChecker = null,
        ?callable $actorResolver = null,
        ?callable $accountingPrerequisite = null,
        ?callable $revisionPrerequisite = null,
        ?callable $filingModeResolver = null,
        ?callable $liveApprovalResolver = null,
    ) {
        $this->securityCheck = $securityCheck !== null ? Closure::fromCallable($securityCheck) : null;
        $this->contextResolver = $contextResolver !== null ? Closure::fromCallable($contextResolver) : null;
        $this->lockChecker = $lockChecker !== null ? Closure::fromCallable($lockChecker) : null;
        $this->actorResolver = $actorResolver !== null ? Closure::fromCallable($actorResolver) : null;
        $this->accountingPrerequisite = $accountingPrerequisite !== null
            ? Closure::fromCallable($accountingPrerequisite)
            : null;
        $this->revisionPrerequisite = $revisionPrerequisite !== null
            ? Closure::fromCallable($revisionPrerequisite)
            : null;
        $this->filingModeResolver = $filingModeResolver !== null
            ? Closure::fromCallable($filingModeResolver)
            : null;
        $this->liveApprovalResolver = $liveApprovalResolver !== null
            ? Closure::fromCallable($liveApprovalResolver)
            : null;
    }

    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $intent = match (trim((string)$request->input('intent', ''))) {
            'prepare_revised_accounts' => 'prepare_accounts',
            'submit_revised_accounts' => 'submit_accounts',
            'refresh_revised_accounts_status' => 'refresh_accounts_status',
            'download_revised_accounts_ixbrl' => 'download_accounts_ixbrl',
            'preflight_revised_accounts' => 'preflight_accounts',
            'submit_preflighted_revised_accounts' => 'submit_preflighted_accounts',
            'poll_revised_accounts_status' => 'poll_accounts_status',
            'ack_revised_accounts_status' => 'ack_accounts_status',
            'retrieve_revised_accounts_document' => 'retrieve_accounts_document',
            'reconcile_revised_accounts_status' => 'reconcile_accounts_status',
            default => trim((string)$request->input('intent', '')),
        };
        $allowedIntents = [
            'record_gateway_eligibility',
            'save_variance_explanation',
            'prepare_accounts',
            'submit_accounts',
            'refresh_accounts_status',
            'preflight_accounts',
            'submit_preflighted_accounts',
            'poll_accounts_status',
            'ack_accounts_status',
            'retrieve_accounts_document',
            'download_accounts_ixbrl',
            'reconcile_accounts_status',
        ];
        if (!in_array($intent, $allowedIntents, true)) {
            return $this->error('Unknown Companies House accounts action.');
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

        if (!in_array($intent, [
            'record_gateway_eligibility',
            'save_variance_explanation',
            'preflight_accounts',
        ], true)
            && !$this->isLocked($companyId, $accountingPeriodId)) {
            return $this->error('Complete and lock Year End before using Companies House accounts filing.');
        }
        $developerIntent = in_array($intent, [
            'preflight_accounts',
            'submit_preflighted_accounts',
            'poll_accounts_status',
            'ack_accounts_status',
            'retrieve_accounts_document',
            'reconcile_accounts_status',
        ], true);
        if ($developerIntent && !(bool)AppConfigurationStore::get('developer_options', false)) {
            return $this->error('Developer options must be enabled for step-by-step Companies House exchanges.');
        }
        if ($intent === 'download_accounts_ixbrl') {
            $this->downloadRevisedAccountsIxbrl($companyId, $accountingPeriodId);
        }

        try {
            $result = match ($intent) {
                'record_gateway_eligibility' => $this->recordEligibility($request, $companyId, $accountingPeriodId),
                'save_variance_explanation' => $this->saveVarianceExplanation($request, $companyId, $accountingPeriodId),
                'prepare_accounts' => $this->prepareRevision(
                    $request,
                    $companyId,
                    $accountingPeriodId,
                    $services->actionProgress()
                ),
                'submit_accounts' => $this->submitRevision($request, $companyId, $accountingPeriodId, $services->actionProgress()),
                'refresh_accounts_status' => $this->refreshStatus(
                    $request,
                    $companyId,
                    $accountingPeriodId,
                    $services->actionProgress()
                ),
                'preflight_accounts' => $this->preflightRevision(
                    $request,
                    $companyId,
                    $accountingPeriodId,
                    $services->actionProgress()
                ),
                'submit_preflighted_accounts' => $this->submitRevision(
                    $request,
                    $companyId,
                    $accountingPeriodId,
                    $services->actionProgress()
                ),
                'poll_accounts_status' => $this->protocolStatusAction(
                    $request,
                    $companyId,
                    $accountingPeriodId,
                    'pollStatus'
                ),
                'ack_accounts_status' => $this->protocolStatusAction(
                    $request,
                    $companyId,
                    $accountingPeriodId,
                    'acknowledgeStatus'
                ),
                'retrieve_accounts_document' => $this->protocolStatusAction(
                    $request,
                    $companyId,
                    $accountingPeriodId,
                    'retrieveDocument'
                ),
                'reconcile_accounts_status' => $this->reconcileStatus(
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

    private function downloadRevisedAccountsIxbrl(int $companyId, int $accountingPeriodId): never
    {
        $artifact = (new \eel_accounts\Service\IxbrlArtifactDownloadService())
            ->companiesHouse($companyId, $accountingPeriodId);
        if (empty($artifact['ok']) || (string)($artifact['state'] ?? '') !== 'ready') {
            header('Content-Type: text/plain; charset=utf-8', true, 409);
            echo (string)(($artifact['errors'] ?? [])[0]
                ?? 'The prepared Companies House iXBRL artifact is not current.');
            exit;
        }
        $path = trim((string)($artifact['path'] ?? ''));
        if ($path === '' || !is_file($path)) {
            header('Content-Type: text/plain; charset=utf-8', true, 404);
            echo 'The prepared Companies House iXBRL artifact was not found.';
            exit;
        }

        $filename = basename((string)($artifact['filename'] ?? 'companies-house-accounts.xhtml'));
        header('Content-Type: application/xhtml+xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
        $size = filesize($path);
        if (is_int($size)) {
            header('Content-Length: ' . $size);
        }
        readfile($path);
        exit;
    }

    private function recordEligibility(
        RequestFramework $request,
        int $companyId,
        int $accountingPeriodId
    ): array {
        $originalDocumentId = (int)$request->input('original_document_id', 0);
        $decision = strtolower(trim((string)$request->input('eligibility_decision', '')));

        if ($originalDocumentId <= 0) {
            return ['success' => false, 'errors' => ['Select the exact original Companies House filing before recording eligibility.']];
        }
        if (!in_array($decision, ['eligible', 'ineligible'], true)) {
            return ['success' => false, 'errors' => ['Record whether Companies House confirmed the filing as eligible or ineligible.']];
        }
        return $this->service()->recordEligibility(
            $companyId,
            $accountingPeriodId,
            $originalDocumentId,
            $decision,
            $this->actor($request)
        );
    }

    private function prepareRevision(
        RequestFramework $request,
        int $companyId,
        int $accountingPeriodId,
        ActionProgressFramework $progress
    ): array {
        @set_time_limit(0);
        try {
            return (array)(new \eel_accounts\Service\IxbrlFilingOperationLockService())->execute(
                $companyId,
                $accountingPeriodId,
                function () use ($request, $companyId, $accountingPeriodId, $progress): array {
                    $revisionReadiness = $this->revisionPrerequisite !== null
                        ? (array)($this->revisionPrerequisite)($companyId, $accountingPeriodId)
                        : (new \eel_accounts\Service\CompaniesHouseRevisedAccountsReadinessService())
                            ->assess($companyId, $accountingPeriodId);
                    if (!empty($revisionReadiness['applicable'])
                        && empty($revisionReadiness['ready'])) {
                        return [
                            'success' => false,
                            'errors' => (array)($revisionReadiness['errors'] ?? [
                                'The revised accounts approval date is not ready.',
                            ]),
                        ];
                    }
                    $context = $this->service()->fetchContext($companyId, $accountingPeriodId);
                    if (empty($context['can_prepare'])
                        && empty($context['can_prepare_after_accounts_generation'])) {
                        return [
                            'success' => false,
                            'errors' => (array)($context['preparation_blockers'] ?? [
                                'The Companies House accounts iXBRL is not ready to prepare.',
                            ]),
                        ];
                    }
                    $prerequisite = $this->accountingPrerequisite !== null
                        ? ($this->accountingPrerequisite)($companyId, $accountingPeriodId, $progress)
                        : $this->ensureAccountingIxbrlReady($companyId, $accountingPeriodId, $progress);
                    if (empty($prerequisite['success'])) {
                        return $prerequisite;
                    }

                    $progress->report('Preparing the Companies House accounts iXBRL…', 65);
                    return $this->service()->prepareAccounts(
                        $companyId,
                        $accountingPeriodId,
                        [
                            'non_compliance_explanation' => trim((string)$request->input('non_compliance_explanation', '')),
                            'significant_amendments' => trim((string)$request->input('significant_amendments', '')),
                            'revision_approval_date' => trim((string)$request->input('revision_approval_date', '')),
                            'original_software_filing_confirmed' => (bool)$request->input('original_software_filing_confirmed', false),
                        ],
                        $this->actor($request),
                        $progress
                    );
                }
            );
        } catch (Throwable $exception) {
            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }
    }

    private function ensureAccountingIxbrlReady(
        int $companyId,
        int $accountingPeriodId,
        ActionProgressFramework $progress
    ): array {
        $readiness = (new \eel_accounts\Service\IxbrlReadinessService())
            ->getReadiness($companyId, $accountingPeriodId);
        if (empty($readiness['can_generate'])) {
            return [
                'success' => false,
                'errors' => (array)($readiness['generation_errors'] ?? [
                    'The Accounting iXBRL prerequisites are incomplete.',
                ]),
            ];
        }
        if (empty($readiness['ready_for_filing'])) {
            if (empty($readiness['can_validate'])) {
                $progress->report('Generating the prerequisite Accounting iXBRL…', 5);
                $generated = (new \eel_accounts\Service\IxbrlAccountingService())
                    ->generateFilingExport($companyId, $accountingPeriodId);
                if (empty($generated['success'])) {
                    return $generated;
                }
                \eel_accounts\Support\RequestCache::clear();
            }

            $progress->report('Running Arelle validation for the Accounting iXBRL…', 40);
            $external = (new \eel_accounts\Service\IxbrlExternalValidationService())
                ->validateLatestRun($companyId, $accountingPeriodId);
            if ((string)($external['status'] ?? '') !== 'passed') {
                return [
                    'success' => false,
                    'errors' => (array)($external['errors'] ?? [
                        'The prerequisite Accounting iXBRL did not pass Arelle validation.',
                    ]),
                ];
            }

            \eel_accounts\Support\RequestCache::clear();
            $readiness = (new \eel_accounts\Service\IxbrlReadinessService())
                ->getReadiness($companyId, $accountingPeriodId);
            if (empty($readiness['ready_for_filing'])) {
                return [
                    'success' => false,
                    'errors' => (array)($readiness['filing_errors'] ?? [
                        'The prerequisite Accounting iXBRL is not filing-ready.',
                    ]),
                ];
            }
        }

        return ['success' => true, 'errors' => []];
    }

    private function saveVarianceExplanation(
        RequestFramework $request,
        int $companyId,
        int $accountingPeriodId
    ): array {
        return $this->service()->saveVarianceExplanation(
            $companyId,
            $accountingPeriodId,
            (int)$request->input('original_document_id', 0),
            trim((string)$request->input('variance_explanation', '')),
            $this->actor($request)
        );
    }

    private function submitRevision(
        RequestFramework $request,
        int $companyId,
        int $accountingPeriodId,
        ActionProgressFramework $progress
    ): array {
        @set_time_limit(0);
        $progress->report(
            'Submission request received. Starting Companies House transmission checks; nothing has been sent yet.',
            0
        );
        $submissionId = (int)$request->input('submission_id', 0);
        $companyAuthCode = trim((string)$request->input('company_auth_code', ''));
        if ($submissionId <= 0) {
            return ['success' => false, 'errors' => ['The prepared accounts submission could not be identified.']];
        }
        if (preg_match('/^[A-Za-z0-9]{6}$/D', $companyAuthCode) !== 1) {
            return ['success' => false, 'errors' => ['The company authentication code must contain exactly 6 letters or numbers.']];
        }

        $filingKind = $this->service()->submissionFilingKindForContext(
            $submissionId,
            $companyId,
            $accountingPeriodId
        );
        if ($filingKind === null) {
            return ['success' => false, 'errors' => ['The prepared submission does not belong to the selected company and accounting period.']];
        }
        $mode = $this->filingMode();
        if (!in_array($mode, ['TEST', 'LIVE'], true)) {
            return ['success' => false, 'errors' => ['Companies House accounts filing is disabled.']];
        }
        if ($mode === 'LIVE') {
            if (!$this->liveFilingApproved()) {
                return ['success' => false, 'errors' => ['Companies House LIVE accounts filing has not been approved.']];
            }
            if ((string)$request->input('authority_confirmed', '') !== '1') {
                return ['success' => false, 'errors' => ['Confirm that you are authorised to file these statutory accounts.']];
            }
            $confirmationPhrase = 'SUBMIT LIVE ' . strtoupper($filingKind) . ' ACCOUNTS';
            if (trim((string)$request->input('live_confirmation_phrase', '')) !== $confirmationPhrase) {
                return ['success' => false, 'errors' => ['Type the exact LIVE submission confirmation phrase before filing.']];
            }
        }

        return $this->service()->submitAccounts(
            $submissionId,
            $companyAuthCode,
            $this->actor($request),
            $progress
        );
    }

    private function preflightRevision(
        RequestFramework $request,
        int $companyId,
        int $accountingPeriodId,
        ActionProgressFramework $progress
    ): array {
        $progress->report('Checking the Companies House company authentication code…', 0);
        $companyAuthCode = trim((string)$request->input('company_auth_code', ''));
        if (preg_match('/^[A-Za-z0-9]{6}$/D', $companyAuthCode) !== 1) {
            return ['success' => false, 'errors' => ['The company authentication code must contain exactly 6 letters or numbers.']];
        }
        return $this->service()->checkCompanyAuthentication(
            $companyId,
            $accountingPeriodId,
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

    private function refreshStatus(
        RequestFramework $request,
        int $companyId,
        int $accountingPeriodId,
        ActionProgressFramework $progress
    ): array
    {
        $progress->report(
            'Status request received. Preparing to query the Companies House submission; nothing has been sent yet.',
            0
        );
        $submissionId = (int)$request->input('submission_id', 0);
        if ($submissionId <= 0) {
            return ['success' => false, 'errors' => ['The Companies House submission could not be identified.']];
        }
        $context = (array)$this->service()->fetchContext($companyId, $accountingPeriodId);
        if ((int)(($context['submission'] ?? [])['id'] ?? 0) !== $submissionId) {
            return ['success' => false, 'errors' => ['The submission does not belong to the selected company and accounting period.']];
        }

        return $this->service()->refreshStatus($submissionId, $this->actor($request), $progress);
    }

    private function result(string $intent, array $result): ActionResultFramework
    {
        $success = !empty($result['success']);
        $messages = $this->normaliseMessages($result[$success ? 'messages' : 'errors'] ?? []);
        if ($messages === []) {
            $messages = [$success ? $this->successMessage($intent) : 'The Companies House accounts action failed.'];
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
            'save_variance_explanation' => 'Companies House variance explanation saved.',
            'prepare_accounts' => 'Companies House accounts prepared for review.',
            'submit_accounts' => 'Accounts sent to Companies House.',
            'refresh_accounts_status' => 'Companies House submission status refreshed.',
            default => 'Companies House accounts filing updated.',
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
                return 'Only administrators can use Companies House accounts filing.';
            }
        } catch (Throwable) {
            return 'Companies House filing authorisation could not be verified.';
        }

        return null;
    }

    private function contextError(int $companyId, int $accountingPeriodId): ?string
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return 'Select a company and accounting period before using Companies House accounts filing.';
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

    private function filingMode(): string
    {
        $mode = $this->filingModeResolver !== null
            ? (string)($this->filingModeResolver)()
            : AccountingConfigurationStore::companiesHouseAccountsFilingMode();

        return strtoupper(trim($mode));
    }

    private function liveFilingApproved(): bool
    {
        return $this->liveApprovalResolver !== null
            ? (bool)($this->liveApprovalResolver)()
            : AccountingConfigurationStore::companiesHouseAccountsLiveApproved();
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
