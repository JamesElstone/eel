<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _companies_empty_stateCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'companies_empty_state';
    }

    public function services(): array
    {
        return [];
    }

    protected function additionalInvalidationFacts(): array
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
        if ((int)($context['company']['id'] ?? 0) > 0) {
            return '';
        }

        return '<div class="helper">Select or add a company first, and the company settings, accounting defaults, nominal defaults, and import controls will appear here.</div>';
    }
}
