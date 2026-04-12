<?php
declare(strict_types=1);

final class WebCardFactory
{
    public function create(string $cardKey): WebCardInterface
    {
        $className = FrameworkHelper::cardKeyToClassName($cardKey);

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
