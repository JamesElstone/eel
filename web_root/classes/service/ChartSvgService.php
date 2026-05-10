<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class ChartSvgService
{
    private const DEFAULT_COLORS = [
        '#1d4ed8',
        '#16a34a',
        '#d97706',
        '#7c3aed',
        '#dc2626',
        '#0891b2',
        '#475569',
    ];

    /**
     * @param array<int, array{label?: string, value?: int|float|string, color?: string}> $points
     * @param array<string, mixed> $options
     */
    public function bar(array $points, array $options = []): string
    {
        $points = $this->normalisePoints($points);
        if ($points === []) {
            return $this->emptyChart('Bar chart', $options);
        }

        $width = $this->dimension($options, 'width', 640);
        $height = $this->dimension($options, 'height', 300);
        $padding = ['top' => 34.0, 'right' => 22.0, 'bottom' => 54.0, 'left' => 48.0];
        $plotWidth = $width - $padding['left'] - $padding['right'];
        $plotHeight = $height - $padding['top'] - $padding['bottom'];
        $max = max(1.0, max(array_column($points, 'value')));
        $barGap = 12.0;
        $barWidth = max(8.0, ($plotWidth - ($barGap * (count($points) - 1))) / count($points));
        $gridHtml = $this->gridLines($padding, $plotWidth, $plotHeight, $max, 4);
        $barsHtml = '';

        foreach ($points as $index => $point) {
            $x = $padding['left'] + (($barWidth + $barGap) * $index);
            $barHeight = ($point['value'] / $max) * $plotHeight;
            $y = $padding['top'] + $plotHeight - $barHeight;
            $color = $this->color($point, $index);

            $barsHtml .= '<rect class="chart-bar" x="' . $this->number($x) . '" y="' . $this->number($y) . '" width="' . $this->number($barWidth) . '" height="' . $this->number($barHeight) . '" fill="' . HelperFramework::escape($color) . '">';
            $barsHtml .= '<title>' . HelperFramework::escape($point['label'] . ': ' . $this->formatValue($point['value'])) . '</title>';
            $barsHtml .= '</rect>';
            $barsHtml .= '<text class="chart-axis-label" x="' . $this->number($x + ($barWidth / 2)) . '" y="' . $this->number($height - 22) . '" text-anchor="middle">' . HelperFramework::escape($point['label']) . '</text>';
            $barsHtml .= '<text class="chart-value-label" x="' . $this->number($x + ($barWidth / 2)) . '" y="' . $this->number(max($padding['top'] + 14, $y - 8)) . '" text-anchor="middle">' . HelperFramework::escape($this->formatValue($point['value'])) . '</text>';
        }

        return $this->svg(
            $width,
            $height,
            (string)($options['title'] ?? 'Bar chart'),
            $gridHtml . $barsHtml . $this->axisLines($padding, $plotWidth, $plotHeight),
            'bar'
        );
    }

    /**
     * @param array<int, array{label?: string, value?: int|float|string, color?: string, points?: array<int, array{label?: string, value?: int|float|string, color?: string}>}> $points
     * @param array<string, mixed> $options
     */
    public function line(array $points, array $options = []): string
    {
        $series = $this->normaliseLineSeries($points, $options);
        if ($series === [] || count($series[0]['points']) < 2) {
            return $this->emptyChart('Line chart', $options);
        }

        $width = $this->dimension($options, 'width', 640);
        $height = $this->dimension($options, 'height', 300);
        $hasLegend = count($series) > 1;
        $padding = ['top' => 34.0, 'right' => $hasLegend ? 150.0 : 24.0, 'bottom' => 54.0, 'left' => 48.0];
        $plotWidth = $width - $padding['left'] - $padding['right'];
        $plotHeight = $height - $padding['top'] - $padding['bottom'];
        $values = [];
        $xLabels = [];

        foreach ($series as $lineSeries) {
            foreach ($lineSeries['points'] as $index => $point) {
                $values[] = $point['value'];
                if (!isset($xLabels[$index])) {
                    $xLabels[$index] = $point['label'];
                }
            }
        }

        $min = min(0.0, min($values));
        $max = max(1.0, max($values));
        if ($max === $min) {
            $max += 1.0;
        }

        $stepX = $plotWidth / max(1, count($xLabels) - 1);
        $lineHtml = '';
        $pointHtml = '';
        $labelHtml = '';
        $legendHtml = '';

        foreach ($xLabels as $index => $label) {
            $x = $padding['left'] + ($stepX * $index);
            $labelHtml .= '<text class="chart-axis-label" x="' . $this->number($x) . '" y="' . $this->number($height - 22) . '" text-anchor="middle">' . HelperFramework::escape($label) . '</text>';
        }

        foreach ($series as $seriesIndex => $lineSeries) {
            $coordinates = [];
            $color = $lineSeries['color'];

            foreach ($lineSeries['points'] as $index => $point) {
                $x = $padding['left'] + ($stepX * $index);
                $y = $padding['top'] + $plotHeight - (($point['value'] - $min) / ($max - $min) * $plotHeight);
                $coordinates[] = $this->number($x) . ',' . $this->number($y);
                $pointHtml .= '<circle class="chart-line-point" cx="' . $this->number($x) . '" cy="' . $this->number($y) . '" r="4" stroke="' . HelperFramework::escape($color) . '">';
                $pointHtml .= '<title>' . HelperFramework::escape($lineSeries['label'] . ' - ' . $point['label'] . ': ' . $this->formatValue($point['value'])) . '</title>';
                $pointHtml .= '</circle>';
            }

            $lineHtml .= '<polyline class="chart-line-path" points="' . HelperFramework::escape(implode(' ', $coordinates)) . '" stroke="' . HelperFramework::escape($color) . '"></polyline>';

            if ($hasLegend) {
                $legendY = 52 + ($seriesIndex * 23);
                $legendX = $padding['left'] + $plotWidth + 20;
                $legendHtml .= '<line class="chart-legend-line" x1="' . $this->number($legendX) . '" y1="' . $this->number($legendY - 4) . '" x2="' . $this->number($legendX + 14) . '" y2="' . $this->number($legendY - 4) . '" stroke="' . HelperFramework::escape($color) . '"></line>';
                $legendHtml .= '<text class="chart-legend-label" x="' . $this->number($legendX + 22) . '" y="' . $this->number($legendY) . '">' . HelperFramework::escape($lineSeries['label']) . '</text>';
            }
        }

        return $this->svg(
            $width,
            $height,
            (string)($options['title'] ?? 'Line chart'),
            $this->gridLines($padding, $plotWidth, $plotHeight, $max, 4, $min) . $lineHtml . $pointHtml . $labelHtml . $legendHtml . $this->axisLines($padding, $plotWidth, $plotHeight),
            'line'
        );
    }

    /**
     * @param array<int, array{label?: string, value?: int|float|string, color?: string}> $segments
     * @param array<string, mixed> $options
     */
    public function pie(array $segments, array $options = []): string
    {
        $segments = $this->normalisePoints($segments);
        if ($segments === []) {
            return $this->emptyChart('Pie chart', $options);
        }

        $width = $this->dimension($options, 'width', 420);
        $height = $this->dimension($options, 'height', 300);
        $radius = min($width, $height) * 0.34;
        $centerX = $width * 0.34;
        $centerY = $height * 0.5;
        $total = max(1.0, array_sum(array_column($segments, 'value')));
        $startAngle = -90.0;
        $pathsHtml = '';
        $legendHtml = '';

        foreach ($segments as $index => $segment) {
            $angle = ($segment['value'] / $total) * 360.0;
            $endAngle = $startAngle + $angle;
            $largeArc = $angle > 180.0 ? 1 : 0;
            $start = $this->polarPoint($centerX, $centerY, $radius, $startAngle);
            $end = $this->polarPoint($centerX, $centerY, $radius, $endAngle);
            $color = $this->color($segment, $index);
            $percent = round(($segment['value'] / $total) * 100, 1);

            $pathsHtml .= '<path class="chart-pie-slice" d="M ' . $this->number($centerX) . ' ' . $this->number($centerY) . ' L ' . $this->number($start['x']) . ' ' . $this->number($start['y']) . ' A ' . $this->number($radius) . ' ' . $this->number($radius) . ' 0 ' . $largeArc . ' 1 ' . $this->number($end['x']) . ' ' . $this->number($end['y']) . ' Z" fill="' . HelperFramework::escape($color) . '">';
            $pathsHtml .= '<title>' . HelperFramework::escape($segment['label'] . ': ' . $this->formatValue($segment['value']) . ' (' . $percent . '%)') . '</title>';
            $pathsHtml .= '</path>';

            $legendY = 62 + ($index * 28);
            $legendHtml .= '<rect class="chart-legend-swatch" x="' . $this->number($width * 0.66) . '" y="' . $this->number($legendY - 11) . '" width="12" height="12" fill="' . HelperFramework::escape($color) . '"></rect>';
            $legendHtml .= '<text class="chart-legend-label" x="' . $this->number(($width * 0.66) + 20) . '" y="' . $this->number($legendY) . '">' . HelperFramework::escape($segment['label'] . ' ' . $percent . '%') . '</text>';
            $startAngle = $endAngle;
        }

        return $this->svg(
            $width,
            $height,
            (string)($options['title'] ?? 'Pie chart'),
            $pathsHtml . $legendHtml,
            'pie'
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    public function gauge(float|int|string $value, array $options = []): string
    {
        $width = $this->dimension($options, 'width', 420);
        $height = $this->dimension($options, 'height', 250);
        $min = (float)($options['min'] ?? 0);
        $max = (float)($options['max'] ?? 100);
        if ($max <= $min) {
            $max = $min + 100;
        }

        $value = max($min, min($max, (float)$value));
        $percent = ($value - $min) / ($max - $min);
        $centerX = $width / 2;
        $centerY = $height * 0.78;
        $radius = min($width * 0.36, $height * 0.58);
        $background = $this->arcPath($centerX, $centerY, $radius, 180, 360);
        $foreground = $this->arcPath($centerX, $centerY, $radius, 180, 180 + (180 * $percent));
        $label = trim((string)($options['label'] ?? 'Gauge'));
        $color = trim((string)($options['color'] ?? '#1d4ed8'));

        $html = '<path class="chart-gauge-track" d="' . HelperFramework::escape($background) . '"></path>';
        $html .= '<path class="chart-gauge-value" d="' . HelperFramework::escape($foreground) . '" stroke="' . HelperFramework::escape($color) . '"></path>';
        $html .= '<text class="chart-gauge-number" x="' . $this->number($centerX) . '" y="' . $this->number($centerY - 24) . '" text-anchor="middle">' . HelperFramework::escape($this->formatValue($value)) . '</text>';
        $html .= '<text class="chart-gauge-label" x="' . $this->number($centerX) . '" y="' . $this->number($centerY + 6) . '" text-anchor="middle">' . HelperFramework::escape($label) . '</text>';
        $html .= '<text class="chart-axis-label" x="' . $this->number($centerX - $radius) . '" y="' . $this->number($centerY + 28) . '" text-anchor="middle">' . HelperFramework::escape($this->formatValue($min)) . '</text>';
        $html .= '<text class="chart-axis-label" x="' . $this->number($centerX + $radius) . '" y="' . $this->number($centerY + 28) . '" text-anchor="middle">' . HelperFramework::escape($this->formatValue($max)) . '</text>';

        return $this->svg($width, $height, (string)($options['title'] ?? 'Gauge'), $html, 'gauge');
    }

    /**
     * @return array<string, string>
     */
    public function demoTrendCharts(): array
    {
        $monthly = [
            ['label' => 'Jan', 'value' => 42],
            ['label' => 'Feb', 'value' => 58],
            ['label' => 'Mar', 'value' => 51],
            ['label' => 'Apr', 'value' => 74],
            ['label' => 'May', 'value' => 69],
            ['label' => 'Jun', 'value' => 88],
        ];

        $series = [
            [
                'label' => 'Support',
                'color' => '#1d4ed8',
                'points' => [
                    ['label' => 'Jan', 'value' => 42],
                    ['label' => 'Feb', 'value' => 58],
                    ['label' => 'Mar', 'value' => 51],
                    ['label' => 'Apr', 'value' => 74],
                    ['label' => 'May', 'value' => 69],
                    ['label' => 'Jun', 'value' => 88],
                ],
            ],
            [
                'label' => 'Ops',
                'color' => '#16a34a',
                'points' => [
                    ['label' => 'Jan', 'value' => 36],
                    ['label' => 'Feb', 'value' => 44],
                    ['label' => 'Mar', 'value' => 62],
                    ['label' => 'Apr', 'value' => 66],
                    ['label' => 'May', 'value' => 81],
                    ['label' => 'Jun', 'value' => 79],
                ],
            ],
            [
                'label' => 'Security',
                'color' => '#d97706',
                'points' => [
                    ['label' => 'Jan', 'value' => 18],
                    ['label' => 'Feb', 'value' => 28],
                    ['label' => 'Mar', 'value' => 34],
                    ['label' => 'Apr', 'value' => 47],
                    ['label' => 'May', 'value' => 43],
                    ['label' => 'Jun', 'value' => 55],
                ],
            ],
            [
                'label' => 'Billing',
                'color' => '#7c3aed',
                'points' => [
                    ['label' => 'Jan', 'value' => 24],
                    ['label' => 'Feb', 'value' => 31],
                    ['label' => 'Mar', 'value' => 29],
                    ['label' => 'Apr', 'value' => 36],
                    ['label' => 'May', 'value' => 52],
                    ['label' => 'Jun', 'value' => 61],
                ],
            ],
            [
                'label' => 'Projects',
                'color' => '#0891b2',
                'points' => [
                    ['label' => 'Jan', 'value' => 12],
                    ['label' => 'Feb', 'value' => 22],
                    ['label' => 'Mar', 'value' => 41],
                    ['label' => 'Apr', 'value' => 38],
                    ['label' => 'May', 'value' => 56],
                    ['label' => 'Jun', 'value' => 72],
                ],
            ],
        ];

        return [
            'bar' => $this->bar($monthly, ['title' => 'Monthly workload']),
            'line' => $this->line($series, ['title' => 'Monthly trend by team']),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function demoCompositionCharts(): array
    {
        return [
            'pie' => $this->pie([
                ['label' => 'Complete', 'value' => 46, 'color' => '#16a34a'],
                ['label' => 'Review', 'value' => 22, 'color' => '#d97706'],
                ['label' => 'Queued', 'value' => 32, 'color' => '#1d4ed8'],
            ], ['title' => 'Work status split']),
            'gauge' => $this->gauge(72, [
                'title' => 'Service health gauge',
                'label' => 'Health',
                'color' => '#16a34a',
            ]),
        ];
    }

    /**
     * @param array<int, array{label?: string, value?: int|float|string, color?: string}> $points
     * @return array<int, array{label: string, value: float, color?: string}>
     */
    private function normalisePoints(array $points): array
    {
        $normalised = [];

        foreach ($points as $index => $point) {
            if (!is_array($point)) {
                continue;
            }

            $value = (float)($point['value'] ?? 0);
            if (!is_finite($value) || $value < 0) {
                continue;
            }

            $label = trim((string)($point['label'] ?? ('Item ' . ($index + 1))));
            $item = [
                'label' => $label !== '' ? $label : ('Item ' . ($index + 1)),
                'value' => $value,
            ];

            if (isset($point['color'])) {
                $item['color'] = (string)$point['color'];
            }

            $normalised[] = $item;
        }

        return $normalised;
    }

    /**
     * @param array<int, array{label?: string, value?: int|float|string, color?: string, points?: array<int, array{label?: string, value?: int|float|string, color?: string}>}> $seriesInput
     * @param array<string, mixed> $options
     * @return array<int, array{label: string, color: string, points: array<int, array{label: string, value: float, color?: string}>}>
     */
    private function normaliseLineSeries(array $seriesInput, array $options): array
    {
        $hasSeriesShape = false;

        foreach ($seriesInput as $item) {
            if (is_array($item) && array_key_exists('points', $item)) {
                $hasSeriesShape = true;
                break;
            }
        }

        if (!$hasSeriesShape) {
            $points = $this->normalisePoints($seriesInput);

            return $points === []
                ? []
                : [[
                    'label' => trim((string)($options['series_label'] ?? 'Series')),
                    'color' => trim((string)($options['color'] ?? self::DEFAULT_COLORS[0])) ?: self::DEFAULT_COLORS[0],
                    'points' => $points,
                ]];
        }

        $series = [];

        foreach ($seriesInput as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $points = $this->normalisePoints((array)($item['points'] ?? []));
            if (count($points) < 2) {
                continue;
            }

            $label = trim((string)($item['label'] ?? ('Series ' . ($index + 1))));
            $color = trim((string)($item['color'] ?? self::DEFAULT_COLORS[$index % count(self::DEFAULT_COLORS)]));

            $series[] = [
                'label' => $label !== '' ? $label : ('Series ' . ($index + 1)),
                'color' => $color !== '' ? $color : self::DEFAULT_COLORS[$index % count(self::DEFAULT_COLORS)],
                'points' => $points,
            ];
        }

        return array_slice($series, 0, 5);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function dimension(array $options, string $key, int $default): int
    {
        $value = (int)($options[$key] ?? $default);

        return max(160, min(1200, $value));
    }

    /**
     * @param array{label: string, value: float, color?: string} $point
     */
    private function color(array $point, int $index): string
    {
        $color = trim((string)($point['color'] ?? ''));

        return $color !== '' ? $color : self::DEFAULT_COLORS[$index % count(self::DEFAULT_COLORS)];
    }

    /**
     * @param array{top: float, right: float, bottom: float, left: float} $padding
     */
    private function gridLines(array $padding, float $plotWidth, float $plotHeight, float $max, int $steps, float $min = 0.0): string
    {
        $html = '';
        $range = max(1.0, $max - $min);

        for ($step = 0; $step <= $steps; $step++) {
            $ratio = $step / max(1, $steps);
            $y = $padding['top'] + ($plotHeight * $ratio);
            $value = $max - ($range * $ratio);
            $html .= '<line class="chart-grid-line" x1="' . $this->number($padding['left']) . '" y1="' . $this->number($y) . '" x2="' . $this->number($padding['left'] + $plotWidth) . '" y2="' . $this->number($y) . '"></line>';
            $html .= '<text class="chart-axis-label" x="' . $this->number($padding['left'] - 10) . '" y="' . $this->number($y + 4) . '" text-anchor="end">' . HelperFramework::escape($this->formatValue($value)) . '</text>';
        }

        return $html;
    }

    /**
     * @param array{top: float, right: float, bottom: float, left: float} $padding
     */
    private function axisLines(array $padding, float $plotWidth, float $plotHeight): string
    {
        return '<line class="chart-axis-line" x1="' . $this->number($padding['left']) . '" y1="' . $this->number($padding['top']) . '" x2="' . $this->number($padding['left']) . '" y2="' . $this->number($padding['top'] + $plotHeight) . '"></line>'
            . '<line class="chart-axis-line" x1="' . $this->number($padding['left']) . '" y1="' . $this->number($padding['top'] + $plotHeight) . '" x2="' . $this->number($padding['left'] + $plotWidth) . '" y2="' . $this->number($padding['top'] + $plotHeight) . '"></line>';
    }

    /**
     * @return array{x: float, y: float}
     */
    private function polarPoint(float $centerX, float $centerY, float $radius, float $angleDegrees): array
    {
        $angleRadians = deg2rad($angleDegrees);

        return [
            'x' => $centerX + ($radius * cos($angleRadians)),
            'y' => $centerY + ($radius * sin($angleRadians)),
        ];
    }

    private function arcPath(float $centerX, float $centerY, float $radius, float $startAngle, float $endAngle): string
    {
        $start = $this->polarPoint($centerX, $centerY, $radius, $startAngle);
        $end = $this->polarPoint($centerX, $centerY, $radius, $endAngle);
        $largeArc = abs($endAngle - $startAngle) > 180 ? 1 : 0;

        return 'M ' . $this->number($start['x']) . ' ' . $this->number($start['y'])
            . ' A ' . $this->number($radius) . ' ' . $this->number($radius)
            . ' 0 ' . $largeArc . ' 1 '
            . $this->number($end['x']) . ' ' . $this->number($end['y']);
    }

    private function number(float|int $value): string
    {
        return rtrim(rtrim(number_format((float)$value, 3, '.', ''), '0'), '.');
    }

    private function formatValue(float|int $value): string
    {
        $value = (float)$value;

        return abs($value - round($value)) < 0.001
            ? (string)(int)round($value)
            : number_format($value, 1);
    }

    private function svg(int $width, int $height, string $title, string $content, string $type): string
    {
        $titleId = 'chart-title-' . bin2hex(random_bytes(4));

        return '<svg class="chart chart-' . HelperFramework::escape($type) . '" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-labelledby="' . HelperFramework::escape($titleId) . '" preserveAspectRatio="xMidYMid meet">'
            . '<title id="' . HelperFramework::escape($titleId) . '">' . HelperFramework::escape($title) . '</title>'
            . $content
            . '</svg>';
    }

    /**
     * @param array<string, mixed> $options
     */
    private function emptyChart(string $defaultTitle, array $options): string
    {
        $width = $this->dimension($options, 'width', 420);
        $height = $this->dimension($options, 'height', 220);
        $title = (string)($options['title'] ?? $defaultTitle);

        return $this->svg(
            $width,
            $height,
            $title,
            '<rect class="chart-empty-box" x="16" y="16" width="' . ($width - 32) . '" height="' . ($height - 32) . '"></rect>'
            . '<text class="chart-empty-label" x="' . $this->number($width / 2) . '" y="' . $this->number($height / 2) . '" text-anchor="middle">No chart data</text>',
            'empty'
        );
    }
}
