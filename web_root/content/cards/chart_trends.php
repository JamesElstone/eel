<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _chart_trendsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'chart_trends';
    }

    public function helper(array $context): string
    {
        return 'Standalone SVG bar, stacked bar, and multi-series line chart examples rendered by ChartService.';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'trend_charts',
                'service' => ChartService::class,
                'method' => 'demoTrendCharts',
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['test.charts'];
    }

    public function render(array $context): string
    {
        $charts = (array)(($context['services'] ?? [])['trend_charts'] ?? []);

        return '<div class="chart-demo-grid">'
            . $this->chartPanel('Bar graph', (string)($charts['bar'] ?? ''))
            . $this->chartPanel('Stacked bar graph', (string)($charts['stacked_bar'] ?? ''))
            . $this->chartPanel('Line graph', (string)($charts['line'] ?? ''))
            . '</div>';
    }

    private function chartPanel(string $title, string $chartHtml): string
    {
        return '<div class="chart-panel">'
            . '<h3>' . HelperFramework::escape($title) . '</h3>'
            . $chartHtml
            . '</div>';
    }
}
