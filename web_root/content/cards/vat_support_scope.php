<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _vat_support_scopeCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'vat_support_scope';
    }

    public function title(): string
    {
        return 'VAT Support Scope';
    }

    public function services(): array
    {
        return [[
            'key' => 'vat_support_scope',
            'service' => \eel_accounts\Service\VatSupportScopeService::class,
            'method' => 'fetchForCompany',
            'params' => ['companyId' => ':company.id'],
        ]];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $scope = (array)($context['services']['vat_support_scope'] ?? $context['vat_support_scope'] ?? []);
        if (empty($scope['tax_year_end_read_only'])) {
            return '<div class="helper">Tax and Year End remain available because the selected company has not been confirmed as VAT registered through the LIVE HMRC VAT API.</div>';
        }

        return '<div class="helper" data-vat-support-read-only="1">'
            . '<span class="badge warning">Unsupported VAT scope - read only</span> '
            . HelperFramework::escape((string)($scope['message'] ?? \eel_accounts\Service\VatSupportScopeService::UNSUPPORTED_MESSAGE))
            . ' Historical figures and period selectors remain available; actions that would change Tax or Year End data are disabled.'
            . '</div>';
    }
}
