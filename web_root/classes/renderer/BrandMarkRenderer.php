<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class BrandMarkRenderer
{
    public static function configuredMark(): string
    {
        $brandMark = trim((string)AppConfigurationStore::get('brand-mark', 'E'));

        return $brandMark !== '' ? $brandMark : 'E';
    }

    public static function html(string $imageClass): string
    {
        $brandMark = self::configuredMark();

        if (self::isImagePath($brandMark)) {
            return '<img class="' . HelperFramework::escape($imageClass) . '" src="' . HelperFramework::escape(self::webRootImagePath($brandMark)) . '" alt="" aria-hidden="true">';
        }

        return HelperFramework::escape($brandMark);
    }

    private static function isImagePath(string $brandMark): bool
    {
        if (!preg_match('/\.(?:jpg|png)\z/i', $brandMark)) {
            return false;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $brandMark)) {
            return false;
        }

        return !str_starts_with($brandMark, '//') && !str_contains($brandMark, '\\');
    }

    private static function webRootImagePath(string $brandMark): string
    {
        return str_starts_with($brandMark, '/') ? $brandMark : '/' . ltrim($brandMark, '/');
    }
}
