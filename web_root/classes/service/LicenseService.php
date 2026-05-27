<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class LicenseService
{
    /**
     * @return array<string, array{title: string, short_title: string, path: string, source: string, scope: string}>
     */
    public function licenseIndex(): array
    {
        return [
            'bsd_3_clause' => [
                'title' => 'BSD 3-Clause License',
                'short_title' => 'BSD-3',
                'path' => PROJECT_ROOT . 'LICENSES' . DIRECTORY_SEPARATOR . 'BSD-3-Clause.txt',
                'source' => 'LICENSES/BSD-3-Clause.txt',
                'scope' => 'eelKit framework files and components carrying the BSD 3-Clause file-level notice.',
            ],
            'agpl_3_0' => [
                'title' => 'GNU Affero General Public License v3.0',
                'short_title' => 'AGPLv3',
                'path' => PROJECT_ROOT . 'LICENSES' . DIRECTORY_SEPARATOR . 'AGPL-3.0.txt',
                'source' => 'LICENSES/AGPL-3.0.txt',
                'scope' => 'EEL Accounts application-specific files and components carrying the AGPLv3 file-level notice.',
            ],
            'fonts' => [
                'title' => 'Bundled Font Licenses',
                'short_title' => 'OFL-1.1',
                'path' => APP_ROOT . 'fonts' . DIRECTORY_SEPARATOR . 'LICENSE',
                'source' => 'web_root/fonts/LICENSE',
                'scope' => 'Bundled Inter and Roboto font files under web_root/fonts.',
            ],
        ];
    }

    public function licenseText(string $licenseKey): string
    {
        $license = $this->license($licenseKey);
        $path = $license['path'];

        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('License file is not readable: ' . $license['source']);
        }

        $text = file_get_contents($path);

        if ($text === false) {
            throw new RuntimeException('License file could not be read: ' . $license['source']);
        }

        return $text;
    }

    /**
     * @return array{title: string, short_title: string, path: string, source: string, scope: string}
     */
    public function license(string $licenseKey): array
    {
        $licenseKey = $this->normaliseKey($licenseKey);
        $licenses = $this->licenseIndex();

        if (!isset($licenses[$licenseKey])) {
            throw new InvalidArgumentException('Unknown license key: ' . $licenseKey);
        }

        return $licenses[$licenseKey];
    }

    private function normaliseKey(string $licenseKey): string
    {
        $licenseKey = strtolower(trim($licenseKey));
        $licenseKey = str_replace('-', '_', $licenseKey);

        return preg_match('/^[a-z][a-z0-9_]*$/', $licenseKey) === 1 ? $licenseKey : '';
    }
}
