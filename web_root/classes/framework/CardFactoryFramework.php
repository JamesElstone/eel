<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class CardFactoryFramework
{
    public function create(string $cardKey): CardInterfaceFramework
    {
        $className = HelperFramework::cardKeyToClassName($cardKey);

        if (!class_exists($className)) {
            throw new RuntimeException('Unable to resolve card class: ' . $className);
        }

        $card = new $className();

        if (!$card instanceof CardInterfaceFramework) {
            throw new RuntimeException('Resolved card does not implement CardInterfaceFramework: ' . $className);
        }

        return $card;
    }
}

