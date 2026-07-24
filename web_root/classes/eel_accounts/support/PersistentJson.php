<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Support;

/**
 * ASCII-safe JSON for values that cross the database boundary.
 *
 * This is defense in depth for JSON bytes and hashes. It does not replace a
 * correctly configured UTF-8 connection for ordinary VARCHAR/TEXT columns.
 */
final class PersistentJson
{
    public static function encode(mixed $value, int $flags = 0): string
    {
        $flags = ($flags | JSON_THROW_ON_ERROR) & ~JSON_UNESCAPED_UNICODE;
        return json_encode($value, $flags);
    }
}
