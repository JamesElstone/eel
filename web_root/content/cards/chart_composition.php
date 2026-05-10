<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _chart_compositionCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'chart_composition';
    }

    public function helper(array $context): string
    {
        return 'Standalone SVG pie and gauge examples rendered by ChartSvgService.';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'composition_charts',
                'service' => ChartSvgService::class,
                'method' => 'demoCompositionCharts',
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['test.charts'];
    }

    public function render(array $context): string
    {
        $charts = (array)(($context['services'] ?? [])['composition_charts'] ?? []);

        return '<div class="chart-demo-grid">'
            . $this->chartPanel('Pie chart', (string)($charts['pie'] ?? ''))
            . $this->chartPanel('Gauge', (string)($charts['gauge'] ?? ''))
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
