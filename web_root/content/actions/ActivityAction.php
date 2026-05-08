<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class ActivityAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $window = $this->normaliseWindow((string)$request->input('activity_window', '7_days'));

        return ActionResultFramework::success(
            ['dashboard.feed'],
            [],
            ['activity_window' => $window],
            ['activity_window' => $window]
        );
    }

    private function normaliseWindow(string $window): string
    {
        return in_array($window, ['1_day', '7_days', 'this_month'], true) ? $window : '7_days';
    }
}
