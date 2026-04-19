<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class PageFactoryFramework
{
    public function create(string $pageKey, string $fallbackPageKey = 'dashboard'): PageInterfaceFramework
    {
        $className = HelperFramework::pageKeyToClassName($pageKey);

        if (!class_exists($className)) {
            $className = HelperFramework::pageKeyToClassName($fallbackPageKey);
        }

        if (!class_exists($className)) {
            throw new RuntimeException('Unable to resolve page class: ' . $className);
        }

        $page = new $className();

        if (!$page instanceof PageInterfaceFramework) {
            throw new RuntimeException('Resolved page does not implement PageInterfaceFramework: ' . $className);
        }

        return $page;
    }
}

