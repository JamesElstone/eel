<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class HmrcSubmissionAction implements ActionInterfaceFramework
{
    private const LIVE_CONFIRMATION_PHRASE = 'SUBMIT LIVE CT600';

    /** @var list<string> */
    private const CHANGED_FACTS = [
        'hmrc.submission',
        'hmrc.submission.overview',
        'hmrc.submission.controls',
        'hmrc.submission.log',
        'hmrc.submission.history',
        'page.context',
    ];

    private ?\Closure $securityCheck;
    private ?\Closure $contextResolver;
    private ?\Closure $lockChecker;
    private ?\Closure $periodOwnershipCheck;
    private ?\Closure $submissionOwnershipCheck;
    private ?\Closure $actorResolver;

    public function __construct(
        private ?object $orchestrator = null,
        ?callable $securityCheck = null,
        ?callable $contextResolver = null,
        ?callable $lockChecker = null,
        ?callable $periodOwnershipCheck = null,
        ?callable $submissionOwnershipCheck = null,
        ?callable $actorResolver = null,
        private ?object $artifactDownloadService = null,
    ) {
        $this->securityCheck = $securityCheck !== null ? \Closure::fromCallable($securityCheck) : null;
        $this->contextResolver = $contextResolver !== null ? \Closure::fromCallable($contextResolver) : null;
        $this->lockChecker = $lockChecker !== null ? \Closure::fromCallable($lockChecker) : null;
        $this->periodOwnershipCheck = $periodOwnershipCheck !== null ? \Closure::fromCallable($periodOwnershipCheck) : null;
        $this->submissionOwnershipCheck = $submissionOwnershipCheck !== null ? \Closure::fromCallable($submissionOwnershipCheck) : null;
        $this->actorResolver = $actorResolver !== null ? \Closure::fromCallable($actorResolver) : null;
    }

    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $intent = trim((string)$request->input('intent', ''));
        if (!in_array($intent, [
            'prepare_ct600',
            'approve_ct600',
            'submit_ct600',
            'poll_ct600',
            'delete_ct600_response',
            'download_ct600_artifact',
        ], true)) {
            return $this->error('Unknown HMRC CT600 action.');
        }

        $securityError = $this->securityError($request);
        if ($securityError !== null) {
            return $this->error($securityError);
        }

        [$companyId, $accountingPeriodId] = $this->accountingContext();
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return $this->error('Select a company and accounting period before using HMRC CT600 filing.');
        }

        $ctPeriodId = (int)$request->input('ct_period_id', 0);
        if (!$this->ownsCtPeriod($companyId, $accountingPeriodId, $ctPeriodId)) {
            return $this->error('The selected CT period does not belong to the authenticated company and accounting period.');
        }

        $submissionId = (int)$request->input('submission_id', 0);
        if ($intent !== 'prepare_ct600'
            && !$this->ownsSubmission($companyId, $accountingPeriodId, $ctPeriodId, $submissionId)) {
            return $this->error('The selected CT600 package does not belong to the authenticated accounting context.');
        }

        if ($intent === 'download_ct600_artifact') {
            $this->downloadArtifact(
                $submissionId,
                $companyId,
                $accountingPeriodId,
                $ctPeriodId,
                (string)$request->input('artifact', '')
            );
        }

        if (in_array($intent, ['prepare_ct600', 'approve_ct600', 'submit_ct600'], true)
            && !$this->isLocked($companyId, $accountingPeriodId)) {
            return $this->error('Complete and lock Year End before preparing, approving, or sending a CT600 package.');
        }

        $actor = $this->actor($request);
        try {
            $result = match ($intent) {
                'prepare_ct600' => $this->prepare(
                    $request,
                    $companyId,
                    $accountingPeriodId,
                    $ctPeriodId,
                    $actor
                ),
                'approve_ct600' => $this->approve($request, $submissionId, $actor),
                'submit_ct600' => $this->submit($request, $submissionId, $actor),
                'poll_ct600' => $this->service()->poll($submissionId, $actor),
                'delete_ct600_response' => $this->service()->deleteResponse($submissionId, $actor),
            };
        } catch (Throwable $exception) {
            $result = ['success' => false, 'errors' => [$exception->getMessage()]];
        }

        return $this->result($intent, is_array($result) ? $result : [], $ctPeriodId);
    }

    /** @return array<string, mixed> */
    private function prepare(
        RequestFramework $request,
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        string $actor
    ): array {
        $declaration = $this->declarationDetails($request);
        if ($declaration['errors'] !== []) {
            return ['success' => false, 'errors' => $declaration['errors']];
        }

        return $this->service()->prepare(
            $companyId,
            $accountingPeriodId,
            $ctPeriodId,
            $actor,
            [
                'name' => $declaration['name'],
                'status' => $declaration['status'],
                // The CT/5 XML declaration must be complete before IRmark is
                // frozen. Final approval is recorded separately below.
                'confirmed' => true,
            ]
        );
    }

    /** @return array<string, mixed> */
    private function approve(RequestFramework $request, int $submissionId, string $actor): array
    {
        $declaration = $this->declarationDetails($request);
        $name = (string)$declaration['name'];
        $status = (string)$declaration['status'];
        $confirmed = (string)$request->input('declaration_confirmed', '') === '1';
        $scopeConfirmed = (string)$request->input('scope_confirmed', '') === '1';
        $originalUnfiledConfirmed = (string)$request->input('original_unfiled_confirmed', '') === '1';
        $errors = $declaration['errors'];
        if (!$scopeConfirmed) {
            $errors[] = 'Confirm that no unsupported supplementary page, claim, election, or attachment is required.';
        }
        if (!$originalUnfiledConfirmed) {
            $errors[] = 'Confirm that this original CT period return has not already been filed elsewhere.';
        }
        if (!$confirmed) {
            $errors[] = 'Confirm that the exact frozen Company Tax Return is correct and complete.';
        }
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        return $this->service()->approve($submissionId, [
            'name' => $name,
            'status' => $status,
            'confirmed' => true,
            'scope_confirmed' => true,
            'original_unfiled_confirmed' => true,
        ], $actor);
    }

    /** @return array{name:string,status:string,errors:list<string>} */
    private function declarationDetails(RequestFramework $request): array
    {
        $name = trim((string)$request->input('declarant_name', ''));
        $status = strtolower(trim((string)$request->input('declarant_status', '')));
        $errors = [];
        if ($name === '' || mb_strlen($name) > 150) {
            $errors[] = 'Enter the name of the person making the CT600 declaration.';
        }
        if (!in_array($status, ['proper_officer', 'authorised_person'], true)) {
            $errors[] = 'Select whether the declarant is the proper officer or a duly authorised person.';
        }

        return ['name' => $name, 'status' => $status, 'errors' => $errors];
    }

    /** @return array<string, mixed> */
    private function submit(RequestFramework $request, int $submissionId, string $actor): array
    {
        if ((string)$request->input('authority_confirmed', '') !== '1') {
            return ['success' => false, 'errors' => ['Confirm that you are authorised to send this Company Tax Return.']];
        }

        $environment = strtoupper(trim((string)$this->service()->environment()));
        if (!in_array($environment, ['TEST', 'TIL', 'LIVE'], true)) {
            return ['success' => false, 'errors' => ['The server CT filing environment is invalid.']];
        }
        if ($environment === 'LIVE'
            && trim((string)$request->input('live_confirmation', '')) !== self::LIVE_CONFIRMATION_PHRASE) {
            return ['success' => false, 'errors' => ['Type the exact LIVE CT600 confirmation phrase before statutory filing.']];
        }

        return $this->service()->submit($submissionId, $actor);
    }

    private function securityError(RequestFramework $request): ?string
    {
        if ($this->securityCheck !== null) {
            $error = ($this->securityCheck)($request);
            return $error !== null && trim((string)$error) !== '' ? trim((string)$error) : null;
        }

        $csrfToken = trim((string)$request->input('csrf_token', ''));
        if ($csrfToken === '') {
            return 'A valid security token is required for HMRC filing actions.';
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
                return 'Sign in before using HMRC CT600 filing.';
            }
            if ((new CardAccessFramework())->roleIdForUser($userId) !== RoleAssignmentService::ADMIN_ROLE_ID) {
                return 'Only administrators can use HMRC CT600 filing.';
            }
        } catch (Throwable) {
            return 'HMRC filing authorisation could not be verified.';
        }

        return null;
    }

    /** @return array{0:int,1:int} */
    private function accountingContext(): array
    {
        if ($this->contextResolver !== null) {
            $resolved = (array)($this->contextResolver)();
            return [
                (int)($resolved['company_id'] ?? $resolved[0] ?? 0),
                (int)($resolved['accounting_period_id'] ?? $resolved[1] ?? 0),
            ];
        }

        $context = new \eel_accounts\Service\AccountingContextService();
        return [$context->authCompanyId(), $context->authAccountingPeriodId()];
    }

    private function ownsCtPeriod(int $companyId, int $accountingPeriodId, int $ctPeriodId): bool
    {
        if ($this->periodOwnershipCheck !== null) {
            return (bool)($this->periodOwnershipCheck)($companyId, $accountingPeriodId, $ctPeriodId);
        }
        if ($companyId <= 0 || $accountingPeriodId <= 0 || $ctPeriodId <= 0
            || !InterfaceDB::tableExists('corporation_tax_periods')) {
            return false;
        }

        return (int)InterfaceDB::fetchColumn(
            'SELECT COUNT(*) FROM corporation_tax_periods
             WHERE id = :id AND company_id = :company_id AND accounting_period_id = :accounting_period_id',
            ['id' => $ctPeriodId, 'company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        ) === 1;
    }

    private function ownsSubmission(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        int $submissionId
    ): bool {
        if ($this->submissionOwnershipCheck !== null) {
            return (bool)($this->submissionOwnershipCheck)(
                $companyId,
                $accountingPeriodId,
                $ctPeriodId,
                $submissionId
            );
        }
        if ($submissionId <= 0 || !InterfaceDB::tableExists('hmrc_ct600_submissions')) {
            return false;
        }

        return (int)InterfaceDB::fetchColumn(
            'SELECT COUNT(*) FROM hmrc_ct600_submissions
             WHERE id = :id
               AND company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND ct_period_id = :ct_period_id',
            [
                'id' => $submissionId,
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'ct_period_id' => $ctPeriodId,
            ]
        ) === 1;
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
        if ($this->orchestrator === null) {
            $this->orchestrator = new \eel_accounts\Service\HmrcCtSubmissionOrchestrator();
        }

        return $this->orchestrator;
    }

    private function downloadArtifact(
        int $submissionId,
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        string $artifact,
    ): never {
        try {
            $download = ($this->artifactDownloadService ??= new \eel_accounts\Service\HmrcCtArtifactDownloadService())
                ->resolve($submissionId, $companyId, $accountingPeriodId, $ctPeriodId, $artifact);
        } catch (Throwable $exception) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'CT600 artifact download failed: ' . $exception->getMessage();
            exit;
        }

        $filename = str_replace(['"', "\r", "\n"], '', basename((string)$download['filename']));
        header('Content-Type: ' . (string)$download['content_type']);
        header('Content-Length: ' . (int)$download['size_bytes']);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, max-age=0');
        readfile((string)$download['path']);
        exit;
    }

    /** @param array<string,mixed> $result */
    private function result(string $intent, array $result, int $ctPeriodId): ActionResultFramework
    {
        $success = !empty($result['success']);
        $messages = $this->normaliseMessages($result[$success ? 'messages' : 'errors'] ?? []);
        if ($messages === []) {
            $messages = [$success ? $this->successMessage($intent) : 'The HMRC CT600 action failed.'];
        }

        $flash = [];
        foreach ($messages as $message) {
            $flash[] = ['type' => $success ? 'success' : 'error', 'message' => $message];
        }
        foreach ($this->normaliseMessages($result['warnings'] ?? []) as $warning) {
            $flash[] = ['type' => 'warning', 'message' => $warning];
        }

        return new ActionResultFramework(
            $success,
            self::CHANGED_FACTS,
            $flash,
            $ctPeriodId > 0 ? ['ct_period_id' => (string)$ctPeriodId] : []
        );
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
            'prepare_ct600' => 'The CT600 package was frozen and validated.',
            'approve_ct600' => 'The exact frozen CT600 package was approved.',
            'submit_ct600' => 'The CT600 exchange was started. An acknowledgement is not final acceptance.',
            'poll_ct600' => 'The HMRC CT600 exchange status was checked.',
            'delete_ct600_response' => 'The retained HMRC response was deleted from the Transaction Engine.',
            default => 'HMRC CT600 filing updated.',
        };
    }

    /** @return list<string> */
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
                $text = trim((string)($message['message'] ?? $message['text'] ?? $message['detail'] ?? $message['error'] ?? ''));
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
