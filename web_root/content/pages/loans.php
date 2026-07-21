<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _loans extends PageContextFramework
{
    public function id(): string
    {
        return 'loans';
    }

    public function title(): string
    {
        return 'Loans';
    }

    public function subtitle(): string
    {
        return 'Review the director loan statement and supporting workspace for the selected period.';
    }

    public function ajaxPendingBlurScope(): string
    {
        return 'page';
    }

    public function cards(): array
    {
        return [
            'director_loan_state',
            'director_loan_s455',
            'director_loan_ct600a',
            'year_end_director_loan_offset',
        ];
    }

    public function cardLayout(): array
    {
        return [
            [
                'tab' => 'Statement',
                'cards' => [
                    'director_loan_state',
                ],
            ],
            [
                'tab' => 'Participator loans (s455)',
                'cards' => [
                    'director_loan_s455',
                    'director_loan_ct600a',
                ],
            ],
            [
                'tab' => 'Year End Confirmation',
                'cards' => [
                    'year_end_director_loan_offset',
                ],
            ],
        ];
    }
}
