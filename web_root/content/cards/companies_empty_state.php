<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _companies_empty_stateCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'companies_empty_state';
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
        if (!empty($page['has_valid_selected_company'])) {
            return '';
        }

        return '<div class="card">
            <div class="card-header"><h2 class="card-title">Settings Ready When You Are</h2></div>
            <div class="card-body">
                <div class="helper">Select or add a company first, and the company settings, accounting defaults, nominal defaults, and import controls will appear here.</div>
            </div>
        </div>';
    }
}
