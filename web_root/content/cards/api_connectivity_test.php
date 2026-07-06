<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _api_connectivity_testCard extends CardBaseFramework
{
    public function render(array $context): string
    {
        $apiCredentialCheckResults = (array)($context['api_credential_check_results'] ?? []);
        $messages = (array)($context['api_mode_messages'] ?? []);
        $errors = (array)($context['api_mode_errors'] ?? []);

        // 
        // Button enabler on data change functions in a section tag.
        // Now not used in this card as target button removed and ajax saving is occuring.
        // 
        // data-state-fields="companies_house_api_mode,hmrc_api_mode" data-state-target="save_api_mode_button" 
        // 

        return '
            <div class="stack">
                <div>
                    <form method="post" data-ajax="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                        <input type="hidden" name="card_action" value="ApiMode">
                        <button class="button primary" type="submit" name="intent" value="check">Check Credentials</button>
                    </form>
                </div>
                '   . $this->renderApiModeNotices($messages, $errors)
                    . $this->renderApiCredentialCheckResults($apiCredentialCheckResults)
                    . '
            </div>
        ';
    }

    public function helper(array $context): string {
        return 'Check HMRC and Companies House APIs';
    }

    public function title(): string {
        return 'Check API Connectivity and Credentials';
    }

    private function renderApiModeNotices(array $messages, array $errors): string
    {
        $html = '';

        foreach ($messages as $message) {
            $html .= '<div class="notice success">' . HelperFramework::escape((string)$message) . '</div>';
        }

        foreach ($errors as $error) {
            $html .= '<div class="notice error">' . HelperFramework::escape((string)$error) . '</div>';
        }

        if ($html === '') {
            return '';
        }

        return '<div class="card">' . $html . '</div>';

    }

    private function renderApiCredentialCheckResults(array $results): string
    {
        if ($results === []) {
            return '';
        }

        $itemsHtml = '';
        foreach ($results as $item) {
            if (!is_array($item)) {
                continue;
            }

            $state = (string)($item['state'] ?? (!empty($item['ok']) ? 'ok' : 'bad'));
            $label = (string)($item['label'] ?? $item['title'] ?? 'Credential check');
            $detail = (string)($item['detail'] ?? $item['message'] ?? '');
            $statusText = match ($state) {
                'ok' => 'OK',
                'warn' => 'Warning',
                default => 'Needs attention',
            };

            $itemsHtml .= '<div class="list-item">
                <strong>' . HelperFramework::escape($label) . '</strong>
                <span class="status-indicator"><span class="status-square ' . HelperFramework::escape($state) . '"></span>' . HelperFramework::escape($statusText) . '</span>
                <span>' . HelperFramework::escape($detail) . '</span>
            </div>';
        }

        if ($itemsHtml === '') {
            return '';
        }

        return '<div class="card"><div class="list">' . $itemsHtml . '</div></div>';
    }
}
