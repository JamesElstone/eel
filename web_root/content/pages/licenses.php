<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _licenses extends PageContextFramework
{
    public function id(): string
    {
        return 'licenses';
    }

    public function title(): string
    {
        return 'Licenses';
    }

    public function subtitle(): string
    {
        return 'Review project licensing, copyright notices, and the full license texts bundled with this application.';
    }

    public function hiddenSiteContextSelectors(): array
    {
        return ['company_id', 'accounting_period_id'];
    }

    public function cards(): array
    {
        return [
            'licenses_overview',
            'license_bsd_3_clause',
            'license_agpl_3_0',
            'license_fonts',
        ];
    }
}
