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

    $html = $table->render(['page' => ['page_id' => 'test']]);

    $harness->assertTrue(str_contains($html, 'Demo rows 1-2 of 3'));
    $harness->assertTrue(strpos($html, 'Demo rows 1-2 of 3') > strpos($html, '</table>'));
    $harness->assertTrue(str_contains($html, '<label for="table-filter-demo_table-demo_status">Status</label>'));
    $harness->assertTrue(str_contains($html, '<option value="ready" selected>Ready</option>'));
    $harness->assertTrue(str_contains($html, 'method="post" data-ajax="true"'));
    $harness->assertTrue(str_contains($html, 'name="_table_export_prepare" value="csv"'));
    $harness->assertTrue(str_contains($html, 'name="_table_export_prepare" value="xlsx"'));
    $harness->assertTrue(strpos($html, 'name="_table_export_prepare" value="csv"') < strpos($html, '<table'));
    $harness->assertTrue(str_contains($html, 'name="demo_table_page" value="2"'));
    $harness->assertTrue(str_contains($html, 'name="_invalidate_fact" value="demo.table"'));
    $harness->assertTrue(str_contains($html, 'name="cards[]" value="demo_table"'));
    $harness->assertTrue(str_contains($html, '&lt; Prev'));
    $harness->assertTrue(str_contains($html, 'Next &gt;'));
    $harness->assertTrue(str_contains($html, '<span class="badge info">ready</span>'));
    $harness->assertSame(false, str_contains($html, '>Gamma<'));
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

    $html = $table->render(['page' => ['page_id' => 'test']]);
    $csv = $table->exportCsv();
    $sortedRows = $table->sortedRows();

    $harness->assertTrue(str_contains($html, 'class="table-sort-form"'));
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

    $boolRows = TableFramework::make('demo_table', $rows)
        ->column('name', 'Name')
        ->column('active', 'Active', exportType: 'bool')
        ->sorting('active', 'asc')
        ->sortedRows();

    $harness->assertSame('Beta', $boolRows[0]['name']);
    $harness->assertSame('Alpha', $boolRows[1]['name']);
    $harness->assertSame('alpha', $boolRows[2]['name']);
});

$harness->check(TableFramework::class, 'exports unpaginated rows and export-specific values to CSV', function () use ($harness, $rows): void {
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

    $harness->assertTrue(str_contains($csv, "Name,Status,Amount\n"));
    $harness->assertTrue(str_contains($csv, "Alpha,READY,12.5\n"));
    $harness->assertTrue(str_contains($csv, "Gamma,COMPLETE,31.75"));
    $harness->assertSame(false, str_contains($csv, 'Action'));
    $harness->assertSame(false, str_contains($csv, 'Ignore'));
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
    $harness->assertTrue(str_contains($csv, "Beta\n"));
    $harness->assertSame(false, str_contains($csv, 'Gamma'));
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
});

$harness->check(TableFramework::class, 'renders filters and custom toolbar actions in separate rows', function () use ($harness, $rows): void {
    $table = TableFramework::make('demo_table', $rows)
        ->exports(false)
        ->filterSelect('demo_status', 'Status', ['all' => 'All statuses', 'ready' => 'Ready'], 'all')
        ->toolbarActions('<button class="button" type="button">Auto Apply</button>')
        ->column('name', 'Name');

    $html = $table->renderToolbar(['page' => ['page_id' => 'test']]);
    $filterPosition = strpos($html, '<label for="table-filter-demo_table-demo_status">Status</label>');
    $customActionPosition = strpos($html, 'Auto Apply');

    $harness->assertTrue($filterPosition !== false);
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
