<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _expenses extends BaseModulePageFramework
{
    public function id(): string
    {
        return 'expenses';
    }

    public function title(): string
    {
        return 'Expenses';
    }

    public function subtitle(): string
    {
        return 'Manage expense claims, supporting receipts, and the expense workspace for the selected company.';
    }

    public function cards(): array
    {
        return ['expenses_state', 'expenses_workspace'];
    }

    protected function moduleContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult,
        array $baseContext
    ): array {
        return [
            'expense_filters' => [],
        ];
    }
}
