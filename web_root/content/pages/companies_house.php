<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _companies_house extends PageContextFramework
{
    public function id(): string
    {
        return 'companies_house';
    }

    public function title(): string
    {
        return 'Companies House';
    }

    public function subtitle(): string
    {
        return 'Review stored Companies House balance-sheet data and compare it with the selected accounting period.';
    }

    public function cards(): array
    {
        return ['companies_house_snapshot'];
    }
}
