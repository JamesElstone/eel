<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SmtpSettingsAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $session = new SessionAuthenticationService();
        $session->startSession();

        if (!$this->canUpdate($session) || !$session->isValidCsrfToken((string)$request->input('csrf_token', ''))) {
            return new ActionResultFramework(false, ['smtp.settings'], [[
                'type' => 'error',
                'message' => 'You do not have permission to update SMTP settings, or your security token expired.',
            ]]);
        }

        $previousSmtp = (array)(AppConfigurationStore::config()['smtp'] ?? []);
        $existingPassword = trim((string)AppConfigurationStore::get('smtp.password', ''));
        $submittedPassword = trim((string)$request->input('smtp_password', ''));
        $enabled = $this->checkboxValue($request, 'smtp_enabled');
        $developmentMode = $this->checkboxValue($request, 'smtp_development_mode');
        $settings = [
            'enabled' => $enabled,
            'transport' => $this->transport((string)$request->input('smtp_transport', 'smtp')),
            'host' => trim((string)$request->input('smtp_host', '')),
            'port' => $this->port($request->input('smtp_port', 587)),
            'username' => trim((string)$request->input('smtp_username', '')),
            'password' => $submittedPassword !== '' ? $submittedPassword : ($existingPassword !== '' ? '__unchanged__' : ''),
            'encryption' => $this->encryption((string)$request->input('smtp_encryption', 'starttls')),
            'auth_mode' => $this->authMode((string)$request->input('smtp_auth_mode', 'login')),
            'from_address' => strtolower(trim((string)$request->input('smtp_from_address', ''))),
            'from_name' => trim((string)$request->input('smtp_from_name', '')),
            'development_mode' => $developmentMode,
        ];

        AppConfigurationStore::setSmtpSettings($settings);

        return ActionResultFramework::success(['smtp.settings', 'add.user'], [[
            'type' => 'success',
            'message' => $this->successFlashMessage($previousSmtp, $settings),
        ]]);
    }

    private function canUpdate(SessionAuthenticationService $session): bool
    {
        $deviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));
        $userId = $session->authenticatedUserId($deviceId);

        return $userId > 0 && in_array('smtp_settings', (new CardAccessFramework())->allowedCardsForUser($userId, ['smtp_settings']), true);
    }

    private function checkboxValue(RequestFramework $request, string $name): bool
    {
        $value = $request->input($name, '0');
        if (is_array($value)) {
            $value = end($value);
        }

        return (string)$value === '1';
    }

    private function transport(string $transport): string
    {
        return strtolower(trim($transport)) === 'mail' ? 'mail' : 'smtp';
    }

    private function encryption(string $encryption): string
    {
        $encryption = strtolower(trim($encryption));

        return in_array($encryption, ['none', 'ssl_tls', 'starttls'], true) ? $encryption : 'starttls';
    }

    private function authMode(string $authMode): string
    {
        $authMode = strtolower(str_replace('-', '_', trim($authMode)));

        return in_array($authMode, ['none', 'plain', 'login', 'cram_md5'], true) ? $authMode : 'login';
    }

    private function port(mixed $port): int
    {
        return max(1, min(65535, (int)$port));
    }

    private function successFlashMessage(array $previousSmtp, array $settings): string
    {
        $messages = [];
        if ($this->connectionSettingsChanged($previousSmtp, $settings)) {
            $messages[] = 'SMTP connection settings updated: '
                . 'transport ' . $this->transportLabel((string)$settings['transport'])
                . ', encryption ' . $this->encryptionLabel((string)$settings['encryption'])
                . ', authentication ' . $this->authModeLabel((string)$settings['auth_mode'])
                . ', port ' . (string)$settings['port']
                . '.';
        }

        if ((string)($previousSmtp['host'] ?? '') !== (string)($settings['host'] ?? '')
            || (string)($previousSmtp['username'] ?? '') !== (string)($settings['username'] ?? '')) {
            $messages[] = 'SMTP host or username updated.';
        }

        if ((string)($previousSmtp['from_address'] ?? '') !== (string)($settings['from_address'] ?? '')
            || (string)($previousSmtp['from_name'] ?? '') !== (string)($settings['from_name'] ?? '')) {
            $messages[] = 'Email sender details updated.';
        }

        $messages[] = 'Email invitations are ' . (!empty($settings['enabled']) ? 'enabled' : 'disabled') . '.';
        $messages[] = 'Test mode is ' . (!empty($settings['development_mode']) ? 'enabled' : 'disabled') . '.';

        return implode(' ', $messages);
    }

    private function connectionSettingsChanged(array $previousSmtp, array $settings): bool
    {
        foreach (['transport', 'encryption', 'auth_mode', 'port'] as $key) {
            if ((string)($previousSmtp[$key] ?? '') !== (string)($settings[$key] ?? '')) {
                return true;
            }
        }

        return false;
    }

    private function transportLabel(string $transport): string
    {
        return $transport === 'mail' ? 'PHP mail()' : 'SMTP';
    }

    private function encryptionLabel(string $encryption): string
    {
        return match ($encryption) {
            'none' => 'None',
            'ssl_tls' => 'SSL/TLS',
            default => 'STARTTLS',
        };
    }

    private function authModeLabel(string $authMode): string
    {
        return match ($authMode) {
            'none' => 'None',
            'plain' => 'PLAIN',
            'cram_md5' => 'CRAM-MD5',
            default => 'LOGIN',
        };
    }
}
