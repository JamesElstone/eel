<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SmtpTestAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $session = new SessionAuthenticationService();
        $session->startSession();

        $currentUserId = $this->currentUserId($session);
        if ($currentUserId <= 0 || !$this->canUpdate($currentUserId) || !$session->isValidCsrfToken((string)$request->input('csrf_token', ''))) {
            return new ActionResultFramework(false, ['smtp.settings'], [[
                'type' => 'error',
                'message' => 'You do not have permission to test SMTP settings, or your security token expired.',
            ]]);
        }

        $developmentMode = (bool)AppConfigurationStore::get('smtp.development_mode', true);

        try {
            $emailService = new EmailService();
            $currentUser = (new UserManagementService())->currentUserDetails($currentUserId);
            if ($developmentMode) {
                $emailService->testSmtpConnection();
            } else {
                $toAddress = strtolower(trim((string)($currentUser['email_address'] ?? '')));
                $emailService->sendTemplateTestEmail(
                    $toAddress,
                    $this->testLink($request),
                    $this->testExpiresAt(),
                    (string)($currentUser['display_name'] ?? ''),
                    (string)($currentUser['display_name'] ?? ''),
                    (string)($currentUser['email_address'] ?? ''),
                    (string)($currentUser['mobile_number'] ?? '')
                );
            }
        } catch (Throwable $exception) {
            return new ActionResultFramework(false, ['smtp.settings'], [[
                'type' => 'error',
                'message' => $developmentMode
                    ? 'SMTP connection test failed: ' . $exception->getMessage()
                    : 'SMTP test email failed: ' . $exception->getMessage(),
            ]]);
        }

        return ActionResultFramework::success(['smtp.settings'], [[
            'type' => 'success',
            'message' => $developmentMode
                ? 'SMTP connection test succeeded.'
                : 'SMTP test email sent to ' . strtolower(trim((string)($currentUser['email_address'] ?? ''))) . '.',
        ]]);
    }

    private function currentUserId(SessionAuthenticationService $session): int
    {
        $deviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));

        return $session->authenticatedUserId($deviceId);
    }

    private function canUpdate(int $userId): bool
    {
        return $userId > 0 && in_array('smtp_settings', (new CardAccessFramework())->allowedCardsForUser($userId, ['smtp_settings']), true);
    }

    private function testLink(RequestFramework $request): string
    {
        $baseUrl = (new AccountInviteService())->buildBaseUrl($request);

        return $baseUrl !== '' ? $baseUrl . '/signup/?test_email=1' : 'test-email';
    }

    private function testExpiresAt(): string
    {
        $expiryDays = max(1, min(31, (int)AppConfigurationStore::get('invitation.expiry_days', 5)));

        return (new DateTimeImmutable('now'))->modify('+' . $expiryDays . ' days')->format('Y-m-d H:i:s');
    }
}
