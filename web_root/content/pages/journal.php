<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _journal extends PageContextFramework
{
    public function id(): string
    {
        return 'journal';
    }

    public function title(): string
    {
        return 'Journal';
    }

    public function subtitle(): string
    {
        return 'Review posted journals for the selected company and accounting period.';
    }

    public function ajaxPendingBlurScope(): string
    {
        return 'page';
    }

    public function cards(): array
    {
        return [
            'journals_list',
            'journal_cut_offs',
        ];
    }

    public function cardLayout(): array
    {
        return [
            [
                'tab' => 'History',
                'cards' => [
                    'journals_list',
                ],
            ],
            [
                'tab' => 'Adjustments',
                'cards' => [
                    'journal_cut_offs',
                ],
            ],
        ];
    }
}
