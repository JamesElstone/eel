<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _incorporation extends PageContextFramework
{
    public function id(): string
    {
        return 'incorporation';
    }

    public function title(): string
    {
        return 'Incorporation';
    }

    public function subtitle(): string
    {
        return 'Record formation share capital and match paid-up shares to the incoming bank receipt.';
    }

    public function cards(): array
    {
        return [
            'incorporation_status',
            'incorporation_add_shares',
            'incorporation_share_capital',
            'incorporation_payment_matching',
        ];
    }

    public function cardLayout(): array
    {
        return [
            [
                'tab' => 'Status',
                'cards' => [
                    'incorporation_status',
                    'incorporation_share_capital',
                ],
            ],
            [
                'tab' => 'Shares',
                'cards' => [
                    'incorporation_add_shares',
                ],
            ],
            [
                'tab' => 'Payment',
                'cards' => [
                    'incorporation_payment_matching',
                ],
            ],
        ];
    }
}
