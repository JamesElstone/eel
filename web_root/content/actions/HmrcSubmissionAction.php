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

        $contextError = $this->accountingContextError($companyId, $accountingPeriodId);
        if ($contextError !== null) {
            return $this->result(false, [$contextError], [], $changedFacts);
        }
        if (!in_array($intent, ['hmrc_submit_test', 'hmrc_submit_live', 'hmrc_poll'], true)) {
            return $this->result(false, ['Unknown Corporation Tax submission action.'], [], $changedFacts);
        }

        try {
            /** @var \eel_accounts\Service\HmrcCorporationTaxSubmissionService $service */
            $service = $services->get(\eel_accounts\Service\HmrcCorporationTaxSubmissionService::class);
            $periodStatus = $this->ctPeriodStatus($service, $companyId, $accountingPeriodId, $ctPeriodId);
            if (isset($periodStatus['error'])) {
                return $this->result(false, [(string)$periodStatus['error']], [], $changedFacts);
            }

            $actor = (int)$security['user_id'];
            if (in_array($intent, ['hmrc_submit_test', 'hmrc_submit_live'], true)) {
                $declaration = $this->declaration($request);
                $declarationErrors = $this->declarationErrors($declaration);
                if ($declarationErrors !== []) {
                    return $this->result(false, $declarationErrors, [], $changedFacts);
                }
                $command = $intent === 'hmrc_submit_test'
                    ? $service->submitTest($companyId, $ctPeriodId, $actor, $declaration)
                    : $service->submitLive($companyId, $ctPeriodId, $actor, $declaration);
            } else {
                if ($submissionId <= 0) {
                    return $this->result(false, ['Select a pending HMRC submission to check.'], [], $changedFacts);
                }
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
                $command = $service->poll($submissionId, $actor);
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

    private function declaration(RequestFramework $request): array
    {
        return [
            'declaration_name' => trim((string)$request->input('declaration_name', '')),
            'declaration_status' => trim((string)$request->input('declaration_status', '')),
            'declaration_confirmed' => $this->confirmed($request->input('declaration_confirmed', null)),
            'authority_confirmed' => $this->confirmed($request->input('authority_confirmed', null)),
            'supplementary_scope_confirmed' => $this->confirmed($request->input('supplementary_scope_confirmed', null)),
            'original_unfiled_confirmed' => $this->confirmed($request->input('original_unfiled_confirmed', null)),
        ];
    }

    private function declarationErrors(array $declaration): array
    {
        $errors = [];
        if ((string)($declaration['declaration_name'] ?? '') === '') {
            $errors[] = 'Enter the declarant name.';
        }
        if ((string)($declaration['declaration_status'] ?? '') === '') {
            $errors[] = 'Enter the declarant status or capacity.';
        }
        $confirmations = [
            'original_unfiled_confirmed' => 'Confirm that this is an original, unfiled return.',
            'supplementary_scope_confirmed' => 'Confirm that no supplementary page is required.',
            'authority_confirmed' => 'Confirm your authority to file this return.',
            'declaration_confirmed' => 'Confirm the Corporation Tax return declaration.',
        ];
        foreach ($confirmations as $key => $message) {
            if (empty($declaration[$key])) {
                $errors[] = $message;
            }
        }
        return $errors;
    }

    private function confirmed(mixed $value): bool
    {
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function successMessage(string $intent, array $command): string
    {
        if (!empty($command['needs_poll'])) {
            return 'HMRC acknowledged the submission. Use Check HMRC status after the requested polling interval.';
        }
        $outcome = strtolower(trim((string)($command['business_outcome']
            ?? ($command['submission']['business_outcome'] ?? '')
            ?? '')));
        if (in_array($outcome, ['til_validated', 'sandbox_passed'], true)) {
            return 'HMRC Test in Live accepted this filing body.';
        }
        if ($outcome === 'live_accepted') {
            return 'HMRC accepted the Corporation Tax return.';
        }
        if ($outcome === 'accepted') {
            $mode = strtoupper(trim((string)($command['mode']
                ?? ($command['submission']['environment'] ?? ''))));
            return $intent === 'hmrc_submit_test' || $mode === 'TIL'
                ? 'HMRC Test in Live accepted this filing body.'
                : 'HMRC accepted the Corporation Tax return.';
        }
        return match ($intent) {
            'hmrc_submit_test' => 'The Test in Live submission was processed.',
            'hmrc_submit_live' => 'The LIVE Corporation Tax submission was processed.',
            default => 'The latest HMRC submission status was retrieved.',
        };
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
