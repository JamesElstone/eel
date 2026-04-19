<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _director_loan extends BaseModulePageFramework
{
    public function id(): string
    {
        return 'director_loan';
    }

    public function title(): string
    {
        return 'Director Loan';
    }

    public function subtitle(): string
    {
        return 'Review the director loan statement and supporting workspace for the selected period.';
    }

    public function cards(): array
    {
        return ['director_loan_state', 'director_loan_workspace'];
    }
}
