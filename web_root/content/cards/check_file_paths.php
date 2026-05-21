<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _check_file_pathsCard extends CardBaseFramework
{

    public function helper(array $context): string {
        return 'Use Test Paths to check the configured storage directories.';
    }

    public function render(array $context): string
    {
        $pathStatus = (array)($context['path_status'] ?? []);
        $items = array_values(array_filter((array)($pathStatus['items'] ?? []), 'is_array'));
        $pathDebug = (bool)($pathStatus['debug'] ?? false);
        $contentHtml = '';

        if ($items !== [] && $pathDebug) {
            $cardsHtml = '<div class="helper">Number of items checked is ' . count($items) . ':</div>';

            foreach ($items as $item) {
                $state = $this->normaliseItemState($item['state'] ?? false);
                $detailPaths = array_values(array_unique(array_filter(array_map(
                    'strval',
                    (array)($item['paths'] ?? [$item['path'] ?? ''])
                ))));
                $pathsHtml = '';

                foreach ($detailPaths as $detailPath) {
                    $pathsHtml .= '<div class="helper">Path: ' . HelperFramework::escape($detailPath) . '</div>';
                }

                $cardsHtml .= '<div class="path-detail-card">
                    <strong>
                        <span class="status-indicator">
                            <span class="status-square ' . HelperFramework::escape($state) . '"></span>
                            ' . HelperFramework::escape((string)($item['title'] ?? 'Path')) . ': ' . HelperFramework::escape($this->pathStateLabel($state)) . '
                        </span>
                    </strong>'
                    . $pathsHtml . '
                    <span>' . HelperFramework::escape((string)($item['detail'] ?? '')) . '</span>
                </div>';
            }

            $contentHtml = '
                <div class="path-status ' . HelperFramework::escape((string)($pathStatus['state'] ?? 'warn')) . '">
                    <div class="helper">' . HelperFramework::escape((string)($pathStatus['message'] ?? '')) . '</div>
                    <div class="path-detail-grid">' . $cardsHtml . '</div>
                </div>';
        } elseif ($items !== []) {
            $metaHtml = '<div class="helper">Number of items is ' . count($items) . '</div>';

            foreach ($items as $item) {
                $state = $this->normaliseItemState($item['state'] ?? false);

                $metaHtml .= '<div class="path-meta-item">
                    <span class="status-indicator">
                        <span class="status-square ' . HelperFramework::escape($state) . '"></span>
                        ' . HelperFramework::escape((string)($item['title'] ?? 'Path')) . ': ' . HelperFramework::escape($this->pathStateLabel($state)) . '
                    </span>
                </div>';
            }

            $contentHtml = '
                <div class="path-status ' . HelperFramework::escape((string)($pathStatus['state'] ?? 'warn')) . '">
                    <div class="path-meta">' . $metaHtml . '</div>
                </div>';
        } else {
            $contentHtml = '';
        }

        return '
            <div class="stack">
                <form method="post" data-ajax="true">
                    <input type="hidden" name="card_action" value="AppPaths">
                    <div class="inline-actions">
                        <button class="button primary" type="submit" name="intent" value="check">Test Paths</button>
                        <button class="button primary" type="submit" name="intent" value="create">Create Paths</button>
                    </div>
                </form>
            ' . $contentHtml . '
            </div>
        ';
    }

    private function pathStateLabel(string $state): string
    {
        return match ($state) {
            'ok' => 'OK',
            'bad' => 'Needs attention',
            default => 'Check required',
        };
    }

    private function normaliseItemState(mixed $state): string
    {
        if ($state === 'warn') {
            return 'warn';
        }

        return $state === true || $state === 'ok' ? 'ok' : 'bad';
    }
}
