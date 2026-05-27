<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _license_bsd_3_clauseCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'license_bsd_3_clause';
    }

    public function title(): string
    {
        return 'BSD 3-Clause License';
    }

    public function helper(array $context): string
    {
        return 'Read from LICENSES/BSD-3-Clause.txt.';
    }

    public function render(array $context): string
    {
        $text = (new LicenseService())->licenseText('bsd_3_clause');

        return '<pre class="license-text-panel">' . HelperFramework::escape($text) . '</pre>';
    }
}
