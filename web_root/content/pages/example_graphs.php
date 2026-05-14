<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _example_graphs extends PageContextFramework
{
    public function id(): string
    {
        return 'example_graphs';
    }

    public function title(): string
    {
        return 'Example Graphs';
    }

    public function subtitle(): string
    {
        return 'Standalone SVG chart examples rendered by the internal chart service.';
    }

    public function services(): array
    {
        return [];
    }

    public function cards(): array
    {
        return [
            'chart_trends',
            'chart_composition',
            'chart_calendar_heatmap',
            'chart_flow',
        ];
    }
}
