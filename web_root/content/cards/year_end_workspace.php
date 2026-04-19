<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _year_end_workspaceCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'year_end_workspace';
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
        return '<section class="eel-card-fragment" data-card="year-end-workspace" style="grid-column: 1 / -1;">
            <div id="year-end-app">
                <div class="helper">Loading Year End To Do checklist...</div>
            </div>
        </section>';
    }
}
