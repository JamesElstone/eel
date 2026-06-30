<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    ChartService::class,
    static function (GeneratedServiceClassTestHarness $harness, ChartService $service): void {
        $points = [
            ['label' => 'One', 'value' => 1],
            ['label' => 'Two', 'value' => 2],
        ];

        $harness->check(ChartService::class, 'renders bar chart SVG', static function () use ($harness, $service, $points): void {
            $html = $service->bar($points, ['title' => 'Test bar']);

            $harness->assertTrue(str_contains($html, '<svg'));
            $harness->assertTrue(str_contains($html, 'chart-bar'));
            $harness->assertTrue(str_contains($html, 'Test bar'));
        });

        $harness->check(ChartService::class, 'renders stacked bar chart SVG', static function () use ($harness, $service, $points): void {
            $html = $service->stackedBar([
                ['label' => 'First', 'points' => $points],
                ['label' => 'Second', 'points' => [
                    ['label' => 'One', 'value' => 2],
                    ['label' => 'Two', 'value' => 3],
                ]],
            ], ['title' => 'Test stacked bar']);

            $harness->assertTrue(str_contains($html, '<svg'));
            $harness->assertTrue(str_contains($html, 'chart-stacked-bar-segment'));
            $harness->assertTrue(str_contains($html, 'Second'));
        });

        $harness->check(ChartService::class, 'renders line chart SVG', static function () use ($harness, $service, $points): void {
            $html = $service->line($points, ['title' => 'Test line']);

            $harness->assertTrue(str_contains($html, '<svg'));
            $harness->assertTrue(str_contains($html, 'chart-line-path'));
            $harness->assertTrue(str_contains($html, 'Test line'));
        });

        $harness->check(ChartService::class, 'renders multi series line chart SVG', static function () use ($harness, $service, $points): void {
            $html = $service->line([
                ['label' => 'First', 'points' => $points],
                ['label' => 'Second', 'points' => [
                    ['label' => 'One', 'value' => 2],
                    ['label' => 'Two', 'value' => 3],
                ]],
            ], ['title' => 'Test multi line']);

            $harness->assertTrue(str_contains($html, '<svg'));
            $harness->assertTrue(substr_count($html, 'chart-line-path') === 2);
            $harness->assertTrue(str_contains($html, 'chart-legend-line'));
            $harness->assertTrue(str_contains($html, 'Second'));
        });

        $harness->check(ChartService::class, 'renders pie chart SVG', static function () use ($harness, $service, $points): void {
            $html = $service->pie($points, ['title' => 'Test pie']);

            $harness->assertTrue(str_contains($html, '<svg'));
            $harness->assertTrue(str_contains($html, 'chart-pie-slice'));
            $harness->assertTrue(str_contains($html, 'Test pie'));
        });

        $harness->check(ChartService::class, 'renders donut chart SVG', static function () use ($harness, $service, $points): void {
            $html = $service->donut($points, ['title' => 'Test donut']);

            $harness->assertTrue(str_contains($html, '<svg'));
            $harness->assertTrue(str_contains($html, 'chart-donut-segment'));
            $harness->assertTrue(str_contains($html, 'Test donut'));
        });

        $harness->check(ChartService::class, 'renders gauge SVG', static function () use ($harness, $service): void {
            $html = $service->gauge(72, ['title' => 'Test gauge']);

            $harness->assertTrue(str_contains($html, '<svg'));
            $harness->assertTrue(str_contains($html, 'chart-gauge-value'));
            $harness->assertTrue(str_contains($html, 'Test gauge'));
        });

        $harness->check(ChartService::class, 'renders sankey SVG', static function () use ($harness, $service): void {
            $html = $service->sankey([
                ['id' => 'cash', 'label' => 'Cash', 'column' => 0],
                ['id' => 'total', 'label' => 'Total', 'column' => 1],
                ['id' => 'profit', 'label' => 'Profit', 'column' => 2],
            ], [
                ['source' => 'cash', 'target' => 'total', 'value' => 100],
                ['source' => 'total', 'target' => 'profit', 'value' => 100],
            ], [
                'title' => 'Test sankey',
                'balance_node' => 'total',
            ]);

            $harness->assertTrue(str_contains($html, '<svg'));
            $harness->assertTrue(str_contains($html, 'chart-sankey-link'));
            $harness->assertTrue(str_contains($html, 'Balanced flow'));
            $harness->assertTrue(str_contains($html, 'Test sankey'));
        });

        $harness->check(ChartService::class, 'renders calendar heatmap HTML', static function () use ($harness, $service): void {
            $html = $service->calendarHeatmap([
                ['date' => '2026-05-12', 'value' => 1],
                ['date' => '2026-05-13', 'value' => 2],
                ['date' => '2026-05-14', 'value' => 4],
            ], [
                'title' => 'Test calendar heatmap',
                'start_date' => '2026-05-01',
                'end_date' => '2026-05-31',
                'selected_date' => '2026-05-14',
                'ajax_target' => 'test-calendar-table',
            ]);

            $harness->assertTrue(str_contains($html, 'calendar-heatmap'));
            $harness->assertTrue(str_contains($html, '<h3>Test calendar heatmap</h3>'));
            $harness->assertTrue(str_contains($html, 'class="select calendar-heatmap-year-select"'));
            $harness->assertTrue(str_contains($html, 'id="calendar-heatmap-heatmap-date-year"'));
            $harness->assertTrue(str_contains($html, '<option value="2026" selected>2026</option>'));
            $harness->assertTrue(str_contains($html, 'calendar-heatmap-day-level-4'));
            $harness->assertTrue(str_contains($html, 'is-selected'));
            $harness->assertTrue(str_contains($html, 'data-ajax-target="test-calendar-table"'));
            $harness->assertTrue(str_contains($html, 'value="2026-05-14"'));
            $harness->assertTrue(str_contains($html, 'title="4 records on 14 May 2026"'));
            $harness->assertTrue(str_contains($html, 'aria-label="4 records on 14 May 2026"'));
            $harness->assertTrue(str_contains($html, 'data-preserve-title="true"'));
            $harness->assertTrue(!str_contains($html, 'style='));
            $harness->assertTrue(!str_contains(strtolower($html), '<script'));
            $harness->assertTrue(preg_match('/\son[a-z]+\s*=/i', $html) !== 1);
        });

        $harness->check(ChartService::class, 'renders calendar heatmap with empty explicit range', static function () use ($harness, $service): void {
            $html = $service->calendarHeatmap([], [
                'title' => 'Empty calendar heatmap',
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
            ]);

            $harness->assertTrue(str_contains($html, 'calendar-heatmap'));
            $harness->assertTrue(str_contains($html, '<h3>Empty calendar heatmap</h3>'));
            $harness->assertTrue(str_contains($html, '<option value="2026" selected>2026</option>'));
            $harness->assertTrue(str_contains($html, 'calendar-heatmap-day-level-0'));
            $harness->assertTrue(str_contains($html, 'title="0 records on 1 January 2026"'));
            $harness->assertTrue(!str_contains($html, 'calendar-heatmap-empty'));
        });

        $harness->check(ChartService::class, 'renders calendar heatmap date range selector', static function () use ($harness, $service): void {
            $html = $service->calendarHeatmap([
                ['date' => '2026-04-01', 'value' => 2],
                ['date' => '2026-05-01', 'value' => 1],
            ], [
                'title' => 'Period calendar heatmap',
                'id' => 'expense-claim-calendar',
                'start_date' => '2026-04-01',
                'end_date' => '2027-03-31',
                'selected_date' => '2026-05-01',
                'input_name' => 'expense_heatmap_date',
                'range_control' => [
                    'type' => 'date',
                    'name' => 'expense_heatmap_period_start',
                    'id_suffix' => 'period-start',
                    'label' => 'Period <selector>',
                    'options' => [
                        ['value' => '2025-04-01', 'label' => '2025/26'],
                        ['value' => '2026-04-01', 'label' => '2026/27 & current'],
                        ['value' => 'not-a-date', 'label' => '<script>alert(1)</script>'],
                        ['value' => '2026-02-31', 'label' => 'Invalid rollover'],
                    ],
                    'selected_value' => '2026-04-01',
                ],
            ]);

            $harness->assertTrue(str_contains($html, 'class="select calendar-heatmap-range-select"'));
            $harness->assertTrue(str_contains($html, 'id="expense-claim-calendar-period-start"'));
            $harness->assertTrue(str_contains($html, 'name="expense_heatmap_period_start"'));
            $harness->assertTrue(str_contains($html, '<label class="sr-only" for="expense-claim-calendar-period-start">Period &lt;selector&gt;</label>'));
            $harness->assertTrue(str_contains($html, '<option value="2025-04-01">2025/26</option>'));
            $harness->assertTrue(str_contains($html, '<option value="2026-04-01" selected>2026/27 &amp; current</option>'));
            $harness->assertTrue(str_contains($html, 'value="2026-05-01"'));
            $harness->assertTrue(str_contains($html, 'is-selected'));
            $harness->assertTrue(!str_contains($html, 'calendar-heatmap-year-select'));
            $harness->assertTrue(!str_contains($html, 'not-a-date'));
            $harness->assertTrue(!str_contains($html, '2026-02-31'));
            $harness->assertTrue(!str_contains(strtolower($html), '<script'));
            $harness->assertTrue(preg_match('/\son[a-z]+\s*=/i', $html) !== 1);
        });

        $harness->check(ChartService::class, 'omits calendar heatmap date selector when all date options are invalid', static function () use ($harness, $service): void {
            $html = $service->calendarHeatmap([], [
                'title' => 'Invalid period calendar heatmap',
                'start_date' => '2026-04-01',
                'end_date' => '2026-04-30',
                'range_control' => [
                    'type' => 'date',
                    'options' => [
                        ['value' => ''],
                        ['value' => '2026-04-31', 'label' => 'Invalid date'],
                        ['value' => 'not-a-date', 'label' => 'Invalid text'],
                    ],
                ],
            ]);

            $harness->assertTrue(str_contains($html, '<h3>Invalid period calendar heatmap</h3>'));
            $harness->assertTrue(!str_contains($html, 'calendar-heatmap-range-select'));
            $harness->assertTrue(!str_contains($html, 'calendar-heatmap-year-select'));
            $harness->assertTrue(!str_contains($html, '<select'));
        });

        $harness->check(ChartService::class, 'renders month heatmap HTML', static function () use ($harness, $service): void {
            $html = $service->monthHeatmap([
                'id' => 'statement-coverage',
                'label' => 'Statement coverage',
                'start' => '2022-09-05',
                'end' => '2022-11-30',
                'months' => [
                    [
                        'month_key' => '2022-09-01',
                        'label' => 'Sep 2022',
                        'status' => 'fail',
                        'value' => 0,
                        'tooltip' => 'No CSV rows found for September 2022.',
                    ],
                    [
                        'month_key' => '2022-11-01',
                        'label' => 'Nov 2022',
                        'status' => 'warning',
                        'value' => 5,
                        'secondary_value' => 3,
                        'tooltip' => '5 rows uploaded. 3 rows need review.',
                    ],
                ],
                'legend' => [
                    'pass' => 'Covered',
                    'warning' => 'Needs review',
                    'fail' => 'Gap',
                    'muted' => 'No data',
                ],
            ]);

            $harness->assertTrue(str_contains($html, 'class="month-heatmap"'));
            $harness->assertTrue(str_contains($html, 'id="statement-coverage"'));
            $harness->assertTrue(str_contains($html, '<h3>Statement coverage</h3>'));
            $harness->assertTrue(substr_count($html, 'month-heatmap-cell ') === 3);
            $harness->assertTrue(str_contains($html, 'month-heatmap-cell--fail'));
            $harness->assertTrue(str_contains($html, 'month-heatmap-cell--warning'));
            $harness->assertTrue(str_contains($html, 'data-month-key="2022-10-01"'));
            $harness->assertTrue(str_contains($html, 'title="No data supplied for October 2022."'));
            $harness->assertTrue(str_contains($html, 'aria-label="Sep 2022: Gap. No CSV rows found for September 2022."'));
            $harness->assertTrue(str_contains($html, '5<br><span class="month-heatmap-cell-secondary-value">(3)</span>'));
            $harness->assertTrue(str_contains($html, 'data-month-secondary-value="3"'));
            $harness->assertTrue(str_contains($html, 'data-preserve-title="true"'));
            $harness->assertTrue(str_contains($html, 'month-heatmap-legend'));
            $harness->assertTrue(str_contains($html, 'Needs review'));
            $harness->assertTrue(!str_contains($html, 'style='));
            $harness->assertTrue(!str_contains(strtolower($html), '<script'));
            $harness->assertTrue(preg_match('/\son[a-z]+\s*=/i', $html) !== 1);
        });

        $harness->check(ChartService::class, 'renders month heatmap demo from context', static function () use ($harness, $service): void {
            $charts = $service->demoCalendarCharts([]);
            $html = (string)($charts['month_heatmap'] ?? '');

            $harness->assertTrue(str_contains($html, 'Statement coverage by month'));
            $harness->assertTrue(str_contains($html, 'month-heatmap-cell--pass'));
            $harness->assertTrue(str_contains($html, 'month-heatmap-cell--warning'));
            $harness->assertTrue(str_contains($html, 'month-heatmap-cell--fail'));
            $harness->assertTrue(str_contains($html, 'month-heatmap-cell--muted'));
        });

        $harness->check(ChartService::class, 'renders calendar heatmap demo from context', static function () use ($harness, $service): void {
            $charts = $service->demoCalendarCharts([
                'selected_year' => '2025',
                'selected_date' => '2025-02-03',
            ]);
            $html = (string)($charts['calendar_heatmap'] ?? '');

            $harness->assertTrue(str_contains($html, '<option value="2025" selected>2025</option>'));
            $harness->assertTrue(str_contains($html, 'value="2025-02-03"'));
            $harness->assertTrue(str_contains($html, 'is-selected'));
        });
    }
);
