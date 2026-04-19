<?php
declare(strict_types=1);

final class SecurityStore
{
    public static function apiKeysPath(?string $overridePath = null): string
    {
        return self::configuredPath($overridePath, ['api_keys', 'path'], '../secure/api.keys');
    }

    public static function securityKeysPath(?string $overridePath = null): string
    {
        return self::configuredPath($overridePath, ['security_keys', 'path'], '../secure/security.keys');
    }

    public static function credentialCatalog(?string $keysPath = null): array
    {
        static $catalogByPath = [];

        $path = self::apiKeysPath($keysPath);

        if (isset($catalogByPath[$path])) {
            return $catalogByPath[$path];
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('API key file was not found or is not readable: ' . $path);
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('API key file could not be opened: ' . $path);
        }

        $catalog = [];

        try {
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $firstField = trim((string)($row[0] ?? ''));

                if ($firstField !== '' && str_starts_with($firstField, '#')) {
                    continue;
                }

                if (strtoupper($firstField) === 'PROVIDER') {
                    continue;
                }

                if (count($row) < 5) {
                    continue;
                }

                $provider = strtoupper(trim((string)$row[0]));
                $tag = strtoupper(trim((string)$row[1]));

                if ($provider === '' || $tag === '') {
                    continue;
                }

                if (count($row) >= 6) {
                    $environment = HelperFramework::normaliseEnvironmentMode((string)$row[2]);
                    $credential = [
                        'provider' => $provider,
                        'tag' => $tag,
                        'environment' => $environment,
                        'schema' => strtoupper(trim((string)$row[3])),
                        'url' => trim((string)$row[4]),
                        'api_key' => trim((string)$row[5]),
                    ];
                    $catalog[$provider][$tag][$environment] = $credential;
                    continue;
                }

                $credential = [
                    'provider' => $provider,
                    'tag' => $tag,
                    'environment' => 'TEST',
                    'schema' => strtoupper(trim((string)$row[2])),
                    'url' => trim((string)$row[3]),
                    'api_key' => trim((string)$row[4]),
                ];
                $catalog[$provider][$tag]['DEFAULT'] = $credential;
            }
        } finally {
            fclose($handle);
        }

        $catalogByPath[$path] = $catalog;

        return $catalogByPath[$path];
    }

    public static function loadCredential(
        string $provider,
        string $tag,
        ?string $environment = null,
        ?string $keysPath = null
    ): array {
        $provider = strtoupper(trim($provider));
        $tag = strtoupper(trim($tag));
        $environment = HelperFramework::normaliseEnvironmentMode($environment);
        $catalog = self::credentialCatalog($keysPath);
        $providerCatalog = $catalog[$provider] ?? [];
        $tagCatalog = is_array($providerCatalog[$tag] ?? null) ? $providerCatalog[$tag] : [];

        if (is_array($tagCatalog[$environment] ?? null)) {
            return $tagCatalog[$environment];
        }

        if (is_array($tagCatalog['DEFAULT'] ?? null)) {
            $fallbackCredential = $tagCatalog['DEFAULT'];
            $fallbackCredential['environment'] = $environment;

            return $fallbackCredential;
        }

        throw new RuntimeException('API credential not found for ' . $provider . ' / ' . $tag . ' / ' . $environment . '.');
    }

    public static function loadFact(string $key, ?string $keysPath = null): ?string
    {
        $normalisedKey = self::normaliseFactKey($key);

        if ($normalisedKey === '') {
            throw new RuntimeException('Security fact key is required.');
        }

        $path = self::securityKeysPath($keysPath);

        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Security key file could not be opened: ' . $path);
        }

        try {
            $facts = self::readFactsFromHandle($handle);
        } finally {
            fclose($handle);
        }

        return $facts[$normalisedKey] ?? null;
    }

    public static function ensureFact(string $key, ?string $keysPath = null): string
    {
        $normalisedKey = self::normaliseFactKey($key);

        if ($normalisedKey === '') {
            throw new RuntimeException('Security fact key is required.');
        }

        $path = self::securityKeysPath($keysPath);
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Security key directory could not be created: ' . $directory);
        }

        $handle = fopen($path, 'c+b');

        if ($handle === false) {
            throw new RuntimeException('Security key file could not be opened: ' . $path);
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Security key file could not be locked: ' . $path);
            }

            $facts = self::readFactsFromHandle($handle);

            if (isset($facts[$normalisedKey]) && $facts[$normalisedKey] !== '') {
                return $facts[$normalisedKey];
            }

            $facts[$normalisedKey] = self::generateFact();
            self::writeFactsToHandle($handle, $facts);

            return $facts[$normalisedKey];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function configuredPath(?string $overridePath, array $configPath, string $defaultRelativePath): string
    {
        $overridePath = trim((string)$overridePath);

        if ($overridePath !== '') {
            return self::resolvePath($overridePath);
        }

        $config = AppConfigurationStore::config();
        $configuredPath = self::configValue($config, $configPath);

        if ($configuredPath === '') {
            $configuredPath = $defaultRelativePath;
        }

        return self::resolvePath($configuredPath);
    }

    private static function configValue(array $config, array $segments): string
    {
        $value = $config;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return '';
            }

            $value = $value[$segment];
        }

        return trim((string)$value);
    }

    private static function resolvePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '';
        }

        if (
            preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1
            || str_starts_with($path, '/')
            || str_starts_with($path, '\\')
        ) {
            return $path;
        }

        $combined = APP_ROOT . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
        $prefix = '';

        if (preg_match('/^[A-Za-z]:/', $combined) === 1) {
            $prefix = substr($combined, 0, 2);
            $combined = substr($combined, 2);
        }

        $leadingSeparator = str_starts_with($combined, DIRECTORY_SEPARATOR);
        $parts = preg_split('/[\\\\\\/]+/', $combined) ?: [];
        $normalised = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                if ($normalised !== [] && end($normalised) !== '..') {
                    array_pop($normalised);
                    continue;
                }

                if (!$leadingSeparator) {
                    $normalised[] = $part;
                }

                continue;
            }

            $normalised[] = $part;
        }

        $resolved = implode(DIRECTORY_SEPARATOR, $normalised);

        if ($leadingSeparator) {
            $resolved = DIRECTORY_SEPARATOR . $resolved;
        }

        if ($prefix !== '') {
            $resolved = $prefix . $resolved;
        }

        return $resolved;
    }

    private static function readFactsFromHandle($handle): array
    {
        rewind($handle);
        $facts = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $firstField = trim((string)($row[0] ?? ''));

            if ($firstField === '') {
                continue;
            }

            if (str_starts_with($firstField, '#')) {
                continue;
            }

            if (self::normaliseFactKey($firstField) === 'keys') {
                continue;
            }

            if (count($row) < 2) {
                continue;
            }

            $factKey = self::normaliseFactKey((string)$row[0]);

            if ($factKey === '') {
                continue;
            }

            $facts[$factKey] = trim((string)$row[1]);
        }

        return $facts;
    }

    private static function writeFactsToHandle($handle, array $facts): void
    {
        ksort($facts);
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, "# keys,fact\n");

        foreach ($facts as $key => $fact) {
            fwrite($handle, self::csvLine([$key, $fact]));
        }

        fflush($handle);
    }

    private static function csvLine(array $values): string
    {
        $encoded = array_map(
            static fn(string $value): string => '"' . str_replace('"', '""', $value) . '"',
            $values
        );

        return implode(',', $encoded) . "\n";
    }

    private static function normaliseFactKey(string $key): string
    {
        return strtolower(trim($key));
    }

    private static function generateFact(): string
    {
        return bin2hex(random_bytes(32));
    }
}
