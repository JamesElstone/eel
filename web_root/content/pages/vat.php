<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
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
