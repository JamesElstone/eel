<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Support;

final class IxbrlNumericFactValue
{
    public static function semantic(mixed $normalisedNumeric, mixed $signHint): ?float
    {
        if (is_bool($normalisedNumeric) || (!is_int($normalisedNumeric) && !is_float($normalisedNumeric) && !is_string($normalisedNumeric))) {
            return null;
        }

        if (is_string($normalisedNumeric)) {
            $normalisedNumeric = trim($normalisedNumeric);
            if ($normalisedNumeric === '' || !is_numeric($normalisedNumeric)) {
                return null;
            }
        }

        $value = (float)$normalisedNumeric;
        if (!is_finite($value)) {
            return null;
        }

        $hint = is_scalar($signHint) ? strtolower(trim((string)$signHint)) : '';
        if (str_contains($hint, 'ix_sign')) {
            return $value == 0.0 ? 0.0 : -abs($value);
        }

        return $value;
    }
}
