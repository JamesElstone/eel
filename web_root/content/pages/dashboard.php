<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
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
        return 'At a glance view of your accounts.';
    }

    public function services(): array
    {
        return [CompanyAccountService::class];
    }

    public function cards(): array
    {
        return [
            'overview',
            'dashboard_action_queue',
            'dashboard_year_end_readiness',
            'dashboard_recent_transactions',
            'activity',
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
