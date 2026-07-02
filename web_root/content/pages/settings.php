<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _settings extends PageContextFramework
{
    public function id(): string
    {
        return 'settings';
    }

    public function title(): string
    {
        return 'Settings';
    }

    public function subtitle(): string
    {
        return 'Review API mode, import controls, storage paths, setup checks, and application settings.';
    }

    public function hiddenSiteContextSelectors(): array
    {
        return ['accounting_period_id'];
    }

    public function cards(): array
    {
        return [
            'api_mode',
            'settings_import_review',
            'api_connectivity_test',
            'hmrc_anti_fraud_test',
            'check_file_paths',
            'settings_setup_health',
            'application_settings',
            'web_environment',
            'invitation_settings',
            'sms_settings',
            'smtp_settings',
         ];
    }

    public function services(): array
    {
        return [];
    }

    protected function buildContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult
    ): array {
        $sessionAuthenticationService = new SessionAuthenticationService();
        $sessionAuthenticationService->startSession();

        return [
            'page' => [
                'page_id' => 'settings',
                'page_cards' => $this->cards(),
                'csrf_token' => $sessionAuthenticationService->csrfToken(),
            ],
        ];
    }

}
