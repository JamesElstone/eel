<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _license_fontsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'license_fonts';
    }

    public function title(): string
    {
        return 'Bundled Font Licenses';
    }

    public function helper(array $context): string
    {
        return 'Read from web_root/fonts/LICENSE.';
    }

    public function render(array $context): string
    {
        $text = (new LicenseService())->licenseText('fonts');

        return '<pre class="license-text-panel">' . HelperFramework::escape($text) . '</pre>';
    }
}
