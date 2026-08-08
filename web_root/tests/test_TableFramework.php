<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();

$rows = [
    ['name' => 'Alpha', 'status' => 'ready', 'amount' => 12.5],
    ['name' => 'Beta', 'status' => 'review', 'amount' => 25],
    ['name' => 'Gamma', 'status' => 'complete', 'amount' => 31.75],
];

$harness->check(TableFramework::class, 'renders visible rows with toolbar export and pagination controls', function () use ($harness, $rows): void {
    $pagination = HelperFramework::paginateArray($rows, 1, 2);
    $table = TableFramework::make('demo_table', $rows)
        ->filename('demo-table')
        ->column('name', 'Name')
        ->column(
            'status',
            'Status',
            html: static fn(array $row): string => '<span class="badge info">' . HelperFramework::escape((string)$row['status']) . '</span>',
            export: static fn(array $row): string => strtoupper((string)$row['status'])
        )
        ->column('amount', 'Amount', exportType: 'number')
        ->visibleRows((array)$pagination['items'])
        ->pagination($pagination, 'Demo rows', 'demo_table_page', [
            'page' => 'test',
            '_pagination' => '1',
            '_invalidate_fact' => 'demo.table',
            'cards[]' => ['demo_table'],
        ])
        ->filterSelect(
            'demo_status',
            'Status',
            ['all' => 'All statuses', 'ready' => 'Ready'],
            'ready',
            [
                'page' => 'test',
                '_pagination' => '1',
                '_invalidate_fact' => 'demo.table',
                'cards[]' => ['demo_table'],
            ]
        );

    $html = $table->render(['page' => ['page_id' => 'test', 'csrf_token' => 'test-csrf-token']]);

    $harness->assertTrue(str_contains($html, 'Demo rows 1-2 of 3'));
    $harness->assertTrue(str_contains($html, 'name="csrf_token" value="test-csrf-token"'));
    $harness->assertTrue(strpos($html, 'Demo rows 1-2 of 3') > strpos($html, '</table>'));
    $harness->assertTrue(str_contains($html, '<label for="table-filter-demo_table-demo_status">Status</label>'));
    $harness->assertTrue(str_contains($html, '<option value="ready" selected>Ready</option>'));
    $harness->assertTrue(str_contains($html, 'method="post" data-ajax="true"'));
    $harness->assertTrue(str_contains($html, 'name="_table_export_prepare" value="csv"'));
    $harness->assertTrue(str_contains($html, 'name="_table_export_prepare" value="xlsx"'));
    $harness->assertTrue(str_contains($html, 'name="_table_export_prepare" value="tsv"'));
    $harness->assertTrue(str_contains($html, 'name="_table_export_prepare" value="ascii"'));
    $harness->assertTrue(str_contains($html, 'class="button table-condensed-toggle"'));
    $harness->assertTrue(str_contains($html, 'data-table-condensed-default="0"'));
    $harness->assertTrue(str_contains($html, 'aria-pressed="false"'));
    $harness->assertSame(false, str_contains($html, 'data-table-condensed-default="1"'));
    $harness->assertSame(false, str_contains($html, 'class="button primary table-condensed-toggle"'));
    $harness->assertTrue(strpos($html, 'name="_table_export_prepare" value="csv"') < strpos($html, '<table'));
    $harness->assertTrue(strpos($html, 'name="_table_export_prepare" value="tsv"') < strpos($html, '<table'));
    $harness->assertTrue(strpos($html, 'name="_table_export_prepare" value="ascii"') < strpos($html, '<table'));
    $harness->assertTrue(str_contains($html, 'data-table-framework="true"'));
    $harness->assertTrue(str_contains($html, 'data-table-key="demo_table"'));
    $harness->assertTrue(str_contains($html, 'data-table-pagination-field="demo_table_page"'));
    $harness->assertTrue(str_contains($html, 'data-table-pagination-page="1"'));
    $harness->assertTrue(str_contains($html, 'name="demo_table_page" value="2"'));
    $harness->assertTrue(str_contains($html, 'name="_invalidate_fact" value="demo.table"'));
    $harness->assertTrue(str_contains($html, 'name="cards[]" value="demo_table"'));
    $harness->assertTrue(str_contains($html, '|&lt; First'));
    $harness->assertTrue(str_contains($html, '&lt; Prev'));
    $harness->assertTrue(str_contains($html, 'Next &gt;'));
    $harness->assertTrue(str_contains($html, 'Last &gt;|'));
    $harness->assertTrue(strpos($html, '|&lt; First') < strpos($html, '&lt; Prev'));
    $harness->assertTrue(strpos($html, '&lt; Prev') < strpos($html, 'Next &gt;'));
    $harness->assertTrue(strpos($html, 'Next &gt;') < strpos($html, 'Last &gt;|'));
    $harness->assertTrue(str_contains($html, '<span class="badge info">ready</span>'));
    $harness->assertSame(false, str_contains($html, '>Gamma<'));
    $harness->assertSame(false, str_contains($html, 'table-sort-form'));
    $harness->assertSame(false, str_contains($html, 'table-sortable-heading'));
});

$harness->check(TableFramework::class, 'renders configured condensed default state', function () use ($harness, $rows): void {
    $path = AppConfigurationStore::configPath();
    $original = file_get_contents($path);

    if (!is_string($original)) {
        throw new RuntimeException('Unable to read fixture config.');
    }

    try {
        AppConfigurationStore::set('table_condensed_default', true);

        $html = TableFramework::make('demo_table', $rows)
            ->column('name', 'Name')
            ->render(['page' => ['page_id' => 'test']]);

        $harness->assertTrue(str_contains($html, 'class="button primary table-condensed-toggle"'));
        $harness->assertTrue(str_contains($html, 'data-table-condensed-default="1"'));
        $harness->assertTrue(str_contains($html, 'aria-pressed="true"'));
        $harness->assertTrue(str_contains($html, 'class="table-scroll table-condensed"'));

        $wrapperlessHtml = TableFramework::make('demo_table', $rows)
            ->classes('', '')
            ->column('name', 'Name')
            ->render(['page' => ['page_id' => 'test']]);

        $harness->assertTrue(str_contains($wrapperlessHtml, '<table class="table-condensed">'));
    } finally {
        file_put_contents($path, $original, LOCK_EX);
        AppConfigurationStore::config(true);
    }
});

$harness->check(TableFramework::class, 'renders sortable headings and sorts full row sets', function () use ($harness): void {
    $rows = [
        ['name' => 'Beta', 'amount' => '10', 'active' => false, 'action' => 'ignore'],
        ['name' => 'alpha', 'amount' => '2', 'active' => true, 'action' => 'ignore'],
        ['name' => 'Alpha', 'amount' => '2', 'active' => false, 'action' => 'ignore'],
        ['name' => 'Gamma', 'amount' => '', 'active' => true, 'action' => 'ignore'],
    ];

    $table = TableFramework::make('demo_table', $rows)
        ->column('name', 'Name')
        ->column('amount', 'Amount', exportType: 'number')
        ->column('active', 'Active', exportType: 'bool')
        ->column('action', 'Action', html: static fn(): string => '<button>Ignore</button>', exportable: false)
        ->sorting('amount', 'asc', [
            'page' => 'test',
            '_pagination' => '1',
            '_invalidate_fact' => 'demo.table',
            'cards[]' => ['demo_table'],
        ])
        ->visibleRows([$rows[0]])
        ->pagination(HelperFramework::paginateArray($rows, 2, 1), 'Demo rows', 'demo_table_page', [
            'page' => 'test',
            '_pagination' => '1',
            '_invalidate_fact' => 'demo.table',
            'cards[]' => ['demo_table'],
        ]);

    $html = $table->render(['page' => ['page_id' => 'test', 'csrf_token' => 'sort-csrf-token']]);
    $csv = $table->exportCsv();
    $tsv = $table->exportTsv();
    $ascii = $table->exportAscii();
    $sortedRows = $table->sortedRows();

    $harness->assertTrue(str_contains($html, 'class="table-sort-form"'));
    $harness->assertTrue(str_contains($html, 'name="csrf_token" value="sort-csrf-token"'));
    $harness->assertTrue(str_contains($html, 'name="demo_table_sort" value="name"'));
    $harness->assertTrue(str_contains($html, 'name="demo_table_sort" value="amount"'));
    $harness->assertTrue(str_contains($html, 'name="demo_table_sort_direction" value="desc"'));
    $harness->assertTrue(str_contains($html, 'aria-sort="ascending"'));
    $harness->assertTrue(str_contains($html, 'name="demo_table_page" value="1"'));
    $harness->assertSame(false, str_contains($html, 'name="demo_table_sort" value="action"'));
    $harness->assertSame('alpha', $sortedRows[0]['name']);
    $harness->assertSame('Alpha', $sortedRows[1]['name']);
    $harness->assertSame('Beta', $sortedRows[2]['name']);
    $harness->assertSame('Gamma', $sortedRows[3]['name']);
    $harness->assertTrue(strpos($csv, "alpha,2,Yes") < strpos($csv, "Beta,10,No"));
    $harness->assertTrue(strpos($csv, "Beta,10,No") < strpos($csv, "Gamma,,Yes"));
    $harness->assertTrue(strpos($tsv, "alpha\t2\tYes") < strpos($tsv, "Beta\t10\tNo"));
    $harness->assertTrue(strpos($tsv, "Beta\t10\tNo") < strpos($tsv, "Gamma\t\tYes"));
    $harness->assertTrue(strpos($ascii, '| alpha | 2      | Yes    |') < strpos($ascii, '| Beta  | 10     | No     |'));
    $harness->assertTrue(strpos($ascii, '| Beta  | 10     | No     |') < strpos($ascii, '| Gamma |        | Yes    |'));

    $boolRows = TableFramework::make('demo_table', $rows)
        ->column('name', 'Name')
        ->column('active', 'Active', exportType: 'bool')
        ->sorting('active', 'asc')
        ->sortedRows();

    $harness->assertSame('Beta', $boolRows[0]['name']);
    $harness->assertSame('Alpha', $boolRows[1]['name']);
    $harness->assertSame('alpha', $boolRows[2]['name']);
});

$harness->check(TableFramework::class, 'exports unpaginated rows and export-specific values to CSV TSV and ASCII', function () use ($harness, $rows): void {
    $table = TableFramework::make('demo_table', $rows)
        ->column('name', 'Name')
        ->column(
            'status',
            'Status',
            html: static fn(array $row): string => '<span>' . HelperFramework::escape((string)$row['status']) . '</span>',
            export: static fn(array $row): string => strtoupper((string)$row['status'])
        )
        ->column('amount', 'Amount')
        ->column('action', 'Action', html: static fn(): string => '<button>Ignore</button>', exportable: false)
        ->visibleRows([$rows[0]]);

    $csv = $table->exportCsv();
    $tsv = $table->exportTsv();
    $ascii = $table->exportAscii();

    $harness->assertTrue(str_contains($csv, "Name,Status,Amount\n"));
    $harness->assertTrue(str_contains($csv, "Alpha,READY,12.5\n"));
    $harness->assertTrue(str_contains($csv, "Gamma,COMPLETE,31.75"));
    $harness->assertSame(false, str_contains($csv, 'Action'));
    $harness->assertSame(false, str_contains($csv, 'Ignore'));
    $harness->assertTrue(str_contains($tsv, "Name\tStatus\tAmount\n"));
    $harness->assertTrue(str_contains($tsv, "Alpha\tREADY\t12.5\n"));
    $harness->assertTrue(str_contains($tsv, "Gamma\tCOMPLETE\t31.75"));
    $harness->assertSame(false, str_contains($tsv, 'Action'));
    $harness->assertSame(false, str_contains($tsv, 'Ignore'));
    $harness->assertTrue(str_contains($ascii, '| Name  | Status   | Amount |'));
    $harness->assertTrue(str_contains($ascii, '| Alpha | READY    | 12.5   |'));
    $harness->assertTrue(str_contains($ascii, '| Gamma | COMPLETE | 31.75  |'));
    $harness->assertSame(false, str_contains($ascii, 'Action'));
    $harness->assertSame(false, str_contains($ascii, 'Ignore'));
});

$harness->check(TableFramework::class, 'keeps legacy export whitespace normalisation by default', function () use ($harness): void {
    $rows = [['value' => "  Line 1\t\tpart\r\nLine 2   part\rLine 3  "]];
    $defaultCsv = TableFramework::make('default_whitespace', $rows)
        ->column('value', 'Value')
        ->exportCsv();
    $explicitFalseCsv = TableFramework::make('explicit_whitespace', $rows)
        ->column('value', 'Value', preserveExportLineBreaks: false)
        ->exportCsv();

    $harness->assertSame("Value\n\"Line 1 part Line 2 part Line 3\"\n", $defaultCsv);
    $harness->assertSame($defaultCsv, $explicitFalseCsv);
});

$harness->check(TableFramework::class, 'preserves multiline scalar and callback values in CSV and TSV', function () use ($harness): void {
    $parseDelimited = static function (string $contents, string $delimiter): array {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open temporary delimited export stream.');
        }

        fwrite($handle, $contents);
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    };

    $table = TableFramework::make('multiline_delimited', [[
        'id' => 'TXN-1',
        'raw' => "  Line 1\t\tpart\r\nLine 2\r\rLine 4  ",
        'callback' => 'unused',
        'html' => 'unused',
    ]])
        ->column('id', 'ID')
        ->column('raw', 'Raw', preserveExportLineBreaks: true)
        ->column(
            'callback',
            'Callback',
            export: static fn(): string => "Error 1, \"quoted\"\r\nLocation\t\tone\rError 2",
            preserveExportLineBreaks: true
        )
        ->column(
            'html',
            'HTML',
            html: static fn(): string => "<span>HTML 1\r\nHTML 2</span>",
            export: true,
            preserveExportLineBreaks: true
        );

    $expectedRaw = "Line 1 part\nLine 2\n\nLine 4";
    $expectedCallback = "Error 1, \"quoted\"\nLocation one\nError 2";
    $expectedHtml = "HTML 1\nHTML 2";
    $csv = $table->exportCsv();
    $tsv = $table->exportTsv();
    $csvRows = $parseDelimited($csv, ',');
    $tsvRows = $parseDelimited($tsv, "\t");
    $html = $table->render(['page' => ['page_id' => 'test']]);

    $harness->assertSame(['ID', 'Raw', 'Callback', 'HTML'], $csvRows[0]);
    $harness->assertSame(['TXN-1', $expectedRaw, $expectedCallback, $expectedHtml], $csvRows[1]);
    $harness->assertSame($csvRows, $tsvRows);
    $harness->assertTrue(str_contains($csv, "\"Error 1, \"\"quoted\"\"\nLocation one\nError 2\""));
    $harness->assertTrue(str_contains($tsv, "\"Line 1 part\nLine 2\n\nLine 4\""));
    $harness->assertTrue(str_contains($html, '>Line 1 part Line 2 Line 4</td>'));
});

$harness->check(TableFramework::class, 'preserves multiline XLSX values and flattens ASCII records', function () use ($harness): void {
    $extractZipEntry = static function (string $archive, string $targetName): string {
        $offset = 0;
        $archiveLength = strlen($archive);

        while ($offset + 30 <= $archiveLength) {
            $header = unpack(
                'Vsignature/vversion/vflags/vcompression/vtime/vdate/Vcrc/VcompressedLength/VcontentsLength/vnameLength/vextraLength',
                substr($archive, $offset, 30)
            );
            if (!is_array($header) || (int)($header['signature'] ?? 0) !== 0x04034b50) {
                break;
            }

            $nameLength = (int)($header['nameLength'] ?? 0);
            $extraLength = (int)($header['extraLength'] ?? 0);
            $compressedLength = (int)($header['compressedLength'] ?? 0);
            $nameOffset = $offset + 30;
            $contentsOffset = $nameOffset + $nameLength + $extraLength;
            $name = substr($archive, $nameOffset, $nameLength);
            $compressed = substr($archive, $contentsOffset, $compressedLength);

            if ($name === $targetName) {
                if ((int)($header['compression'] ?? 0) === 0) {
                    return $compressed;
                }

                $contents = gzinflate($compressed);
                if (!is_string($contents)) {
                    throw new RuntimeException('Unable to inflate XLSX worksheet entry.');
                }

                return $contents;
            }

            $offset = $contentsOffset + $compressedLength;
        }

        throw new RuntimeException('XLSX worksheet entry was not found.');
    };

    $table = TableFramework::make('multiline_formats', [[
        'id' => '1',
        'evidence' => "Line 1\r\nLine 2 & \"quoted\"",
    ]])
        ->column('id', 'ID')
        ->column('evidence', 'Evidence', preserveExportLineBreaks: true);

    $worksheetXml = $extractZipEntry($table->exportXlsx(), 'xl/worksheets/sheet1.xml');
    $previousXmlErrorMode = libxml_use_internal_errors(true);
    $worksheet = simplexml_load_string($worksheetXml);
    libxml_clear_errors();
    libxml_use_internal_errors($previousXmlErrorMode);
    $harness->assertTrue($worksheet !== false);

    $worksheet->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $evidenceCells = $worksheet->xpath('//s:c[@r="B2"]/s:is/s:t');
    $harness->assertTrue(is_array($evidenceCells));
    $harness->assertSame("Line 1\nLine 2 & \"quoted\"", (string)($evidenceCells[0] ?? ''));

    $ascii = $table->exportAscii();
    $harness->assertTrue(str_contains($ascii, '| 1  | Line 1 Line 2 & "quoted" |'));
    $harness->assertSame(5, substr_count($ascii, "\n"));
});

$harness->check(TableFramework::class, 'passes multiline preservation through convenience columns', function () use ($harness): void {
    $table = TableFramework::make('multiline_convenience', [[
        'text' => "Text 1\nText 2",
        'status' => 'ready',
        'primary' => 'Primary',
        'details_json' => '{"detail":"value"}',
        'secondary' => 'Secondary',
    ]])
        ->textColumn('text', 'Text', preserveExportLineBreaks: true)
        ->badgeColumn(
            'status',
            'Status',
            labelFormatter: static fn(): string => "Ready\nStatus",
            preserveExportLineBreaks: true
        )
        ->textWithJsonSummaryColumn(
            'primary',
            'Summary',
            'details_json',
            separator: "\n",
            preserveExportLineBreaks: true
        )
        ->primarySecondaryColumn(
            'primary',
            'Primary / Secondary',
            'secondary',
            separator: "\n",
            preserveExportLineBreaks: true
        );

    $handle = fopen('php://temp', 'r+');
    if ($handle === false) {
        throw new RuntimeException('Unable to open temporary CSV stream.');
    }
    fwrite($handle, $table->exportCsv());
    rewind($handle);
    fgetcsv($handle, 0, ',', '"', '');
    $values = fgetcsv($handle, 0, ',', '"', '');
    fclose($handle);

    $harness->assertSame([
        "Text 1\nText 2",
        "Ready\nStatus",
        "Primary\ndetail: value",
        "Primary\nSecondary",
    ], $values);
});

$harness->check(TableFramework::class, 'keeps multiline column sorting backward compatible', function () use ($harness): void {
    $rows = [
        ['id' => 'beta', 'evidence' => "Beta\nEvidence", 'sort_rank' => 2],
        ['id' => 'alpha', 'evidence' => "Alpha\nEvidence", 'sort_rank' => 1],
    ];

    $defaultSorted = TableFramework::make('multiline_default_sort', $rows)
        ->column('evidence', 'Evidence', preserveExportLineBreaks: true)
        ->sorting('evidence', 'asc')
        ->sortedRows();
    $customSorted = TableFramework::make('multiline_custom_sort', array_reverse($rows))
        ->column(
            'evidence',
            'Evidence',
            sort: static fn(array $row): int => (int)$row['sort_rank'],
            preserveExportLineBreaks: true
        )
        ->sorting('evidence', 'desc')
        ->sortedRows();

    $harness->assertSame(['alpha', 'beta'], array_column($defaultSorted, 'id'));
    $harness->assertSame(['beta', 'alpha'], array_column($customSorted, 'id'));
});

$harness->check(TableFramework::class, 'exports ASCII grid tables', function () use ($harness): void {
    $table = TableFramework::make('demo_table', [
        ['id' => 261, 'display_name' => 'Alex Example'],
    ])
        ->column('id', 'id')
        ->column('display_name', 'display_name');

    $harness->assertSame(
        "+-----+--------------+\n"
            . "| id  | display_name |\n"
            . "+-----+--------------+\n"
            . "| 261 | Alex Example |\n"
            . "+-----+--------------+\n",
        $table->exportAscii()
    );
});

$harness->check(TableFramework::class, 'exports XLSX with all rows', function () use ($harness, $rows): void {
    $table = TableFramework::make('demo_table', $rows)
        ->filename('demo-table')
        ->column('name', 'Name')
        ->column('status', 'Status')
        ->visibleRows([$rows[0]]);

    $xlsx = $table->exportXlsx();

    $harness->assertSame('PK', substr($xlsx, 0, 2));
    $harness->assertTrue(str_contains($xlsx, '[Content_Types].xml'));
    $harness->assertTrue(str_contains($xlsx, 'xl/worksheets/sheet1.xml'));

    $download = $table->downloadResponse('xlsx');
    $harness->assertSame(
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        $download->contentType()
    );
    $harness->assertTrue(str_starts_with((string)$download->headerValue('Content-Disposition'), 'attachment; filename="demo-table_'));
    $harness->assertTrue(str_ends_with((string)$download->headerValue('Content-Disposition'), '.xlsx"'));

    $tsvDownload = $table->downloadResponse('tsv');
    $harness->assertSame('text/tab-separated-values; charset=utf-8', $tsvDownload->contentType());
    $harness->assertTrue(str_starts_with((string)$tsvDownload->headerValue('Content-Disposition'), 'attachment; filename="demo-table_'));
    $harness->assertTrue(str_ends_with((string)$tsvDownload->headerValue('Content-Disposition'), '.tsv"'));

    $asciiDownload = $table->downloadResponse('ascii');
    $harness->assertSame('text/plain; charset=utf-8', $asciiDownload->contentType());
    $harness->assertTrue(str_starts_with((string)$asciiDownload->headerValue('Content-Disposition'), 'attachment; filename="demo-table_'));
    $harness->assertTrue(str_ends_with((string)$asciiDownload->headerValue('Content-Disposition'), '.txt"'));
});

$harness->check(TableFramework::class, 'honours configured export formats and export row limits', function () use ($harness, $rows): void {
    $table = TableFramework::make('demo_table', $rows)
        ->filename('demo-table')
        ->exportFormats(['csv' => 'Comma'])
        ->exportLimit(2)
        ->column('name', 'Name');

    $html = $table->render(['page' => ['page_id' => 'test']]);
    $csv = $table->exportCsv();

    $harness->assertTrue(str_contains($html, '>Comma</button>'));
    $harness->assertSame(false, str_contains($html, 'XLSX'));
    $harness->assertSame(false, str_contains($html, 'TSV'));
    $harness->assertSame(false, str_contains($html, 'ASCII'));
    $harness->assertTrue(str_contains($csv, "Beta\n"));
    $harness->assertSame(false, str_contains($csv, 'Gamma'));

    $tsvTable = TableFramework::make('demo_table', $rows)
        ->filename('demo-table')
        ->exportFormats(['tsv' => 'Tabs'])
        ->exportLimit(2)
        ->column('name', 'Name');

    $tsvHtml = $tsvTable->render(['page' => ['page_id' => 'test']]);
    $tsv = $tsvTable->exportTsv();

    $harness->assertTrue(str_contains($tsvHtml, '>Tabs</button>'));
    $harness->assertSame(false, str_contains($tsvHtml, 'CSV'));
    $harness->assertSame(false, str_contains($tsvHtml, 'XLSX'));
    $harness->assertSame(false, str_contains($tsvHtml, 'ASCII'));
    $harness->assertTrue(str_contains($tsv, "Beta\n"));
    $harness->assertSame(false, str_contains($tsv, 'Gamma'));

    $asciiTable = TableFramework::make('demo_table', $rows)
        ->filename('demo-table')
        ->exportFormats(['ascii' => 'Text'])
        ->exportLimit(2)
        ->column('name', 'Name');

    $asciiHtml = $asciiTable->render(['page' => ['page_id' => 'test']]);
    $ascii = $asciiTable->exportAscii();

    $harness->assertTrue(str_contains($asciiHtml, '>Text</button>'));
    $harness->assertSame(false, str_contains($asciiHtml, 'CSV'));
    $harness->assertSame(false, str_contains($asciiHtml, 'XLSX'));
    $harness->assertSame(false, str_contains($asciiHtml, 'TSV'));
    $harness->assertTrue(str_contains($ascii, '| Beta  |'));
    $harness->assertSame(false, str_contains($ascii, 'Gamma'));
});

$harness->check(TableFramework::class, 'renders custom toolbar actions before built-in controls', function () use ($harness, $rows): void {
    $table = TableFramework::make('demo_table', $rows)
        ->toolbarActions('<button class="button" type="button">Auto Apply</button>')
        ->column('name', 'Name');

    $html = $table->render(['page' => ['page_id' => 'test']]);

    $harness->assertTrue(str_contains($html, '<button class="button" type="button">Auto Apply</button>'));
    $harness->assertTrue(strpos($html, 'Auto Apply') < strpos($html, 'Condensed View'));
    $harness->assertTrue(strpos($html, 'Auto Apply') < strpos($html, 'name="_table_export_prepare" value="csv"'));
    $harness->assertTrue(strpos($html, 'Auto Apply') < strpos($html, 'name="_table_export_prepare" value="xlsx"'));
    $harness->assertTrue(strpos($html, 'Auto Apply') < strpos($html, 'name="_table_export_prepare" value="tsv"'));
    $harness->assertTrue(strpos($html, 'Auto Apply') < strpos($html, 'name="_table_export_prepare" value="ascii"'));
});

$harness->check(TableFramework::class, 'renders custom toolbar actions when exports are disabled', function () use ($harness, $rows): void {
    $table = TableFramework::make('demo_table', $rows)
        ->exports(false)
        ->toolbarActions('<form method="post"><button class="button" type="submit">Post Categorised Transactions</button></form>')
        ->column('name', 'Name');

    $html = $table->render(['page' => ['page_id' => 'test']]);

    $harness->assertTrue(str_contains($html, '<div class="card-toolbar">'));
    $harness->assertTrue(str_contains($html, 'Post Categorised Transactions'));
    $harness->assertSame(false, str_contains($html, 'Condensed View'));
    $harness->assertSame(false, str_contains($html, 'name="_table_export_prepare" value="csv"'));
    $harness->assertSame(false, str_contains($html, 'name="_table_export_prepare" value="xlsx"'));
    $harness->assertSame(false, str_contains($html, 'name="_table_export_prepare" value="tsv"'));
    $harness->assertSame(false, str_contains($html, 'name="_table_export_prepare" value="ascii"'));
});

$harness->check(TableFramework::class, 'renders filters and custom toolbar actions in separate rows', function () use ($harness, $rows): void {
    $table = TableFramework::make('demo_table', $rows)
        ->exports(false)
        ->filterSelect('demo_status', 'Status', ['all' => 'All statuses', 'ready' => 'Ready'], 'all')
        ->toolbarActions('<button class="button" type="button">Auto Apply</button>')
        ->column('name', 'Name');

    $html = $table->renderToolbar(['page' => ['page_id' => 'test', 'csrf_token' => 'toolbar-csrf-token']]);
    $filterPosition = strpos($html, '<label for="table-filter-demo_table-demo_status">Status</label>');
    $customActionPosition = strpos($html, 'Auto Apply');

    $harness->assertTrue($filterPosition !== false);
    $harness->assertTrue(str_contains($html, 'name="csrf_token" value="toolbar-csrf-token"'));
    $harness->assertTrue($customActionPosition !== false);
    $harness->assertTrue($filterPosition < $customActionPosition);
    $harness->assertSame(2, substr_count($html, '<div class="actions-row">'));
});

$harness->check(TableFramework::class, 'renders convenience column formats with export-safe values', function () use ($harness): void {
    $rows = [[
        'actor' => '',
        'action_type' => 'password_reset',
        'reason' => 'Admin request',
        'details_json' => '{"forced_password_change":true,"attempts":2,"nested":{"ignored":true}}',
        'ip_address' => '127.0.0.1',
        'user_agent' => str_repeat('Browser ', 20),
    ]];

    $table = TableFramework::make('demo_table', $rows)
        ->textColumn('actor', 'Actor', fallback: 'System')
        ->badgeColumn('action_type', 'Action', badgeClass: 'info', labelSeparator: '_')
        ->textWithJsonSummaryColumn('reason', 'Reason', 'details_json')
        ->primarySecondaryColumn(
            'ip_address',
            'IP / User Agent',
            secondaryKey: 'user_agent',
            secondaryPreviewLength: 24,
            secondaryPreviewClass: 'log-agent-preview'
        );

    $html = $table->render(['page' => ['page_id' => 'test']]);
    $csv = $table->exportCsv();

    $harness->assertTrue(str_contains($html, '>System</td>'));
    $harness->assertTrue(str_contains($html, '<span class="badge info">Password Reset</span>'));
    $harness->assertTrue(str_contains($html, 'forced password change: true'));
    $harness->assertTrue(str_contains($html, 'class="helper log-agent-preview"'));
    $harness->assertTrue(str_contains($csv, 'System,"Password Reset"'));
    $harness->assertTrue(str_contains($csv, 'Admin request | forced password change: true | attempts: 2'));
    $harness->assertTrue(str_contains($csv, '127.0.0.1 | Browser Browser'));
});
