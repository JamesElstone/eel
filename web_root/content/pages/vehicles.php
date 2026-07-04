<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _vehicles extends PageContextFramework
{
    public function id(): string
    {
        return 'vehicles';
    }

    public function title(): string
    {
        return 'Vehicles';
    }

    public function subtitle(): string
    {
        return 'Review motor vehicle assets and the tax facts needed for capital allowances.';
    }

    public function cards(): array
    {
        return ['vehicle_register'];
    }
}
