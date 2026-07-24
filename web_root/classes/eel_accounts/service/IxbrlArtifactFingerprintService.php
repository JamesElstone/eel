<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

use eel_accounts\Support\RequestCache;

/** Provides request-scoped fingerprints for immutable iXBRL artifacts. */
final class IxbrlArtifactFingerprintService
{
    private const CACHE_NAMESPACE = 'ixbrl.artifact.sha256';

    public function sha256(string $path): ?string
    {
        $path = trim($path);
        $canonicalPath = $path !== '' ? realpath($path) : false;
        $cachePath = is_string($canonicalPath) ? $canonicalPath : $this->normalisePath($path);

        return RequestCache::remember(
            self::CACHE_NAMESPACE,
            RequestCache::key($cachePath),
            static function () use ($canonicalPath): ?string {
                if (!is_string($canonicalPath) || !is_file($canonicalPath)) {
                    return null;
                }

                $hash = hash_file('sha256', $canonicalPath);

                return is_string($hash) && $hash !== '' ? strtolower($hash) : null;
            }
        );
    }

    private function normalisePath(string $path): string
    {
        return rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }
}
