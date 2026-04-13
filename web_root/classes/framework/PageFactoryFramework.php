<?php
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

