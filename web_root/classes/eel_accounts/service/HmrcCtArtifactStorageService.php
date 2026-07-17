<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/**
 * Immutable, non-public storage for credential-free CT600 filing artefacts.
 *
 * Database rows store only the relative keys returned by this class. Absolute
 * paths are resolved only at the authenticated read boundary.
 */
final class HmrcCtArtifactStorageService
{
    private const ENVIRONMENTS = ['TEST', 'TIL', 'LIVE'];
    private const RESPONSE_KINDS = [
        'acknowledgement',
        'poll',
        'final',
        'receipt',
        'recovery',
        'delete',
        'error',
    ];

    private string $root;

    public function __construct(
        ?string $artifactRoot = null,
        ?HmrcCtConfigurationService $configuration = null,
    ) {
        if ($artifactRoot === null || trim($artifactRoot) === '') {
            $profile = ($configuration ?? new HmrcCtConfigurationService())->profile();
            $artifactRoot = (string)($profile['artifact_root'] ?? '');
        }

        $this->root = $this->initialiseRoot((string)$artifactRoot);
    }

    /**
     * Stores one complete, frozen package. Repeating the call with identical
     * bytes is idempotent; changing any byte at the same key fails closed.
     *
     * @param array<string, mixed>|\JsonSerializable|string $manifest
     * @return array<string, mixed>
     */
    public function storePreparedPackage(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        string $environment,
        string $packageHash,
        string $ct600IrEnvelope,
        string $accountsIxbrl,
        string $computationsIxbrl,
        array|\JsonSerializable|string $manifest,
    ): array {
        $this->assertPositiveId($companyId, 'company');
        $this->assertPositiveId($accountingPeriodId, 'accounting period');
        $this->assertPositiveId($ctPeriodId, 'Corporation Tax period');
        $environment = $this->environment($environment);
        $packageHash = $this->sha256($packageHash, 'package');

        foreach (
            [
                'CT600 IR envelope' => $ct600IrEnvelope,
                'accounts iXBRL' => $accountsIxbrl,
                'computations iXBRL' => $computationsIxbrl,
            ] as $label => $contents
        ) {
            if ($contents === '') {
                throw new \InvalidArgumentException($label . ' bytes are required.');
            }
            $this->assertCredentialFree($contents, $label);
        }

        $manifestJson = $this->manifestJson($manifest);
        $this->assertCredentialFree($manifestJson, 'package manifest');
        $directory = implode('/', [
            'companies',
            (string)$companyId,
            'accounting-periods',
            (string)$accountingPeriodId,
            'ct-periods',
            (string)$ctPeriodId,
            $environment,
            $packageHash,
        ]);

        $ct600 = $this->writeImmutable($directory . '/ct600-ir-envelope.xml', $ct600IrEnvelope);
        $accounts = $this->writeImmutable($directory . '/accounts.ixbrl.html', $accountsIxbrl);
        $computations = $this->writeImmutable($directory . '/computations.ixbrl.html', $computationsIxbrl);
        $manifestArtifact = $this->writeImmutable($directory . '/manifest.json', $manifestJson);

        return [
            'directory' => $directory,
            'package_hash' => $packageHash,
            'ct600_path' => $ct600['path'],
            'ct600_sha256' => $ct600['sha256'],
            'accounts_ixbrl_path' => $accounts['path'],
            'accounts_sha256' => $accounts['sha256'],
            'computations_ixbrl_path' => $computations['path'],
            'computations_sha256' => $computations['sha256'],
            'manifest_path' => $manifestArtifact['path'],
            'manifest_sha256' => $manifestArtifact['sha256'],
        ];
    }

    /** @return array{path: string, sha256: string, bytes: int} */
    public function storeRedactedRequest(string $packageDirectory, string $xml): array
    {
        $packageDirectory = $this->normaliseStorageKey($packageDirectory, false);
        if ($xml === '') {
            throw new \InvalidArgumentException('The redacted GovTalk request is empty.');
        }
        $this->assertCredentialFree($xml, 'redacted GovTalk request');

        return $this->writeImmutable($packageDirectory . '/submission-request-redacted.xml', $xml);
    }

    /** @return array{path: string, sha256: string, bytes: int} */
    public function storeResponse(
        string $packageDirectory,
        string $kind,
        string $contents,
        string $identifier = '',
    ): array {
        $packageDirectory = $this->normaliseStorageKey($packageDirectory, false);
        $kind = strtolower(trim($kind));
        if (!in_array($kind, self::RESPONSE_KINDS, true)) {
            throw new \InvalidArgumentException('Unsupported HMRC response artefact kind.');
        }
        if ($contents === '') {
            throw new \InvalidArgumentException('The HMRC response artefact is empty.');
        }
        $this->assertCredentialFree($contents, 'HMRC response');

        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            $identifier = substr(hash('sha256', $contents), 0, 20);
        }
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,79}$/D', $identifier)) {
            throw new \InvalidArgumentException('The response artefact identifier is invalid.');
        }

        return $this->writeImmutable(
            $packageDirectory . '/responses/' . $kind . '-' . $identifier . '.xml',
            $contents
        );
    }

    /** @return array{path: string, sha256: string, bytes: int} */
    public function writeImmutable(string $storageKey, string $contents, ?string $expectedSha256 = null): array
    {
        $storageKey = $this->normaliseStorageKey($storageKey, true);
        $this->assertCredentialFree($contents, 'CT600 artefact');
        $actualHash = hash('sha256', $contents);
        if ($expectedSha256 !== null && $this->sha256($expectedSha256, 'expected') !== $actualHash) {
            throw new \RuntimeException('The CT600 artefact bytes do not match the expected SHA-256 fingerprint.');
        }

        $absolute = $this->absolutePath($storageKey);
        $directory = dirname($absolute);
        $this->ensureDirectory($directory);

        if (is_file($absolute)) {
            return $this->existingArtifact($storageKey, $absolute, $actualHash, strlen($contents));
        }

        $temporary = tempnam($directory, '.ct600-');
        if (!is_string($temporary) || $temporary === '') {
            throw new \RuntimeException('Unable to create a temporary CT600 artefact file.');
        }

        try {
            $written = file_put_contents($temporary, $contents, LOCK_EX);
            if ($written !== strlen($contents)) {
                throw new \RuntimeException('Unable to write the complete CT600 artefact.');
            }
            @chmod($temporary, 0600);
            if (!hash_equals($actualHash, (string)hash_file('sha256', $temporary))) {
                throw new \RuntimeException('The staged CT600 artefact failed SHA-256 verification.');
            }

            $lockPath = $directory . DIRECTORY_SEPARATOR . '.publish.lock';
            $lock = fopen($lockPath, 'c+b');
            if ($lock === false || !flock($lock, LOCK_EX)) {
                if (is_resource($lock)) {
                    fclose($lock);
                }
                throw new \RuntimeException('Unable to lock the CT600 artefact directory for publication.');
            }

            try {
                @chmod($lockPath, 0600);
                if (is_file($absolute)) {
                    return $this->existingArtifact($storageKey, $absolute, $actualHash, strlen($contents));
                }
                if (!@rename($temporary, $absolute)) {
                    throw new \RuntimeException('Unable to publish the CT600 artefact atomically.');
                }
                $temporary = '';
                @chmod($absolute, 0600);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } finally {
            if ($temporary !== '' && is_file($temporary)) {
                @unlink($temporary);
            }
        }

        return $this->verifiedMetadata($storageKey, $absolute, $actualHash, strlen($contents));
    }

    public function readVerified(string $storageKey, ?string $expectedSha256 = null): string
    {
        $storageKey = $this->normaliseStorageKey($storageKey, true);
        $absolute = $this->absolutePath($storageKey);
        if (!is_file($absolute)) {
            throw new \RuntimeException('The requested CT600 artefact does not exist.');
        }
        $this->assertResolvedFileBeneathRoot($absolute);

        $contents = file_get_contents($absolute);
        if (!is_string($contents)) {
            throw new \RuntimeException('The requested CT600 artefact could not be read.');
        }
        $actualHash = (string)hash_file('sha256', $absolute);
        if ($expectedSha256 !== null && !hash_equals($this->sha256($expectedSha256, 'expected'), $actualHash)) {
            throw new \RuntimeException('The requested CT600 artefact failed SHA-256 verification.');
        }

        return $contents;
    }

    public function verify(string $storageKey, string $expectedSha256): bool
    {
        try {
            $storageKey = $this->normaliseStorageKey($storageKey, true);
            $absolute = $this->absolutePath($storageKey);
            if (!is_file($absolute)) {
                return false;
            }
            $this->assertResolvedFileBeneathRoot($absolute);
            return hash_equals($this->sha256($expectedSha256, 'expected'), (string)hash_file('sha256', $absolute));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Resolves a protected key for an authenticated download controller. This
     * method never accepts an absolute path and never creates a file.
     */
    public function resolveForRead(string $storageKey): string
    {
        $storageKey = $this->normaliseStorageKey($storageKey, true);
        $absolute = $this->absolutePath($storageKey);
        if (!is_file($absolute)) {
            throw new \RuntimeException('The requested CT600 artefact does not exist.');
        }

        $resolved = realpath($absolute);
        if (!is_string($resolved) || !$this->isBeneathRoot($resolved)) {
            throw new \RuntimeException('The requested CT600 artefact is outside protected storage.');
        }

        return $resolved;
    }

    /** @return array{path: string, sha256: string, bytes: int} */
    private function existingArtifact(
        string $storageKey,
        string $absolute,
        string $expectedHash,
        int $expectedBytes,
    ): array {
        $this->assertResolvedFileBeneathRoot($absolute);
        $actualHash = (string)hash_file('sha256', $absolute);
        $actualBytes = filesize($absolute);
        if (!hash_equals($expectedHash, $actualHash) || $actualBytes !== $expectedBytes) {
            throw new \RuntimeException('An immutable CT600 artefact already exists with different contents.');
        }

        return $this->verifiedMetadata($storageKey, $absolute, $expectedHash, $expectedBytes);
    }

    /** @return array{path: string, sha256: string, bytes: int} */
    private function verifiedMetadata(
        string $storageKey,
        string $absolute,
        string $expectedHash,
        int $expectedBytes,
    ): array {
        if (!is_file($absolute)) {
            throw new \RuntimeException('The published CT600 artefact is missing.');
        }
        $actualHash = (string)hash_file('sha256', $absolute);
        $actualBytes = filesize($absolute);
        if (!hash_equals($expectedHash, $actualHash) || $actualBytes !== $expectedBytes) {
            throw new \RuntimeException('The published CT600 artefact failed final verification.');
        }

        return ['path' => $storageKey, 'sha256' => $actualHash, 'bytes' => (int)$actualBytes];
    }

    private function assertResolvedFileBeneathRoot(string $absolute): void
    {
        $resolved = realpath($absolute);
        if (!is_string($resolved) || !$this->isBeneathRoot($resolved)) {
            throw new \RuntimeException('The CT600 artefact resolved outside protected storage.');
        }
    }

    private function initialiseRoot(string $root): string
    {
        $root = trim($root);
        if ($root === '' || !$this->isAbsolutePath($root)) {
            throw new \RuntimeException('HMRC CT600 artefact storage must use an absolute non-public path.');
        }
        if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
            throw new \RuntimeException('Unable to create protected HMRC CT600 artefact storage.');
        }
        @chmod($root, 0700);

        $resolved = realpath($root);
        if (!is_string($resolved) || $resolved === '') {
            throw new \RuntimeException('Unable to resolve protected HMRC CT600 artefact storage.');
        }

        $publicRoot = realpath(APP_ROOT);
        if (is_string($publicRoot) && $this->pathIsWithin($resolved, $publicRoot)) {
            throw new \RuntimeException('HMRC CT600 artefacts must not be stored beneath the public web root.');
        }

        return rtrim($resolved, '\\/');
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create a protected CT600 artefact directory.');
        }
        $resolved = realpath($directory);
        if (!is_string($resolved) || !$this->isBeneathRoot($resolved)) {
            throw new \RuntimeException('The CT600 artefact directory escaped protected storage.');
        }
        @chmod($resolved, 0700);
    }

    private function absolutePath(string $storageKey): string
    {
        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storageKey);
    }

    private function normaliseStorageKey(string $storageKey, bool $requireFilename): string
    {
        $storageKey = trim(str_replace('\\', '/', $storageKey));
        if (
            $storageKey === ''
            || str_contains($storageKey, "\0")
            || $this->isAbsolutePath($storageKey)
            || str_ends_with($storageKey, '/')
        ) {
            throw new \InvalidArgumentException('A relative protected-storage key is required.');
        }
        $segments = explode('/', $storageKey);
        foreach ($segments as $segment) {
            if (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
                || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $segment)
            ) {
                throw new \InvalidArgumentException('The protected-storage key contains an invalid path segment.');
            }
        }
        if ($requireFilename && !str_contains((string)end($segments), '.')) {
            throw new \InvalidArgumentException('The protected-storage key must name a file.');
        }
        if (strlen($storageKey) > 900) {
            throw new \InvalidArgumentException('The protected-storage key is too long.');
        }

        return implode('/', $segments);
    }

    private function isBeneathRoot(string $path): bool
    {
        return $this->pathIsWithin($path, $this->root);
    }

    private function pathIsWithin(string $path, string $parent): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $parent = rtrim(str_replace('\\', '/', $parent), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $parent = strtolower($parent);
        }

        return $path === $parent || str_starts_with($path, $parent . '/');
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1 || str_starts_with($path, '/');
    }

    private function assertCredentialFree(string $contents, string $label): void
    {
        if ($this->containsUnredactedGovTalkAuthentication($contents)) {
            throw new \RuntimeException($label . ' contains authentication material and cannot be persisted.');
        }

        $patterns = [
            '/<\s*(?:[A-Za-z0-9_-]+:)?Password\b/i',
            '/\bAuthorization\s*:\s*(?:Basic|Bearer)\b/i',
            '/\b(?:api[_-]?key|access[_-]?token|client[_-]?secret|sender[_-]?password)\b\s*[=:]/i',
            '/"(?:password|secret|token|authorization|credential)"\s*:/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                throw new \RuntimeException($label . ' contains authentication material and cannot be persisted.');
            }
        }
    }

    private function containsUnredactedGovTalkAuthentication(string $contents): bool
    {
        if (stripos($contents, 'SenderDetails') === false
            && stripos($contents, 'IDAuthentication') === false
        ) {
            return false;
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $loaded = $document->loadXML($contents, \LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($loaded) {
            $xpath = new \DOMXPath($document);
            $nodes = $xpath->query(
                '//*[local-name()="SenderDetails"]//*[local-name()="SenderID"]'
                . ' | //*[local-name()="SenderDetails"]//*[local-name()="Authentication"]'
                . '/*[local-name()="Value"]'
            );
            if ($nodes !== false) {
                foreach ($nodes as $node) {
                    if (!$this->isRedactedCredentialValue((string)$node->textContent)) {
                        return true;
                    }
                }
            }

            return false;
        }

        // Malformed diagnostic XML still must not provide a way to bypass the
        // persistence guard.  Check the two GovTalk credential element shapes
        // without depending on the document being parseable.
        if (preg_match_all(
            '/<(?:[A-Za-z_][A-Za-z0-9_.-]*:)?SenderID\b[^>]*>(.*?)<\/(?:[A-Za-z_][A-Za-z0-9_.-]*:)?SenderID\s*>/is',
            $contents,
            $matches
        ) === false) {
            return true;
        }
        foreach ((array)($matches[1] ?? []) as $value) {
            if (!$this->isRedactedCredentialValue(strip_tags((string)$value))) {
                return true;
            }
        }
        if (preg_match_all(
            '/<(?:[A-Za-z_][A-Za-z0-9_.-]*:)?Authentication\b[^>]*>(.*?)<\/(?:[A-Za-z_][A-Za-z0-9_.-]*:)?Authentication\s*>/is',
            $contents,
            $authenticationBlocks
        ) === false) {
            return true;
        }
        foreach ((array)($authenticationBlocks[1] ?? []) as $block) {
            if (preg_match_all(
                '/<(?:[A-Za-z_][A-Za-z0-9_.-]*:)?Value\b[^>]*>(.*?)<\/(?:[A-Za-z_][A-Za-z0-9_.-]*:)?Value\s*>/is',
                (string)$block,
                $values
            ) === false) {
                return true;
            }
            foreach ((array)($values[1] ?? []) as $value) {
                if (!$this->isRedactedCredentialValue(strip_tags((string)$value))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isRedactedCredentialValue(string $value): bool
    {
        $value = trim(html_entity_decode($value, \ENT_QUOTES | \ENT_XML1, 'UTF-8'));
        return $value === '' || in_array(strtoupper($value), ['[REDACTED]', 'REDACTED'], true);
    }

    /** @param array<string, mixed>|\JsonSerializable|string $manifest */
    private function manifestJson(array|\JsonSerializable|string $manifest): string
    {
        if (is_string($manifest)) {
            json_decode($manifest, true, 64, JSON_THROW_ON_ERROR);
            return rtrim($manifest) . "\n";
        }

        return json_encode(
            $manifest,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ) . "\n";
    }

    private function environment(string $environment): string
    {
        $environment = strtoupper(trim($environment));
        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            throw new \InvalidArgumentException('The HMRC CT environment must be TEST, TIL or LIVE.');
        }

        return $environment;
    }

    private function sha256(string $hash, string $label): string
    {
        $hash = strtolower(trim($hash));
        if (!preg_match('/^[a-f0-9]{64}$/D', $hash)) {
            throw new \InvalidArgumentException('The ' . $label . ' SHA-256 fingerprint is invalid.');
        }

        return $hash;
    }

    private function assertPositiveId(int $id, string $label): void
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('A valid ' . $label . ' ID is required.');
        }
    }
}
