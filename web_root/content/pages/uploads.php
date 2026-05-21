<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _uploads extends PageContextFramework
{

    public function id(): string
    {
        return 'uploads';
    }

    public function title(): string
    {
        return 'Uploads';
    }

    public function subtitle(): string
    {
        return 'Upload bank CSV files, review mapping, and validate staged rows before committing transactions.';
    }

    public function cards(): array
    {
        return [
            'uploads_bank_transactions', 
            'uploads_details', 
            // 'dump_context', 
            'statement_field_mapping', 
            'uploads_validate_commit', 
            'transactions_monthly_status', 
            'csv_export',
        ];
    }

    public function cardLayout(): array
    {
        return [
            [
                'tab' => 'Upload',
                'cards' => [
                    'uploads_bank_transactions',
                    'uploads_details',
                    'transactions_monthly_status',
                ],
            ],
            [
                'tab' => 'Field Mappings',
                'cards' => [
                    'statement_field_mapping',
                ],
            ],
            [
                'tab' => 'Commit Transactions',
                'cards' => [
                    'uploads_validate_commit',
                ],
            ],
            [
                'tab' => 'Export',
                'cards' => [
                    'csv_export',
                ],
            ],
        ];
    }
}
