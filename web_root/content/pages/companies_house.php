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

    public function ajaxPendingBlurScope(): string
    {
        return 'page';
    }

    public function cards(): array
    {
        return [
            'companies_house_snapshot',
            'year_end_companies_house_comparison',
        ];
    }

    public function cardLayout(): array
    {
        return [
            [
                'tab' => 'Snapshot',
                'cards' => [
                    'companies_house_snapshot',
                ],
            ],
            [
                'tab' => 'Year End Confirmation',
                'on_demand' => true,
                'cards' => [
                    'year_end_companies_house_comparison',
                ],
            ],
        ];
    }
}
