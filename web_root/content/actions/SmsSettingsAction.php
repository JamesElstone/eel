<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SmsSettingsAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $session = new SessionAuthenticationService();
        $session->startSession();

        if (!$this->canUpdate($session) || !$session->isValidCsrfToken((string)$request->input('csrf_token', ''))) {
            return new ActionResultFramework(false, ['sms.settings'], [[
                'type' => 'error',
                'message' => 'You do not have permission to update SMS settings, or your security token expired.',
            ]]);
        }

        $existingToken = trim((string)AppConfigurationStore::get('sms.auth_token', ''));
        $submittedToken = trim((string)$request->input('sms_auth_token', ''));
        $enabled = $this->checkboxValue($request, 'sms_enabled');
        $developmentMode = $this->checkboxValue($request, 'sms_development_mode');

        AppConfigurationStore::setSmsSettings([
            'enabled' => $enabled,
            'api_url' => trim((string)$request->input('sms_api_url', '')),
            'method' => 'POST',
            'auth_header' => trim((string)$request->input('sms_auth_header', '')),
            'auth_token' => $submittedToken !== '' ? $submittedToken : ($existingToken !== '' ? '__unchanged__' : ''),
            'development_mode' => $developmentMode,
        ]);

        return ActionResultFramework::success(['sms.settings', 'add.user'], [[
            'type' => 'success',
            'message' => $this->successFlashMessage($enabled, $developmentMode),
        ]]);
    }

    private function canUpdate(SessionAuthenticationService $session): bool
    {
        $deviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));
        $userId = $session->authenticatedUserId($deviceId);

        return $userId > 0 && in_array('sms_settings', (new CardAccessFramework())->allowedCardsForUser($userId, ['sms_settings']), true);
    }

    private function checkboxValue(RequestFramework $request, string $name): bool
    {
        $value = $request->input($name, '0');
        if (is_array($value)) {
            $value = end($value);
        }

        return (string)$value === '1';
    }

    private function successFlashMessage(bool $enabled, bool $developmentMode): string
    {
        return 'SMS settings updated. SMS invitations are '
            . ($enabled ? 'enabled' : 'disabled')
            . '. Development/test mode is '
            . ($developmentMode ? 'enabled' : 'disabled')
            . '.';
    }
}
