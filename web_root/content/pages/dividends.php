<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _dividends extends PageContextFramework
{
    public function id(): string
    {
        return 'dividends';
    }

    public function title(): string
    {
        return 'Dividends';
    }

    public function subtitle(): string
    {
        return 'Review dividend capacity, declare conservative interim dividends, and inspect posted dividend history.';
    }

    public function ajaxPendingBlurScope(): string
    {
        return 'page';
    }

    public function cards(): array
    {
        return [
            'dividend_capacity',
            'dividend_reserve_review',
            'dividend_vouchers',
            'dividend_declare',
            'dividend_history',
        ];
    }

    public function cardLayout(): array
    {
        return [
            [
                'tab' => 'Overview',
                'cards' => [
                    'dividend_capacity',
                    'dividend_vouchers',
                ],
            ],
            [
                'tab' => 'Reserve Review',
                'cards' => [
                    'dividend_reserve_review',
                ],
            ],
            [
                'tab' => 'Declare Dividend',
                'cards' => [
                    'dividend_declare',
                ],
            ],
            [
                'tab' => 'History',
                'cards' => [
                    'dividend_history',
                ],
            ],
        ];
    }

}
