<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _prepayments extends PageContextFramework
{
    public function id(): string
    {
        return 'prepayments';
    }

    public function title(): string
    {
        return 'Prepayments';
    }

    public function subtitle(): string
    {
        return 'Review period-sensitive service costs from transactions and expense claims.';
    }

    public function ajaxPendingBlurScope(): string
    {
        return 'page';
    }

    public function cards(): array
    {
        return [
            'prepayments_review',
        ];
    }
}
