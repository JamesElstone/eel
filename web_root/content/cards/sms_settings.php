<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _sms_settingsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'sms_settings';
    }

    public function title(): string
    {
        return 'SMS Settings';
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
        $sms = (array)(AppConfigurationStore::config()['sms'] ?? []);
        $currentUser = (array)(($context['services'] ?? [])['current_user'] ?? []);
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');
        $hasToken = trim((string)($sms['auth_token'] ?? '')) !== '';
        $testDisabledReason = $this->testDisabledReason((string)($currentUser['mobile_number'] ?? ''));
        $testFormId = 'sms-test-form';

        return '<form method="post" action="?page=settings" data-ajax="true" class="form-grid">
            ' . $this->hiddenFields($context) . '
            <input type="hidden" name="card_action" value="SmsSettings">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
            <fieldset class="form-row full settings-fieldset">
                <legend>SMS API</legend>
                <label class="checkbox-item" for="sms-enabled">
                    <input type="hidden" name="sms_enabled" value="0">
                    <input id="sms-enabled" name="sms_enabled" type="checkbox" value="1" data-submit-on-change="true"' . (!empty($sms['enabled']) ? ' checked' : '') . '>
                    <span class="checkbox-copy"><span>Enable SMS invitations.</span></span>
                </label>
                <label class="checkbox-item" for="sms-development-mode">
                    <input type="hidden" name="sms_development_mode" value="0">
                    <input id="sms-development-mode" name="sms_development_mode" type="checkbox" value="1" data-submit-on-change="true"' . (!empty($sms['development_mode']) ? ' checked' : '') . '>
                    <span class="checkbox-copy"><span>Test mode: generate SMS messages but do not send.</span></span>
                </label>
                <div class="form-grid">
                    ' . $this->input('sms-api-url', 'SMS Gateway URL', 'sms_api_url', (string)($sms['api_url'] ?? ''), 'text', true, 'http://hydrogen.int.elstone.net/sms-gateway/send/{telephone_number}', 'e.g. http://<sms gateway service api server>/send/{telephone_number}') . '
                    ' . $this->input('sms-auth-header', 'Auth Header Name', 'sms_auth_header', (string)($sms['auth_header'] ?? 'X-SMS-Gateway-Token'), 'text', false, 'X-SMS-Gateway-Token', 'Authorisation Header name to use, e.g. X-SMS-Gateway-Token') . '
                    ' . $this->input('sms-auth-token', 'Auth Token', 'sms_auth_token', '', 'password', false, $hasToken ? 'Saved token unchanged when blank' : '', 'Secret token to pass in the header when calling the SMS API service') . '
                    <div class="form-row full sms-settings-actions">
                        <button class="button primary" type="submit">Save SMS Settings</button>
                        ' . $this->testButton($testDisabledReason, $testFormId) . '
                    </div>
                </div>
            </fieldset>
        </form>
        <form id="' . HelperFramework::escape($testFormId) . '" method="post" action="?page=settings" data-ajax="true">
            ' . $this->hiddenFields($context) . '
            <input type="hidden" name="card_action" value="SmsTest">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
        </form>';
    }

    private function input(string $id, string $label, string $name, string $value, string $type = 'text', bool $full = false, string $placeholder = '', string $hint = ''): string
    {
        return '<div class="form-row ' . ($full ? 'full' : 'half') . '">
            <label for="' . HelperFramework::escape($id) . '">' . HelperFramework::escape($label) . '</label>
            ' . ($hint !== '' ? '<small class="helper">' . HelperFramework::escape($hint) . '</small>' : '') . '
            <input class="input" id="' . HelperFramework::escape($id) . '" name="' . HelperFramework::escape($name) . '" type="' . HelperFramework::escape($type) . '" value="' . HelperFramework::escape($value) . '"' . ($placeholder !== '' ? ' placeholder="' . HelperFramework::escape($placeholder) . '"' : '') . '>
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

    private function testButton(string $disabledReason, string $formId): string
    {
        $formAttribute = ' form="' . HelperFramework::escape($formId) . '"';
        if ($disabledReason !== '') {
            return '<span title="' . HelperFramework::escape($disabledReason) . '">
                <button class="button" type="submit"' . $formAttribute . ' disabled title="' . HelperFramework::escape($disabledReason) . '">Test SMS Gateway</button>
            </span>';
        }

        return '<button class="button" type="submit"' . $formAttribute . ' formnovalidate>Test SMS Gateway</button>';
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
}
