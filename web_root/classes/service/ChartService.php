<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class ChartService
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
     * @param array<int, array{label?: string, color?: string, points?: array<int, array{label?: string, value?: int|float|string, color?: string}>}> $seriesInput
     * @param array<string, mixed> $options
     */
    public function stackedBar(array $seriesInput, array $options = []): string
    {
        $series = $this->normaliseSeries($seriesInput, 5);
        if ($series === []) {
            return $this->emptyChart('Stacked bar chart', $options);
        }

        $width = $this->dimension($options, 'width', 640);
        $height = $this->dimension($options, 'height', 300);
        $padding = ['top' => 34.0, 'right' => count($series) > 1 ? 150.0 : 24.0, 'bottom' => 54.0, 'left' => 48.0];
        $plotWidth = $width - $padding['left'] - $padding['right'];
        $plotHeight = $height - $padding['top'] - $padding['bottom'];
        $xLabels = $this->seriesLabels($series);
        $totals = array_fill(0, count($xLabels), 0.0);

        foreach ($series as $stackSeries) {
            foreach ($stackSeries['points'] as $index => $point) {
                $totals[$index] = ($totals[$index] ?? 0.0) + $point['value'];
            }
        }

        $max = max(1.0, max($totals));
        $barGap = 14.0;
        $barWidth = max(10.0, ($plotWidth - ($barGap * (count($xLabels) - 1))) / count($xLabels));
        $barsHtml = '';
        $labelHtml = '';
        $legendHtml = '';

        foreach ($xLabels as $index => $label) {
            $x = $padding['left'] + (($barWidth + $barGap) * $index);
            $stackedValue = 0.0;

            foreach ($series as $seriesIndex => $stackSeries) {
                $point = $stackSeries['points'][$index] ?? ['label' => $label, 'value' => 0.0];
                $value = (float)$point['value'];
                if ($value <= 0) {
                    continue;
                }

                $segmentHeight = ($value / $max) * $plotHeight;
                $y = $padding['top'] + $plotHeight - (($stackedValue + $value) / $max * $plotHeight);
                $barsHtml .= '<rect class="chart-bar chart-stacked-bar-segment" x="' . $this->number($x) . '" y="' . $this->number($y) . '" width="' . $this->number($barWidth) . '" height="' . $this->number($segmentHeight) . '" fill="' . HelperFramework::escape($stackSeries['color']) . '">';
                $barsHtml .= '<title>' . HelperFramework::escape($stackSeries['label'] . ' - ' . $label . ': ' . $this->formatValue($value)) . '</title>';
                $barsHtml .= '</rect>';
                $stackedValue += $value;
            }

            $labelHtml .= '<text class="chart-axis-label" x="' . $this->number($x + ($barWidth / 2)) . '" y="' . $this->number($height - 22) . '" text-anchor="middle">' . HelperFramework::escape($label) . '</text>';
            $labelHtml .= '<text class="chart-value-label" x="' . $this->number($x + ($barWidth / 2)) . '" y="' . $this->number(max($padding['top'] + 14, $padding['top'] + $plotHeight - (($totals[$index] / $max) * $plotHeight) - 8)) . '" text-anchor="middle">' . HelperFramework::escape($this->formatValue($totals[$index])) . '</text>';
        }

        foreach ($series as $seriesIndex => $stackSeries) {
            $legendY = 52 + ($seriesIndex * 23);
            $legendX = $padding['left'] + $plotWidth + 20;
            $legendHtml .= '<rect class="chart-legend-swatch" x="' . $this->number($legendX) . '" y="' . $this->number($legendY - 12) . '" width="12" height="12" fill="' . HelperFramework::escape($stackSeries['color']) . '"></rect>';
            $legendHtml .= '<text class="chart-legend-label" x="' . $this->number($legendX + 20) . '" y="' . $this->number($legendY) . '">' . HelperFramework::escape($stackSeries['label']) . '</text>';
        }

        return $this->svg(
            $width,
            $height,
            (string)($options['title'] ?? 'Stacked bar chart'),
            $this->gridLines($padding, $plotWidth, $plotHeight, $max, 4) . $barsHtml . $labelHtml . $legendHtml . $this->axisLines($padding, $plotWidth, $plotHeight),
            'stacked-bar'
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
        $showLegend = ($options['legend'] ?? true) !== false;

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

            if ($showLegend) {
                $legendY = 62 + ($index * 28);
                $legendHtml .= '<rect class="chart-legend-swatch" x="' . $this->number($width * 0.66) . '" y="' . $this->number($legendY - 11) . '" width="12" height="12" fill="' . HelperFramework::escape($color) . '"></rect>';
                $legendHtml .= '<text class="chart-legend-label" x="' . $this->number(($width * 0.66) + 20) . '" y="' . $this->number($legendY) . '">' . HelperFramework::escape($segment['label'] . ' ' . $percent . '%') . '</text>';
            }
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
     * @param array<int, array{label?: string, value?: int|float|string, color?: string}> $segments
     * @param array<string, mixed> $options
     */
    public function donut(array $segments, array $options = []): string
    {
        $segments = $this->normalisePoints($segments);
        if ($segments === []) {
            return $this->emptyChart('Donut chart', $options);
        }

        $width = $this->dimension($options, 'width', 420);
        $height = $this->dimension($options, 'height', 300);
        $radius = min($width, $height) * 0.27;
        $strokeWidth = max(18.0, $radius * 0.42);
        $centerX = $width * 0.34;
        $centerY = $height * 0.5;
        $total = max(1.0, array_sum(array_column($segments, 'value')));
        $circumference = 2 * pi() * $radius;
        $offset = 0.0;
        $segmentsHtml = '';
        $legendHtml = '';
        $showLegend = ($options['legend'] ?? true) !== false;

        foreach ($segments as $index => $segment) {
            $share = $segment['value'] / $total;
            $dash = $share * $circumference;
            $gap = max(0.0, $circumference - $dash);
            $color = $this->color($segment, $index);
            $percent = round($share * 100, 1);

            $segmentsHtml .= '<circle class="chart-donut-segment" cx="' . $this->number($centerX) . '" cy="' . $this->number($centerY) . '" r="' . $this->number($radius) . '" fill="none" stroke="' . HelperFramework::escape($color) . '" stroke-width="' . $this->number($strokeWidth) . '" stroke-dasharray="' . $this->number($dash) . ' ' . $this->number($gap) . '" stroke-dashoffset="' . $this->number(-$offset) . '" transform="rotate(-90 ' . $this->number($centerX) . ' ' . $this->number($centerY) . ')">';
            $segmentsHtml .= '<title>' . HelperFramework::escape($segment['label'] . ': ' . $this->formatValue($segment['value']) . ' (' . $percent . '%)') . '</title>';
            $segmentsHtml .= '</circle>';

            if ($showLegend) {
                $legendY = 62 + ($index * 28);
                $legendHtml .= '<rect class="chart-legend-swatch" x="' . $this->number($width * 0.66) . '" y="' . $this->number($legendY - 11) . '" width="12" height="12" fill="' . HelperFramework::escape($color) . '"></rect>';
                $legendHtml .= '<text class="chart-legend-label" x="' . $this->number(($width * 0.66) + 20) . '" y="' . $this->number($legendY) . '">' . HelperFramework::escape($segment['label'] . ' ' . $percent . '%') . '</text>';
            }
            $offset += $dash;
        }

        $centerLabel = trim((string)($options['center_label'] ?? $this->formatValue($total)));
        $centerSubLabel = trim((string)($options['center_sub_label'] ?? 'Total'));
        $centerHtml = '<text class="chart-donut-number" x="' . $this->number($centerX) . '" y="' . $this->number($centerY - 2) . '" text-anchor="middle">' . HelperFramework::escape($centerLabel) . '</text>';
        $centerHtml .= '<text class="chart-donut-label" x="' . $this->number($centerX) . '" y="' . $this->number($centerY + 18) . '" text-anchor="middle">' . HelperFramework::escape($centerSubLabel) . '</text>';

        return $this->svg(
            $width,
            $height,
            (string)($options['title'] ?? 'Donut chart'),
            $segmentsHtml . $centerHtml . $legendHtml,
            'donut'
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
     * @param array<int, array{id?: string, label?: string, column?: int|string, color?: string}> $nodesInput
     * @param array<int, array{source?: string, target?: string, value?: int|float|string, color?: string}> $linksInput
     * @param array<string, mixed> $options
     */
    public function sankey(array $nodesInput, array $linksInput, array $options = []): string
    {
        $nodes = $this->normaliseSankeyNodes($nodesInput);
        $links = $this->normaliseSankeyLinks($linksInput, $nodes);

        if ($nodes === [] || $links === []) {
            return $this->emptyChart('Sankey diagram', $options);
        }

        $width = $this->dimension($options, 'width', 760);
        $height = $this->dimension($options, 'height', 360);
        $padding = ['top' => 28.0, 'right' => 126.0, 'bottom' => 34.0, 'left' => 126.0];
        $plotWidth = $width - $padding['left'] - $padding['right'];
        $plotHeight = $height - $padding['top'] - $padding['bottom'];
        $nodeWidth = 18.0;
        $nodeGap = 18.0;
        $nodeValues = $this->sankeyNodeValues($nodes, $links);
        $columns = $this->sankeyColumns($nodes);
        $scale = $this->sankeyScale($columns, $nodeValues, $plotHeight, $nodeGap);
        $maxColumn = max(array_keys($columns));
        $columnStep = $maxColumn > 0 ? ($plotWidth - $nodeWidth) / $maxColumn : 0.0;
        $layout = [];

        foreach ($columns as $column => $nodeIds) {
            $columnHeight = 0.0;
            foreach ($nodeIds as $nodeId) {
                $columnHeight += max(8.0, $nodeValues[$nodeId] * $scale);
            }
            $columnHeight += max(0, count($nodeIds) - 1) * $nodeGap;

            $y = $padding['top'] + max(0.0, ($plotHeight - $columnHeight) / 2);
            foreach ($nodeIds as $nodeId) {
                $nodeHeight = max(8.0, $nodeValues[$nodeId] * $scale);
                $layout[$nodeId] = [
                    'x' => $padding['left'] + ($columnStep * $column),
                    'y' => $y,
                    'height' => $nodeHeight,
                    'width' => $nodeWidth,
                ];
                $y += $nodeHeight + $nodeGap;
            }
        }

        $incomingOffsets = array_fill_keys(array_keys($nodes), 0.0);
        $outgoingOffsets = array_fill_keys(array_keys($nodes), 0.0);
        $linksHtml = '';
        $nodesHtml = '';
        $labelHtml = '';

        foreach ($links as $index => $link) {
            $source = $link['source'];
            $target = $link['target'];
            $thickness = max(2.0, $link['value'] * $scale);
            $sourceLayout = $layout[$source];
            $targetLayout = $layout[$target];
            $sourceX = $sourceLayout['x'] + $nodeWidth;
            $sourceY = $sourceLayout['y'] + $outgoingOffsets[$source] + ($thickness / 2);
            $targetX = $targetLayout['x'];
            $targetY = $targetLayout['y'] + $incomingOffsets[$target] + ($thickness / 2);
            $controlOffset = max(40.0, abs($targetX - $sourceX) * 0.5);
            $color = trim((string)($link['color'] ?? $nodes[$source]['color'] ?? self::DEFAULT_COLORS[$index % count(self::DEFAULT_COLORS)]));
            $path = 'M ' . $this->number($sourceX) . ' ' . $this->number($sourceY)
                . ' C ' . $this->number($sourceX + $controlOffset) . ' ' . $this->number($sourceY)
                . ', ' . $this->number($targetX - $controlOffset) . ' ' . $this->number($targetY)
                . ', ' . $this->number($targetX) . ' ' . $this->number($targetY);

            $linksHtml .= '<path class="chart-sankey-link" d="' . HelperFramework::escape($path) . '" stroke="' . HelperFramework::escape($color) . '" stroke-width="' . $this->number($thickness) . '">';
            $linksHtml .= '<title>' . HelperFramework::escape($nodes[$source]['label'] . ' to ' . $nodes[$target]['label'] . ': ' . $this->formatSankeyValue($link['value'], $options)) . '</title>';
            $linksHtml .= '</path>';

            $outgoingOffsets[$source] += $thickness;
            $incomingOffsets[$target] += $thickness;
        }

        foreach ($layout as $nodeId => $nodeLayout) {
            $node = $nodes[$nodeId];
            $column = (int)$node['column'];
            $labelX = $column === 0 ? $nodeLayout['x'] - 10 : $nodeLayout['x'] + $nodeWidth + 10;
            $labelAnchor = $column === 0 ? 'end' : 'start';
            $labelY = $nodeLayout['y'] + ($nodeLayout['height'] / 2) - 2;
            $valueY = $labelY + 15;

            $nodesHtml .= '<rect class="chart-sankey-node" x="' . $this->number($nodeLayout['x']) . '" y="' . $this->number($nodeLayout['y']) . '" width="' . $this->number($nodeWidth) . '" height="' . $this->number($nodeLayout['height']) . '" fill="' . HelperFramework::escape($node['color']) . '">';
            $nodesHtml .= '<title>' . HelperFramework::escape($node['label'] . ': ' . $this->formatSankeyValue($nodeValues[$nodeId], $options)) . '</title>';
            $nodesHtml .= '</rect>';
            $labelHtml .= '<text class="chart-sankey-label" x="' . $this->number($labelX) . '" y="' . $this->number($labelY) . '" text-anchor="' . $labelAnchor . '">' . HelperFramework::escape($node['label']) . '</text>';
            $labelHtml .= '<text class="chart-sankey-value" x="' . $this->number($labelX) . '" y="' . $this->number($valueY) . '" text-anchor="' . $labelAnchor . '">' . HelperFramework::escape($this->formatSankeyValue($nodeValues[$nodeId], $options)) . '</text>';
        }

        $balanceHtml = $this->sankeyBalanceHtml($nodes, $links, $options, $width, $height);

        return $this->svg(
            $width,
            $height,
            (string)($options['title'] ?? 'Sankey diagram'),
            $linksHtml . $nodesHtml . $labelHtml . $balanceHtml,
            'sankey'
        );
    }

    /**
     * Render a server-side HTML calendar heat map.
     *
     * The $days array should contain one item per date with a Y-m-d date and
     * a non-negative numeric value. Missing dates inside the selected range are
     * rendered as zero-value days. Optional label/title values override the
     * generated accessible text and hover title for that day.
     *
     * Example:
     * [
     *     ['date' => '2026-05-12', 'value' => 1],
     *     ['date' => '2026-05-13', 'value' => 2, 'title' => '2 records on 13 May 2026'],
     *     ['date' => '2026-05-14', 'value' => 4, 'label' => 'Peak day: 4 records'],
     * ]
     *
     * Supported options:
     * - title: Visible heading and aria-label for the heat map.
     * - id: Optional HTML id prefix used for internal labels and controls.
     * - start_date/end_date: Y-m-d date range to render; inferred from $days when omitted.
     * - selected_date: Y-m-d date to mark with the selected state.
     * - input_name: Button name used when a day is submitted.
     * - year_input_name: Select name used for the year picker.
     * - years: Optional list of integer years to show in the year picker.
     * - range_control: Optional selector config; type=date renders date-valued options, type=year keeps the year picker.
     * - ajax_target: Optional target id/data value for the framework AJAX handler.
     * - ajax_url: Optional formaction value applied to each day button.
     * - value_label: Label used in generated day titles, for example "records".
     * - legend: Set false to hide the Less/More legend.
     *
     * @param array<int, array{date?: string, value?: int|float|string, label?: string, title?: string}> $days
     * @param array<string, mixed> $options
     */
    public function calendarHeatmap(array $days, array $options = []): string
    {
        $valuesByDate = $this->normaliseCalendarHeatmapDays($days);
        $range = $this->calendarHeatmapRange($valuesByDate, $options);

        if ($range === null) {
            return '<div class="calendar-heatmap calendar-heatmap-empty">' . HelperFramework::escape((string)($options['empty_message'] ?? 'No calendar data')) . '</div>';
        }

        $start = $range['start'];
        $end = $range['end'];
        $gridStart = $start->modify('-' . ((int)$start->format('N') - 1) . ' days');
        $gridEnd = $end->modify('+' . (7 - (int)$end->format('N')) . ' days');
        $weekCount = max(1, (int)floor(((int)$gridStart->diff($gridEnd)->format('%a')) / 7) + 1);
        $values = array_values(array_map(static fn(array $item): float => $item['value'], $valuesByDate));
        $max = max([1.0, ...$values]);
        $selectedDate = $this->calendarHeatmapDateOption($options, 'selected_date');
        $inputName = trim((string)($options['input_name'] ?? 'heatmap_date'));
        $yearInputName = trim((string)($options['year_input_name'] ?? 'heatmap_year'));
        $ajaxTarget = trim((string)($options['ajax_target'] ?? ''));
        $ajaxUrl = trim((string)($options['ajax_url'] ?? ''));
        $valueLabel = trim((string)($options['value_label'] ?? 'records'));
        $chartTitle = trim((string)($options['title'] ?? 'Calendar heatmap'));
        $controlId = $this->calendarHeatmapId($options, $inputName);

        $headingHtml = '<div class="calendar-heatmap-heading">'
            . '<h3>' . HelperFramework::escape($chartTitle !== '' ? $chartTitle : 'Calendar heatmap') . '</h3>'
            . $this->calendarHeatmapRangeControl($start, $end, $selectedDate, $yearInputName, $options, $controlId)
            . '</div>';
        $monthHtml = $this->calendarHeatmapMonthLabels($gridStart, $gridEnd, $weekCount);
        $dayHtml = '';
        $cursor = $gridStart;

        while ($cursor <= $gridEnd) {
            $date = $cursor->format('Y-m-d');
            $item = $valuesByDate[$date] ?? ['value' => 0.0, 'label' => '', 'title' => ''];
            $value = $item['value'];
            $level = $cursor < $start || $cursor > $end ? 0 : $this->calendarHeatmapLevel($value, $max);
            $classes = [
                'calendar-heatmap-day',
                'calendar-heatmap-day-level-' . $level,
            ];

            if ($cursor < $start || $cursor > $end) {
                $classes[] = 'is-outside-range';
            }

            if ($selectedDate !== null && $date === $selectedDate->format('Y-m-d')) {
                $classes[] = 'is-selected';
            }

            $title = trim($item['title']) !== ''
                ? $item['title']
                : (trim($item['label']) !== '' ? $item['label'] : $this->calendarHeatmapTitle($cursor, $value, $valueLabel));
            $attributes = [
                'class' => implode(' ', $classes),
                'type' => 'submit',
                'name' => $inputName !== '' ? $inputName : 'heatmap_date',
                'value' => $date,
                'title' => $title,
                'aria-label' => $title,
                'data-preserve-title' => 'true',
                'data-heatmap-date' => $date,
                'data-heatmap-value' => $this->formatValue($value),
            ];

            if ($ajaxTarget !== '') {
                $attributes['data-ajax-target'] = $ajaxTarget;
            }

            if ($ajaxUrl !== '') {
                $attributes['formaction'] = $ajaxUrl;
            }

            $dayHtml .= '<button' . $this->htmlAttributes($attributes) . '><span class="sr-only">' . HelperFramework::escape($title) . '</span></button>';
            $cursor = $cursor->modify('+1 day');
        }

        $legendHtml = '';
        if (($options['legend'] ?? true) !== false) {
            $legendHtml = '<div class="calendar-heatmap-legend"><span>Less</span>'
                . '<span class="calendar-heatmap-legend-swatch calendar-heatmap-day-level-0"></span>'
                . '<span class="calendar-heatmap-legend-swatch calendar-heatmap-day-level-1"></span>'
                . '<span class="calendar-heatmap-legend-swatch calendar-heatmap-day-level-2"></span>'
                . '<span class="calendar-heatmap-legend-swatch calendar-heatmap-day-level-3"></span>'
                . '<span class="calendar-heatmap-legend-swatch calendar-heatmap-day-level-4"></span>'
                . '<span>More</span></div>';
        }

        return '<div class="calendar-heatmap" role="group" aria-label="' . HelperFramework::escape($chartTitle !== '' ? $chartTitle : 'Calendar heatmap') . '">'
            . $headingHtml
            . '<div class="calendar-heatmap-grid-scroll">'
            . '<div class="calendar-heatmap-months">' . $monthHtml . '</div>'
            . '<div class="calendar-heatmap-body">'
            . '<div class="calendar-heatmap-weekdays"><span>Mon</span><span></span><span>Wed</span><span></span><span>Fri</span><span></span><span>Sun</span></div>'
            . '<div class="calendar-heatmap-days">' . $dayHtml . '</div>'
            . '</div>'
            . '</div>'
            . $legendHtml
            . '</div>';
    }

    /**
     * Render a compact server-side HTML month status heat map.
     *
     * Supported options:
     * - title/label: Visible heading and aria-label for the heat map.
     * - id: Optional HTML id for the root element.
     * - start/start_date and end/end_date: Y-m-d range to render; inferred from months when omitted.
     * - months: Items keyed by month_key with label, status, value, secondary_value, and tooltip/title.
     * - legend: Array of status => label values, true for defaults, or false to hide.
     * - missing_status: Status used for months inside the range with no supplied data.
     *
     * @param array<string, mixed> $options
     */
    public function monthHeatmap(array $options = []): string
    {
        $monthsByKey = $this->normaliseMonthHeatmapMonths((array)($options['months'] ?? []));
        $range = $this->monthHeatmapRange($monthsByKey, $options);
        $chartTitle = trim((string)($options['label'] ?? $options['title'] ?? 'Month heatmap'));

        if ($range === null) {
            return '<div class="month-heatmap month-heatmap-empty">' . HelperFramework::escape((string)($options['empty_message'] ?? 'No month data')) . '</div>';
        }

        $start = $range['start'];
        $end = $range['end'];
        $id = $this->monthHeatmapId($options);
        $missingStatus = $this->monthHeatmapStatus((string)($options['missing_status'] ?? 'fail'));
        $statusLabels = $this->monthHeatmapStatusLabels($options['legend'] ?? true);
        $cellHtml = '';
        $cursor = $start;

        while ($cursor <= $end) {
            $monthKey = $cursor->format('Y-m-01');
            $item = $monthsByKey[$monthKey] ?? [
                'label' => $cursor->format('M Y'),
                'status' => $missingStatus,
                'value' => 0.0,
                'secondary_value' => null,
                'tooltip' => 'No data supplied for ' . $cursor->format('F Y') . '.',
            ];
            $status = $this->monthHeatmapStatus($item['status']);
            $statusLabel = $statusLabels[$status] ?? ucfirst($status);
            $label = trim($item['label']) !== '' ? $item['label'] : $cursor->format('M Y');
            $value = $this->formatValue($item['value']);
            $displayValue = trim((string)($item['display_value'] ?? '')) !== ''
                ? trim((string)$item['display_value'])
                : $value;
            $secondaryValue = $item['secondary_value'] ?? null;
            $displaySecondaryValue = $secondaryValue === null ? '' : $this->formatValue($secondaryValue);
            $tooltip = trim($item['tooltip']) !== ''
                ? $item['tooltip']
                : $label . ': ' . $statusLabel . ' (' . $value . ')';
            $ariaLabel = $label . ': ' . $statusLabel . '. ' . $tooltip;
            $shortLabel = $this->monthHeatmapShortLabel($label, $cursor);
            $classes = [
                'month-heatmap-cell',
                'month-heatmap-cell--' . $status,
            ];

            $cellHtml .= '<button' . $this->htmlAttributes([
                'class' => implode(' ', $classes),
                'type' => 'button',
                'title' => $tooltip,
                'aria-label' => $ariaLabel,
                'data-preserve-title' => 'true',
                'data-month-key' => $monthKey,
                'data-month-status' => $status,
                'data-month-value' => $value,
            ] + ($displaySecondaryValue !== '' ? ['data-month-secondary-value' => $displaySecondaryValue] : [])) . '>'
                . '<span class="month-heatmap-cell-label" aria-hidden="true">' . HelperFramework::escape($shortLabel) . '</span>'
                . '<span class="month-heatmap-cell-value" aria-hidden="true">'
                . HelperFramework::escape($displayValue)
                . ($displaySecondaryValue !== '' ? '<br><span class="month-heatmap-cell-secondary-value">(' . HelperFramework::escape($displaySecondaryValue) . ')</span>' : '')
                . '</span>'
                . '<span class="sr-only">' . HelperFramework::escape($ariaLabel) . '</span>'
                . '</button>';

            $cursor = $cursor->modify('+1 month');
        }

        $legendHtml = '';
        if (($options['legend'] ?? true) !== false) {
            $legendHtml = '<div class="month-heatmap-legend">';
            foreach ($statusLabels as $status => $label) {
                $legendHtml .= '<span class="month-heatmap-legend-item">'
                    . '<span class="month-heatmap-legend-swatch month-heatmap-cell--' . HelperFramework::escape($status) . '"></span>'
                    . HelperFramework::escape($label)
                    . '</span>';
            }
            $legendHtml .= '</div>';
        }

        return '<div class="month-heatmap" id="' . HelperFramework::escape($id) . '" role="group" aria-label="' . HelperFramework::escape($chartTitle !== '' ? $chartTitle : 'Month heatmap') . '">'
            . '<div class="month-heatmap-heading"><h3>' . HelperFramework::escape($chartTitle !== '' ? $chartTitle : 'Month heatmap') . '</h3></div>'
            . '<div class="month-heatmap-scroll">' . $cellHtml . '</div>'
            . $legendHtml
            . '</div>';
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
            'stacked_bar' => $this->stackedBar([
                [
                    'label' => 'Support',
                    'color' => '#1d4ed8',
                    'points' => [
                        ['label' => 'Jan', 'value' => 16],
                        ['label' => 'Feb', 'value' => 20],
                        ['label' => 'Mar', 'value' => 18],
                        ['label' => 'Apr', 'value' => 24],
                    ],
                ],
                [
                    'label' => 'Ops',
                    'color' => '#16a34a',
                    'points' => [
                        ['label' => 'Jan', 'value' => 12],
                        ['label' => 'Feb', 'value' => 15],
                        ['label' => 'Mar', 'value' => 19],
                        ['label' => 'Apr', 'value' => 22],
                    ],
                ],
                [
                    'label' => 'Security',
                    'color' => '#d97706',
                    'points' => [
                        ['label' => 'Jan', 'value' => 8],
                        ['label' => 'Feb', 'value' => 10],
                        ['label' => 'Mar', 'value' => 13],
                        ['label' => 'Apr', 'value' => 16],
                    ],
                ],
            ], ['title' => 'Quarter workload by team']),
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
            'donut' => $this->donut([
                ['label' => 'Passed', 'value' => 127, 'color' => '#16a34a'],
                ['label' => 'Failed', 'value' => 4, 'color' => '#dc2626'],
                ['label' => 'Skipped', 'value' => 3, 'color' => '#64748b'],
            ], [
                'title' => 'Test result donut',
                'center_label' => '134',
                'center_sub_label' => 'Tests',
            ]),
            'gauge' => $this->gauge(72, [
                'title' => 'Service health gauge',
                'label' => 'Health',
                'color' => '#16a34a',
            ]),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function demoFlowCharts(): array
    {
        $nodes = [
            ['id' => 'cash_sales', 'label' => 'Cash sales', 'column' => 0, 'color' => '#1d4ed8'],
            ['id' => 'online_sales', 'label' => 'Online sales', 'column' => 0, 'color' => '#0891b2'],
            ['id' => 'donations', 'label' => 'Donations', 'column' => 0, 'color' => '#7c3aed'],
            ['id' => 'total_value', 'label' => 'Total value', 'column' => 1, 'color' => '#475569'],
            ['id' => 'overheads', 'label' => 'Overheads', 'column' => 2, 'color' => '#dc2626'],
            ['id' => 'food_purchases', 'label' => 'Materials', 'column' => 2, 'color' => '#d97706'],
            ['id' => 'profit', 'label' => 'Profit', 'column' => 2, 'color' => '#16a34a'],
            ['id' => 'support', 'label' => 'Support', 'column' => 2, 'color' => '#1d4ed8'],
        ];

        $links = [
            ['source' => 'cash_sales', 'target' => 'total_value', 'value' => 4200],
            ['source' => 'online_sales', 'target' => 'total_value', 'value' => 6800],
            ['source' => 'donations', 'target' => 'total_value', 'value' => 1200],
            ['source' => 'total_value', 'target' => 'overheads', 'value' => 3000, 'color' => '#dc2626'],
            ['source' => 'total_value', 'target' => 'food_purchases', 'value' => 4600, 'color' => '#d97706'],
            ['source' => 'total_value', 'target' => 'profit', 'value' => 3100, 'color' => '#16a34a'],
            ['source' => 'total_value', 'target' => 'support', 'value' => 1500, 'color' => '#1d4ed8'],
        ];

        return [
            'sankey' => $this->sankey($nodes, $links, [
                'title' => 'Income and allocation Sankey',
                'value_prefix' => '£',
                'balance_node' => 'total_value',
            ]),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function demoCalendarCharts(array $context = []): array
    {
        $selectedYear = $this->demoCalendarSelectedYear($context);
        $selectedDate = $this->demoCalendarSelectedDate($context, $selectedYear);
        $days = [];
        $start = new DateTimeImmutable($selectedYear . '-01-01');
        $end = new DateTimeImmutable($selectedYear . '-12-31');
        $cursor = $start;

        while ($cursor <= $end) {
            $dayOfYear = (int)$cursor->format('z');
            $weekday = (int)$cursor->format('N');
            $value = (($dayOfYear * 7) + ($weekday * 3) + ((int)$selectedYear % 17)) % 42;

            if ($weekday >= 6) {
                $value = max(0, $value - 18);
            }

            $days[] = [
                'date' => $cursor->format('Y-m-d'),
                'value' => $value,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return [
            'calendar_heatmap' => $this->calendarHeatmap($days, [
                'title' => 'Calendar based Heat Map',
                'id' => 'example-calendar-heatmap',
                'start_date' => $selectedYear . '-01-01',
                'end_date' => $selectedYear . '-12-31',
                'selected_date' => $selectedDate,
                'years' => [2024, 2025, 2026],
                'value_label' => 'records',
                'input_name' => 'calendar_heatmap_date',
                'year_input_name' => 'calendar_heatmap_year',
                'ajax_target' => 'calendar-heatmap-detail-table',
            ]),
            'month_heatmap' => $this->monthHeatmap([
                'id' => 'example-statement-coverage',
                'label' => 'Statement coverage by month',
                'start' => '2022-09-05',
                'end' => '2023-09-30',
                'months' => [
                    [
                        'month_key' => '2022-09-01',
                        'label' => 'Sep 2022',
                        'status' => 'fail',
                        'value' => 0,
                        'tooltip' => 'No CSV rows found for September 2022. Upload the missing statement or confirm this month had no transactions.',
                    ],
                    [
                        'month_key' => '2022-10-01',
                        'label' => 'Oct 2022',
                        'status' => 'pass',
                        'value' => 5,
                        'tooltip' => '5 rows uploaded. Opening balance matches previous closing balance.',
                    ],
                    [
                        'month_key' => '2022-11-01',
                        'label' => 'Nov 2022',
                        'status' => 'warning',
                        'value' => 3,
                        'tooltip' => '3 rows uploaded. Continuity cannot be confirmed because the previous closing balance is unavailable.',
                    ],
                    [
                        'month_key' => '2022-12-01',
                        'label' => 'Dec 2022',
                        'status' => 'pass',
                        'value' => 8,
                        'tooltip' => '8 rows uploaded. Opening balance matches previous closing balance.',
                    ],
                    [
                        'month_key' => '2023-01-01',
                        'label' => 'Jan 2023',
                        'status' => 'pass',
                        'value' => 12,
                        'tooltip' => '12 rows uploaded. Opening balance matches previous closing balance.',
                    ],
                    [
                        'month_key' => '2023-02-01',
                        'label' => 'Feb 2023',
                        'status' => 'fail',
                        'value' => 4,
                        'tooltip' => '4 rows uploaded, but the opening balance does not match the previous closing balance.',
                    ],
                    [
                        'month_key' => '2023-03-01',
                        'label' => 'Mar 2023',
                        'status' => 'pass',
                        'value' => 7,
                        'tooltip' => '7 rows uploaded. Opening balance matches previous closing balance.',
                    ],
                    [
                        'month_key' => '2023-04-01',
                        'label' => 'Apr 2023',
                        'status' => 'muted',
                        'value' => 0,
                        'tooltip' => 'Statement data is not yet available for April 2023.',
                    ],
                    [
                        'month_key' => '2023-05-01',
                        'label' => 'May 2023',
                        'status' => 'pass',
                        'value' => 6,
                        'secondary_value' => 2,
                        'tooltip' => '6 rows uploaded. Opening balance matches previous closing balance.',
                    ],
                    [
                        'month_key' => '2023-06-01',
                        'label' => 'Jun 2023',
                        'status' => 'warning',
                        'value' => 2,
                        'tooltip' => '2 rows uploaded. Balance continuity needs manual review.',
                    ],
                    [
                        'month_key' => '2023-07-01',
                        'label' => 'Jul 2023',
                        'status' => 'pass',
                        'value' => 9,
                        'tooltip' => '9 rows uploaded. Opening balance matches previous closing balance.',
                    ],
                    [
                        'month_key' => '2023-08-01',
                        'label' => 'Aug 2023',
                        'status' => 'pass',
                        'value' => 10,
                        'tooltip' => '10 rows uploaded. Opening balance matches previous closing balance.',
                    ],
                    [
                        'month_key' => '2023-09-01',
                        'label' => 'Sep 2023',
                        'status' => 'fail',
                        'value' => 0,
                        'tooltip' => 'No CSV rows found for September 2023. Upload the missing statement.',
                    ],
                ],
                'legend' => [
                    'pass' => 'Covered',
                    'warning' => 'Needs review',
                    'fail' => 'Gap',
                    'muted' => 'No data',
                ],
            ]),
        ];
    }

    private function demoCalendarSelectedYear(array $context): string
    {
        $year = (int)($context['selected_year'] ?? 2026);

        return in_array($year, [2024, 2025, 2026], true) ? (string)$year : '2026';
    }

    private function demoCalendarSelectedDate(array $context, string $selectedYear): string
    {
        $selectedDate = $this->normaliseDateString((string)($context['selected_date'] ?? ''));

        if ($selectedDate !== null && str_starts_with($selectedDate, $selectedYear . '-')) {
            return $selectedDate;
        }

        return $selectedYear . '-05-14';
    }

    /**
     * @param array<int, array{date?: string, value?: int|float|string, label?: string, title?: string}> $days
     * @return array<string, array{value: float, label: string, title: string}>
     */
    private function normaliseCalendarHeatmapDays(array $days): array
    {
        $normalised = [];

        foreach ($days as $day) {
            if (!is_array($day)) {
                continue;
            }

            $date = $this->normaliseDateString((string)($day['date'] ?? ''));
            if ($date === null) {
                continue;
            }

            $value = (float)($day['value'] ?? 0);
            if (!is_finite($value) || $value < 0) {
                $value = 0.0;
            }

            $normalised[$date] = [
                'value' => $value,
                'label' => trim((string)($day['label'] ?? '')),
                'title' => trim((string)($day['title'] ?? '')),
            ];
        }

        ksort($normalised);

        return $normalised;
    }

    /**
     * @param array<int, mixed> $months
     * @return array<string, array{label: string, status: string, value: float, display_value: string, secondary_value: float|null, tooltip: string}>
     */
    private function normaliseMonthHeatmapMonths(array $months): array
    {
        $normalised = [];

        foreach ($months as $month) {
            if (!is_array($month)) {
                continue;
            }

            $monthKey = $this->normaliseMonthKey((string)($month['month_key'] ?? $month['date'] ?? ''));
            if ($monthKey === null) {
                continue;
            }

            $value = (float)($month['value'] ?? 0);
            if (!is_finite($value) || $value < 0) {
                $value = 0.0;
            }

            $secondaryValue = $this->normaliseOptionalMonthHeatmapValue($month['secondary_value'] ?? null);

            $normalised[$monthKey] = [
                'label' => trim((string)($month['label'] ?? '')),
                'status' => $this->monthHeatmapStatus((string)($month['status'] ?? 'muted')),
                'value' => $value,
                'display_value' => trim((string)($month['display_value'] ?? '')),
                'secondary_value' => $secondaryValue,
                'tooltip' => trim((string)($month['tooltip'] ?? $month['title'] ?? '')),
            ];
        }

        ksort($normalised);

        return $normalised;
    }

    private function normaliseOptionalMonthHeatmapValue(mixed $value): ?float
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }

        $normalised = (float)$value;

        if (!is_finite($normalised) || $normalised < 0) {
            return null;
        }

        return $normalised;
    }

    private function normaliseMonthKey(string $date): ?string
    {
        $normalised = $this->normaliseDateString($date);

        if ($normalised === null) {
            return null;
        }

        return (new DateTimeImmutable($normalised))->format('Y-m-01');
    }

    /**
     * @param array<string, array{label: string, status: string, value: float, tooltip: string}> $monthsByKey
     * @param array<string, mixed> $options
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable}|null
     */
    private function monthHeatmapRange(array $monthsByKey, array $options): ?array
    {
        $start = $this->normaliseMonthKey((string)($options['start'] ?? $options['start_date'] ?? ''));
        $end = $this->normaliseMonthKey((string)($options['end'] ?? $options['end_date'] ?? ''));

        if ($start === null && $monthsByKey !== []) {
            $start = (string)array_key_first($monthsByKey);
        }

        if ($end === null && $monthsByKey !== []) {
            $end = (string)array_key_last($monthsByKey);
        }

        if ($start === null || $end === null) {
            return null;
        }

        $startDate = new DateTimeImmutable($start);
        $endDate = new DateTimeImmutable($end);

        if ($endDate < $startDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        return ['start' => $startDate, 'end' => $endDate];
    }

    private function monthHeatmapStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return in_array($status, ['pass', 'warning', 'fail', 'muted'], true) ? $status : 'muted';
    }

    /**
     * @return array<string, string>
     */
    private function monthHeatmapStatusLabels(mixed $legend): array
    {
        $labels = [
            'pass' => 'Covered',
            'warning' => 'Needs review',
            'fail' => 'Gap',
            'muted' => 'No data',
        ];

        if (is_array($legend)) {
            foreach ($labels as $status => $fallback) {
                $label = trim((string)($legend[$status] ?? ''));
                $labels[$status] = $label !== '' ? $label : $fallback;
            }
        }

        return $labels;
    }

    private function monthHeatmapShortLabel(string $label, DateTimeImmutable $month): string
    {
        $label = trim($label);

        if ($label === '') {
            return $month->format('M');
        }

        $parts = preg_split('/\s+/', $label);

        return substr((string)($parts[0] ?? $label), 0, 3);
    }

    /**
     * @param array<string, array{value: float, label: string, title: string}> $valuesByDate
     * @param array<string, mixed> $options
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable}|null
     */
    private function calendarHeatmapRange(array $valuesByDate, array $options): ?array
    {
        $start = $this->calendarHeatmapDateOption($options, 'start_date');
        $end = $this->calendarHeatmapDateOption($options, 'end_date');

        if ($start === null && $valuesByDate !== []) {
            $start = new DateTimeImmutable((string)array_key_first($valuesByDate));
        }

        if ($end === null && $valuesByDate !== []) {
            $end = new DateTimeImmutable((string)array_key_last($valuesByDate));
        }

        if ($start === null || $end === null) {
            return null;
        }

        if ($end < $start) {
            [$start, $end] = [$end, $start];
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function calendarHeatmapDateOption(array $options, string $key): ?DateTimeImmutable
    {
        $date = $this->normaliseDateString((string)($options[$key] ?? ''));

        return $date !== null ? new DateTimeImmutable($date) : null;
    }

    private function normaliseDateString(string $date): ?string
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();

        if (!$parsed instanceof DateTimeImmutable || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $parsed->format('Y-m-d');
    }

    private function calendarHeatmapLevel(float $value, float $max): int
    {
        if ($value <= 0) {
            return 0;
        }

        return max(1, min(4, (int)ceil(($value / max(1.0, $max)) * 4)));
    }

    private function calendarHeatmapTitle(DateTimeImmutable $date, float $value, string $valueLabel): string
    {
        $label = trim($valueLabel) !== '' ? trim($valueLabel) : 'items';

        return $this->formatValue($value) . ' ' . $label . ' on ' . $date->format('j F Y');
    }

    private function calendarHeatmapMonthLabels(DateTimeImmutable $gridStart, DateTimeImmutable $gridEnd, int $weekCount): string
    {
        $monthByWeek = [];
        $monthCursor = new DateTimeImmutable($gridStart->format('Y-m-01'));

        if ($monthCursor < $gridStart) {
            $monthCursor = $monthCursor->modify('first day of next month');
        }

        while ($monthCursor <= $gridEnd) {
            $week = (int)floor(((int)$gridStart->diff($monthCursor)->format('%a')) / 7) + 1;
            $monthByWeek[$week] = $monthCursor->format('M');
            $monthCursor = $monthCursor->modify('first day of next month');
        }

        $html = '';

        for ($week = 1; $week <= $weekCount; $week++) {
            $label = (string)($monthByWeek[$week] ?? '');
            $class = 'calendar-heatmap-month' . ($label === '' ? ' is-empty' : '');

            $html .= '<span class="' . HelperFramework::escape($class) . '">' . HelperFramework::escape($label) . '</span>';
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function calendarHeatmapRangeControl(DateTimeImmutable $start, DateTimeImmutable $end, ?DateTimeImmutable $selectedDate, string $yearInputName, array $options, string $controlId): string
    {
        $rangeControl = $options['range_control'] ?? null;
        if (!is_array($rangeControl) || (string)($rangeControl['type'] ?? 'year') !== 'date') {
            return $this->calendarHeatmapYearSelect($start, $end, $selectedDate, $yearInputName, $options, $controlId);
        }

        return $this->calendarHeatmapDateSelect($rangeControl, $controlId);
    }

    /**
     * @param array<string, mixed> $rangeControl
     */
    private function calendarHeatmapDateSelect(array $rangeControl, string $controlId): string
    {
        $options = $this->calendarHeatmapDateSelectOptions((array)($rangeControl['options'] ?? []));
        if ($options === []) {
            return '';
        }

        $name = trim((string)($rangeControl['name'] ?? 'heatmap_range'));
        $name = $name !== '' ? $name : 'heatmap_range';
        $idSuffix = $this->calendarHeatmapControlSuffix((string)($rangeControl['id_suffix'] ?? 'range'));
        $selectId = $controlId . '-' . $idSuffix;
        $label = trim((string)($rangeControl['label'] ?? 'Range'));
        $label = $label !== '' ? $label : 'Range';
        $selectedValue = $this->normaliseDateString((string)($rangeControl['selected_value'] ?? ''));
        $html = '<label class="sr-only" for="' . HelperFramework::escape($selectId) . '">' . HelperFramework::escape($label) . '</label>'
            . '<select class="select calendar-heatmap-range-select" id="' . HelperFramework::escape($selectId) . '" name="' . HelperFramework::escape($name) . '">';

        foreach ($options as $option) {
            $html .= '<option value="' . HelperFramework::escape($option['value']) . '"' . ($option['value'] === $selectedValue ? ' selected' : '') . '>' . HelperFramework::escape($option['label']) . '</option>';
        }

        return $html . '</select>';
    }

    /**
     * @param array<int, mixed> $configuredOptions
     * @return array<int, array{value: string, label: string}>
     */
    private function calendarHeatmapDateSelectOptions(array $configuredOptions): array
    {
        $options = [];

        foreach ($configuredOptions as $configuredOption) {
            if (!is_array($configuredOption)) {
                continue;
            }

            $value = $this->normaliseDateString((string)($configuredOption['value'] ?? ''));
            if ($value === null) {
                continue;
            }

            $label = trim((string)($configuredOption['label'] ?? ''));
            $options[] = [
                'value' => $value,
                'label' => $label !== '' ? $label : $value,
            ];
        }

        return $options;
    }

    private function calendarHeatmapControlSuffix(string $suffix): string
    {
        $suffix = strtolower((string)preg_replace('/[^a-zA-Z0-9-]+/', '-', trim($suffix)));
        $suffix = trim($suffix, '-_');

        return $suffix !== '' ? $suffix : 'range';
    }

    /**
     * @param array<string, mixed> $options
     */
    private function calendarHeatmapYearSelect(DateTimeImmutable $start, DateTimeImmutable $end, ?DateTimeImmutable $selectedDate, string $inputName, array $options, string $controlId): string
    {
        $years = $this->calendarHeatmapYearOptions((array)($options['years'] ?? []), $start, $end);
        $selectedYear = $selectedDate instanceof DateTimeImmutable ? (int)$selectedDate->format('Y') : (int)$start->format('Y');
        $name = $inputName !== '' ? $inputName : 'heatmap_year';
        $yearSelectId = $controlId . '-year';
        $html = '<label class="sr-only" for="' . HelperFramework::escape($yearSelectId) . '">Year</label>'
            . '<select class="select calendar-heatmap-year-select" id="' . HelperFramework::escape($yearSelectId) . '" name="' . HelperFramework::escape($name) . '">';

        foreach ($years as $year) {
            $html .= '<option value="' . $year . '"' . ($year === $selectedYear ? ' selected' : '') . '>' . $year . '</option>';
        }

        return $html . '</select>';
    }

    /**
     * @param array<int, mixed> $configuredYears
     * @return array<int, int>
     */
    private function calendarHeatmapYearOptions(array $configuredYears, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $years = [];

        foreach ($configuredYears as $year) {
            $year = (int)$year;
            if ($year >= 1900 && $year <= 2200) {
                $years[] = $year;
            }
        }

        if ($years === []) {
            $startYear = (int)$start->format('Y');
            $endYear = (int)$end->format('Y');
            for ($year = $startYear; $year <= $endYear; $year++) {
                $years[] = $year;
            }
        }

        $years = array_values(array_unique($years));
        sort($years);

        return $years;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function calendarHeatmapId(array $options, string $inputName): string
    {
        $id = trim((string)($options['id'] ?? ''));
        if ($id === '') {
            $id = 'calendar-heatmap-' . ($inputName !== '' ? $inputName : 'chart');
        }

        $id = strtolower((string)preg_replace('/[^a-zA-Z0-9-]+/', '-', $id));
        $id = trim($id, '-_');

        return $id !== '' ? $id : 'calendar-heatmap-chart';
    }

    /**
     * @param array<string, mixed> $options
     */
    private function monthHeatmapId(array $options): string
    {
        $id = trim((string)($options['id'] ?? ''));
        if ($id === '') {
            $id = 'month-heatmap-chart';
        }

        $id = strtolower((string)preg_replace('/[^a-zA-Z0-9-]+/', '-', $id));
        $id = trim($id, '-_');

        return $id !== '' ? $id : 'month-heatmap-chart';
    }

    /**
     * @param array<string, string|int|float> $attributes
     */
    private function htmlAttributes(array $attributes): string
    {
        $html = '';

        foreach ($attributes as $name => $value) {
            $html .= ' ' . $name . '="' . HelperFramework::escape((string)$value) . '"';
        }

        return $html;
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
     * @param array<int, array{id?: string, label?: string, column?: int|string, color?: string}> $nodesInput
     * @return array<string, array{id: string, label: string, column: int, color: string}>
     */
    private function normaliseSankeyNodes(array $nodesInput): array
    {
        $nodes = [];

        foreach ($nodesInput as $index => $node) {
            if (!is_array($node)) {
                continue;
            }

            $id = trim((string)($node['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $label = trim((string)($node['label'] ?? $id));
            $column = max(0, (int)($node['column'] ?? 0));
            $color = trim((string)($node['color'] ?? self::DEFAULT_COLORS[$index % count(self::DEFAULT_COLORS)]));

            $nodes[$id] = [
                'id' => $id,
                'label' => $label !== '' ? $label : $id,
                'column' => $column,
                'color' => $color !== '' ? $color : self::DEFAULT_COLORS[$index % count(self::DEFAULT_COLORS)],
            ];
        }

        return $nodes;
    }

    /**
     * @param array<int, array{source?: string, target?: string, value?: int|float|string, color?: string}> $linksInput
     * @param array<string, array{id: string, label: string, column: int, color: string}> $nodes
     * @return array<int, array{source: string, target: string, value: float, color?: string}>
     */
    private function normaliseSankeyLinks(array $linksInput, array $nodes): array
    {
        $links = [];

        foreach ($linksInput as $link) {
            if (!is_array($link)) {
                continue;
            }

            $source = trim((string)($link['source'] ?? ''));
            $target = trim((string)($link['target'] ?? ''));
            $value = (float)($link['value'] ?? 0);

            if ($source === '' || $target === '' || !isset($nodes[$source], $nodes[$target]) || !is_finite($value) || $value <= 0) {
                continue;
            }

            $item = [
                'source' => $source,
                'target' => $target,
                'value' => $value,
            ];

            if (isset($link['color'])) {
                $item['color'] = (string)$link['color'];
            }

            $links[] = $item;
        }

        return $links;
    }

    /**
     * @param array<string, array{id: string, label: string, column: int, color: string}> $nodes
     * @param array<int, array{source: string, target: string, value: float, color?: string}> $links
     * @return array<string, float>
     */
    private function sankeyNodeValues(array $nodes, array $links): array
    {
        $incoming = array_fill_keys(array_keys($nodes), 0.0);
        $outgoing = array_fill_keys(array_keys($nodes), 0.0);

        foreach ($links as $link) {
            $outgoing[$link['source']] += $link['value'];
            $incoming[$link['target']] += $link['value'];
        }

        $values = [];
        foreach (array_keys($nodes) as $nodeId) {
            $values[$nodeId] = max(1.0, $incoming[$nodeId], $outgoing[$nodeId]);
        }

        return $values;
    }

    /**
     * @param array<string, array{id: string, label: string, column: int, color: string}> $nodes
     * @return array<int, array<int, string>>
     */
    private function sankeyColumns(array $nodes): array
    {
        $columns = [];

        foreach ($nodes as $nodeId => $node) {
            $columns[(int)$node['column']][] = $nodeId;
        }

        ksort($columns);

        return $columns;
    }

    /**
     * @param array<int, array<int, string>> $columns
     * @param array<string, float> $nodeValues
     */
    private function sankeyScale(array $columns, array $nodeValues, float $plotHeight, float $nodeGap): float
    {
        $scale = null;

        foreach ($columns as $nodeIds) {
            $availableHeight = max(1.0, $plotHeight - (max(0, count($nodeIds) - 1) * $nodeGap));
            $totalValue = 0.0;

            foreach ($nodeIds as $nodeId) {
                $totalValue += $nodeValues[$nodeId] ?? 0.0;
            }

            if ($totalValue <= 0) {
                continue;
            }

            $columnScale = $availableHeight / $totalValue;
            $scale = $scale === null ? $columnScale : min($scale, $columnScale);
        }

        return max(0.001, (float)($scale ?? 1.0));
    }

    /**
     * @param array<string, array{id: string, label: string, column: int, color: string}> $nodes
     * @param array<int, array{source: string, target: string, value: float, color?: string}> $links
     * @param array<string, mixed> $options
     */
    private function sankeyBalanceHtml(array $nodes, array $links, array $options, int $width, int $height): string
    {
        $balanceNode = trim((string)($options['balance_node'] ?? ''));
        if ($balanceNode === '' || !isset($nodes[$balanceNode])) {
            return '';
        }

        $incoming = 0.0;
        $outgoing = 0.0;

        foreach ($links as $link) {
            if ($link['target'] === $balanceNode) {
                $incoming += $link['value'];
            }

            if ($link['source'] === $balanceNode) {
                $outgoing += $link['value'];
            }
        }

        $difference = $incoming - $outgoing;
        $class = abs($difference) < 0.001 ? ' balanced' : ' unbalanced';
        $message = abs($difference) < 0.001
            ? 'Balanced flow'
            : 'Difference ' . $this->formatSankeyValue($difference, $options);

        return '<text class="chart-sankey-balance' . $class . '" x="' . $this->number($width / 2) . '" y="' . $this->number($height - 12) . '" text-anchor="middle">' . HelperFramework::escape($message) . '</text>';
    }

    /**
     * @param array<int, array{label?: string, value?: int|float|string, color?: string, points?: array<int, array{label?: string, value?: int|float|string, color?: string}>}> $seriesInput
     * @param array<string, mixed> $options
     * @return array<int, array{label: string, color: string, points: array<int, array{label: string, value: float, color?: string}>}>
     */
    private function normaliseLineSeries(array $seriesInput, array $options): array
    {
        if (!$this->hasSeriesShape($seriesInput)) {
            $points = $this->normalisePoints($seriesInput);

            return $points === []
                ? []
                : [[
                    'label' => trim((string)($options['series_label'] ?? 'Series')),
                    'color' => trim((string)($options['color'] ?? self::DEFAULT_COLORS[0])) ?: self::DEFAULT_COLORS[0],
                    'points' => $points,
                ]];
        }

        return array_values(array_filter(
            $this->normaliseSeries($seriesInput, 5),
            static fn(array $series): bool => count($series['points']) >= 2
        ));
    }

    private function hasSeriesShape(array $seriesInput): bool
    {
        foreach ($seriesInput as $item) {
            if (is_array($item) && array_key_exists('points', $item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array{label?: string, color?: string, points?: array<int, array{label?: string, value?: int|float|string, color?: string}>}> $seriesInput
     * @return array<int, array{label: string, color: string, points: array<int, array{label: string, value: float, color?: string}>}>
     */
    private function normaliseSeries(array $seriesInput, int $limit): array
    {
        $series = [];

        foreach ($seriesInput as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $points = $this->normalisePoints((array)($item['points'] ?? []));
            if (count($points) < 1) {
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

        return array_slice($series, 0, max(1, $limit));
    }

    /**
     * @param array<int, array{label: string, color: string, points: array<int, array{label: string, value: float, color?: string}>}> $series
     * @return array<int, string>
     */
    private function seriesLabels(array $series): array
    {
        $labels = [];

        foreach ($series as $item) {
            foreach ($item['points'] as $index => $point) {
                if (!isset($labels[$index])) {
                    $labels[$index] = $point['label'];
                }
            }
        }

        return array_values($labels);
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

    /**
     * @param array<string, mixed> $options
     */
    private function formatSankeyValue(float|int $value, array $options): string
    {
        $prefix = (string)($options['value_prefix'] ?? '');
        $suffix = (string)($options['value_suffix'] ?? '');
        $absolute = abs((float)$value);
        $formatted = $absolute >= 1000
            ? number_format($absolute, 0)
            : $this->formatValue($absolute);

        return ((float)$value < 0 ? '-' : '') . $prefix . $formatted . $suffix;
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
