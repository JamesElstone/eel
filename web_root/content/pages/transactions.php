<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _transactions extends PageContextFramework
{
    public function id(): string
    {
        return 'transactions';
    }

    public function title(): string
    {
        return 'Transactions';
    }

    public function subtitle(): string
    {
        return 'Categorise imported transactions, manage rules, and review month-by-month posting readiness.';
    }

    public function cards(): array
    {
        return [
            'transactions_monthly_status',
            'transaction_category_audit_log',
            'transactions_imported',
            'transactions_rules',
            'transactions_rule_form',
            'nominals_add_account',
        ];
    }

    public function cardLayout(): array
    {
        return [
            [
                'tab' => 'Summary',
                'cards' => [
                    'transactions_monthly_status',
                    'transaction_category_audit_log',
                ],
            ],
            [
                'tab' => 'Categorise',
                'cards' => [
                    'transactions_imported',
                ],
            ],
            [
                'tab' => 'Rules',
                'cards' => [
                    'transactions_rules',
                    'transactions_rule_form',
                ],
            ],
            [
                'tab' => 'Add Nominal',
                'cards' => [
                    'nominals_add_account',
                ],
            ],
        ];
    }
}
