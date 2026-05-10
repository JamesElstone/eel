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
    ChartSvgService::class,
    static function (GeneratedServiceClassTestHarness $harness, ChartSvgService $service): void {
        $points = [
            ['label' => 'One', 'value' => 1],
            ['label' => 'Two', 'value' => 2],
        ];

        $harness->check(ChartSvgService::class, 'renders bar chart SVG', static function () use ($harness, $service, $points): void {
            $html = $service->bar($points, ['title' => 'Test bar']);

            $harness->assertTrue(str_contains($html, '<svg'));
            $harness->assertTrue(str_contains($html, 'chart-bar'));
            $harness->assertTrue(str_contains($html, 'Test bar'));
        });

        $harness->check(ChartSvgService::class, 'renders line chart SVG', static function () use ($harness, $service, $points): void {
            $html = $service->line($points, ['title' => 'Test line']);

            $harness->assertTrue(str_contains($html, '<svg'));
            $harness->assertTrue(str_contains($html, 'chart-line-path'));
            $harness->assertTrue(str_contains($html, 'Test line'));
        });

        $harness->check(ChartSvgService::class, 'renders multi series line chart SVG', static function () use ($harness, $service, $points): void {
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

        $harness->check(ChartSvgService::class, 'renders pie chart SVG', static function () use ($harness, $service, $points): void {
            $html = $service->pie($points, ['title' => 'Test pie']);

            $harness->assertTrue(str_contains($html, '<svg'));
            $harness->assertTrue(str_contains($html, 'chart-pie-slice'));
            $harness->assertTrue(str_contains($html, 'Test pie'));
        });

        $harness->check(ChartSvgService::class, 'renders gauge SVG', static function () use ($harness, $service): void {
            $html = $service->gauge(72, ['title' => 'Test gauge']);

            $harness->assertTrue(str_contains($html, '<svg'));
            $harness->assertTrue(str_contains($html, 'chart-gauge-value'));
            $harness->assertTrue(str_contains($html, 'Test gauge'));
        });
    }
);
