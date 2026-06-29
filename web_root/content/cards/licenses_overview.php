<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _licenses_overviewCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'licenses_overview';
    }

    public function title(): string
    {
        return 'Project Licensing';
    }

    public function helper(array $context): string
    {
        return 'The applicable license is determined by the file-level notice where one is present.';
    }

    public function render(array $context): string
    {
        $service = new \eel_accounts\Service\LicenseService();
        $licenses = $service->licenseIndex();
        $items = '';

        foreach ($licenses as $license) {
            $items .= '<div class="license-summary-item">
                <strong>' . HelperFramework::escape($license['short_title']) . '</strong>
                <span>' . HelperFramework::escape($license['scope']) . '</span>
                <span class="helper">Full text: ' . HelperFramework::escape($license['source']) . '</span>
            </div>';
        }

        return '<div class="stack">
            <div class="panel-soft">
                <p class="license-copy">This repository contains code and assets under three license areas: BSD 3-Clause for eelKit framework files, AGPLv3 for EEL Accounts application files, and SIL Open Font License terms for bundled fonts.</p>
                <p class="license-copy">Copyright (c) 2026 James Elstone. All rights are granted only under the applicable license terms for each file or bundled asset.</p>
            </div>
            <div class="license-summary-grid">' . $items . '</div>
        </div>';
    }
}
