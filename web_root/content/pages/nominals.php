<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _nominals extends PageContextFramework
{
    public function id(): string
    {
        return 'nominals';
    }

    public function title(): string
    {
        return 'Nominals';
    }

    public function subtitle(): string
    {
        return 'Maintain nominal accounts, subtypes, and import or export tools for the shared chart.';
    }

    public function cards(): array
    {
        return [
            'nominals_accounts',
            'nominals_add_account',
            'nominals_categories',
            'nominals_add_category',
            'nominals_account_types',
            'nominals_import_export',
            'nominal_opening_balances',
        ];
    }

    public function cardLayout(): array
    {
        return [
            [
                'tab' => 'Chart of Accounts',
                'cards' => [
                    'nominals_accounts',
                ],
            ],
            [
                'tab' => 'Add Account',
                'cards' => [
                    'nominals_add_account',
                ],
            ],
            [
                'tab' => 'Categories',
                'cards' => [
                    'nominals_categories',
                    'nominals_add_category',
                    'nominals_account_types',
                ],
            ],
            [
                'tab' => 'Import / Export',
                'cards' => [
                    'nominals_import_export',
                ],
            ],
            [
                'tab' => 'Opening Balances',
                'cards' => [
                    'nominal_opening_balances',
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
        $editNominalId = max(0, (int)$request->input('edit_nominal_id', $request->query('edit_nominal_id', 0)));
        $editSubtypeId = max(0, (int)$request->input('edit_subtype_id', $request->query('edit_subtype_id', 0)));

        return [
            'nominals' => [
                'editing_nominal_id' => $editNominalId,
                'editing_subtype_id' => $editSubtypeId,
            ],
        ];
    }
}
