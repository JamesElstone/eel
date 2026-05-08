<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _dashboard extends PageContextFramework
{
    public function id(): string
    {
        return 'dashboard';
    }

    public function title(): string
    {
        return 'Dashboard';
    }

    public function subtitle(): string
    {
        return 'Track the new page architecture with convention-led cards, shared rendering, and AJAX-only card updates.';
    }

    public function services(): array
    {
        return [];
    }

    public function cards(): array
    {
        return [
            'activity',
            'dashboard_notes',
            // 'dump_context',
        ];
    }

    protected function buildContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult
    ): array
    {
        return parent::buildContext($request, $services, $actionResult);
    }
}
