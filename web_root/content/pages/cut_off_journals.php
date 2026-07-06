<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _cut_off_journals extends PageContextFramework
{
    public function id(): string
    {
        return 'cut_off_journals';
    }

    public function title(): string
    {
        return 'Cut-off Journals';
    }

    public function subtitle(): string
    {
        return 'Post and review year-end accrual, prepayment, deferred income, and other cut-off journals.';
    }

    public function ajaxPendingBlurScope(): string
    {
        return 'page';
    }

    public function cards(): array
    {
        return [
            'cut_off_journals',
        ];
    }
}
