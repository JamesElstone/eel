<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _chart_flowCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'chart_flow';
    }

    public function helper(array $context): string
    {
        return 'Standalone SVG Sankey example showing income sources flowing into total value and then into allocations.';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'flow_charts',
                'service' => ChartService::class,
                'method' => 'demoFlowCharts',
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['test.charts'];
    }

    public function render(array $context): string
    {
        $charts = (array)(($context['services'] ?? [])['flow_charts'] ?? []);

        return '<div class="chart-demo-grid chart-demo-grid-wide">'
            . $this->chartPanel('Income flow Sankey', (string)($charts['sankey'] ?? ''))
            . '</div>';
    }

    private function chartPanel(string $title, string $chartHtml): string
    {
        return '<div class="chart-panel chart-panel-wide">'
            . '<h3>' . HelperFramework::escape($title) . '</h3>'
            . $chartHtml
            . '</div>';
    }
}
