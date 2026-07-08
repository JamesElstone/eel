<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _assets extends PageContextFramework
{
    public function id(): string
    {
        return 'assets';
    }

    public function title(): string
    {
        return 'Assets';
    }

    public function subtitle(): string
    {
        return 'Manage the fixed asset register and additions for the selected accounting period.';
    }

    public function ajaxPendingBlurScope(): string
    {
        return 'page';
    }

    public function cards(): array
    {
        return ['asset_create', 'asset_reconcile_manual', 'asset_register', 'not_an_asset'];
    }

    public function cardLayout(): array
    {
        return [
            [
                'tab' => 'Asset Register',
                'cards' => [
                    'asset_register',
                ],
            ],
            [
                'tab' => 'Manual Assets',
                'cards' => [
                    'asset_create',
                    'asset_reconcile_manual',
                ],
            ],
            [
                'tab' => 'Non-Assets',
                'cards' => [
                    'not_an_asset',
                ],
            ],
        ];
    }

    protected function moduleContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult,
        array $baseContext
    ): array {
        return [
            'prefill_transaction_id' => max(0, (int)$request->input('transaction_id', $request->query('transaction_id', 0))),
            'prefill_transaction_split_line_id' => max(0, (int)$request->input('transaction_split_line_id', $request->query('transaction_split_line_id', 0))),
        ];
    }
}
