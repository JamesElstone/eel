<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class CardFactoryFramework
{
    public function create(string $cardKey): CardInterfaceFramework
    {
        $className = HelperFramework::cardKeyToClassName($cardKey);

        if (!class_exists($className)) {
            throw new RuntimeException('CardFactoryFramework: Unable to resolve card class: ' . $className);
        }

        $card = new $className();

        if (!$card instanceof CardInterfaceFramework) {
            throw new RuntimeException('CardFactoryFramework: Resolved card does not implement CardInterfaceFramework: ' . $className);
        }

        return $card;
    }
}

