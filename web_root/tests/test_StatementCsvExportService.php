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
$harness->run(\eel_accounts\Service\StatementCsvExportService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\StatementCsvExportService $service): void {
    $harness->check(\eel_accounts\Service\StatementCsvExportService::class, 'exports every mapping column with blanks for unmapped fields', static function () use ($harness, $service): void {
        $directory = test_tmp_directory();
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $path = $directory . DIRECTORY_SEPARATOR . 'statement-csv-export.csv';
        file_put_contents($path, "date,description,amount\n2026-02-01,Example,12.34\n");

        $method = new ReflectionMethod(\eel_accounts\Service\StatementCsvExportService::class, 'buildMappedCsv');
        $method->setAccessible(true);
        $csv = (string)$method->invoke($service, $path, [
            'created' => ['header' => 'date', 'index' => 0],
            'description' => ['header' => 'description', 'index' => 1],
            'amount' => ['header' => 'amount', 'index' => 2],
            'currency' => ['default_value' => 'GBP', 'label' => '£ GBP'],
        ]);

        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        $harness->assertSame('account,created,processed,type,description,reference,counterparty,card,amount,balance,currency,category,document', $lines[0] ?? '');
        $harness->assertSame(',2026-02-01,,,Example,,,,12.34,,GBP,,', $lines[1] ?? '');
    });

    $harness->check(\eel_accounts\Service\StatementCsvExportService::class, 'committed transaction values override source CSV values', static function () use ($harness, $service): void {
        $directory = test_tmp_directory();
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $path = $directory . DIRECTORY_SEPARATOR . 'statement-csv-export-committed.csv';
        file_put_contents($path, "date,description,amount\n2026-02-01,Original,12.34\n");

        $method = new ReflectionMethod(\eel_accounts\Service\StatementCsvExportService::class, 'buildMappedCsv');
        $method->setAccessible(true);
        $csv = (string)$method->invoke($service, $path, [
            'created' => ['header' => 'date', 'index' => 0],
            'description' => ['header' => 'description', 'index' => 1],
            'amount' => ['header' => 'amount', 'index' => 2],
        ], [
            2 => [
                'created' => '2026-02-02',
                'description' => 'Posted',
                'amount' => '99.99',
                'currency' => 'GBP',
                'category' => '5000 - Purchases',
            ],
        ]);

        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        $harness->assertSame(',2026-02-02,,,Posted,,,,99.99,,GBP,"5000 - Purchases",', $lines[1] ?? '');
    });

    $harness->check(\eel_accounts\Service\StatementCsvExportService::class, 'monthly export only includes rows for the selected transaction month', static function () use ($harness, $service): void {
        $directory = test_tmp_directory();
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $path = $directory . DIRECTORY_SEPARATOR . 'statement-csv-export-month.csv';
        file_put_contents($path, "processed,created,description,amount\n2026-01-31,2026-01-30,January,10.00\n2026-02-01,2026-01-31,February,20.00\n,2026-02-15,Created February,30.00\n");

        $method = new ReflectionMethod(\eel_accounts\Service\StatementCsvExportService::class, 'buildMappedCsv');
        $method->setAccessible(true);
        $csv = (string)$method->invoke($service, $path, [
            'processed' => ['header' => 'processed', 'index' => 0],
            'created' => ['header' => 'created', 'index' => 1],
            'description' => ['header' => 'description', 'index' => 2],
            'amount' => ['header' => 'amount', 'index' => 3],
        ], [], '2026-02-01');

        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        $harness->assertSame('account,created,processed,type,description,reference,counterparty,card,amount,balance,currency,category,document', $lines[0] ?? '');
        $harness->assertSame(',2026-01-31,2026-02-01,,February,,,,20.00,,,,', $lines[1] ?? '');
        $harness->assertSame(',2026-02-15,,,"Created February",,,,30.00,,,,', $lines[2] ?? '');
        $harness->assertSame(3, count($lines));
    });

    $harness->check(\eel_accounts\Service\StatementCsvExportService::class, 'xlsx export includes an opaque row key and category dropdown sheet', static function () use ($harness, $service): void {
        $method = new ReflectionMethod(\eel_accounts\Service\StatementCsvExportService::class, 'buildXlsx');
        $method->setAccessible(true);
        $xlsx = (string)$method->invoke($service, 47, 216, [
            'account',
            'created',
            'processed',
            'type',
            'description',
            'amount',
            'balance',
            'currency',
            'category',
            'document',
        ], [
            [
                '_row_number' => '2',
                'account' => 'Example Bank',
                'created' => '2026-02-01',
                'description' => 'Example',
                'amount' => '12.34',
                'category' => '',
            ],
        ], [
            '5000 - Purchases',
            '7000 - Sales',
        ]);

        $harness->assertSame('PK', substr($xlsx, 0, 2));
        $harness->assertTrue(str_contains($xlsx, 'eel_row_key'));
        $harness->assertTrue(str_contains($xlsx, '5000 - Purchases'));
        $harness->assertTrue(str_contains($xlsx, 'Categories!$A$1:$A$2'));
        $harness->assertTrue(str_contains($xlsx, 'state="hidden"'));
        $harness->assertTrue(str_contains($xlsx, '<col min="2" max="2" width="30" customWidth="1"/>'));
        $harness->assertTrue(str_contains($xlsx, '<col min="6" max="6" width="40" customWidth="1"/>'));
        $harness->assertTrue(str_contains($xlsx, '<col min="10" max="10" width="40" customWidth="1"/>'));
    });

    $harness->check(\eel_accounts\Service\StatementCsvExportService::class, 'opaque row key is stable when editable fields change', static function () use ($harness): void {
        $first = \eel_accounts\Service\StatementCsvExportService::exportRowKey(47, 216, 2, [
            'created' => '2026-02-01',
            'description' => 'Original description',
            'amount' => '12.34',
        ]);
        $second = \eel_accounts\Service\StatementCsvExportService::exportRowKey(47, 216, 2, [
            'created' => '2026-02-01',
            'description' => 'Edited description',
            'amount' => '99.99',
        ]);

        $harness->assertSame($first, $second);
    });
});
