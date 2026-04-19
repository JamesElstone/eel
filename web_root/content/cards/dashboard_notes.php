<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _dashboard_notesCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'dashboard_notes';
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
        return '<div class="card">
            <div class="card-header">
                <h2 class="card-title">Notes</h2>
            </div>
            <div class="card-body">
                <div class="list">
                    <div class="list-item">
                        <strong>Director loan workflow</strong>
                        <span>Settings should point manual journals and future registers toward the director loan liability nominal.</span>
                    </div>
                    <div class="list-item">
                        <strong>VAT workflow</strong>
                        <span>Keep one clear VAT control nominal so imported HMRC and VAT-related transactions land consistently.</span>
                    </div>
                    <div class="list-item">
                        <strong>Future extension</strong>
                        <span>This screen can later gain users, API keys, storage tests, and export preferences without wrecking the layout.</span>
                    </div>
                </div>
            </div>
        </div>';
    }
}
