<?php
declare(strict_types=1);

final class MimeTypeHelper
{
    public static function detectFromFile(string $filename): ?string
    {
        if (!is_file($filename) || !function_exists('finfo_open')) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        $mimeType = finfo_file($finfo, $filename) ?: null;
        finfo_close($finfo);

        return is_string($mimeType) ? $mimeType : null;
    }
}
