<?php
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
