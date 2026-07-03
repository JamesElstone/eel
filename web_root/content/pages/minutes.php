<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _minutes extends PageContextFramework
{
    public function id(): string
    {
        return 'minutes';
    }

    public function title(): string
    {
        return 'Minutes';
    }

    public function subtitle(): string
    {
        return 'Review company minutes recorded for the selected accounting period.';
    }

    public function cards(): array
    {
        return [
            'company_minutes',
        ];
    }
}
