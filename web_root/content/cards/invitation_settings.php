<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _invitation_settingsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'invitation_settings';
    }

    public function title(): string
    {
        return 'Invitation Settings';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'current_user',
                'service' => UserManagementService::class,
                'method' => 'currentUserDetails',
            ],
        ];
    }

    public function render(array $context): string
    {
        $config = AppConfigurationStore::config();
        $invitation = is_array($config['invitation'] ?? null) ? $config['invitation'] : [];
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');

        return '<form method="post" action="?page=settings" data-ajax="true" class="form-grid">
            ' . $this->hiddenFields($context) . '
            <input type="hidden" name="card_action" value="InvitationSettings">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
            <fieldset class="form-row full settings-fieldset">
                <legend>Account completion invitations</legend>
                <label class="checkbox-item" for="invitation-enabled">
                    <input type="hidden" name="invitation_enabled" value="0">
                    <input id="invitation-enabled" name="invitation_enabled" type="checkbox" value="1" data-submit-on-change="true"' . (!empty($invitation['enabled']) ? ' checked' : '') . '>
                    <span class="checkbox-copy"><span>Enable invited account completion.</span></span>
                </label>
                <div class="form-grid">
                    ' . $this->input('invitation-expiry-days', 'Invitations Expires After This Many Days', 'invitation_expiry_days', (string)($invitation['expiry_days'] ?? 5), 'number', '1', '31') . '
                    ' . $this->templateVariablesPanel($context, $config) . '
                    ' . $this->input('invitation-email-subject', 'Email Invite Subject Template', 'invitation_email_subject_template', (string)($invitation['email_subject_template'] ?? ''), 'text', '', '', true) . '
                    ' . $this->textarea('invitation-email-body', 'Email Invite Body Template', 'invitation_email_body_template', (string)($invitation['email_body_template'] ?? '')) . '
                    ' . $this->textarea('invitation-sms-template', 'SMS Invite Template', 'invitation_sms_template', (string)($invitation['sms_template'] ?? '')) . '
                    <div class="form-row full">
                        <button class="button primary" type="submit">Save Invitation Settings</button>
                    </div>
                </div>
            </fieldset>
        </form>';
    }

    private function templateVariablesPanel(array $context, array $config): string
    {
        $currentUser = (array)(($context['services'] ?? [])['current_user'] ?? []);
        $displayName = trim((string)($currentUser['display_name'] ?? ''));
        $displayEmail = strtolower(trim((string)($currentUser['email_address'] ?? '')));
        $displayMobile = trim((string)($currentUser['mobile_number'] ?? ''));
        $appName = trim((string)($config['app_name'] ?? ''));
        if ($appName === '') {
            $appName = 'eelKit Framework';
        }

        return '<div class="form-row full">
            <fieldset class="panel-soft">
                <legend>Supported template variables</legend>
                <p class="helper">These variables can be used in the email subject, email body, and SMS template fields.</p>
                <p class="helper"><code>{display_name}</code> - The signed-in user sending the invitation (' . HelperFramework::escape($displayName !== '' ? $displayName : 'unavailable') . ').</p>
                <p class="helper"><code>{display_email}</code> - The signed-in user email address (' . HelperFramework::escape($displayEmail !== '' ? $displayEmail : 'unavailable') . ').</p>
                <p class="helper"><code>{display_mobile}</code> - The signed-in user mobile number (' . HelperFramework::escape($displayMobile !== '' ? $displayMobile : 'unavailable') . ').</p>
                <p class="helper"><code>{recipient_name}</code> - The user receiving the invitation.</p>
                <p class="helper"><code>{app_name}</code> - The configured name of this app (' . HelperFramework::escape($appName) . ').</p>
                <p class="helper"><code>{link}</code> - The invitation URL to respond to.</p>
                <p class="helper"><code>{expires_at}</code> - The date and time that the above link will expire by.</p>
            </fieldset>
        </div>';
    }

    private function input(string $id, string $label, string $name, string $value, string $type = 'text', string $min = '', string $max = '', bool $full = false): string
    {
        return '<div class="form-row ' . ($full ? 'full' : 'half') . '">
            <label for="' . HelperFramework::escape($id) . '">' . HelperFramework::escape($label) . '</label>
            <input class="input" id="' . HelperFramework::escape($id) . '" name="' . HelperFramework::escape($name) . '" type="' . HelperFramework::escape($type) . '" value="' . HelperFramework::escape($value) . '"' . ($min !== '' ? ' min="' . HelperFramework::escape($min) . '"' : '') . ($max !== '' ? ' max="' . HelperFramework::escape($max) . '"' : '') . '>
        </div>';
    }

    private function textarea(string $id, string $label, string $name, string $value): string
    {
        return '<div class="form-row full">
            <label for="' . HelperFramework::escape($id) . '">' . HelperFramework::escape($label) . '</label>
            <textarea class="input" id="' . HelperFramework::escape($id) . '" name="' . HelperFramework::escape($name) . '" rows="5">' . HelperFramework::escape($value) . '</textarea>
        </div>';
    }

    private function hiddenFields(array $context): string
    {
        $html = '';
        foreach ((array)($context['page']['page_cards'] ?? []) as $cardKey) {
            $html .= '<input type="hidden" name="cards[]" value="' . HelperFramework::escape((string)$cardKey) . '">';
        }

        return $html;
    }
}
