<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class ApiCredentialFileFormat
{
    public const LEGACY_LAYOUT = 'legacy';
    public const CANONICAL_LAYOUT = 'canonical';
    public const MAX_SOFTWARE_REFERENCE_LENGTH = 1000;
    public const LEGACY_HEADER = ['PROVIDER', 'GATEWAY', 'TAG', 'ENVIRONMENT', 'SCHEMA', 'URL', 'API_IDENTITY', 'API_KEY'];
    public const CANONICAL_HEADER = ['PROVIDER', 'GATEWAY', 'TAG', 'ENVIRONMENT', 'SCHEMA', 'URL', 'SOFTWARE_REFERENCE', 'API_IDENTITY', 'API_KEY'];

    /** @param list<string|null> $header */
    public static function requireLayout(array $header): string
    {
        if ($header === self::LEGACY_HEADER) {
            return self::LEGACY_LAYOUT;
        }

        if ($header === self::CANONICAL_HEADER) {
            return self::CANONICAL_LAYOUT;
        }

        throw new RuntimeException('API key file header must be either legacy '
            . self::headerText(self::LEGACY_HEADER) . ' or canonical '
            . self::headerText(self::CANONICAL_HEADER) . '.');
    }

    public static function expectedColumnCount(string $layout): int
    {
        return $layout === self::CANONICAL_LAYOUT
            ? count(self::CANONICAL_HEADER)
            : count(self::LEGACY_HEADER);
    }

    public static function columnCountRequirement(): string
    {
        return 'eight columns for legacy header ' . self::headerText(self::LEGACY_HEADER)
            . ' or nine columns for canonical header ' . self::headerText(self::CANONICAL_HEADER);
    }

    public static function normaliseSoftwareReference(mixed $value): string
    {
        if (!is_string($value)) {
            throw new RuntimeException('Software Reference must be a string.');
        }
        if (preg_match('//u', $value) !== 1) {
            throw new RuntimeException('Software Reference must be valid UTF-8 text.');
        }
        if (str_contains($value, "\0") || str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new RuntimeException('Software Reference cannot contain NUL, CR, or LF characters.');
        }

        $value = trim($value);
        if (mb_strlen($value, 'UTF-8') > self::MAX_SOFTWARE_REFERENCE_LENGTH) {
            throw new RuntimeException('Software Reference cannot exceed 1000 Unicode characters.');
        }

        return $value;
    }

    /** @param list<string> $header */
    private static function headerText(array $header): string
    {
        return implode(',', $header);
    }
}
