<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SecurityStore
{
    private const SECURITY_FILE_MODE = 0600;

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
        $path = self::apiKeysPath($keysPath);

        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('API key file was not found or is not readable: ' . $path);
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('API key file could not be opened: ' . $path);
        }

        $catalog = [];
        $catalogService = new ApiCredentialCatalogService();
        $headerRead = false;
        $rowNumber = 0;

        try {
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $rowNumber++;
                $firstField = trim((string)($row[0] ?? ''));

                if ($firstField === '' || str_starts_with($firstField, '#')) {
                    continue;
                }

                if (!$headerRead) {
                    if ($row !== ['PROVIDER', 'GATEWAY', 'TAG', 'ENVIRONMENT', 'SCHEMA', 'URL', 'API_IDENTITY', 'API_KEY']) {
                        throw new RuntimeException('API key file header must be PROVIDER,GATEWAY,TAG,ENVIRONMENT,SCHEMA,URL,API_IDENTITY,API_KEY.');
                    }
                    $headerRead = true;
                    continue;
                }

                if (count($row) !== 8) {
                    throw new RuntimeException('API credential row ' . $rowNumber . ' must contain exactly eight columns.');
                }

                $selection = $catalogService->requireAllowed(
                    (string)$row[0],
                    (string)$row[1],
                    (string)$row[2],
                    (string)$row[3]
                );
                $schema = strtoupper(trim((string)$row[4]));
                $url = trim((string)$row[5]);
                $apiIdentity = (string)$row[6];
                $apiKey = (string)$row[7];
                if ($schema === '' || $apiKey === '') {
                    throw new RuntimeException('API credential row ' . $rowNumber . ' has a blank schema or API key.');
                }
                if (!self::isValidSecretValue($apiIdentity) || !self::isValidSecretValue($apiKey)) {
                    throw new RuntimeException('API credential row ' . $rowNumber . ' has an invalid API identity or API key.');
                }
                $credential = [
                    ...$selection,
                    'schema' => $schema,
                    'url' => $url,
                    'api_identity' => $apiIdentity,
                    'api_key' => $apiKey,
                ];
                if (isset($catalog[$selection['provider']][$selection['gateway']][$selection['tag']][$selection['environment']])) {
                    throw new RuntimeException('Duplicate API credential row for ' . implode(' / ', $selection) . '.');
                }
                $catalog[$selection['provider']][$selection['gateway']][$selection['tag']][$selection['environment']] = $credential;
            }
        } finally {
            fclose($handle);
        }

        if (!$headerRead) {
            throw new RuntimeException('API key file is missing the required credential header.');
        }

        return $catalog;
    }

    public static function loadCredential(
        string $provider,
        string $gateway,
        string $tag,
        string $environment,
        ?string $keysPath = null
    ): array {
        $selection = (new ApiCredentialCatalogService())->requireAllowed(
            $provider,
            $gateway,
            $tag,
            $environment
        );
        $catalog = self::credentialCatalog($keysPath);
        $credential = $catalog[$selection['provider']][$selection['gateway']][$selection['tag']][$selection['environment']] ?? null;

        if (is_array($credential)) {
            return $credential;
        }

        throw new RuntimeException('API credential not found for ' . implode(' / ', $selection) . '.');
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
            self::ensurePrivateFileMode($path);

            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Security key file could not be locked: ' . $path);
            }

            $facts = self::readFactsFromHandle($handle);

            if (isset($facts[$normalisedKey]) && $facts[$normalisedKey] !== '') {
                return $facts[$normalisedKey];
            }

            $facts[$normalisedKey] = self::generateFact();
            self::writeFactsToHandle($handle, $facts);
            self::ensurePrivateFileMode($path);

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

    private static function ensurePrivateFileMode(string $path): void
    {
        if (DIRECTORY_SEPARATOR === '\\' || !function_exists('chmod')) {
            return;
        }

        if (!@chmod($path, self::SECURITY_FILE_MODE)) {
            throw new RuntimeException('Unable to set security key file permissions: ' . $path);
        }
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

    private static function isValidSecretValue(string $value): bool
    {
        return !str_contains($value, "\0") && preg_match('//u', $value) === 1;
    }

    private static function generateFact(): string
    {
        return bin2hex(random_bytes(32));
    }
}
