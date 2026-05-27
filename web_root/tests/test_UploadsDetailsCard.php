<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(_uploads_detailsCard::class, static function (GeneratedServiceClassTestHarness $harness, _uploads_detailsCard $card): void {
    $harness->check(_uploads_detailsCard::class, 'renders upload summary by accounting period beside the history filter', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'id' => 12,
            ],
            'uploads' => [
                'filter' => 'all',
                'page' => 1,
            ],
            'services' => [
                'filter_terms' => [
                    'all' => 'All uploads',
                ],
                'upload_history' => [],
                'upload_summary_by_accounting_period' => [
                    [
                        'accounting_period_id' => 4,
                        'label' => '01/10/2025 to 30/09/2026',
                        'upload_count' => 1,
                        'row_count' => 93,
                    ],
                    [
                        'accounting_period_id' => 3,
                        'label' => '01/10/2024 to 30/09/2025',
                        'upload_count' => 0,
                        'row_count' => 0,
                    ],
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'Filtered by:'));
        $harness->assertTrue(str_contains($html, 'Accounting Period'));
        $harness->assertTrue(str_contains($html, 'data-accounting-period-summary-button="true" data-accounting-period-id="4"'));
        $harness->assertTrue(str_contains($html, 'aria-label="Switch to accounting period 01/10/2025 to 30/09/2026"'));
        $harness->assertTrue(str_contains($html, '1 CSV (93 rows)'));
        $harness->assertTrue(str_contains($html, '0 CSV (0 rows)'));
    });

    $harness->check(_uploads_detailsCard::class, 'renders uploaded row totals before mapping is staged', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'id' => 12,
            ],
            'uploads' => [
                'filter' => 'all',
                'page' => 1,
            ],
            'services' => [
                'filter_terms' => [
                    'all' => 'All uploads',
                ],
                'upload_history' => [
                    [
                        'id' => 88,
                        'uploaded_at' => '2026-05-01 13:09',
                        'filename' => 'example_2026-02-ANNA_010226_280226.csv',
                        'month' => 'May 2026',
                        'account_name' => 'Anna Money - Current Account',
                        'account_type' => 'bank',
                        'workflow_status' => 'uploaded',
                        'rows_parsed' => 93,
                        'rows_ready_to_import' => 0,
                        'inserted' => 0,
                    ],
                ],
                'upload_summary_by_accounting_period' => [],
            ],
        ]);

        $harness->assertTrue(str_contains($html, '93 (0 ready, 0 committed)'));
    });

    $harness->check(_uploads_detailsCard::class, 'labels missing accounting period uploads as requiring action', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'id' => 12,
            ],
            'uploads' => [
                'filter' => 'action_required',
                'page' => 1,
            ],
            'services' => [
                'filter_terms' => [
                    'all' => 'All uploads',
                    'action_required' => 'Action required',
                ],
                'upload_history' => [
                    [
                        'id' => 89,
                        'uploaded_at' => '2026-05-01 13:09',
                        'filename' => 'example.csv',
                        'month' => '01/10/2026 to 02/10/2026',
                        'account_name' => 'Anna Money - Current Account',
                        'account_type' => 'bank',
                        'workflow_status' => 'needs_accounting_period',
                        'rows_parsed' => 2,
                        'rows_ready_to_import' => 0,
                        'inserted' => 0,
                    ],
                ],
                'upload_summary_by_accounting_period' => [],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'Needs Accounting Period'));
        $harness->assertSame(false, str_contains($html, 'Preview Ready'));
    });

    $harness->check(_uploads_detailsCard::class, 'hides preview actions for zero-row uploads', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'id' => 12,
            ],
            'uploads' => [
                'filter' => 'zero_row_csv',
                'page' => 1,
            ],
            'services' => [
                'filter_terms' => [
                    'zero_row_csv' => 'Zero-row CSVs',
                ],
                'upload_history' => [
                    [
                        'id' => 90,
                        'uploaded_at' => '2026-05-01 13:09',
                        'filename' => 'empty.csv',
                        'month' => 'May 2026',
                        'account_name' => 'Anna Money - Current Account',
                        'account_type' => 'bank',
                        'workflow_status' => 'staged',
                        'rows_parsed' => 0,
                        'rows_ready_to_import' => 0,
                        'inserted' => 0,
                    ],
                ],
                'upload_summary_by_accounting_period' => [],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'No Rows Found'));
        $harness->assertTrue(str_contains($html, 'No rows to preview.'));
        $harness->assertSame(false, str_contains($html, 'Field Mappings'));
        $harness->assertSame(false, str_contains($html, 'Preview And Validate'));
    });

    $harness->check(_uploads_detailsCard::class, 'hides preview actions for duplicate file uploads', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'id' => 12,
            ],
            'uploads' => [
                'filter' => 'duplicate_files',
                'page' => 1,
            ],
            'services' => [
                'filter_terms' => [
                    'duplicate_files' => 'Duplicate files',
                ],
                'upload_history' => [
                    [
                        'id' => 91,
                        'uploaded_at' => '2026-05-01 13:09',
                        'filename' => 'duplicate.csv',
                        'month' => 'May 2026',
                        'account_name' => 'Anna Money - Current Account',
                        'account_type' => 'bank',
                        'workflow_status' => 'staged',
                        'rows_parsed' => 12,
                        'rows_ready_to_import' => 12,
                        'inserted' => 0,
                        'duplicate_file' => true,
                    ],
                ],
                'upload_summary_by_accounting_period' => [],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'Duplicate File'));
        $harness->assertTrue(str_contains($html, 'Duplicate file already uploaded.'));
        $harness->assertSame(false, str_contains($html, 'Field Mappings'));
        $harness->assertSame(false, str_contains($html, 'Preview And Validate'));
    });
});
