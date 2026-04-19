<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _companies_accountingCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'companies_accounting';
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
        $hasValidSelectedCompany = !empty($page['has_valid_selected_company']);

        if (!$hasValidSelectedCompany) {
            return '';
        }

        return (new _accounting_sectionCard())->render($context);
    }
}
