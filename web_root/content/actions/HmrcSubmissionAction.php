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
    private const AUTOMATIC_CLEANUP_WAIT_LIMIT_SECONDS = 60;
    private const WAIT_PROGRESS_CHUNK_SECONDS = 5;

    private readonly Closure $sleeper;

    public function __construct(?callable $sleeper = null)
    {
        $this->sleeper = $sleeper === null
            ? static function (int $seconds): void {
                if ($seconds > 0) {
                    sleep($seconds);
                }
            }
            : Closure::fromCallable($sleeper);
    }

    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $changedFacts = ['hmrc.ct600.submissions', 'ct.filing', 'page.context'];
        $security = $this->securityContext($request);
        if (isset($security['error'])) {
            return $this->result(false, [(string)$security['error']], [], $changedFacts);
        }

        $intent = trim((string)$request->input('intent', ''));
        $companyId = (int)$request->input('company_id', 0);
        $accountingPeriodId = (int)$request->input('accounting_period_id', 0);
        $ctPeriodId = (int)$request->input('ct_period_id', 0);
        $submissionId = (int)$request->input('submission_id', 0);
        $exchangeId = (int)$request->input('exchange_id', 0);
        $requestEnvironment = strtoupper(trim((string)$request->input('request_environment', '')));

        $contextError = $this->accountingContextError($companyId, $accountingPeriodId);
        if ($contextError !== null) {
            return $this->result(false, [$contextError], [], $changedFacts);
        }
        if (!in_array($intent, [
            'hmrc_submit_test',
            'hmrc_submit_live',
            'hmrc_retry_test',
            'hmrc_retry_live',
            'hmrc_generate_request',
            'hmrc_download_request_artifact',
            'hmrc_reprocess_response',
            'hmrc_poll',
        ], true)) {
            return $this->result(false, ['Unknown Corporation Tax submission action.'], [], $changedFacts);
        }
        if (in_array($intent, ['hmrc_generate_request', 'hmrc_download_request_artifact'], true)
            && !(bool)AppConfigurationStore::get('developer_options', false)) {
            return $this->result(
                false,
                ['Developer options must be enabled to generate or download an HMRC GovTalk request artefact.'],
                [],
                $changedFacts
            );
        }
        if ($intent === 'hmrc_reprocess_response'
            && !(bool)AppConfigurationStore::get('developer_options', false)) {
            return $this->result(
                false,
                ['Developer Options must be enabled to reprocess an archived HMRC response.'],
                [],
                $changedFacts
            );
        }
        if (in_array($intent, ['hmrc_retry_test', 'hmrc_retry_live'], true)
            && !(bool)AppConfigurationStore::get('developer_options', false)) {
            return $this->result(
                false,
                ['Developer Options must be enabled to retry a definitive HMRC Gateway rejection.'],
                [],
                $changedFacts
            );
        }

        try {
            /** @var \eel_accounts\Service\HmrcCorporationTaxSubmissionService $service */
            $service = $services->get(\eel_accounts\Service\HmrcCorporationTaxSubmissionService::class);
            $actor = (int)$security['user_id'];
            if ($intent === 'hmrc_download_request_artifact') {
                $file = $service->requestArtifactForDownload(
                    $companyId,
                    $accountingPeriodId,
                    $ctPeriodId,
                    $requestEnvironment
                );
                if ((string)($file['source'] ?? '') === 'submitted'
                    && (int)($file['exchange_id'] ?? 0) > 0) {
                    (new \eel_accounts\Service\GovTalkTransmissionHistoryService())
                        ->recordEvidenceDownload(
                            $companyId,
                            (int)$file['exchange_id'],
                            'request',
                            (string)$actor
                        );
                } elseif ((string)($file['source'] ?? '') === 'generated'
                    && (int)($file['bundle_id'] ?? 0) > 0
                    && (int)($file['artifact_row_id'] ?? 0) > 0) {
                    (new \eel_accounts\Service\FilingEvidenceService())->recordEvent(
                        (int)$file['bundle_id'],
                        'hmrc_developer_request_downloaded',
                        'info',
                        (string)$actor,
                        'An administrator downloaded an immutable generated HMRC GovTalk request artefact.',
                        [
                            'environment' => (string)($file['environment'] ?? ''),
                            'sha256' => (string)($file['sha256'] ?? ''),
                        ],
                        (int)$file['artifact_row_id']
                    );
                }
                $this->streamRequestArtifact($file);
            }
            $progress = $services->actionProgress();
            @set_time_limit(0);
            $progress->report('Checking the selected HMRC transmission and CT Period…', 0);
            $periodStatus = $this->ctPeriodStatus($service, $companyId, $accountingPeriodId, $ctPeriodId);
            if (isset($periodStatus['error'])) {
                return $this->result(false, [(string)$periodStatus['error']], [], $changedFacts);
            }

            $report = static function (string $message, int $percent) use ($progress): void {
                $progress->report($message, $percent);
            };
            if ($intent === 'hmrc_generate_request') {
                $progress->report(
                    'Preparing the exact'
                        . ($requestEnvironment !== '' ? ' ' . $requestEnvironment : '')
                        . ' HMRC GovTalk request without transmitting it…',
                    8
                );
                $command = $service->generateRequestFile(
                    $companyId,
                    $ctPeriodId,
                    $actor,
                    $report,
                    $requestEnvironment !== '' ? $requestEnvironment : null
                );
            } elseif (in_array($intent, [
                'hmrc_submit_test', 'hmrc_submit_live',
                'hmrc_retry_test', 'hmrc_retry_live',
            ], true)) {
                $live = in_array($intent, ['hmrc_submit_live', 'hmrc_retry_live'], true);
                $retry = in_array($intent, ['hmrc_retry_test', 'hmrc_retry_live'], true);
                $progress->report(
                    $live
                        ? 'Preparing the approved return for LIVE HMRC transmission…'
                        : 'Preparing the approved return for HMRC test transmission…',
                    8
                );
                $command = !$live
                    ? $service->submitTest($companyId, $ctPeriodId, $actor, $report, $retry)
                    : $service->submitLive($companyId, $ctPeriodId, $actor, $report, $retry);
            } else {
                if ($submissionId <= 0) {
                    return $this->result(false, ['Select a pending HMRC submission to check.'], [], $changedFacts);
                }
                if ($intent === 'hmrc_reprocess_response') {
                    if ($exchangeId <= 0) {
                        return $this->result(
                            false,
                            ['Select the archived HMRC exchange to reprocess.'],
                            [],
                            $changedFacts
                        );
                    }
                    $progress->report(
                        'Preparing to reprocess the archived HMRC response without transmitting…',
                        10
                    );
                    $command = $service->reprocessArchivedResponse(
                        $companyId,
                        $accountingPeriodId,
                        $ctPeriodId,
                        $submissionId,
                        $exchangeId,
                        $actor,
                        $report
                    );
                } else {
                    $pending = (array)(($periodStatus['period'] ?? [])['pending_submission'] ?? []);
                    $authorisedSubmissionId = (int)($pending['submission_id'] ?? $pending['id'] ?? 0);
                    if ($authorisedSubmissionId <= 0 || $submissionId !== $authorisedSubmissionId) {
                        return $this->result(
                            false,
                            ['The selected HMRC conversation is not pending for this CT period.'],
                            [],
                            $changedFacts
                        );
                    }
                    $progress->report('Preparing to check the pending HMRC conversation…', 10);
                    $command = $this->pollWithAutomaticCleanup(
                        static fn(callable $phaseReport): array => $service->poll(
                            $submissionId,
                            $actor,
                            $phaseReport
                        ),
                        $report,
                        strtolower(trim((string)($pending['protocol_state'] ?? '')))
                    );
                }
            }
            if (!empty($command['success'])) {
                $completionMessage = match ($intent) {
                    'hmrc_generate_request' => 'The HMRC GovTalk request file is ready; nothing was transmitted.',
                    'hmrc_reprocess_response' =>
                        'Local HMRC response reprocessing is complete; no request was sent to HMRC.',
                    default => 'HMRC transmission processing is complete.',
                };
                $progress->report($completionMessage, 100);
            }
        } catch (Throwable $exception) {
            $command = ['success' => false, 'errors' => [$exception->getMessage()], 'warnings' => []];
        }

        $success = !empty($command['success']);
        return $this->result(
            $success,
            (array)($command['errors'] ?? []),
            $success ? [$this->successMessage($intent, $command)] : [],
            $changedFacts,
            (array)($command['warnings'] ?? [])
        );
    }

    /**
     * Advance one pending HMRC conversation and, when a status poll discovers
     * required cleanup, honour HMRC's delay and perform exactly one follow-up.
     *
     * @param callable(callable(string,int):void):array<string,mixed> $poll
     * @param callable(string,int):void $report
     * @return array<string,mixed>
     */
    private function pollWithAutomaticCleanup(
        callable $poll,
        callable $report,
        string $initialProtocolState
    ): array {
        $first = $poll($this->phaseReporter($report, 12, 70));
        $state = strtolower(trim((string)($first['protocol_state'] ?? '')));
        if ($initialProtocolState !== 'awaiting_poll' || $state !== 'delete_pending') {
            return $first;
        }

        $waitSeconds = max(0, (int)($first['poll_after_seconds'] ?? 0));
        if ($waitSeconds > self::AUTOMATIC_CLEANUP_WAIT_LIMIT_SECONDS) {
            $first['warnings'] = array_values(array_unique(array_merge(
                (array)($first['warnings'] ?? []),
                [
                    'HMRC returned the final result, but requested a wait of '
                        . $waitSeconds . ' seconds before conversation cleanup. '
                        . 'Use Complete HMRC Cleanup after that interval.',
                ]
            )));
            return $first;
        }

        $remaining = $waitSeconds;
        $report(
            'HMRC returned the final result and requires conversation cleanup. '
                . 'Waiting ' . $remaining . ' seconds before the delete request…',
            72
        );
        while ($remaining > 0) {
            $chunk = min(self::WAIT_PROGRESS_CHUNK_SECONDS, $remaining);
            ($this->sleeper)($chunk);
            $remaining -= $chunk;
            $elapsed = $waitSeconds - $remaining;
            $percent = 72 + (int)floor(($elapsed / max(1, $waitSeconds)) * 12);
            $report(
                $remaining > 0
                    ? 'Waiting ' . $remaining . ' more seconds before HMRC conversation cleanup…'
                    : 'HMRC cleanup wait complete; preparing the delete request…',
                min(84, $percent)
            );
        }

        $report('Sending the required HMRC conversation cleanup request…', 85);
        $cleanup = $poll($this->phaseReporter($report, 86, 98));
        $firstOutcome = strtolower(trim((string)($first['business_outcome'] ?? '')));
        $cleanup['warnings'] = array_values(array_unique(array_merge(
            (array)($first['warnings'] ?? []),
            (array)($cleanup['warnings'] ?? [])
        )));
        if ($firstOutcome === 'rejected') {
            $cleanup['success'] = false;
            $cleanup['errors'] = array_values(array_unique(array_merge(
                (array)($first['errors'] ?? []),
                (array)($cleanup['errors'] ?? [])
            )));
        }

        return $cleanup;
    }

    /** @return Closure(string,int):void */
    private function phaseReporter(callable $report, int $start, int $end): Closure
    {
        return static function (string $message, int $percent) use ($report, $start, $end): void {
            $mapped = $start + (int)floor((max(0, min(100, $percent)) / 100) * ($end - $start));
            $report($message, min($end, $mapped));
        };
    }

    /** @return array{user_id?:int,error?:string} */
    private function securityContext(RequestFramework $request): array
    {
        if (!$request->isPost()) {
            return ['error' => 'Corporation Tax submission actions require a POST request.'];
        }

        $csrfToken = trim((string)$request->input('csrf_token', ''));
        if ($csrfToken === '') {
            return ['error' => 'A valid security token is required for Corporation Tax submission actions.'];
        }

        try {
            $session = new SessionAuthenticationService();
            $session->startSession();
            if (!$session->isValidCsrfToken($csrfToken)) {
                return ['error' => 'The security token expired. Refresh the page before trying again.'];
            }

            $deviceId = trim((string)AntiFraudService::instance($request)->requestValue('Client-Device-ID'));
            $userId = $session->authenticatedUserId($deviceId !== '' ? $deviceId : null);
            if ($userId <= 0) {
                return ['error' => 'Sign in before using Corporation Tax submission actions.'];
            }
            if ((new CardAccessFramework())->roleIdForUser($userId) !== RoleAssignmentService::ADMIN_ROLE_ID) {
                return ['error' => 'Only administrators can use Corporation Tax submission actions.'];
            }

            return ['user_id' => $userId];
        } catch (Throwable) {
            return ['error' => 'Corporation Tax filing authorisation could not be verified.'];
        }
    }

    private function accountingContextError(int $companyId, int $accountingPeriodId): ?string
    {
        $context = new \eel_accounts\Service\AccountingContextService();
        $authorisedCompanyId = $context->authCompanyId();
        $authorisedAccountingPeriodId = $context->authAccountingPeriodId();

        if ($authorisedCompanyId <= 0 || $authorisedAccountingPeriodId <= 0) {
            return 'Select a company and accounting period before using Corporation Tax submission.';
        }
        if ($companyId !== $authorisedCompanyId || $accountingPeriodId !== $authorisedAccountingPeriodId) {
            return 'The submitted company or accounting period does not match the authenticated accounting context.';
        }
        return null;
    }

    private function ctPeriodStatus(
        \eel_accounts\Service\HmrcCorporationTaxSubmissionService $service,
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId
    ): array {
        if ($ctPeriodId <= 0) {
            return ['error' => 'Select a valid CT period.'];
        }
        $status = $service->status($companyId, $accountingPeriodId);
        if (empty($status['success']) && (array)($status['errors'] ?? []) !== []) {
            return ['error' => (string)((array)$status['errors'])[0]];
        }
        foreach ((array)($status['periods'] ?? []) as $period) {
            if ((int)($period['ct_period_id'] ?? $period['id'] ?? 0) === $ctPeriodId) {
                return ['period' => (array)$period];
            }
        }
        return ['error' => 'The selected CT period does not belong to the authenticated accounting period.'];
    }

    private function successMessage(string $intent, array $command): string
    {
        if ($intent === 'hmrc_generate_request') {
            $filename = trim((string)($command['filename'] ?? ''));
            $mode = strtoupper(trim((string)($command['mode'] ?? '')));
            $label = match ($mode) {
                'TEST' => 'TEST',
                'TIL' => 'Test-in-Live',
                'LIVE' => 'LIVE',
                default => '',
            };
            $existing = strtolower(trim((string)($command['status'] ?? ''))) === 'existing';
            return 'The HMRC' . ($label !== '' ? ' ' . $label : '')
                . ' GovTalk request artefact '
                . ($existing ? 'already existed; no new file was generated' : 'was generated without transmission')
                . ($filename !== '' ? ': ' . $filename : '.');
        }
        if ($intent === 'hmrc_reprocess_response') {
            return 'The archived HMRC response was reprocessed locally and its recorded result was applied. '
                . 'No request was sent to HMRC.';
        }
        $outcome = strtolower(trim((string)($command['business_outcome']
            ?? ($command['submission']['business_outcome'] ?? '')
            ?? '')));
        $mode = strtoupper(trim((string)($command['mode']
            ?? ($command['submission']['environment'] ?? ''))));
        $cleanupPending = strtolower(trim((string)($command['protocol_state']
            ?? ($command['submission']['protocol_state'] ?? '')))) === 'delete_pending';
        if ($outcome === 'sandbox_passed') {
            return 'HMRC Test accepted this filing body.'
                . ($cleanupPending ? ' Conversation cleanup remains pending.' : '');
        }
        if ($outcome === 'til_validated') {
            return 'HMRC Test in Live accepted this filing body.'
                . ($cleanupPending ? ' Conversation cleanup remains pending.' : '');
        }
        if ($outcome === 'live_accepted') {
            return 'HMRC accepted the Corporation Tax return.'
                . ($cleanupPending ? ' Conversation cleanup remains pending.' : '');
        }
        if ($outcome === 'accepted') {
            $message = match ($mode) {
                'TEST' => 'HMRC Test accepted this filing body.',
                'TIL' => 'HMRC Test in Live accepted this filing body.',
                default => 'HMRC accepted the Corporation Tax return.',
            };
            return $message . ($cleanupPending ? ' Conversation cleanup remains pending.' : '');
        }
        if (!empty($command['needs_poll'])) {
            return 'HMRC acknowledged the submission. Use Check Submission Status after the requested polling interval.';
        }
        return match ($intent) {
            'hmrc_submit_test' => $mode === 'TEST'
                ? 'The HMRC Test submission was processed.'
                : 'The Test in Live submission was processed.',
            'hmrc_submit_live' => 'The LIVE Corporation Tax submission was processed.',
            'hmrc_retry_test' => $mode === 'TEST'
                ? 'The HMRC Test retry was processed.'
                : 'The Test in Live retry was processed.',
            'hmrc_retry_live' => 'The LIVE Corporation Tax retry was processed.',
            default => 'The latest HMRC submission status was retrieved.',
        };
    }

    /** @param array<string,mixed> $file */
    private function streamRequestArtifact(array $file): never
    {
        $path = (string)($file['path'] ?? '');
        $filename = str_replace(['"', "\r", "\n"], '', (string)($file['filename'] ?? ''));
        if ($path === '' || $filename === '' || !is_file($path)) {
            throw new RuntimeException('The HMRC GovTalk request artefact is unavailable.');
        }
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        $size = filesize($path);
        if (is_int($size)) {
            header('Content-Length: ' . $size);
        }
        readfile($path);
        exit;
    }

    private function result(
        bool $success,
        array $errors,
        array $messages,
        array $changedFacts,
        array $warnings = []
    ): ActionResultFramework {
        $flash = [];
        if ($success) {
            foreach ($messages !== [] ? $messages : ['Corporation Tax submission updated.'] as $message) {
                $flash[] = ['type' => 'success', 'message' => (string)$message];
            }
        } else {
            foreach ($errors !== [] ? $errors : ['The Corporation Tax submission action failed.'] as $error) {
                $flash[] = ['type' => 'error', 'message' => (string)$error];
            }
        }
        foreach ($warnings as $warning) {
            $flash[] = ['type' => 'warning', 'message' => (string)$warning];
        }
        return new ActionResultFramework($success, $changedFacts, $flash);
    }

}
