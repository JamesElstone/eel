<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Support;

final class Utf8Table
{
    public static function make(string $key, array $rows): \TableFramework
    {
        /** @var array<int|string, mixed> $normalizedRows */
        $normalizedRows = Utf8::normalizeValue($rows);

        return \TableFramework::make($key, $normalizedRows);
    }
}
