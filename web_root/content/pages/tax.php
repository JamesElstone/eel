<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _tax extends PageContextFramework
{
    public function id(): string
    {
        return 'tax';
    }

    public function title(): string
    {
        return 'Tax';
    }

    public function subtitle(): string
    {
        return 'Inspect read-only Corporation Tax workings, capital allowance pools, losses, and tax data warnings.';
    }

    public function cards(): array
    {
        return [
            'tax_corporation_tax_summary',
            'tax_taxable_profit_bridge',
            'tax_disallowable_add_backs',
            'tax_depreciation_add_back',
            'tax_capital_allowances_summary',
            'tax_aia_allocation',
            'tax_main_rate_pool',
            'tax_special_rate_pool',
            'tax_car_co2_treatment',
            'tax_disposals_balancing',
            'tax_losses',
            'tax_rate_bands',
            'tax_warnings',
        ];
    }
}
