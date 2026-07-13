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
$harness->run(_csv_exportCard::class, static function (GeneratedServiceClassTestHarness $harness, _csv_exportCard $card): void {
    $harness->check(_csv_exportCard::class, 'renders export buttons for uploaded CSVs by month', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'id' => 47,
                'accounting_period_id' => 74,
            ],
            'services' => [
                'csv_export_months' => [
                    [
                        'label' => '01/2026',
                        'uploads' => [],
                    ],
                    [
                        'label' => '02/2026',
                        'month_key' => '2026-02-01',
                        'uploads' => [
                            [
                                'id' => 216,
                                'original_filename' => 'example.csv',
                                'account_name' => 'Example Bank',
                                'rows_parsed' => 93,
                                'export_rows' => 42,
                                'workflow_status' => 'uploaded',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $harness->assertTrue(!str_contains($html, '01/2026'));
        $harness->assertTrue(!str_contains($html, 'No CSV uploads for this month.'));
        $harness->assertTrue(str_contains($html, '<span class="table-sort-label">Month</span>'));
        $harness->assertTrue(str_contains($html, '02/2026'));
        $harness->assertTrue(!str_contains($html, 'example.csv'));
        $harness->assertTrue(str_contains($html, 'Example Bank'));
        $harness->assertTrue(str_contains($html, '<td>42</td>'));
        $harness->assertTrue(str_contains($html, '<span class="table-sort-label">Account</span>'));
        $harness->assertTrue(str_contains($html, 'table-condensed-toggle'));
        $harness->assertTrue(str_contains($html, '<div class="actions-row actions-row-nowrap">'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="export_csv_upload"'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="export_xlsx_upload"'));
        $harness->assertTrue(str_contains($html, 'name="export_month" value="2026-02-01"'));
        $harness->assertTrue(str_contains($html, '<button class="button primary" type="submit">Export CSV</button>'));
        $harness->assertTrue(str_contains($html, '<button class="button" type="submit">Export XLSX</button>'));
    });

    $harness->check(_csv_exportCard::class, 'paginates uploaded CSVs at 15 rows', static function () use ($harness, $card): void {
        $uploads = [];
        foreach (range(1, 16) as $id) {
            $uploads[] = [
                'id' => $id,
                'account_name' => 'Export Account ' . $id,
                'export_rows' => $id,
                'workflow_status' => 'uploaded',
            ];
        }
        $context = [
            'company' => ['id' => 47, 'accounting_period_id' => 74],
            'page' => ['page_id' => 'uploads', 'page_cards' => ['csv_export']],
            'services' => [
                'csv_export_months' => [[
                    'label' => '02/2026',
                    'month_key' => '2026-02-01',
                    'uploads' => $uploads,
                ]],
            ],
        ];

        $pageOneHtml = $card->render($context);
        $harness->assertTrue(str_contains($pageOneHtml, 'Uploads 1-15 of 16'));
        $harness->assertTrue(str_contains($pageOneHtml, 'Export Account 15'));
        $harness->assertTrue(!str_contains($pageOneHtml, 'Export Account 16'));

        $context['page']['csv_export_page'] = 2;
        $pageTwoHtml = $card->render($context);
        $harness->assertTrue(str_contains($pageTwoHtml, 'Uploads 16 of 16'));
        $harness->assertTrue(str_contains($pageTwoHtml, 'Export Account 16'));
        $harness->assertTrue(!str_contains($pageTwoHtml, 'Export Account 15'));
        $harness->assertTrue(str_contains($pageTwoHtml, 'data-table-pagination-field="csv_export_page"'));
    });

    $harness->check(_csv_exportCard::class, 'shows the empty period message when no months have uploaded CSVs', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'id' => 47,
                'accounting_period_id' => 74,
            ],
            'services' => [
                'csv_export_months' => [
                    [
                        'label' => '01/2026',
                        'uploads' => [],
                    ],
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'data-table-framework="true"'));
        $harness->assertTrue(str_contains($html, 'No uploaded CSV files are available for this accounting period.'));
        $harness->assertTrue(str_contains($html, 'Uploads 0 of 0'));
    });
});
