<?php
declare(strict_types=1);

final class _vat extends BaseModulePageFramework
{
    public function id(): string
    {
        return 'vat';
    }

    public function title(): string
    {
        return 'VAT';
    }

    public function subtitle(): string
    {
        return 'Maintain VAT registration details and review validation readiness for the selected company.';
    }

    public function showsTaxYearSelector(): bool
    {
        return false;
    }

    public function cards(): array
    {
        return ['vat_registration', 'vat_readiness'];
    }
}
