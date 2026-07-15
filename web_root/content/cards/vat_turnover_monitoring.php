<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _vat_turnover_monitoringCard extends CardBaseFramework
{
    private const PAGE_SIZE = 13;

    public function key(): string
    {
        return 'vat_turnover_monitoring';
    }

    public function title(): string
    {
        return 'Gross Income and VAT Threshold Monitoring';
    }

    public function helper(array $context): string
    {
        return 'Monthly gross accounting income is shown for the selected accounting period. The rolling line uses exact trailing 12-month date windows across accounting-period boundaries.';
    }

    public function services(): array
    {
        return [[
            'key' => 'vat_turnover_monitoring',
            'service' => \eel_accounts\Service\VatTurnoverMonitoringService::class,
            'method' => 'fetchMonitoring',
            'params' => [
                'companyId' => ':company.id',
                'accountingPeriodId' => ':company.accounting_period_id',
            ],
        ]];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $pageContext = parent::handle($request, $services, $pageContext, $actionResult);

        return $this->applyPaginationContext($request, $pageContext);
    }

    public function render(array $context): string
    {
        $monitoring = (array)($context['services']['vat_turnover_monitoring'] ?? []);
        if (empty($monitoring['available'])) {
            return '<div class="helper">' . HelperFramework::escape((string)($monitoring['message'] ?? 'Select a company and accounting period to view gross income.')) . '</div>';
        }
        if (!empty($monitoring['not_started'])) {
            return '<div class="helper">This accounting period has not started. Select a current or past period to view income monitoring.</div>' . $this->links();
        }

        $settings = (array)($context['company']['settings'] ?? ($context['page']['settings'] ?? []));
        $threshold = (array)($monitoring['threshold'] ?? []);
        $thresholdAmount = !empty($threshold['available'])
            ? $this->money($settings, $threshold['registration_threshold'] ?? 0)
            : 'Unavailable';
        $headroom = $monitoring['threshold_headroom'] ?? null;
        $headroomText = $headroom === null
            ? 'Unavailable'
            : ((float)$headroom >= 0
                ? $this->money($settings, $headroom)
                : $this->money($settings, abs((float)$headroom)) . ' above threshold');

        $chartService = new ChartService();
        $barPoints = (array)($monitoring['bar_points'] ?? []);
        $hasNegativeMonth = array_filter($barPoints, static fn(array $point): bool => (float)($point['value'] ?? 0) < 0.0) !== [];
        $monthlyChart = $hasNegativeMonth
            ? $this->signedBarChart($barPoints, 'Monthly gross accounting income (signed bar values)')
            : $chartService->bar($barPoints, [
                'title' => 'Monthly gross accounting income',
                'height' => 320,
            ]);
        $rollingPoints = (array)($monitoring['rolling_points'] ?? []);
        $thresholdPoints = (array)($monitoring['threshold_points'] ?? []);
        $singlePointComparison = count($rollingPoints) === 1;
        if ($singlePointComparison) {
            $effectiveDate = (string)($monitoring['effective_date'] ?? '');
            $rollingPoints = $this->repeatSingleLinePoint($rollingPoints, $effectiveDate);
            $thresholdPoints = $this->repeatSingleLinePoint($thresholdPoints, $effectiveDate);
        }

        $lineSeries = [[
            'label' => 'Cumulative Income',
            'color' => '#1d4ed8',
            'points' => $rollingPoints,
        ]];
        if (count($thresholdPoints) >= 2) {
            $lineSeries[] = [
                'label' => 'Threshold',
                'color' => '#dc2626',
                'points' => $thresholdPoints,
            ];
        }
        $rollingChart = $chartService->line($lineSeries, [
            'title' => 'Cumulative Income against Threshold',
            'height' => 320,
        ]);

        return $this->limitationsPanel((array)($monitoring['warnings'] ?? []))
            . (empty($threshold['available']) ? $this->thresholdImportNotice() : '')
            . '<div class="summary-grid">'
            . $this->summary('Effective date', (string)($monitoring['effective_date'] ?? ''))
            . $this->summary('Gross Income in Accounting Period', $this->money($settings, $monitoring['ap_to_date_gross_income'] ?? 0))
            . $this->summary('Cumulative Income', $this->money($settings, $monitoring['trailing_12_month_gross_income'] ?? 0))
            . $this->summary('Threshold', $thresholdAmount)
            . $this->summary('Threshold used', ($monitoring['threshold_percentage_used'] ?? null) === null ? 'Unavailable' : number_format((float)$monitoring['threshold_percentage_used'], 1) . '%')
            . $this->summary('Headroom', $headroomText)
            . '</div>'
            . '<div class="chart-demo-grid vat-turnover-monitoring-chart-grid">'
            . '<div class="chart-panel"><h4 class="card-title">Monthly Gross Income</h4>' . $monthlyChart . '</div>'
            . '<div class="chart-panel"><h4 class="card-title">VAT Threshold Monitor</h4>' . $rollingChart
            . ($singlePointComparison
                ? '<div class="helper">Only one monthly observation is available. Each available series is repeated at the effective date solely to render this comparison.</div>'
                : '')
            . '</div>'
            . '</div>'
            . '<section class="panel-soft">'
            . $this->monthTable($context, (array)($monitoring['months'] ?? []), $settings)
            . '</section>';
    }

    public function tables(array $context): array
    {
        $monitoring = (array)($context['services']['vat_turnover_monitoring'] ?? []);
        $settings = (array)($context['company']['settings'] ?? ($context['page']['settings'] ?? []));

        return [$this->monthTableBuilder((array)($monitoring['months'] ?? []), $settings)];
    }

    private function monthTable(array $context, array $months, array $settings): string
    {
        $table = $this->monthTableBuilder($months, $settings);
        $pagination = HelperFramework::paginateArray($table->sortedRows(), $this->paginationPage($context), self::PAGE_SIZE);
        $hiddenFields = [
            'page' => (string)($context['page']['page_id'] ?? 'vat'),
            '_pagination' => '1',
            '_invalidate_fact' => (string)($this->invalidationFacts()[0] ?? $this->key()),
            'cards[]' => [$this->key()],
        ];

        return $table
            ->visibleRows((array)$pagination['items'])
            ->pagination($pagination, 'Months', $this->paginationPageField(), $hiddenFields)
            ->render($context, ['cards[]' => (array)($context['page']['page_cards'] ?? [])]);
    }

    private function monthTableBuilder(array $months, array $settings): TableFramework
    {
        $rows = [];
        foreach ($months as $month) {
            if (!is_array($month)) {
                continue;
            }
            $headroom = $month['threshold_headroom'] ?? null;
            $headroomText = $headroom === null
                ? 'Unavailable'
                : ((float)$headroom >= 0
                    ? $this->money($settings, $headroom)
                    : $this->money($settings, abs((float)$headroom)) . ' above');
            $rows[] = [
                'month' => (string)($month['label'] ?? ''),
                'included_dates' => (string)($month['start_date'] ?? '') . ' to ' . (string)($month['end_date'] ?? ''),
                'monthly_gross_income' => (float)($month['gross_income'] ?? 0),
                'cumulative_income' => (float)($month['rolling_12_month_gross_income'] ?? 0),
                'threshold' => $month['registration_threshold'] ?? null,
                'headroom' => $headroomText,
                'coverage' => (string)($month['coverage_label'] ?? 'Unknown'),
                'coverage_complete' => !empty($month['coverage_complete']),
            ];
        }

        $moneyColumn = fn(string $key, string $label): array => [
            $key,
            $label,
            fn(array $row): string => HelperFramework::escape($this->money($settings, $row[$key] ?? 0)),
            static fn(array $row): string => number_format((float)($row[$key] ?? 0), 2, '.', ''),
        ];
        [$monthlyKey, $monthlyLabel, $monthlyHtml, $monthlyExport] = $moneyColumn('monthly_gross_income', 'Monthly Gross Income');
        [$cumulativeKey, $cumulativeLabel, $cumulativeHtml, $cumulativeExport] = $moneyColumn('cumulative_income', 'Cumulative Income');

        return TableFramework::make($this->key(), $rows)
            ->filename('vat-turnover-monitoring')
            ->exportLimit(5000)
            ->empty('No accounting-period months are available at the effective date.')
            ->classes(wrapperClass: 'table-scroll vat-turnover-monitoring-table')
            ->textColumn('month', 'Month')
            ->textColumn('included_dates', 'Included Dates')
            ->column($monthlyKey, $monthlyLabel, html: $monthlyHtml, export: $monthlyExport, cellClass: 'numeric', exportType: 'number')
            ->column($cumulativeKey, $cumulativeLabel, html: $cumulativeHtml, export: $cumulativeExport, cellClass: 'numeric', exportType: 'number')
            ->column(
                'threshold',
                'Threshold',
                html: fn(array $row): string => ($row['threshold'] ?? null) === null
                    ? 'Unavailable'
                    : HelperFramework::escape($this->money($settings, $row['threshold'])),
                export: static fn(array $row): string => ($row['threshold'] ?? null) === null
                    ? ''
                    : number_format((float)$row['threshold'], 2, '.', ''),
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->textColumn('headroom', 'Headroom', cellClass: 'numeric')
            ->column(
                'coverage',
                'Coverage',
                html: static fn(array $row): string => '<span class="badge ' . (!empty($row['coverage_complete']) ? 'success' : 'warning') . '">'
                    . HelperFramework::escape((string)($row['coverage'] ?? 'Unknown')) . '</span>',
                export: static fn(array $row): string => (string)($row['coverage'] ?? 'Unknown')
            );
    }

    private function warnings(array $warnings): string
    {
        if ($warnings === []) {
            return '';
        }

        return '<div class="helper"><strong>Important limitations and coverage checks</strong><ul><li>'
            . implode('</li><li>', array_map(static fn(mixed $warning): string => HelperFramework::escape((string)$warning), $warnings))
            . '</li></ul></div>';
    }

    private function limitationsPanel(array $warnings): string
    {
        return '<section class="panel-soft">'
            . $this->warnings($warnings)
            . $this->links()
            . '</section>';
    }

    private function links(): string
    {
        return '<div class="helper">Official guidance: '
            . '<a class="button button-inline" href="' . \eel_accounts\Service\VatThresholdRuleService::REGISTRATION_GUIDANCE_URL . '" target="_blank" rel="noopener noreferrer">HMRC - Register for VAT</a>'
            . ' <a class="button button-inline" href="' . \eel_accounts\Service\VatThresholdRuleService::THRESHOLDS_URL . '" target="_blank" rel="noopener noreferrer">HMRC - VAT Thresholds</a>'
            . '</div>';
    }

    private function thresholdImportNotice(): string
    {
        return '<div class="helper"><strong>Threshold unavailable.</strong> '
            . '<a class="button button-inline" href="?page=tax_rates">Import HMRC VAT thresholds on the Tax Rates page</a>.'
            . '</div>';
    }

    private function summary(string $label, string $value): string
    {
        return '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label)
            . '</div><div class="summary-value">' . HelperFramework::escape($value) . '</div></div>';
    }

    private function money(array $settings, mixed $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($settings, $value);
    }

    private function repeatSingleLinePoint(array $points, string $effectiveDate): array
    {
        $points = array_values(array_filter($points, static fn(mixed $point): bool => is_array($point)));
        if (count($points) !== 1) {
            return $points;
        }

        $point = $points[0];
        $point['label'] = trim($effectiveDate) !== ''
            ? 'As at ' . trim($effectiveDate)
            : (string)($point['label'] ?? 'Effective date');
        $points[] = $point;

        return $points;
    }

    private function signedBarChart(array $points, string $title): string
    {
        $points = array_values(array_filter($points, static fn(mixed $point): bool => is_array($point)));
        if ($points === []) {
            return '';
        }

        $width = 640.0;
        $height = 320.0;
        $left = 58.0;
        $right = 24.0;
        $top = 34.0;
        $bottom = 54.0;
        $plotWidth = $width - $left - $right;
        $plotHeight = $height - $top - $bottom;
        $values = array_map(static fn(array $point): float => (float)($point['value'] ?? 0), $points);
        $min = min(0.0, min($values));
        $max = max(0.0, max($values));
        if ($max <= $min) {
            $max = $min + 1.0;
        }
        $range = $max - $min;
        $zeroY = $top + $plotHeight - ((0.0 - $min) / $range * $plotHeight);
        $gap = 12.0;
        $barWidth = max(8.0, ($plotWidth - ($gap * max(0, count($points) - 1))) / max(1, count($points)));
        $html = '';

        foreach ([$max, 0.0, $min] as $gridValue) {
            $gridY = $top + $plotHeight - (($gridValue - $min) / $range * $plotHeight);
            $html .= '<line class="chart-grid-line' . (abs($gridValue) < 0.001 ? ' chart-zero-axis-line' : '')
                . '" x1="' . $this->chartNumber($left) . '" y1="' . $this->chartNumber($gridY)
                . '" x2="' . $this->chartNumber($left + $plotWidth) . '" y2="' . $this->chartNumber($gridY) . '"></line>'
                . '<text class="chart-axis-label" x="' . $this->chartNumber($left - 10) . '" y="' . $this->chartNumber($gridY + 4)
                . '" text-anchor="end">' . HelperFramework::escape(number_format($gridValue, 0)) . '</text>';
        }

        foreach ($points as $index => $point) {
            $value = (float)($point['value'] ?? 0);
            $valueY = $top + $plotHeight - (($value - $min) / $range * $plotHeight);
            $x = $left + (($barWidth + $gap) * $index);
            $y = min($zeroY, $valueY);
            $barHeight = max(1.0, abs($zeroY - $valueY));
            $label = trim((string)($point['label'] ?? ''));
            $color = $value < 0 ? '#dc2626' : '#1d4ed8';
            $html .= '<rect class="chart-bar' . ($value < 0 ? ' chart-bar-negative' : '') . '" x="' . $this->chartNumber($x)
                . '" y="' . $this->chartNumber($y) . '" width="' . $this->chartNumber($barWidth)
                . '" height="' . $this->chartNumber($barHeight) . '" fill="' . $color . '"><title>'
                . HelperFramework::escape($label . ': ' . number_format($value, 2)) . '</title></rect>'
                . '<text class="chart-axis-label" x="' . $this->chartNumber($x + ($barWidth / 2)) . '" y="' . $this->chartNumber($height - 22)
                . '" text-anchor="middle">' . HelperFramework::escape($label) . '</text>';
        }

        $titleId = 'vat-signed-bar-' . bin2hex(random_bytes(4));

        return '<svg class="chart chart-bar chart-signed-bar" viewBox="0 0 640 320" role="img" aria-labelledby="'
            . HelperFramework::escape($titleId) . '" preserveAspectRatio="xMidYMid meet"><title id="'
            . HelperFramework::escape($titleId) . '">' . HelperFramework::escape($title) . '</title>'
            . $html
            . '<line class="chart-axis-line" x1="' . $this->chartNumber($left) . '" y1="' . $this->chartNumber($top)
            . '" x2="' . $this->chartNumber($left) . '" y2="' . $this->chartNumber($top + $plotHeight) . '"></line>'
            . '</svg>';
    }

    private function chartNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
