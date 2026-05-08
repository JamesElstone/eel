<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class FormattingFramework
{
    public static function money(float|int|string|null $value): string
    {
        return number_format((float)$value, 2, '.', ',');
    }

    public static function nullableMoney(mixed $value, string $fallback = '-'): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        return self::money($value);
    }

    public static function normaliseLimit(int $limit, int $minimum = 1, int $maximum = 500): int
    {
        $minimum = max(1, $minimum);
        $maximum = max($minimum, $maximum);

        return max($minimum, min($maximum, $limit));
    }

    public static function nominalLabel(mixed $nominal, string $separator = ' - '): string
    {
        if (!is_array($nominal)) {
            return '';
        }

        $code = trim((string)($nominal['code'] ?? ''));
        $name = trim((string)($nominal['name'] ?? ''));

        if ($code === '') {
            return $name;
        }

        if ($name === '') {
            return $code;
        }

        return $code . $separator . $name;
    }
}
