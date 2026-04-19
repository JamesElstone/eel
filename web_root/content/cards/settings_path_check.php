<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _settings_path_checkCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'settings_path_check';
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
        $pathStatus = (array)($page['path_status'] ?? []);
        $showBasePathDetails = !empty($page['show_base_path_details']);
        $items = (array)($pathStatus['items'] ?? []);

        $contentHtml = '';
        if ($showBasePathDetails) {
            $cardsHtml = '';
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $detailPaths = array_values(array_unique(array_filter(array_map('strval', (array)($item['paths'] ?? [$item['path'] ?? ''])))));
                $pathsHtml = '';
                foreach ($detailPaths as $detailPath) {
                    $pathsHtml .= '<div class="helper">Path: ' . HelperFramework::escape($detailPath) . '</div>';
                }

                $cardsHtml .= '<div class="path-detail-card">
                    <strong>
                        <span class="status-indicator">
                            <span class="status-square ' . HelperFramework::escape((string)($item['state'] ?? 'warn')) . '"></span>
                            ' . HelperFramework::escape((string)($item['title'] ?? 'Path')) . ': ' . HelperFramework::escape($this->pathStateLabel((string)($item['state'] ?? 'warn'))) . '
                        </span>
                    </strong>'
                    . $pathsHtml . '
                    <span>' . HelperFramework::escape((string)($item['detail'] ?? '')) . '</span>
                </div>';
            }

            $contentHtml = '<div class="helper" style="margin-top:6px;">' . HelperFramework::escape((string)($pathStatus['message'] ?? '')) . '</div>
                <div class="path-detail-grid">' . $cardsHtml . '</div>';
        } else {
            $metaHtml = '';
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $metaHtml .= '<div class="path-meta-item">
                    <span class="status-indicator">
                        <span class="status-square ' . HelperFramework::escape((string)($item['state'] ?? 'warn')) . '"></span>
                        ' . HelperFramework::escape((string)($item['title'] ?? 'Path')) . ': ' . HelperFramework::escape($this->pathStateLabel((string)($item['state'] ?? 'warn'))) . '
                    </span>
                </div>';
            }

            $contentHtml = '<div class="path-meta">' . $metaHtml . '</div>';
        }

        return '<section class="eel-card-fragment" data-card="settings-path-check">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Path Check</h2>
                </div>
                <div class="card-body">
                    <div class="path-status ' . HelperFramework::escape((string)($pathStatus['state'] ?? 'warn')) . '">'
                        . $contentHtml . '
                        <div style="margin-top: 16px;">
                            <button class="button primary" type="submit" onclick="document.getElementById(\'settings_action_field\').value=\'test_paths\'" data-ajax-card-update="settings-path-check">Test Paths</button>
                        </div>
                    </div>
                </div>
            </div>
        </section>';
    }

    private function pathStateLabel(string $state): string
    {
        return match ($state) {
            'ok' => 'OK',
            'bad' => 'Needs attention',
            default => 'Check required',
        };
    }
}
