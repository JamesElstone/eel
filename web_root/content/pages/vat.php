<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _vat extends PageContextFramework
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
        return 'Monitor gross income against VAT registration thresholds and maintain VAT validation details for the selected company.';
    }

    public function cards(): array
    {
        return ['vat_turnover_monitoring', 'vat_registration', 'vat_readiness'];
    }
}
