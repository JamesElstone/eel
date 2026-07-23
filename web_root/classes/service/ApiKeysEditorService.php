<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class ApiKeysEditorService
{
    private const HEADER = ['PROVIDER', 'GATEWAY', 'TAG', 'ENVIRONMENT', 'SCHEMA', 'URL', 'API_IDENTITY', 'API_KEY'];

    public function __construct(
        private readonly ?string $keysPath = null,
        private readonly ?ApiCredentialCatalogService $catalogService = null,
    ) {
    }

    /** @return array{rows:list<array<string,string>>,catalog:list<array<string,string>>} */
    public function listing(): array
    {
        $rows = [];
        foreach ($this->parse($this->readContents()) as $entry) {
            if (($entry['kind'] ?? '') === 'credential') { $rows[] = $this->metadata($entry); }
        }
        return ['rows' => $rows, 'catalog' => $this->catalog()->entries()];
    }

    /** @param array<string, mixed> $submitted @return array{changed:bool,backup_created:bool} */
    public function save(string $credentialId, array $submitted): array
    {
        return $this->mutate(function (array $document) use ($credentialId, $submitted): array {
            $metadata = $this->validatedMetadata($submitted);
            $identitySupplied = array_key_exists('api_identity', $submitted) && (string)$submitted['api_identity'] !== '';
            $keySupplied = array_key_exists('api_key', $submitted) && (string)$submitted['api_key'] !== '';
            $apiIdentity = (string)($submitted['api_identity'] ?? '');
            $apiKey = (string)($submitted['api_key'] ?? '');
            if ($identitySupplied) { $this->assertSecretValue($apiIdentity, 'API identity'); }
            if ($keySupplied) { $this->assertSecretValue($apiKey, 'API key'); }

            if ($credentialId === '') {
                if (!$keySupplied) { throw new RuntimeException('A new API credential requires an API key.'); }
                $document[] = ['kind' => 'credential', 'id' => $this->nextId($document), 'api_identity' => $apiIdentity, 'api_key' => $apiKey] + $metadata;
            } else {
                foreach ($document as $index => $entry) {
                    if (($entry['kind'] ?? '') !== 'credential' || (string)($entry['id'] ?? '') !== $credentialId) { continue; }
                    $document[$index] = array_replace($entry, $metadata);
                    if ($identitySupplied) { $document[$index]['api_identity'] = $apiIdentity; }
                    if ($keySupplied) { $document[$index]['api_key'] = $apiKey; }
                    $this->assertUnique($document);
                    return $document;
                }
                throw new RuntimeException('The selected API credential no longer exists. Refresh the page and try again.');
            }
            $this->assertUnique($document);
            return $document;
        });
    }

    /** @return array{changed:bool,backup_created:bool} */
    private function mutate(Closure $mutation): array
    {
        $path = $this->path();
        $directory = dirname($path);
        if (!is_dir($directory)) { throw new RuntimeException('The configured API key directory is unavailable.'); }
        $lock = fopen($path . '.lock', 'c+b');
        if ($lock === false) { throw new RuntimeException('The API key editor could not acquire its private lock.'); }
        try {
            if (!flock($lock, LOCK_EX)) { throw new RuntimeException('The API key editor could not lock the credential file.'); }
            $original = $this->readContents();
            $replacement = $this->serialise($mutation($this->parse($original)));
            if ($replacement === $original) { return ['changed' => false, 'backup_created' => false]; }
            $backup = $this->backupPath($path);
            if (file_put_contents($backup, $original, LOCK_EX) === false || !hash_equals(hash('sha256', $original), (string)hash_file('sha256', $backup))) {
                @unlink($backup);
                throw new RuntimeException('The API key backup could not be created and verified.');
            }
            $this->restrictPermissions($backup);
            $temporary = tempnam($directory, 'api.keys.write.');
            if (!is_string($temporary) || file_put_contents($temporary, $replacement, LOCK_EX) === false) {
                @unlink((string)$temporary);
                throw new RuntimeException('The updated API key file could not be prepared.');
            }
            $this->restrictPermissions($temporary);
            if (!@rename($temporary, $path) || !hash_equals(hash('sha256', $replacement), (string)hash_file('sha256', $path))) {
                @unlink($temporary);
                throw new RuntimeException('The updated API key file could not be installed and verified.');
            }
            return ['changed' => true, 'backup_created' => true];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function readContents(): string
    {
        $contents = file_get_contents($this->path());
        if (!is_string($contents)) { throw new RuntimeException('The API key file is not readable.'); }
        return $contents;
    }

    /** @return list<array<string, mixed>> */
    private function parse(string $contents): array
    {
        $document = [];
        $headerRead = false;
        foreach ($this->csvRecords($contents) as $recordIndex => $rawRecord) {
            $body = rtrim($rawRecord, "\r\n");
            $trimmed = trim($body);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) { $document[] = ['kind' => 'raw', 'raw' => $rawRecord]; continue; }
            $fields = str_getcsv($body, ',', '"', '');
            if (!$headerRead) {
                if ($fields !== self::HEADER) { throw new RuntimeException('API key file header must be PROVIDER,GATEWAY,TAG,ENVIRONMENT,SCHEMA,URL,API_IDENTITY,API_KEY.'); }
                $document[] = ['kind' => 'header'];
                $headerRead = true;
                continue;
            }
            if (count($fields) !== 8) { throw new RuntimeException('API credential record ' . ($recordIndex + 1) . ' must contain exactly eight columns.'); }
            $selection = $this->catalog()->requireAllowed((string)$fields[0], (string)$fields[1], (string)$fields[2], (string)$fields[3]);
            $schema = strtoupper(trim((string)$fields[4]));
            $url = trim((string)$fields[5]);
            $apiIdentity = (string)$fields[6];
            $apiKey = (string)$fields[7];
            if ($schema === '' || $url === '' || $apiKey === '') { throw new RuntimeException('API credential record ' . ($recordIndex + 1) . ' has a blank schema, URL, or API key.'); }
            $this->assertSecretValue($apiIdentity, 'API identity');
            $this->assertSecretValue($apiKey, 'API key');
            $document[] = ['kind' => 'credential', 'id' => 'record-' . $recordIndex, 'api_identity' => $apiIdentity, 'api_key' => $apiKey, 'schema' => $schema, 'url' => $url] + $selection;
        }
        if (!$headerRead) { throw new RuntimeException('API key file is missing the required credential header.'); }
        return $document;
    }

    /** @return list<string> */
    private function csvRecords(string $contents): array
    {
        $records = [];
        $record = '';
        $inQuotes = false;
        $length = strlen($contents);
        for ($index = 0; $index < $length; $index++) {
            $character = $contents[$index];
            $record .= $character;
            if ($character === '"') {
                if ($inQuotes && $index + 1 < $length && $contents[$index + 1] === '"') { $record .= $contents[++$index]; continue; }
                $inQuotes = !$inQuotes;
                continue;
            }
            if (($character === "\n" || $character === "\r") && !$inQuotes) {
                if ($character === "\r" && $index + 1 < $length && $contents[$index + 1] === "\n") { $record .= $contents[++$index]; }
                $records[] = $record;
                $record = '';
            }
        }
        if ($inQuotes) { throw new RuntimeException('API key file contains an unterminated quoted CSV field.'); }
        if ($record !== '') { $records[] = $record; }
        return $records;
    }

    /** @param list<array<string, mixed>> $document */
    private function serialise(array $document): string
    {
        $output = '';
        foreach ($document as $entry) {
            if (($entry['kind'] ?? '') === 'raw') { $output .= (string)$entry['raw']; }
            elseif (($entry['kind'] ?? '') === 'header') { $output .= $this->csvLine(self::HEADER); }
            elseif (($entry['kind'] ?? '') === 'credential') {
                $output .= $this->csvLine([$entry['provider'], $entry['gateway'], $entry['tag'], $entry['environment'], $entry['schema'], $entry['url'], $entry['api_identity'], $entry['api_key']], [6, 7]);
            }
        }
        return $output;
    }

    /** @param array<string, mixed> $values @return array{provider:string,gateway:string,tag:string,environment:string,schema:string,url:string} */
    private function validatedMetadata(array $values): array
    {
        $selection = $this->catalog()->requireAllowed((string)($values['provider'] ?? ''), (string)($values['gateway'] ?? ''), (string)($values['tag'] ?? ''), (string)($values['environment'] ?? ''));
        $schema = strtoupper(trim((string)($values['schema'] ?? '')));
        $url = trim((string)($values['url'] ?? ''));
        if (preg_match('/^[A-Z][A-Z0-9+_.-]{0,31}$/D', $schema) !== 1 || $url === '' || strlen($url) > 1000 || str_contains($url, "\n") || str_contains($url, "\r")) { throw new RuntimeException('Credential schema or URL is invalid.'); }
        return $selection + ['schema' => $schema, 'url' => $url];
    }

    private function assertSecretValue(string $value, string $label): void
    {
        if (str_contains($value, "\0") || preg_match('//u', $value) !== 1) { throw new RuntimeException($label . ' must be valid UTF-8 text and cannot contain NUL.'); }
    }

    /** @param list<array<string, mixed>> $document */
    private function assertUnique(array $document): void
    {
        $seen = [];
        foreach ($document as $entry) {
            if (($entry['kind'] ?? '') !== 'credential') { continue; }
            $identity = implode('|', [$entry['provider'], $entry['gateway'], $entry['tag'], $entry['environment']]);
            if (isset($seen[$identity])) { throw new RuntimeException('Duplicate API credential metadata is not allowed.'); }
            $seen[$identity] = true;
        }
    }

    /** @param list<array<string, mixed>> $document */
    private function nextId(array $document): string { return 'new-' . count($document) . '-' . bin2hex(random_bytes(4)); }

    /** @param array<string, mixed> $entry @return array<string,string> */
    private function metadata(array $entry): array { return array_intersect_key($entry, array_flip(['id', 'provider', 'gateway', 'tag', 'environment', 'schema', 'url'])); }

    private function catalog(): ApiCredentialCatalogService { return $this->catalogService ?? new ApiCredentialCatalogService(); }
    private function path(): string { return $this->keysPath ?? SecurityStore::apiKeysPath(); }

    private function backupPath(string $path): string
    {
        $base = $path . '.backup.' . date('Ymd-His');
        $candidate = $base;
        for ($sequence = 1; file_exists($candidate); $sequence++) { $candidate = $base . '.' . str_pad((string)$sequence, 2, '0', STR_PAD_LEFT); }
        return $candidate;
    }

    /** @param list<mixed> $values @param list<int> $alwaysQuote */
    private function csvLine(array $values, array $alwaysQuote = []): string
    {
        $fields = [];
        foreach ($values as $index => $value) {
            $value = (string)$value;
            $quote = in_array($index, $alwaysQuote, true) || strpbrk($value, ",\"\r\n") !== false;
            $fields[] = $quote ? '"' . str_replace('"', '""', $value) . '"' : $value;
        }
        return implode(',', $fields) . "\n";
    }

    private function restrictPermissions(string $path): void
    {
        if (DIRECTORY_SEPARATOR !== '\\' && function_exists('chmod')) { @chmod($path, 0600); }
    }
}
