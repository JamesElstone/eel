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
        return [];
    }

    public function cardLayout(): array
    {
        return [
            [
                'tab' => 'Upload',
                'cards' => [
                    'uploads_statement_coverage',
                    'uploads_bank_transactions',
                    'transactions_monthly_status',
                ],
            ],
            [
                'tab' => 'Review Uploads',
                'cards' => [
                    'uploads_details',
                    'statement_field_mapping',
                ],
            ],
            [
                'tab' => 'Import Transactions',
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

    protected function moduleContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult,
        array $baseContext
    ): array {
        $uploads = (array)($actionResult->context()['uploads'] ?? []);
        $filter = trim((string)($uploads['filter'] ?? $request->input('filter', 'ready')));

        return [
            'uploads' => [
                'id' => max(0, (int)($uploads['id'] ?? $request->input('upload_id', 0))),
                'filter' => $filter !== '' ? $filter : 'ready',
                'page' => max(1, (int)($uploads['page'] ?? $request->input('page', 1))),
            ],
            'field_mapping' => [
                'account_id' => 0,
            ],
        ];
    }
}
