<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _activityCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'activity';
    }

    public function services(): array
    {
        return [];
    }

    public function invalidationFacts(): array
    {
        return ['dashboard.feed'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $itemsHtml = '';
        $page = (array)($context['page'] ?? []);

        foreach ((array)($page['activity'] ?? []) as $item) {
            $itemsHtml .= '<div class="list-item">
                <strong>' . HelperFramework::escape((string)($item['title'] ?? '')) . '</strong>
                <span>' . HelperFramework::escape((string)($item['detail'] ?? '')) . '</span>
            </div>';
        }

        return '<div class="card">
            <div class="card-header card-header-has-eyebrow">
                <h2 class="card-title">What this example proves</h2>
                <p class="eyebrow card-header-corner-eyebrow">Card: ' . HelperFramework::escape($this->key()) . '</p>
            </div>
            <div class="card-body">
                <div class="list">' . $itemsHtml . '</div>
            </div>
        </div>';
    }
}

