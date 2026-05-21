<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _companies extends PageContextFramework
{
    public function id(): string
    {
        return 'companies';
    }

    public function title(): string
    {
        return 'Companies';
    }

    public function subtitle(): string
    {
        return 'Set up company records, stored Companies House details, accounting defaults, and control settings.';
    }

    public function hiddenSiteContextSelectors(): array
    {
        return ['tax_year_id'];
    }

    public function pageStackCards(): array
    {
        return [
            'companies_stored_detail' => 'card-full',
        ];
    }

    public function cards(): array
    {
        return [
            'companies_search',
            'companies_company_settings',
            'companies_stored_detail',
            'accounting_periods',
            'companies_nominals',
            'settings_setup_health',
            'companies_danger',
            // 'dump_context',
        ];
    }

    public function cardLayout(): array
    {
        return [
            [
                'tab' => 'Summary',
                'layout' => 'split',
                'cards' => [
                    'companies_company_settings',
                    'settings_setup_health',
                    'companies_stored_detail',
                ],
            ],
            [
                'tab' => 'Add',
                'cards' => [
                    'companies_search',
                ],
            ],
            [
                'tab' => 'Details',
                'layout' => 'split',
                'cards' => [
                    'accounting_periods',
                    'companies_nominals',
                ],
            ],
            [
                'tab' => 'Danger',
                'cards' => [
                    'companies_danger',
                ],
            ],
        ];
    }

    protected function moduleContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult,
        array $baseContext
    ): array {
        $companyId = (int)($baseContext['company']['id'] ?? 0);

        return (new HealthAction())->buildHealthContext($companyId);
    }
}
