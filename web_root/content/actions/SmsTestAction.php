<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SmsTestAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $session = new SessionAuthenticationService();
        $session->startSession();

        $currentUserId = $this->currentUserId($session);
        if ($currentUserId <= 0 || !$this->canUpdate($currentUserId) || !$session->isValidCsrfToken((string)$request->input('csrf_token', ''))) {
            return new ActionResultFramework(false, ['sms.settings'], [[
                'type' => 'error',
                'message' => 'You do not have permission to test SMS settings, or your security token expired.',
            ]]);
        }

        $currentUser = (new UserManagementService())->currentUserDetails($currentUserId);
        $mobileNumber = trim((string)($currentUser['mobile_number'] ?? ''));
        $disabledReason = $this->testDisabledReason($mobileNumber);
        if ($disabledReason !== '') {
            return new ActionResultFramework(false, ['sms.settings'], [[
                'type' => 'error',
                'message' => $disabledReason,
            ]]);
        }

        try {
            $result = (new SmsService())->sendInvite(
                $mobileNumber,
                $this->testLink($request),
                $this->testExpiresAt(),
                (string)($currentUser['display_name'] ?? ''),
                (string)($currentUser['display_name'] ?? ''),
                (string)($currentUser['email_address'] ?? ''),
                (string)($currentUser['mobile_number'] ?? '')
            );
        } catch (Throwable $exception) {
            return new ActionResultFramework(false, ['sms.settings'], [[
                'type' => 'error',
                'message' => 'Test SMS could not be sent: ' . $exception->getMessage(),
            ]]);
        }

        $developmentMode = !empty($result['development_mode']);

        return ActionResultFramework::success(['sms.settings'], [[
            'type' => 'success',
            'message' => $developmentMode
                ? 'Test SMS generated in test mode; no SMS was sent.'
                : 'Test SMS sent to ' . $mobileNumber . '.',
        ]]);
    }

    private function currentUserId(SessionAuthenticationService $session): int
    {
        $deviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));

        return $session->authenticatedUserId($deviceId);
    }

    private function canUpdate(int $userId): bool
    {
        return $userId > 0 && in_array('sms_settings', (new CardAccessFramework())->allowedCardsForUser($userId, ['sms_settings']), true);
    }

    private function testDisabledReason(string $mobileNumber): string
    {
        $mobileNumber = trim($mobileNumber);
        if ($mobileNumber === '') {
            return 'No mobile number for current user.';
        }

        $numeric = preg_replace('/\s+/', '', $mobileNumber) ?? '';
        if (str_starts_with($numeric, '+')) {
            $numeric = substr($numeric, 1);
        }

        if ($numeric === '' || preg_match('/^[0-9]+$/', $numeric) !== 1 || preg_match('/[1-9]/', $numeric) !== 1) {
            return 'Current user mobile number is not numeric.';
        }

        return '';
    }

    private function testLink(RequestFramework $request): string
    {
        $baseUrl = (new AccountInviteService())->buildBaseUrl($request);

        return $baseUrl !== '' ? $baseUrl . '/signup/?test_sms=1' : 'test-sms';
    }

    private function testExpiresAt(): string
    {
        $expiryDays = max(1, min(31, (int)AppConfigurationStore::get('invitation.expiry_days', 5)));

        return (new DateTimeImmutable('now'))->modify('+' . $expiryDays . ' days')->format('Y-m-d H:i:s');
    }
}
