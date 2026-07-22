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
            'director_loan_attribution',
            'director_loan_s455',
            'director_loan_ct600a',
            'loan_review',
            'year_end_loan_confirmation',
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
                'tab' => 'Participant Loan Assignment',
                'on_demand' => true,
                'cards' => [
                    'director_loan_attribution',
                ],
            ],
            [
                'tab' => 'Loans Tax Position',
                'on_demand' => true,
                'cards' => [
                    'director_loan_s455',
                    'director_loan_ct600a',
                ],
            ],
            [
                'tab' => 'Review',
                'on_demand' => true,
                'cards' => [
                    'loan_review',
                ],
            ],
            [
                'tab' => 'Year End Confirmation',
                'on_demand' => true,
                'cards' => [
                    'year_end_loan_confirmation',
                ],
            ],
        ];
    }
}
