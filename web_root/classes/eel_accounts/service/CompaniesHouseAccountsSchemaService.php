<?php
declare(strict_types=1);

namespace eel_accounts\Service;

use eel_accounts\Store\AccountingConfigurationStore;

final class CompaniesHouseAccountsSchemaService implements CompaniesHouseSchemaCurrentnessInterface
{
    public const SOURCE_URL = 'https://xmlgw.companieshouse.gov.uk/SchemaStatus';
    /** @var array<string,string> */
    private const ROOTS = [
        'envelope' => 'https://xmlgw.companieshouse.gov.uk/v1-0/schema/Egov_ch-v2-0.xsd',
        'form_submission' => 'https://xmlgw.companieshouse.gov.uk/v1-0/schema/forms/FormSubmission-v2-11.xsd',
        'submission_status' => 'https://xmlgw.companieshouse.gov.uk/v1-0/schema/forms/GetSubmissionStatus-v2-9.xsd',
        'status_ack' => 'https://xmlgw.companieshouse.gov.uk/v1-0/schema/forms/GetStatusAck-v1-1.xsd',
        'company_data' => 'https://xmlgw.companieshouse.gov.uk/v1-0/schema/CompanyData-v3-6.xsd',
        'get_document' => 'https://xmlgw.companieshouse.gov.uk/v1-0/schema/forms/GetDocument-v1-1.xsd',
    ];
    private const MAX_FILES = 500;
    private const MAX_FILE_BYTES = 5242880;
    private const MAX_TOTAL_BYTES = 104857600;

    private ?\Closure $fetcher;
    private string $cacheDirectory;
    private string $stagingDirectory;
    private string $validationDirectory;
    private CompaniesHouseSchemaCompatibilityService $compatibilityService;

    public function __construct(
        ?callable $fetcher = null,
        ?string $cacheDirectory = null,
        ?string $stagingDirectory = null,
        ?string $validationDirectory = null,
        ?CompaniesHouseSchemaCompatibilityService $compatibilityService = null
    )
    {
        $this->fetcher = $fetcher === null ? null : \Closure::fromCallable($fetcher);
        $this->cacheDirectory = rtrim(
            $cacheDirectory ?? dirname(__DIR__, 4) . '/third_party/companies_house/assets',
            '/\\'
        );
        $this->stagingDirectory = rtrim(
            $stagingDirectory ?? $this->configuredStagingDirectory(),
            '/\\'
        );
        $this->validationDirectory = rtrim(
            $validationDirectory
                ?? dirname($this->cacheDirectory) . '/validation/'
                    . CompaniesHouseSchemaCompatibilityService::PROFILE,
            '/\\'
        );
        $this->compatibilityService = $compatibilityService
            ?? new CompaniesHouseSchemaCompatibilityService();
    }

    public function fetchStatus(): array
    {
        $catalogue = \InterfaceDB::fetchAll(
            'SELECT schema_name, source_url, lifecycle_status, release_date, live_date, deprecated_date, retirement_date, last_seen_at FROM companies_house_schema_catalogue ORDER BY schema_name'
        );
        try {
            $installed = $this->installedSchemas();
            $state = [
                'ready' => true,
                'file_count' => count((array)$installed['files']),
                'checked_at' => (string)($installed['checked_at'] ?? ''),
                'verified_at' => (string)($installed['verified_at'] ?? ''),
                'validation_profile' => (string)($installed['validation_profile'] ?? ''),
                'validation_verified_at' => (string)(
                    $installed['validation_verified_at'] ?? ''
                ),
                'error' => '',
            ];
            $files = (array)$installed['files'];
        } catch (\Throwable $exception) {
            $files = array_map(
                [$this, 'evidenceFile'],
                \InterfaceDB::fetchAll(
                    'SELECT * FROM companies_house_schema_files ORDER BY relative_path'
                )
            );
            $state = [
                'ready' => false,
                'file_count' => count($files),
                'checked_at' => $files === [] ? '' : max(array_column($files, 'checked_at')),
                'verified_at' => $files === [] ? '' : max(array_column($files, 'verified_at')),
                'validation_profile' => '',
                'validation_verified_at' => '',
                'error' => $exception->getMessage(),
            ];
        }
        return [
            'state' => $state,
            'catalogue' => $catalogue,
            'roots' => self::ROOTS,
            'files' => $files,
        ];
    }

    public function installedSchemas(): array
    {
        return $this->installedSchemaSet(self::ROOTS);
    }

    public function installedSchemasForOperation(string $operation): array
    {
        $operation = str_replace('-', '_', strtolower(trim($operation)));
        if (!array_key_exists($operation, self::ROOTS) || $operation === 'envelope') {
            throw new \InvalidArgumentException(
                'Choose a supported Companies House XML schema operation.'
            );
        }

        return $this->installedSchemaSet([
            'envelope' => self::ROOTS['envelope'],
            $operation => self::ROOTS[$operation],
        ]);
    }

    public function fetchOperationStatus(string $operation): array
    {
        try {
            $installed = $this->installedSchemasForOperation($operation);
            return [
                'state' => [
                    'ready' => true,
                    'file_count' => count((array)$installed['files']),
                    'checked_at' => (string)($installed['checked_at'] ?? ''),
                    'verified_at' => (string)($installed['verified_at'] ?? ''),
                    'validation_profile' => (string)(
                        $installed['validation_profile'] ?? ''
                    ),
                    'validation_verified_at' => (string)(
                        $installed['validation_verified_at'] ?? ''
                    ),
                    'error' => '',
                ],
                'files' => (array)$installed['files'],
            ];
        } catch (\Throwable $exception) {
            return [
                'state' => [
                    'ready' => false,
                    'file_count' => 0,
                    'checked_at' => '',
                    'verified_at' => '',
                    'validation_profile' => '',
                    'validation_verified_at' => '',
                    'error' => $exception->getMessage(),
                ],
                'files' => [],
            ];
        }
    }

    /** @param array<string,string> $roots */
    private function installedSchemaSet(array $roots): array
    {
        $rows = \InterfaceDB::fetchAll(
            'SELECT * FROM companies_house_schema_files ORDER BY source_url'
        );
        if ($rows === []) {
            throw new \RuntimeException(
                'No Companies House filing schemas are installed. Refresh them from Artefacts before filing.'
            );
        }
        $byUrl = [];
        foreach ($rows as $row) {
            $byUrl[$this->canonicalUrl((string)$row['source_url'])] = $row;
        }
        $dependencies = \InterfaceDB::fetchAll(
            'SELECT parent.source_url AS parent_url, child.source_url AS child_url
             FROM companies_house_schema_dependencies dependency
             INNER JOIN companies_house_schema_files parent ON parent.id = dependency.parent_file_id
             INNER JOIN companies_house_schema_files child ON child.id = dependency.child_file_id'
        );
        $children = [];
        foreach ($dependencies as $dependency) {
            $children[$this->canonicalUrl((string)$dependency['parent_url'])][] =
                $this->canonicalUrl((string)$dependency['child_url']);
        }
        $queue = array_values(array_map(fn(string $url): string => $this->canonicalUrl($url), $roots));
        $selected = [];
        while ($queue !== []) {
            $url = array_shift($queue);
            if (isset($selected[$url])) {
                continue;
            }
            $file = $byUrl[$url] ?? null;
            if (!is_array($file)) {
                throw new \RuntimeException(
                    'The installed Companies House schema inventory does not cover the current filing profile. '
                    . 'Refresh it from Artefacts before filing.'
                );
            }
            $path = $this->cacheDirectory . '/' . ltrim(
                str_replace('\\', '/', (string)$file['relative_path']),
                '/'
            );
            $hash = is_file($path) ? hash_file('sha256', $path) : false;
            if (!is_string($hash)
                || !hash_equals(strtolower((string)$file['sha256']), strtolower($hash))) {
                throw new \RuntimeException(
                    'An installed Companies House schema is missing or has changed. '
                    . 'Refresh it from Artefacts before filing.'
                );
            }
            $xml = file_get_contents($path);
            if (!is_string($xml)) {
                throw new \RuntimeException(
                    'An installed Companies House schema could not be read. '
                    . 'Refresh it from Artefacts before filing.'
                );
            }
            $document = $this->loadXml($xml, $url);
            $validationProfile = trim((string)($file['validation_profile'] ?? ''));
            $validationRelativePath = ltrim(str_replace(
                '\\',
                '/',
                trim((string)($file['validation_relative_path'] ?? ''))
            ), '/');
            $validationHash = strtolower(trim((string)(
                $file['validation_sha256'] ?? ''
            )));
            if ($validationProfile !== CompaniesHouseSchemaCompatibilityService::PROFILE
                || $validationRelativePath === ''
                || preg_match('/^[a-f0-9]{64}$/D', $validationHash) !== 1
                || trim((string)($file['validation_verified_at'] ?? '')) === '') {
                throw new \RuntimeException(
                    'The installed Companies House schema validation assets are incomplete. '
                    . 'Refresh them from Artefacts before filing.'
                );
            }
            $validationPath = $this->validationDirectory . '/' . $validationRelativePath;
            $actualValidationHash = is_file($validationPath)
                ? hash_file('sha256', $validationPath)
                : false;
            if (!is_string($actualValidationHash)
                || !hash_equals($validationHash, strtolower($actualValidationHash))) {
                throw new \RuntimeException(
                    'An installed Companies House schema validation asset is missing or has changed. '
                    . 'Refresh it from Artefacts before filing.'
                );
            }
            $xpath = new \DOMXPath($document);
            $xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
            $recordedChildren = array_fill_keys($children[$url] ?? [], true);
            foreach (
                $xpath->query(
                    '/xs:schema/xs:include | /xs:schema/xs:import | /xs:schema/xs:redefine'
                ) ?: [] as $node
            ) {
                if (!$node instanceof \DOMElement) {
                    continue;
                }
                $location = trim($node->getAttribute('schemaLocation'));
                if ($location === '') {
                    continue;
                }
                $childUrl = $this->resolveUrl($url, $location);
                if (!isset($recordedChildren[$childUrl])) {
                    throw new \RuntimeException(
                        'The installed Companies House schema dependency inventory is incomplete. '
                        . 'Refresh it from Artefacts before filing.'
                    );
                }
                $queue[] = $childUrl;
            }
            $selected[$url] = $file;
            foreach ($children[$url] ?? [] as $childUrl) {
                $queue[] = $childUrl;
            }
        }
        foreach ($roots as $role => $url) {
            if ($role !== 'envelope'
                && strtolower((string)($selected[$this->canonicalUrl($url)]['catalogue_status'] ?? '')) !== 'live') {
                throw new \RuntimeException(
                    'Companies House does not mark a pinned filing schema as Live. '
                    . 'Refresh Artefacts or update the filing profile.'
                );
            }
        }
        $files = array_map([$this, 'evidenceFile'], array_values($selected));
        usort($files, static fn(array $a, array $b): int => strcmp($a['relative_path'], $b['relative_path']));
        return [
            'success' => true,
            'changed' => false,
            'root_path' => $this->cacheDirectory,
            'validation_root_path' => $this->validationDirectory,
            'validation_profile' => CompaniesHouseSchemaCompatibilityService::PROFILE,
            'files' => $files,
            'checked_at' => max(array_column($selected, 'checked_at')),
            'verified_at' => max(array_column($selected, 'verified_at')),
            'validation_verified_at' => max(array_column(
                $selected,
                'validation_verified_at'
            )),
        ];
    }

    public function refreshInstalledSchemas(mixed $progress = null): array
    {
        $this->progress($progress, 'Checking the Companies House XML schema catalogue.', 5);
        $this->ensureDirectory($this->cacheDirectory);
        $lock = fopen($this->cacheDirectory . '/.refresh.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('The Companies House schema refresh lock could not be acquired.');
        }
        try {
            $this->migrateLegacyAssets();
            return $this->refreshLocked($progress);
        } finally {
            $this->removeStagingDirectoryIfEmpty();
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function refreshLocked(mixed $progress): array
    {
        $statusResponse = $this->fetch(self::SOURCE_URL);
        $catalogue = $this->parseCatalogue($statusResponse['body']);
        foreach (self::ROOTS as $key => $url) {
            if ($key === 'envelope') {
                continue; // The envelope schema is not consistently listed on SchemaStatus.
            }
            $entry = $catalogue[$this->canonicalUrl($url)] ?? null;
            if (!is_array($entry) || ($entry['lifecycle_status'] ?? '') !== 'live') {
                throw new \RuntimeException('Companies House marks the pinned ' . basename($url) . ' schema as unavailable for LIVE use. A software update is required.');
            }
        }

        $staging = $this->stagingDirectory . '/' . bin2hex(random_bytes(12));
        $this->ensureDirectory($staging);
        try {
            [$files, $edges] = $this->downloadClosure($staging, $catalogue, $progress);
            $this->progress(
                $progress,
                'Preparing the Companies House libxml validation assets.',
                66
            );
            $validationStaging = $staging . '/.validation';
            $files = $this->compatibilityService->prepareAndCompile(
                $staging,
                $validationStaging,
                $files,
                self::ROOTS
            );
            $changed = $this->inventoryChanged($files, $edges);
            $this->publishFiles($staging, $files);
            $this->publishValidationFiles($validationStaging, $files);
            $this->removeTree($staging);
            $this->persistCatalogue($catalogue);
            $this->persistInventory($files, $edges);
            $installed = $this->installedSchemas();
            $installed['changed'] = $changed;
            $this->progress(
                $progress,
                $changed
                    ? 'Companies House XML schemas and validation assets verified and installed.'
                    : 'Companies House XML schemas and validation assets are current.',
                70
            );
            return $installed;
        } catch (\Throwable $exception) {
            if (is_dir($staging)) {
                $this->removeTree($staging);
            }
            throw $exception;
        }
    }

    private function evidenceFile(array $file): array
    {
        return [
            'source_url' => $this->canonicalUrl((string)$file['source_url']),
            'relative_path' => (string)$file['relative_path'],
            'schema_name' => (string)$file['schema_name'],
            'file_role' => (string)$file['file_role'],
            'catalogue_status' => $file['catalogue_status'] ?? null,
            'sha256' => strtolower((string)$file['sha256']),
            'validation_profile' => (string)($file['validation_profile'] ?? ''),
            'validation_relative_path' => (string)(
                $file['validation_relative_path'] ?? ''
            ),
            'validation_sha256' => strtolower((string)(
                $file['validation_sha256'] ?? ''
            )),
            'checked_at' => (string)($file['checked_at'] ?? ''),
            'verified_at' => (string)($file['verified_at'] ?? ''),
            'validation_verified_at' => (string)(
                $file['validation_verified_at'] ?? ''
            ),
        ];
    }

    /** @param array<string,array<string,mixed>> $files */
    private function publishFiles(string $staging, array $files): void
    {
        foreach ($files as $file) {
            $relativePath = ltrim(str_replace('\\', '/', (string)$file['relative_path']), '/');
            $source = $staging . '/' . $relativePath;
            $this->publishFile($source, $relativePath, strtolower((string)$file['sha256']));
        }
    }

    /** @param array<string,array<string,mixed>> $files */
    private function publishValidationFiles(string $staging, array $files): void
    {
        foreach ($files as $file) {
            $relativePath = ltrim(str_replace(
                '\\',
                '/',
                (string)$file['validation_relative_path']
            ), '/');
            $source = $staging . '/' . $relativePath;
            $this->publishFileToRoot(
                $this->validationDirectory,
                $source,
                $relativePath,
                strtolower((string)$file['validation_sha256'])
            );
        }
    }

    private function publishFile(string $source, string $relativePath, string $expectedHash): void
    {
        $this->publishFileToRoot(
            $this->cacheDirectory,
            $source,
            $relativePath,
            $expectedHash
        );
    }

    private function publishFileToRoot(
        string $root,
        string $source,
        string $relativePath,
        string $expectedHash
    ): void {
        $target = rtrim($root, '/\\') . '/'
            . ltrim(str_replace('\\', '/', $relativePath), '/');
        if (is_file($target)) {
            $actual = hash_file('sha256', $target);
            if (!is_string($actual) || !hash_equals($expectedHash, strtolower($actual))) {
                throw new \RuntimeException(
                    'Companies House changed an existing schema pathname: ' . $relativePath
                    . '. Review and update the filing profile before replacing it.'
                );
            }
            return;
        }
        if (!is_file($source)) {
            throw new \RuntimeException('A staged Companies House schema is missing: ' . $relativePath . '.');
        }
        $this->ensureDirectory(dirname($target));
        $temporary = $target . '.incoming-' . bin2hex(random_bytes(8));
        try {
            if (!copy($source, $temporary)) {
                throw new \RuntimeException('A verified Companies House schema could not be published.');
            }
            $actual = hash_file('sha256', $temporary);
            if (!is_string($actual) || !hash_equals($expectedHash, strtolower($actual))) {
                throw new \RuntimeException('A Companies House schema changed while being published.');
            }
            if (!@rename($temporary, $target)) {
                if (is_file($target)
                    && is_string($targetHash = hash_file('sha256', $target))
                    && hash_equals($expectedHash, strtolower($targetHash))) {
                    return;
                }
                throw new \RuntimeException('A verified Companies House schema could not be activated atomically.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function migrateLegacyAssets(): void
    {
        $legacySnapshots = dirname($this->cacheDirectory) . '/schema/snapshots';
        if (!is_dir($legacySnapshots)) {
            return;
        }
        $inventory = \InterfaceDB::fetchAll(
            'SELECT relative_path, sha256 FROM companies_house_schema_files ORDER BY relative_path'
        );
        $byPath = [];
        foreach ($inventory as $file) {
            $byPath[str_replace('\\', '/', (string)$file['relative_path'])] =
                strtolower((string)$file['sha256']);
        }
        foreach (new \DirectoryIterator($legacySnapshots) as $directory) {
            if ($directory->isDot() || !$directory->isDir()
                || preg_match('/^[a-f0-9]{64}$/i', $directory->getFilename()) !== 1) {
                continue;
            }
            $legacyRoot = $directory->getPathname();
            $complete = true;
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($legacyRoot, \FilesystemIterator::SKIP_DOTS)
            ) as $legacyFile) {
                if (!$legacyFile instanceof \SplFileInfo || !$legacyFile->isFile()) {
                    continue;
                }
                $relativePath = str_replace(
                    '\\',
                    '/',
                    substr($legacyFile->getPathname(), strlen($legacyRoot) + 1)
                );
                $expectedHash = $byPath[$relativePath] ?? '';
                if ($expectedHash === '') {
                    $complete = false;
                    continue;
                }
                $source = $legacyFile->getPathname();
                $actualHash = is_file($source) ? hash_file('sha256', $source) : false;
                if (!is_string($actualHash) || !hash_equals($expectedHash, strtolower($actualHash))) {
                    throw new \RuntimeException(
                        'A legacy Companies House schema file is missing or has changed: '
                        . $relativePath . '.'
                    );
                }
                $this->publishFile($source, $relativePath, $expectedHash);
            }
            if ($complete) {
                $this->removeTree($legacyRoot);
            }
        }
    }

    /** @return array{0:array<string,array<string,mixed>>,1:list<array<string,string>>} */
    private function downloadClosure(string $staging, array $catalogue, mixed $progress): array
    {
        $queue = [];
        foreach (self::ROOTS as $key => $url) {
            $queue[] = [$this->canonicalUrl($url), $key === 'envelope' ? 'envelope' : 'profile_root'];
        }
        $files = [];
        $edges = [];
        $total = 0;
        while ($queue !== []) {
            [$url, $role] = array_shift($queue);
            if (isset($files[$url])) {
                if ($role !== 'dependency') {
                    $files[$url]['file_role'] = $role;
                }
                continue;
            }
            if (count($files) >= self::MAX_FILES) {
                throw new \RuntimeException('Companies House schema dependency limit exceeded.');
            }
            $response = $this->fetch($url);
            $body = $response['body'];
            $size = strlen($body);
            $total += $size;
            if ($size === 0 || $size > self::MAX_FILE_BYTES || $total > self::MAX_TOTAL_BYTES) {
                throw new \RuntimeException('A Companies House schema exceeded the configured download limit.');
            }
            $document = $this->loadXml($body, $url);
            $relativePath = $this->relativePath($url);
            $target = $staging . '/' . $relativePath;
            $this->ensureDirectory(dirname($target));
            if (file_put_contents($target, $body, LOCK_EX) !== $size) {
                throw new \RuntimeException('A Companies House schema could not be written to staging.');
            }
            $root = $document->documentElement;
            $canonical = $this->canonicalUrl($url);
            $files[$canonical] = [
                'source_url' => $canonical,
                'relative_path' => $relativePath,
                'schema_name' => basename((string)parse_url($url, PHP_URL_PATH)),
                'file_role' => $role,
                'catalogue_status' => $catalogue[$canonical]['lifecycle_status'] ?? null,
                'target_namespace' => $root?->getAttribute('targetNamespace') ?: null,
                'file_size' => $size,
                'sha256' => hash('sha256', $body),
                'etag' => $response['headers']['etag'] ?? null,
                'last_modified' => $response['headers']['last-modified'] ?? null,
            ];
            $xpath = new \DOMXPath($document);
            $xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
            foreach ($xpath->query('/xs:schema/xs:include | /xs:schema/xs:import | /xs:schema/xs:redefine') ?: [] as $node) {
                if (!$node instanceof \DOMElement) { continue; }
                $location = trim($node->getAttribute('schemaLocation'));
                if ($location === '') { continue; }
                $child = $this->resolveUrl($url, $location);
                $edges[] = ['parent_url' => $canonical, 'child_url' => $child, 'relation_type' => $node->localName, 'declared_namespace' => $node->getAttribute('namespace'), 'schema_location' => $location];
                $queue[] = [$child, 'dependency'];
            }
            $this->progress($progress, 'Downloaded ' . count($files) . ' Companies House schema file(s).', min(65, 10 + count($files)));
        }
        ksort($files);
        usort($edges, static fn(array $a, array $b): int => strcmp(implode('|', $a), implode('|', $b)));
        return [$files, $edges];
    }

    private function inventoryChanged(array $files, array $edges): bool
    {
        $storedFiles = \InterfaceDB::fetchAll(
            'SELECT source_url, relative_path, file_role, catalogue_status, sha256,
                    validation_profile, validation_relative_path, validation_sha256
             FROM companies_house_schema_files ORDER BY source_url'
        );
        $expectedFiles = array_map(
            static fn(array $file): array => [
                'source_url' => (string)$file['source_url'],
                'relative_path' => (string)$file['relative_path'],
                'file_role' => (string)$file['file_role'],
                'catalogue_status' => $file['catalogue_status'],
                'sha256' => (string)$file['sha256'],
                'validation_profile' => (string)$file['validation_profile'],
                'validation_relative_path' => (string)$file['validation_relative_path'],
                'validation_sha256' => (string)$file['validation_sha256'],
            ],
            array_values($files)
        );
        $storedByUrl = [];
        foreach ($storedFiles as $file) {
            $storedByUrl[(string)$file['source_url']] = $file;
        }
        foreach ($expectedFiles as $file) {
            $stored = $storedByUrl[$file['source_url']] ?? null;
            if (!is_array($stored)
                || (string)$stored['relative_path'] !== $file['relative_path']
                || (string)$stored['file_role'] !== $file['file_role']
                || (string)($stored['catalogue_status'] ?? '') !== (string)($file['catalogue_status'] ?? '')
                || !hash_equals(strtolower((string)$stored['sha256']), strtolower($file['sha256']))
                || (string)($stored['validation_profile'] ?? '') !== $file['validation_profile']
                || (string)($stored['validation_relative_path'] ?? '') !== $file['validation_relative_path']
                || !hash_equals(
                    strtolower((string)($stored['validation_sha256'] ?? '')),
                    strtolower($file['validation_sha256'])
                )) {
                return true;
            }
        }
        $storedEdges = \InterfaceDB::fetchAll(
            'SELECT parent.source_url AS parent_url, child.source_url AS child_url,
                    dependency.relation_type, dependency.declared_namespace,
                    dependency.schema_location
             FROM companies_house_schema_dependencies dependency
             INNER JOIN companies_house_schema_files parent ON parent.id = dependency.parent_file_id
             INNER JOIN companies_house_schema_files child ON child.id = dependency.child_file_id
             ORDER BY parent.source_url, child.source_url, dependency.relation_type'
        );
        $normalise = static function (array $edge): string {
            return implode('|', [
                (string)$edge['parent_url'],
                (string)$edge['child_url'],
                (string)$edge['relation_type'],
                (string)($edge['declared_namespace'] ?? ''),
                (string)$edge['schema_location'],
            ]);
        };
        $storedEdgeKeys = array_map($normalise, $storedEdges);
        $expectedEdgeKeys = array_map($normalise, $edges);
        sort($storedEdgeKeys);
        sort($expectedEdgeKeys);
        return $storedEdgeKeys !== $expectedEdgeKeys;
    }

    private function persistInventory(array $files, array $edges): void
    {
        $now = gmdate('Y-m-d H:i:s');
        \InterfaceDB::transaction(function () use ($files, $edges, $now): void {
            $ids = [];
            foreach ($files as $url => $file) {
                $existing = \InterfaceDB::fetchOne(
                    'SELECT id, source_url, relative_path, sha256
                     FROM companies_house_schema_files
                     WHERE source_url = :url OR relative_path = :path
                     LIMIT 1',
                    ['url' => $url, 'path' => $file['relative_path']]
                );
                if (is_array($existing)
                    && ((string)$existing['source_url'] !== $url
                        || !hash_equals(strtolower((string)$existing['sha256']), strtolower((string)$file['sha256']))
                        || (string)$existing['relative_path'] !== (string)$file['relative_path'])) {
                    throw new \RuntimeException(
                        'Companies House changed an existing schema pathname. Review the filing profile.'
                    );
                }
                $params = [
                    'url'=>$url, 'path'=>$file['relative_path'], 'name'=>$file['schema_name'],
                    'role'=>$file['file_role'], 'status'=>$file['catalogue_status'],
                    'namespace'=>$file['target_namespace'], 'size'=>$file['file_size'],
                    'sha'=>$file['sha256'], 'etag'=>$file['etag'],
                    'modified'=>$file['last_modified'], 'checked'=>$now, 'verified'=>$now,
                    'validation_profile'=>$file['validation_profile'],
                    'validation_path'=>$file['validation_relative_path'],
                    'validation_sha'=>$file['validation_sha256'],
                    'validation_verified'=>$file['validation_verified_at'],
                ];
                if (is_array($existing)) {
                    $params['id'] = (int)$existing['id'];
                    \InterfaceDB::prepareExecute(
                        'UPDATE companies_house_schema_files
                         SET relative_path=:path, schema_name=:name, file_role=:role,
                             catalogue_status=:status, target_namespace=:namespace,
                             file_size=:size, sha256=:sha, etag=:etag,
                             last_modified=:modified, checked_at=:checked, verified_at=:verified,
                             validation_profile=:validation_profile,
                             validation_relative_path=:validation_path,
                             validation_sha256=:validation_sha,
                             validation_verified_at=:validation_verified
                         WHERE id=:id',
                        $params
                    );
                    $ids[$url] = (int)$existing['id'];
                } else {
                    \InterfaceDB::prepareExecute(
                        'INSERT INTO companies_house_schema_files
                         (source_url,relative_path,schema_name,file_role,catalogue_status,
                          target_namespace,file_size,sha256,etag,last_modified,checked_at,verified_at,
                          validation_profile,validation_relative_path,validation_sha256,
                          validation_verified_at)
                         VALUES (:url,:path,:name,:role,:status,:namespace,:size,:sha,:etag,
                                 :modified,:checked,:verified,:validation_profile,:validation_path,
                                 :validation_sha,:validation_verified)',
                        $params
                    );
                    $ids[$url] = $this->lastInsertId();
                }
            }
            \InterfaceDB::execute('DELETE FROM companies_house_schema_dependencies');
            foreach ($edges as $edge) {
                \InterfaceDB::prepareExecute(
                    'INSERT INTO companies_house_schema_dependencies
                     (parent_file_id,child_file_id,relation_type,declared_namespace,schema_location)
                     VALUES (:parent,:child,:relation,:namespace,:location)',
                    ['parent'=>$ids[$edge['parent_url']], 'child'=>$ids[$edge['child_url']],
                     'relation'=>$edge['relation_type'],
                     'namespace'=>$edge['declared_namespace'] ?: null,
                     'location'=>$edge['schema_location']]
                );
            }
        });
    }

    private function parseCatalogue(string $html): array
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $ok = $document->loadHTML($html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        if (!$ok) { throw new \RuntimeException('Companies House SchemaStatus could not be parsed.'); }
        $result = [];
        $xpath = new \DOMXPath($document);
        foreach ($xpath->query('//tr[.//a[@href]]') ?: [] as $row) {
            if (!$row instanceof \DOMElement) { continue; }
            $link = null;
            foreach ($xpath->query('.//a[@href]', $row) ?: [] as $candidate) {
                if ($candidate instanceof \DOMElement && str_contains(strtolower($candidate->getAttribute('href')), '.xsd')) { $link = $candidate; break; }
            }
            if (!$link instanceof \DOMElement) { continue; }
            $url = $this->resolveUrl(self::SOURCE_URL, $link->getAttribute('href'));
            $cellTexts = [];
            foreach ($xpath->query('./th | ./td', $row) ?: [] as $cell) {
                $cellTexts[] = strtolower(preg_replace('/\s+/', ' ', trim($cell->textContent)) ?? '');
            }
            $text = implode(' ', $cellTexts);
            $status = null;
            foreach (['retired','deprecated','live','released'] as $candidate) {
                if (in_array($candidate, $cellTexts, true) || preg_match('/\b' . $candidate . '\b/', $text)) { $status = $candidate; break; }
            }
            if ($status === null) { continue; }
            preg_match_all('/\b([0-3]?\d)[\/\-]([01]?\d)[\/\-](20\d{2})\b/', $text, $dates);
            $normalisedDates = [];
            foreach ($dates[0] ?? [] as $date) { $normalisedDates[] = $this->normaliseDate($date); }
            $canonical = $this->canonicalUrl($url);
            $result[$canonical] = ['schema_name'=>basename((string)parse_url($url, PHP_URL_PATH)),'source_url'=>$canonical,'lifecycle_status'=>$status,'release_date'=>$normalisedDates[0] ?? null,'live_date'=>$normalisedDates[1] ?? null,'deprecated_date'=>$normalisedDates[2] ?? null,'retirement_date'=>$normalisedDates[3] ?? null];
        }
        if ($result === []) { throw new \RuntimeException('Companies House SchemaStatus contained no recognised schema lifecycle rows.'); }
        ksort($result);
        return $result;
    }

    private function persistCatalogue(array $catalogue): void
    {
        $now = gmdate('Y-m-d H:i:s');
        foreach ($catalogue as $entry) {
            $params = ['name'=>$entry['schema_name'],'url'=>$entry['source_url'],'status'=>$entry['lifecycle_status'],'released'=>$entry['release_date'],'live'=>$entry['live_date'],'deprecated'=>$entry['deprecated_date'],'retired'=>$entry['retirement_date'],'seen'=>$now];
            $existing = \InterfaceDB::fetchOne('SELECT id FROM companies_house_schema_catalogue WHERE source_url = :url', ['url'=>$entry['source_url']]);
            if (is_array($existing)) {
                \InterfaceDB::prepareExecute('UPDATE companies_house_schema_catalogue SET schema_name=:name,lifecycle_status=:status,release_date=:released,live_date=:live,deprecated_date=:deprecated,retirement_date=:retired,last_seen_at=:seen WHERE source_url=:url', $params);
            } else {
                \InterfaceDB::prepareExecute('INSERT INTO companies_house_schema_catalogue (schema_name,source_url,lifecycle_status,release_date,live_date,deprecated_date,retirement_date,last_seen_at) VALUES (:name,:url,:status,:released,:live,:deprecated,:retired,:seen)', $params);
            }
        }
    }

    private function fetch(string $url): array
    {
        $url = $this->canonicalUrl($url);
        $response = $this->fetcher instanceof \Closure ? ($this->fetcher)($url) : $this->curlFetch($url);
        if (!is_array($response) || (int)($response['status_code'] ?? 0) !== 200 || !is_string($response['body'] ?? null)) {
            throw new \RuntimeException('Companies House schema download failed for ' . basename((string)parse_url($url, PHP_URL_PATH)) . '.');
        }
        $final = $this->canonicalUrl((string)($response['final_url'] ?? $url));
        if ($final !== $url) { throw new \RuntimeException('Companies House schema downloads may not redirect.'); }
        $headers = [];
        foreach ((array)($response['headers'] ?? []) as $name => $value) { $headers[strtolower((string)$name)] = trim((string)$value); }
        return ['body'=>$response['body'],'headers'=>$headers];
    }

    private function curlFetch(string $url): array
    {
        $headers = [];
        $handle = curl_init($url);
        if ($handle === false) { throw new \RuntimeException('Companies House schema download could not be initialised.'); }
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_USERAGENT=>'eel_accounts Companies House schema verifier',CURLOPT_HEADERFUNCTION=>static function ($curl, string $line) use (&$headers): int { $parts=explode(':',$line,2); if(count($parts)===2){$headers[trim($parts[0])]=trim($parts[1]);} return strlen($line); }]);
        $body = curl_exec($handle); $status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE); $final=(string)curl_getinfo($handle,CURLINFO_EFFECTIVE_URL); $error=curl_error($handle); curl_close($handle);
        if (!is_string($body)) { throw new \RuntimeException('Companies House schema download failed: ' . $error); }
        return ['status_code'=>$status,'headers'=>$headers,'body'=>$body,'final_url'=>$final];
    }

    private function loadXml(string $xml, string $url): \DOMDocument
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) { throw new \RuntimeException('Unsafe XML declaration in Companies House schema.'); }
        $document = new \DOMDocument(); $previous=libxml_use_internal_errors(true);
        try { $ok=$document->loadXML($xml, LIBXML_NONET); $errors=libxml_get_errors(); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        if (!$ok || $document->documentElement?->namespaceURI !== 'http://www.w3.org/2001/XMLSchema') { throw new \RuntimeException('Invalid Companies House XSD: ' . basename((string)parse_url($url, PHP_URL_PATH)) . '.'); }
        return $document;
    }

    private function canonicalUrl(string $url): string
    {
        $parts=parse_url(trim($url));
        if (!is_array($parts) || strtolower((string)($parts['host'] ?? '')) !== 'xmlgw.companieshouse.gov.uk' || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http','https'], true)) { throw new \RuntimeException('Companies House schema URL is outside the approved host.'); }
        $path=(string)($parts['path'] ?? ''); if ($path === '' || str_contains($path, '..')) { throw new \RuntimeException('Companies House schema URL has an unsafe path.'); }
        return 'https://xmlgw.companieshouse.gov.uk' . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    private function resolveUrl(string $base, string $relative): string
    {
        if (preg_match('#^https?://#i', $relative)) { return $this->canonicalUrl($relative); }
        $basePath=(string)parse_url($base,PHP_URL_PATH); $path=str_starts_with($relative,'/') ? $relative : dirname($basePath) . '/' . $relative;
        $parts=[]; foreach(explode('/',$path) as $part){if($part===''||$part==='.')continue;if($part==='..'){array_pop($parts);continue;}$parts[]=$part;}
        return $this->canonicalUrl('https://xmlgw.companieshouse.gov.uk/' . implode('/',$parts));
    }

    private function relativePath(string $url): string { return ltrim((string)parse_url($url, PHP_URL_PATH), '/'); }
    private function configuredStagingDirectory(): string
    {
        return AccountingConfigurationStore::temporaryDirectory()
            . DIRECTORY_SEPARATOR . 'companies_house_schema';
    }
    private function normaliseDate(string $date): ?string { $value=\DateTimeImmutable::createFromFormat('!j/n/Y',str_replace('-','/',$date)); return $value?->format('Y-m-d') ?: null; }
    private function lastInsertId(): int { return (int)(\InterfaceDB::fetchColumn(strtolower(\InterfaceDB::driverName())==='sqlite'?'SELECT last_insert_rowid()':'SELECT LAST_INSERT_ID()') ?: 0); }
    private function progress(mixed $progress,string $message,int $percent): void { if($progress instanceof \ActionProgressFramework){$progress->report($message,$percent);return;} if(is_callable($progress)){$progress($message,$percent);} }
    private function ensureDirectory(string $path): void { if(!is_dir($path)&&!mkdir($path,0770,true)&&!is_dir($path)){throw new \RuntimeException('Companies House schema cache directory could not be created.');} }
    private function removeStagingDirectoryIfEmpty(): void { if(is_dir($this->stagingDirectory)){@rmdir($this->stagingDirectory);} }
    private function removeTree(string $path): void { if(!is_dir($path)){return;} foreach(new \FilesystemIterator($path) as $item){$item->isDir()&&!$item->isLink()?$this->removeTree($item->getPathname()):unlink($item->getPathname());} rmdir($path); }
}
