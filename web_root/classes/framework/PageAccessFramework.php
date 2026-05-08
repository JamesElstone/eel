<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class PageAccessFramework
{
    public static function isPageAvailable(string $pageKey): bool
    {
        if (!self::isDeveloperOnlyPage($pageKey)) {
            return true;
        }

        return self::developerOptionsEnabled();
    }

    public static function isDeveloperOnlyPage(string $pageKey): bool
    {
        $pageKey = HelperFramework::normalisePageKey($pageKey);
        if ($pageKey === '') {
            return false;
        }

        return in_array($pageKey, self::developerOnlyPages(), true);
    }

    public static function developerOptionsEnabled(): bool
    {
        return (bool)AppConfigurationStore::get('developer_options', false);
    }

    private static function developerOnlyPages(): array
    {
        $configuredPages = AppConfigurationStore::get('navigation.developer_only_pages', []);
        if (!is_array($configuredPages)) {
            return [];
        }

        $pages = [];
        foreach ($configuredPages as $pageKey) {
            $pageKey = HelperFramework::normalisePageKey((string)$pageKey);
            if ($pageKey !== '') {
                $pages[] = $pageKey;
            }
        }

        return array_values(array_unique($pages));
    }
}
