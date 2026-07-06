<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _backup extends PageContextFramework
{
    public function id(): string
    {
        return 'backup';
    }

    public function title(): string
    {
        return 'Backup';
    }

    public function subtitle(): string
    {
        return 'Create zipped SQL database dumps that can restore the application database to a point in time.';
    }

    public function hiddenSiteContextSelectors(): array
    {
        return ['company_id', 'accounting_period_id'];
    }

    public function cards(): array
    {
        return [
            'backup',
            'backups_available',
        ];
    }

    protected function buildContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult
    ): array {
        $sessionAuthenticationService = new SessionAuthenticationService();
        $sessionAuthenticationService->startSession();

        $context = parent::buildContext($request, $services, $actionResult);
        $context['page']['csrf_token'] = $sessionAuthenticationService->csrfToken();

        return $context;
    }
}
