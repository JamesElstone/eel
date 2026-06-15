<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _logs extends PageContextFramework
{
    public function id(): string
    {
        return 'logs';
    }

    public function title(): string
    {
        return 'Logs';
    }

    public function subtitle(): string
    {
        return 'Review system audit and history activity recorded by the application.';
    }

    public function services(): array
    {
        return parent::services();
    }

    public function cards(): array
    {
        return [
            'activity',
            'signup_token_lockouts',
            'signup_verification_lockouts',
            'user_account_audit_log',
            'user_logon_history_log',
        ];
    }

    protected function handlePageAction(
        RequestFramework $request,
        PageServiceFramework $services
    ): ActionResultFramework {
        $sessionAuthenticationService = new SessionAuthenticationService();
        $sessionAuthenticationService->startSession();

        if (!$sessionAuthenticationService->isValidCsrfToken((string)$request->input('csrf_token', ''))) {
            return new ActionResultFramework(false, ['page.context'], [[
                'type' => 'error',
                'message' => 'Your security token expired. Please refresh the page and try again.',
            ]]);
        }

        $currentUserId = $this->currentUserIdFromSession($sessionAuthenticationService);
        $canResetSignupTokenLockouts = $currentUserId > 0
            && in_array('signup_token_lockouts', (new CardAccessFramework())->allowedCardsForUser($currentUserId, ['signup_token_lockouts']), true);
        $canResetSignupVerificationLockouts = $currentUserId > 0
            && in_array('signup_verification_lockouts', (new CardAccessFramework())->allowedCardsForUser($currentUserId, ['signup_verification_lockouts']), true);

        return match ($request->action()) {
            'logs-reset-signup-token-lockout' => $this->resetSignupTokenLockout($request, $canResetSignupTokenLockouts),
            'logs-reset-signup-verification-lockout' => $this->resetSignupVerificationLockout($request, $canResetSignupVerificationLockouts),
            default => ActionResultFramework::none(),
        };
    }

    protected function buildContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult
    ): array {
        $sessionAuthenticationService = new SessionAuthenticationService();
        $sessionAuthenticationService->startSession();

        return [
            'page' => [
                'page_id' => 'logs',
                'page_cards' => $this->cards(),
                'csrf_token' => $sessionAuthenticationService->csrfToken(),
            ],
        ];
    }

    private function resetSignupTokenLockout(RequestFramework $request, bool $canReset): ActionResultFramework
    {
        if (!$canReset) {
            return new ActionResultFramework(false, ['signup.token.lockouts'], [[
                'type' => 'error',
                'message' => 'You do not have permission to reset signup token lockouts.',
            ]]);
        }

        $clearedRows = (new SignupTokenRateLimitService())->clearBlock((string)$request->input('client_ip', ''));
        $message = $clearedRows > 0
            ? 'Signup token lockout reset. The client can try the invitation link again.'
            : 'No matching signup token lockout was found.';

        return ActionResultFramework::success(['signup.token.lockouts'], [[
            'type' => 'success',
            'message' => $message,
        ]]);
    }

    private function resetSignupVerificationLockout(RequestFramework $request, bool $canReset): ActionResultFramework
    {
        if (!$canReset) {
            return new ActionResultFramework(false, ['signup.verification.lockouts'], [[
                'type' => 'error',
                'message' => 'You do not have permission to reset signup verification lockouts.',
            ]]);
        }

        $clearedRows = (new SignupVerificationRateLimitService())->clearBlock(
            (string)$request->input('scope_type', ''),
            (string)$request->input('scope_key', '')
        );
        $message = $clearedRows > 0
            ? 'Signup verification lockout reset. The client can try verification again.'
            : 'No matching signup verification lockout was found.';

        return ActionResultFramework::success(['signup.verification.lockouts'], [[
            'type' => 'success',
            'message' => $message,
        ]]);
    }

    private function currentUserIdFromSession(SessionAuthenticationService $sessionAuthenticationService): int
    {
        $currentDeviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));

        return $sessionAuthenticationService->authenticatedUserId($currentDeviceId);
    }
}
