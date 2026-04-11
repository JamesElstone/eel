<?php
declare(strict_types=1);

final class WebPageFactory
{
    public function create(string $pageKey, string $fallbackPageKey = 'dashboard'): WebPageInterface
    {
        $className = FrameWorkHelper::pageKeyToClassName($pageKey);

        if (!class_exists($className)) {
            $className = FrameWorkHelper::pageKeyToClassName($fallbackPageKey);
        }

        if (!class_exists($className)) {
            throw new RuntimeException('Unable to resolve page class: ' . $className);
        }

        $page = new $className();

        if (!$page instanceof WebPageInterface) {
            throw new RuntimeException('Resolved page does not implement WebPageInterface: ' . $className);
        }

        return $page;
    }
}
