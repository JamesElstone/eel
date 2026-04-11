<?php
declare(strict_types=1);

final class WebCardFactory
{
    public function create(string $pageId, string $cardKey): WebCardInterface
    {
        $className = FrameWorkHelper::cardKeyToClassName($pageId, $cardKey);

        if (!class_exists($className)) {
            throw new RuntimeException('Unable to resolve card class: ' . $className);
        }

        $card = new $className();

        if (!$card instanceof WebCardInterface) {
            throw new RuntimeException('Resolved card does not implement WebCardInterface: ' . $className);
        }

        return $card;
    }
}
