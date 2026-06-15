<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _smtp_settingsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'smtp_settings';
    }

    public function title(): string
    {
        return 'SMTP Settings';
    }

    public function render(array $context): string
    {
        $smtp = (array)(AppConfigurationStore::config()['smtp'] ?? []);
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');
        $hasPassword = trim((string)($smtp['password'] ?? '')) !== '';
        $testFormId = 'smtp-test-form';

        return '<form method="post" action="?page=settings" data-ajax="true" class="form-grid">
            ' . $this->hiddenFields($context) . '
            <input type="hidden" name="card_action" value="SmtpSettings">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
            <fieldset class="form-row full settings-fieldset">
                <legend>Outbound email</legend>
                <label class="checkbox-item" for="smtp-enabled">
                    <input type="hidden" name="smtp_enabled" value="0">
                    <input id="smtp-enabled" name="smtp_enabled" type="checkbox" value="1" data-submit-on-change="true"' . (!empty($smtp['enabled']) ? ' checked' : '') . '>
                    <span class="checkbox-copy"><span>Enable email invitations.</span></span>
                </label>
                <label class="checkbox-item" for="smtp-development-mode">
                    <input type="hidden" name="smtp_development_mode" value="0">
                    <input id="smtp-development-mode" name="smtp_development_mode" type="checkbox" value="1" data-submit-on-change="true"' . (!empty($smtp['development_mode']) ? ' checked' : '') . '>
                    <span class="checkbox-copy"><span>Test mode: generate email messages but do not send.</span></span>
                </label>
                <div class="form-grid">
                    <div class="form-row full smtp-connection-row">
                        ' . $this->compactSelect('smtp-transport', 'Transport:', 'smtp_transport', ['smtp' => 'SMTP', 'mail' => 'PHP mail()'], (string)($smtp['transport'] ?? 'smtp')) . '
                        ' . $this->compactSelect('smtp-encryption', 'Encryption:', 'smtp_encryption', ['none' => 'None', 'ssl_tls' => 'SSL/TLS', 'starttls' => 'STARTTLS'], (string)($smtp['encryption'] ?? 'starttls')) . '
                        ' . $this->compactSelect('smtp-auth-mode', 'Authentication:', 'smtp_auth_mode', ['none' => 'None', 'plain' => 'PLAIN', 'login' => 'LOGIN', 'cram_md5' => 'CRAM-MD5'], (string)($smtp['auth_mode'] ?? 'login')) . '
                        ' . $this->compactInput('smtp-port', 'Port:', 'smtp_port', (string)($smtp['port'] ?? 587), 'number', '1', '65535', '5') . '
                    </div>
                    <div class="form-row full smtp-settings-columns">
                        <div class="smtp-settings-column">
                            ' . $this->columnInput('smtp-host', 'Host', 'smtp_host', (string)($smtp['host'] ?? '')) . '
                            ' . $this->columnInput('smtp-username', 'SMTP username', 'smtp_username', (string)($smtp['username'] ?? '')) . '
                            ' . $this->columnInput('smtp-password', 'Password or token', 'smtp_password', '', 'password', $hasPassword ? 'Saved secret unchanged when blank' : '') . '
                        </div>
                        <div class="smtp-settings-column">
                            ' . $this->columnInput('smtp-from-address', 'From address', 'smtp_from_address', (string)($smtp['from_address'] ?? ''), 'email') . '
                            ' . $this->columnInput('smtp-from-name', 'From name', 'smtp_from_name', (string)($smtp['from_name'] ?? '')) . '
                        </div>
                    </div>
                    <div class="form-row full smtp-settings-actions">
                        <button class="button primary" type="submit">Save SMTP Settings</button>
                        <button class="button" type="submit" form="' . HelperFramework::escape($testFormId) . '" formnovalidate data-processing-text="Testing" data-processing-state="disabled">Test SMTP Connection</button>
                    </div>
                </div>
            </fieldset>
        </form>
        <form id="' . HelperFramework::escape($testFormId) . '" method="post" action="?page=settings" data-ajax="true">
            ' . $this->hiddenFields($context) . '
            <input type="hidden" name="card_action" value="SmtpTest">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
        </form>';
    }

    private function input(string $id, string $label, string $name, string $value, string $type = 'text', string $placeholder = ''): string
    {
        return '<div class="form-row half">
            <label for="' . HelperFramework::escape($id) . '">' . HelperFramework::escape($label) . '</label>
            <input class="input" id="' . HelperFramework::escape($id) . '" name="' . HelperFramework::escape($name) . '" type="' . HelperFramework::escape($type) . '" value="' . HelperFramework::escape($value) . '"' . ($placeholder !== '' ? ' placeholder="' . HelperFramework::escape($placeholder) . '"' : '') . '>
        </div>';
    }

    private function columnInput(string $id, string $label, string $name, string $value, string $type = 'text', string $placeholder = ''): string
    {
        return '<div class="smtp-settings-column-field">
            <label for="' . HelperFramework::escape($id) . '">' . HelperFramework::escape($label) . '</label>
            <input class="input" id="' . HelperFramework::escape($id) . '" name="' . HelperFramework::escape($name) . '" type="' . HelperFramework::escape($type) . '" value="' . HelperFramework::escape($value) . '"' . ($placeholder !== '' ? ' placeholder="' . HelperFramework::escape($placeholder) . '"' : '') . '>
        </div>';
    }

    private function compactInput(string $id, string $label, string $name, string $value, string $type = 'text', string $min = '', string $max = '', string $maxlength = ''): string
    {
        return '<div class="smtp-connection-field">
            <label for="' . HelperFramework::escape($id) . '">' . HelperFramework::escape($label) . '</label>
            <input class="input" id="' . HelperFramework::escape($id) . '" name="' . HelperFramework::escape($name) . '" type="' . HelperFramework::escape($type) . '" value="' . HelperFramework::escape($value) . '"' . ($min !== '' ? ' min="' . HelperFramework::escape($min) . '"' : '') . ($max !== '' ? ' max="' . HelperFramework::escape($max) . '"' : '') . ($maxlength !== '' ? ' maxlength="' . HelperFramework::escape($maxlength) . '"' : '') . '>
        </div>';
    }

    private function select(string $id, string $label, string $name, array $options, string $current): string
    {
        $html = '<div class="form-row half">
            <label for="' . HelperFramework::escape($id) . '">' . HelperFramework::escape($label) . '</label>
            <select class="selector-input" id="' . HelperFramework::escape($id) . '" name="' . HelperFramework::escape($name) . '">';

        foreach ($options as $value => $optionLabel) {
            $html .= '<option value="' . HelperFramework::escape((string)$value) . '"' . ((string)$value === $current ? ' selected' : '') . '>' . HelperFramework::escape((string)$optionLabel) . '</option>';
        }

        return $html . '</select></div>';
    }

    private function compactSelect(string $id, string $label, string $name, array $options, string $current): string
    {
        $html = '<div class="smtp-connection-field">
            <label for="' . HelperFramework::escape($id) . '">' . HelperFramework::escape($label) . '</label>
            <select class="selector-input" id="' . HelperFramework::escape($id) . '" name="' . HelperFramework::escape($name) . '">';

        foreach ($options as $value => $optionLabel) {
            $html .= '<option value="' . HelperFramework::escape((string)$value) . '"' . ((string)$value === $current ? ' selected' : '') . '>' . HelperFramework::escape((string)$optionLabel) . '</option>';
        }

        return $html . '</select></div>';
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
