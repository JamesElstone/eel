<?php
declare(strict_types=1);

final class _companies extends BaseModulePageFramework
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

    public function showsTaxYearSelector(): bool
    {
        return false;
    }

    public function cards(): array
    {
        return [
            'companies_search',
            'companies_empty_state',
            'companies_company_settings',
            'companies_stored_detail',
            'companies_accounting',
            'companies_nominals',
            'companies_setup_health',
            'companies_danger',
        ];
    }
}
