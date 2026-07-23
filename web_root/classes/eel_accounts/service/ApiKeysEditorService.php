<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/**
 * Maintains api.keys without ever returning stored API_KEY values.
 */
final class ApiKeysEditorService
{
    private const PROVIDER = 'COMPANIESHOUSE';
    private const ENVIRONMENT = 'TEST';
    private const SCHEMA = 'XML';
    private const GATEWAY = 'https://xmlgw.companieshouse.gov.uk/v1-0/xmlgw/Gateway';

    /** @var list<string> */
    private const CH_TEST_TAGS = [
        'ACCOUNTS_FILING_PRESENTER_ID',
        'ACCOUNTS_FILING_AUTHENTICATION',
        'ACCOUNTS_FILING_PACKAGE_REFERENCE',
        'COMPANY_DATA_PRESENTER_ID',
        'COMPANY_DATA_AUTHENTICATION',
        'PREFLIGHT_BINDING_HMAC_KEY',
    ];

    public function __construct(private readonly ?string $keysPath = null)
    {
    }

    /** @return array{rows:list<array{id:string,provider:string,tag:string,environment:string,schema:string,url:string,legacy:bool}>} */
    public function listing(): array
    {
        $document = $this->readDocument();
        $rows = [];
        foreach ($document as $entry) {
            if (($entry['kind'] ?? '') !== 'credential') {
                continue;
            }
            $rows[] = $this->metadata($entry);
        }
        return ['rows' => $rows];
    }

    /**
     * @param array<string, mixed> $updates
     * @param array<string, mixed> $addition
     * @return array{changed:bool,backup_created:bool}
     */
    public function save(array $updates, array $addition): array
    {
        return $this->mutate(function (array $document) use ($updates, $addition): array {
            $byId = [];
            foreach ($document as $index => $entry) {
                if (($entry['kind'] ?? '') === 'credential') {
                    $byId[(string)$entry['id']] = $index;
                }
            }
            foreach ($updates as $id => $submitted) {
                if (!is_array($submitted) || !isset($byId[(string)$id])) {
                    throw new \RuntimeException('The selected API credential no longer exists. Refresh the page and try again.');
                }
                $index = $byId[(string)$id];
                $entry = $document[$index];
                $metadata = $this->validatedMetadata($submitted, !empty($entry['legacy']));
                $document[$index] = array_replace($entry, $metadata);
                $replacement = (string)($submitted['api_key'] ?? '');
                if (trim($replacement) !== '') {
                    $document[$index]['api_key'] = $replacement;
                }
            }

            $additionSupplied = array_filter($addition, static fn(mixed $value): bool => trim((string)$value) !== '') !== [];
            if ($additionSupplied) {
                $metadata = $this->validatedMetadata($addition, false);
                $apiKey = (string)($addition['api_key'] ?? '');
                if (trim($apiKey) === '') {
                    throw new \RuntimeException('A new API credential requires an API key.');
                }
                $document[] = [
                    'kind' => 'credential',
                    'id' => $this->nextId($document),
                    'legacy' => false,
                    'api_key' => $apiKey,
                ] + $metadata;
            }
            $this->assertUnique($document);
            return $document;
        });
    }

    /**
     * @param array<string, mixed> $values
     * @return array{changed:bool,backup_created:bool}
     */
    public function configureCompaniesHouseTest(array $values, bool $generateBindingKey): array
    {
        return $this->mutate(function (array $document) use ($values, $generateBindingKey): array {
            $identity = [];
            foreach ($document as $index => $entry) {
                if (($entry['kind'] ?? '') !== 'credential') {
                    continue;
                }
                $identity[$this->identity($entry)] = $index;
            }
            foreach (self::CH_TEST_TAGS as $tag) {
                $value = (string)($values[$tag] ?? '');
                $key = $this->identity([
                    'provider' => self::PROVIDER,
                    'tag' => $tag,
                    'environment' => self::ENVIRONMENT,
                ]);
                if (isset($identity[$key])) {
                    $index = $identity[$key];
                    if (trim($value) !== '') {
                        $document[$index]['api_key'] = $value;
                    }
                    continue;
                }
                if ($tag === 'PREFLIGHT_BINDING_HMAC_KEY' && $generateBindingKey) {
                    $value = bin2hex(random_bytes(32));
                }
                if (trim($value) === '') {
                    throw new \RuntimeException('Provide each Companies House TEST credential before creating it.');
                }
                $document[] = [
                    'kind' => 'credential',
                    'id' => $this->nextId($document),
                    'legacy' => false,
                    'provider' => self::PROVIDER,
                    'tag' => $tag,
                    'environment' => self::ENVIRONMENT,
                    'schema' => self::SCHEMA,
                    'url' => self::GATEWAY,
                    'api_key' => $value,
                ];
                $identity[$key] = array_key_last($document);
            }
            $this->assertUnique($document);
            return $document;
        });
    }

    /** @return array{changed:bool,backup_created:bool} */
    private function mutate(\Closure $mutation): array
    {
        $path = $this->path();
        $directory = dirname($path);
        if (!is_dir($directory)) {
            throw new \RuntimeException('The configured API key directory is unavailable.');
        }
        $lock = fopen($path . '.lock', 'c+b');
        if ($lock === false) {
            throw new \RuntimeException('The API key editor could not acquire its private lock.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('The API key editor could not lock the credential file.');
            }
            $original = is_file($path) ? file_get_contents($path) : false;
            if (!is_string($original)) {
                throw new \RuntimeException('The API key file is not readable.');
            }
            $document = $this->parse($original);
            $updated = $mutation($document);
            $replacement = $this->serialise($updated);
            if ($replacement === $original) {
                return ['changed' => false, 'backup_created' => false];
            }
            $backup = $this->backupPath($path);
            if (file_put_contents($backup, $original, LOCK_EX) === false
                || !is_file($backup)
                || !hash_equals(hash('sha256', $original), (string)hash_file('sha256', $backup))) {
                @unlink($backup);
                throw new \RuntimeException('The API key backup could not be created and verified.');
            }
            $this->restrictPermissions($backup);
            $temporary = tempnam($directory, 'api.keys.write.');
            if (!is_string($temporary) || file_put_contents($temporary, $replacement, LOCK_EX) === false) {
                @unlink((string)$temporary);
                throw new \RuntimeException('The updated API key file could not be prepared.');
            }
            $this->restrictPermissions($temporary);
            if (!@rename($temporary, $path)) {
                @unlink($temporary);
                throw new \RuntimeException('The updated API key file could not be installed.');
            }
            if (!hash_equals(hash('sha256', $replacement), (string)hash_file('sha256', $path))) {
                throw new \RuntimeException('The updated API key file could not be verified.');
            }
            return ['changed' => true, 'backup_created' => true];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return list<array<string, mixed>> */
    private function readDocument(): array
    {
        $contents = file_get_contents($this->path());
        if (!is_string($contents)) {
            throw new \RuntimeException('The API key file is not readable.');
        }
        return $this->parse($contents);
    }

    /** @return list<array<string, mixed>> */
    private function parse(string $contents): array
    {
        $lines = preg_split('/(?<=\n)/', $contents) ?: [];
        $document = [];
        foreach ($lines as $index => $line) {
            $body = rtrim($line, "\r\n");
            $trimmed = trim($body);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                $document[] = ['kind' => 'raw', 'raw' => $line];
                continue;
            }
            $fields = str_getcsv($body, ',', '"', '');
            if (count($fields) < 5 || strtoupper(trim((string)($fields[0] ?? ''))) === 'PROVIDER') {
                $document[] = ['kind' => 'raw', 'raw' => $line];
                continue;
            }
            $sixColumn = count($fields) >= 6;
            $document[] = [
                'kind' => 'credential',
                'id' => 'row-' . $index,
                'legacy' => !$sixColumn,
                'provider' => strtoupper(trim((string)$fields[0])),
                'tag' => strtoupper(trim((string)$fields[1])),
                'environment' => $sixColumn ? strtoupper(trim((string)$fields[2])) : 'DEFAULT',
                'schema' => strtoupper(trim((string)($fields[$sixColumn ? 3 : 2] ?? ''))),
                'url' => trim((string)($fields[$sixColumn ? 4 : 3] ?? '')),
                'api_key' => (string)($fields[$sixColumn ? 5 : 4] ?? ''),
            ];
        }
        return $document;
    }

    /** @param list<array<string, mixed>> $document */
    private function serialise(array $document): string
    {
        $content = '';
        foreach ($document as $entry) {
            if (($entry['kind'] ?? '') === 'raw') {
                $content .= (string)$entry['raw'];
                continue;
            }
            $values = !empty($entry['legacy'])
                ? [$entry['provider'], $entry['tag'], $entry['schema'], $entry['url'], $entry['api_key']]
                : [$entry['provider'], $entry['tag'], $entry['environment'], $entry['schema'], $entry['url'], $entry['api_key']];
            $content .= $this->csvLine($values);
        }
        return $content;
    }

    /** @param array<string, mixed> $values @return array{provider:string,tag:string,environment:string,schema:string,url:string} */
    private function validatedMetadata(array $values, bool $legacy): array
    {
        $provider = strtoupper(trim((string)($values['provider'] ?? '')));
        $tag = strtoupper(trim((string)($values['tag'] ?? '')));
        $environment = strtoupper(trim((string)($values['environment'] ?? '')));
        $schema = strtoupper(trim((string)($values['schema'] ?? '')));
        $url = trim((string)($values['url'] ?? ''));
        if (preg_match('/^[A-Z0-9][A-Z0-9_.-]{0,63}$/D', $provider) !== 1
            || preg_match('/^[A-Z0-9][A-Z0-9_.-]{0,127}$/D', $tag) !== 1
            || preg_match('/^[A-Z][A-Z0-9+_.-]{0,31}$/D', $schema) !== 1
            || $url === '' || strlen($url) > 1000 || str_contains($url, "\n") || str_contains($url, "\r")) {
            throw new \RuntimeException('Credential metadata is invalid.');
        }
        if ($legacy ? $environment !== 'DEFAULT' : !in_array($environment, ['TEST', 'LIVE'], true)) {
            throw new \RuntimeException('Credential environment is invalid.');
        }
        return compact('provider', 'tag', 'environment', 'schema', 'url');
    }

    /** @param list<array<string, mixed>> $document */
    private function assertUnique(array $document): void
    {
        $seen = [];
        foreach ($document as $entry) {
            if (($entry['kind'] ?? '') !== 'credential') {
                continue;
            }
            $identity = $this->identity($entry);
            if (isset($seen[$identity])) {
                throw new \RuntimeException('Duplicate API credential metadata is not allowed.');
            }
            $seen[$identity] = true;
        }
    }

    /** @param array<string, mixed> $entry */
    private function identity(array $entry): string
    {
        return strtoupper(trim((string)($entry['provider'] ?? '')))
            . '|' . strtoupper(trim((string)($entry['tag'] ?? '')))
            . '|' . strtoupper(trim((string)($entry['environment'] ?? '')));
    }

    /** @param list<array<string, mixed>> $document */
    private function nextId(array $document): string
    {
        return 'new-' . count($document) . '-' . bin2hex(random_bytes(4));
    }

    /** @param array<string, mixed> $entry @return array{id:string,provider:string,tag:string,environment:string,schema:string,url:string,legacy:bool} */
    private function metadata(array $entry): array
    {
        return [
            'id' => (string)$entry['id'],
            'provider' => (string)$entry['provider'],
            'tag' => (string)$entry['tag'],
            'environment' => (string)$entry['environment'],
            'schema' => (string)$entry['schema'],
            'url' => (string)$entry['url'],
            'legacy' => !empty($entry['legacy']),
        ];
    }

    private function path(): string
    {
        return $this->keysPath ?? \SecurityStore::apiKeysPath();
    }

    private function backupPath(string $path): string
    {
        $base = $path . '.backup.' . date('Ymd-His');
        $candidate = $base;
        for ($sequence = 1; file_exists($candidate); $sequence++) {
            $candidate = $base . '.' . str_pad((string)$sequence, 2, '0', STR_PAD_LEFT);
        }
        return $candidate;
    }

    /** @param list<mixed> $values */
    private function csvLine(array $values): string
    {
        $handle = fopen('php://temp', 'w+b');
        if ($handle === false) {
            throw new \RuntimeException('The API key file could not be encoded.');
        }
        try {
            fputcsv($handle, array_map(static fn(mixed $value): string => (string)$value, $values), ',', '"', '');
            rewind($handle);
            return (string)stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
    }

    private function restrictPermissions(string $path): void
    {
        if (DIRECTORY_SEPARATOR !== '\\' && function_exists('chmod')) {
            @chmod($path, 0600);
        }
    }
}
