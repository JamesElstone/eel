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
    $harness->check(_uploads_detailsCard::class, 'renders upload summary by tax period beside the history filter', static function () use ($harness, $card): void {
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
                'upload_summary_by_tax_year' => [
                    [
                        'tax_year_id' => 4,
                        'label' => '01/10/2025 to 30/09/2026',
                        'upload_count' => 1,
                        'row_count' => 93,
                    ],
                    [
                        'tax_year_id' => 3,
                        'label' => '01/10/2024 to 30/09/2025',
                        'upload_count' => 0,
                        'row_count' => 0,
                    ],
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'Filtered by:'));
        $harness->assertTrue(str_contains($html, 'Tax Period'));
        $harness->assertTrue(str_contains($html, 'data-tax-year-summary-button="true" data-tax-year-id="4"'));
        $harness->assertTrue(str_contains($html, 'aria-label="Switch to tax period 01/10/2025 to 30/09/2026"'));
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
                'upload_summary_by_tax_year' => [],
            ],
        ]);

        $harness->assertTrue(str_contains($html, '93 (0 ready, 0 committed)'));
    });
});
