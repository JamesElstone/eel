<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _web_environmentCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'web_environment';
    }

    public function title(): string
    {
        return 'Web Environment';
    }

    public function helper(array $context): string
    {
        return 'Configure public web URL handling and trusted reverse proxy client IP resolution.';
    }

    public function render(array $context): string
    {
        $config = AppConfigurationStore::config();
        $invitation = is_array($config['invitation'] ?? null) ? $config['invitation'] : [];
        $reverseProxy = is_array($config['reverse_proxy'] ?? null) ? $config['reverse_proxy'] : [];
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');

        return '<form method="post" action="?page=settings" data-ajax="true" class="form-grid">
            ' . $this->hiddenFields($context) . '
            <input type="hidden" name="card_action" value="WebEnvironment">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
            <fieldset class="form-row full settings-fieldset">
                <legend>Server Address</legend>
                <div class="form-grid">
                    <div class="form-row full">
                        <p class="helper">Current server IP address</p>
                        <p><code>' . HelperFramework::escape($this->serverIpAddress()) . '</code></p>
                    </div>
                </div>
            </fieldset>
            ' . $this->uploadLimitsHtml() . '
            <fieldset class="form-row full settings-fieldset">
                <legend>Web Environment</legend>
                <div class="form-grid">
                    ' . $this->input('web-base-url', 'External Base Web URL (Blank for Automatic)', 'web_base_url_override', (string)($invitation['base_url_override'] ?? ''), 'url') . '
                    ' . $this->textarea(
                        'web-trusted-proxy-ips',
                        'Trusted Reverse Proxy IPs',
                        'reverse_proxy_trusted_proxy_ips',
                        implode("\n", (array)($reverseProxy['trusted_proxy_ips'] ?? [])),
                        'One proxy IP address per line. Forwarded client IP headers are ignored unless REMOTE_ADDR matches one of these IPs.',
                        '<button class="button button-inline" type="submit" name="add_current_reverse_proxy" value="1">Add Current Reverse Proxy</button>'
                    ) . '
                    ' . $this->textarea('web-client-ip-headers', 'Client IP Headers', 'reverse_proxy_client_ip_headers', implode("\n", (array)($reverseProxy['client_ip_headers'] ?? [])), 'Checked in order when the request comes from a trusted proxy.') . '
                    <div class="form-row full">
                        <button class="button primary" type="submit">Save Web Environment</button>
                    </div>
                </div>
            </fieldset>
        </form>';
    }

    private function uploadLimitsHtml(): string
    {
        $limits = [
            'upload_max_filesize' => 'Upload max filesize',
            'post_max_size' => 'Post max size',
            'max_file_uploads' => 'Max file uploads',
            'memory_limit' => 'Memory limit',
        ];

        $html = '<fieldset class="form-row full settings-fieldset">
            <legend>Upload Limits</legend>
            <div class="web-environment-soft-panels">';

        foreach ($limits as $key => $label) {
            $value = ini_get($key);
            $html .= '<div class="web-environment-soft-panel">
                <p class="helper">' . HelperFramework::escape($label) . '</p>
                <p><code>' . HelperFramework::escape($value === false ? 'Unavailable' : (string)$value) . '</code></p>
            </div>';
        }

        $html .= '</div>
        </fieldset>';

        return $html;
    }

    private function serverIpAddress(): string
    {
        foreach (['SERVER_ADDR', 'LOCAL_ADDR'] as $serverKey) {
            $ip = trim((string)($_SERVER[$serverKey] ?? ''));
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                return mb_substr($ip, 0, 45);
            }
        }

        return 'Unavailable';
    }

    private function input(string $id, string $label, string $name, string $value, string $type = 'text'): string
    {
        return '<div class="form-row full">
            <label for="' . HelperFramework::escape($id) . '">' . HelperFramework::escape($label) . '</label>
            <input class="input" id="' . HelperFramework::escape($id) . '" name="' . HelperFramework::escape($name) . '" type="' . HelperFramework::escape($type) . '" value="' . HelperFramework::escape($value) . '">
        </div>';
    }

    private function textarea(string $id, string $label, string $name, string $value, string $helper, string $afterTextareaHtml = ''): string
    {
        return '<div class="form-row full">
            <label for="' . HelperFramework::escape($id) . '">' . HelperFramework::escape($label) . '</label>
            <p class="helper">' . HelperFramework::escape($helper) . '</p>
            <textarea class="input" id="' . HelperFramework::escape($id) . '" name="' . HelperFramework::escape($name) . '" rows="3">' . HelperFramework::escape($value) . '</textarea>
            ' . ($afterTextareaHtml !== '' ? '<div class="form-row-actions align-right">' . $afterTextareaHtml . '</div>' : '') . '
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
