<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _settings_api_modeCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'settings_api_mode';
    }

    public function services(): array
    {
        return [];
    }

    public function invalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $page = (array)($context['page'] ?? []);
        $companiesHouseApiMode = strtoupper((string)($page['companies_house_api_mode'] ?? 'TEST'));
        $hmrcApiMode = strtoupper((string)($page['hmrc_api_mode'] ?? 'TEST'));
        $apiCredentialCheckResults = (array)($page['api_credential_check_results'] ?? []);

        return '<section class="eel-card-fragment" data-card="settings-api-mode">
            <div class="card settings-section" data-section="api-mode">
                <div class="card-header">
                    <h2 class="card-title">API Mode</h2>
                </div>
                <div class="card-body">
                    <div class="form-grid">
                        <div class="form-row">
                            <label for="companies_house_api_mode">Companies House Enviroment</label>
                            <select class="select" id="companies_house_api_mode" name="companies_house_api_mode">
                                <option value="TEST"' . ($companiesHouseApiMode === 'TEST' ? ' selected' : '') . '>TEST</option>
                                <option value="LIVE"' . ($companiesHouseApiMode === 'LIVE' ? ' selected' : '') . '>LIVE</option>
                            </select>
                        </div>
                        <div class="form-row">
                            <label for="hmrc_api_mode">HMRC Environment</label>
                            <select class="select" id="hmrc_api_mode" name="hmrc_api_mode">
                                <option value="TEST"' . ($hmrcApiMode === 'TEST' ? ' selected' : '') . '>TEST</option>
                                <option value="LIVE"' . ($hmrcApiMode === 'LIVE' ? ' selected' : '') . '>LIVE</option>
                            </select>
                        </div>
                    </div>
                    <div style="margin-top: 16px;">
                        <button class="button section-save-button" type="submit" disabled onclick="document.getElementById(\'settings_action_field\').value=\'save_api_mode\'" data-ajax-card-update="settings-api-mode">Save API Mode</button>
                        <button class="button primary" type="submit" onclick="document.getElementById(\'settings_action_field\').value=\'check_api_credentials\'" data-ajax-card-update="settings-api-mode">Check Credentials</button>
                    </div>'
                    . $this->renderApiCredentialCheckResults($apiCredentialCheckResults) . '
                </div>
            </div>
        </section>';
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

        return '<div class="list" style="margin-top: 16px;">' . $itemsHtml . '</div>';
    }
}
