<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _settings_setup_healthCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'settings_setup_health';
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
        $installationSetupHealthItems = (array)($page['installation_setup_health_items'] ?? []);

        $itemsHtml = '';
        foreach ($installationSetupHealthItems as $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemsHtml .= '<div class="list-item">
                <strong>' . HelperFramework::escape((string)($item['title'] ?? '')) . '</strong>
                <span class="status-indicator">
                    <span class="status-square ' . (!empty($item['ok']) ? 'ok' : 'bad') . '"></span>
                    ' . (!empty($item['ok']) ? 'OK' : 'Needs attention') . '
                </span>
                <span>' . HelperFramework::escape((string)($item['detail'] ?? '')) . '</span>
            </div>';
        }

        return '<section class="eel-card-fragment" data-card="settings-setup-health">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Installation Setup Health</h2>
                </div>
                <div class="card-body">
                    <div class="list">' . $itemsHtml . '</div>
                </div>
            </div>
        </section>';
    }
}
