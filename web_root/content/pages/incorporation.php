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

    public function ajaxPendingBlurScope(): string
    {
        return 'page';
    }

    public function cards(): array
    {
        return [
            'incorporation_status',
            'incorporation_share_capital',
            'incorporation_payment_matching',
            'director_loan_directors',
            'incorporation_ownership_parties',
            'incorporation_share_allocation',
            'incorporation_relationships',
        ];
    }

    public function cardLayout(): array
    {
        return [
            [
                'tab' => 'Shares',
                'cards' => [
                    'incorporation_status',
                    'incorporation_share_capital',
                ],
            ],
            [
                'tab' => 'Paying Up',
                'cards' => [
                    'incorporation_payment_matching',
                ],
            ],
            [
                'tab' => 'Directors',
                'cards' => [
                    'director_loan_directors',
                ],
            ],
            [
                'tab' => 'Ownership & Parties',
                'cards' => [
                    'incorporation_ownership_parties',
                ],
            ],
            [
                'tab' => 'Share Allocation',
                'cards' => [
                    'incorporation_share_allocation',
                ],
            ],
            [
                'tab' => 'Relationships',
                'cards' => [
                    'incorporation_relationships',
                ],
            ],
        ];
    }
}
