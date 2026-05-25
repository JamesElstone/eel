<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _company_accounts extends PageContextFramework
{
    public function id(): string
    {
        return 'company_accounts';
    }

    public function title(): string
    {
        return 'Company Accounts';
    }

    public function subtitle(): string
    {
        return 'Maintain company accounts, bank CSV mappings, and account checks in one place.';
    }

    public function cards(): array
    {
        return [
            'banking_accounts',
            'banking_account_form',
            'statement_field_mapping',
            'banking_reconciliation',
        ];
    }

    public function cardLayout(): array
    {
        return [
            [
                'tab' => 'Summary',
                'cards' => [
                    'banking_accounts',
                    'banking_reconciliation',
                ],
            ],
            [
                'tab' => 'Add New Account',
                'cards' => [
                    'banking_account_form',
                ],
            ],
            [
                'tab' => 'Bank CSV Mappings',
                'cards' => [
                    'statement_field_mapping',
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
        $intent = trim((string)$request->input('intent', $request->input('global_action', '')));
        $editAccountId = HelperFramework::sanitiseId(
            $actionResult->context()['company']['account']['id']
                ?? $actionResult->query()['edit_account_id']
                ?? $request->input(
                    'edit_account_id',
                    $request->query('edit_account_id', 0)
                )
        );

        if ($editAccountId <= 0 && $intent === 'edit') {
            $editAccountId = HelperFramework::sanitiseId($request->input('account_id', 0));
        }

        return [
            'edit_account_id' => $editAccountId,
            'field_mapping' => [
                'account_id' => max(0, (int)$request->input(
                    'field_mapping_account_id',
                    $request->input(
                        'mapping_account_id',
                        $request->query(
                            'field_mapping_account_id',
                            $request->query('mapping_account_id', 0)
                        )
                    )
                )),
            ],
        ];
    }
}
