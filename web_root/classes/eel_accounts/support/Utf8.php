<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Support;

final class Utf8
{
    public static function normalize(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $normalized = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        if (!mb_check_encoding($normalized, 'UTF-8')) {
            $normalized = mb_scrub($normalized, 'UTF-8');
        }
        if (!mb_check_encoding($normalized, 'UTF-8')) {
            throw new \UnexpectedValueException('Text could not be normalized to valid UTF-8.');
        }

        return $normalized;
    }

    public static function normalizeValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::normalize($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalizedKey = is_string($key) ? self::normalize($key) : $key;
            if (array_key_exists($normalizedKey, $normalized)) {
                throw new \InvalidArgumentException(
                    'UTF-8 normalization produced a duplicate array key: ' . (string)$normalizedKey
                );
            }

            $normalized[$normalizedKey] = self::normalizeValue($item);
        }

        return $normalized;
    }

    public static function html(?string $value): string
    {
        return htmlspecialchars(
            self::normalize((string)$value),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }

    public static function xml(string $value): string
    {
        $normalized = self::normalize($value);
        if (preg_match(
            '/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',
            $normalized
        ) === 1) {
            throw new \InvalidArgumentException('Text contains a character that is not permitted in XML 1.0.');
        }

        return htmlspecialchars(
            $normalized,
            ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }

    public static function json(mixed $value, int $flags = 0): string
    {
        return json_encode(
            self::normalizeValue($value),
            $flags | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }
}
