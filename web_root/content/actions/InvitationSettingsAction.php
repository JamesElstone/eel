<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class InvitationSettingsAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $session = new SessionAuthenticationService();
        $session->startSession();

        if (!$this->canUpdate($session) || !$session->isValidCsrfToken((string)$request->input('csrf_token', ''))) {
            return new ActionResultFramework(false, ['invitation.settings'], [[
                'type' => 'error',
                'message' => 'You do not have permission to update invitation settings, or your security token expired.',
            ]]);
        }

        $previousConfig = AppConfigurationStore::config();
        $previousInvitation = is_array($previousConfig['invitation'] ?? null) ? $previousConfig['invitation'] : [];
        $expiryDays = max(1, min(31, (int)$request->input('invitation_expiry_days', 5)));
        $enabled = $this->checkboxValue($request, 'invitation_enabled');
        AppConfigurationStore::setInvitationSettings([
            'enabled' => $enabled,
            'expiry_days' => $expiryDays,
            'sms_template' => trim((string)$request->input('invitation_sms_template', '')),
            'email_subject_template' => trim((string)$request->input('invitation_email_subject_template', '')),
            'email_body_template' => trim((string)$request->input('invitation_email_body_template', '')),
        ]);

        return ActionResultFramework::success(['invitation.settings', 'add.user'], [[
            'type' => 'success',
            'message' => $this->successFlashMessage((bool)($previousInvitation['enabled'] ?? false), $enabled),
        ]]);
    }

    private function canUpdate(SessionAuthenticationService $session): bool
    {
        $deviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));
        $userId = $session->authenticatedUserId($deviceId);

        return $userId > 0 && in_array('invitation_settings', (new CardAccessFramework())->allowedCardsForUser($userId, ['invitation_settings']), true);
    }

    private function checkboxValue(RequestFramework $request, string $name): bool
    {
        $value = $request->input($name, '0');
        if (is_array($value)) {
            $value = end($value);
        }

        return (string)$value === '1';
    }

    private function successFlashMessage(bool $wasEnabled, bool $isEnabled): string
    {
        if ($wasEnabled !== $isEnabled) {
            return $isEnabled
                ? 'Invited account completion is now enabled.'
                : 'Invited account completion is now disabled.';
        }

        return $isEnabled
            ? 'Invitation settings updated. Invited account completion is enabled.'
            : 'Invitation settings updated. Invited account completion is disabled.';
    }
}
