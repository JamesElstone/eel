<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _license_agpl_3_0Card extends CardBaseFramework
{
    public function key(): string
    {
        return 'license_agpl_3_0';
    }

    public function title(): string
    {
        return 'GNU AGPLv3';
    }

    public function helper(array $context): string
    {
        return 'Read from LICENSES/AGPL-3.0.txt.';
    }

    public function render(array $context): string
    {
        $text = (new \eel_accounts\Service\LicenseService())->licenseText('agpl_3_0');

        return '<pre class="license-text-panel">' . HelperFramework::escape($text) . '</pre>';
    }
}
