<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class HmrcCtComputationCatalogueService
{
    public const SOURCE_URL = 'https://www.gov.uk/government/publications/corporation-tax-technical-specifications-xbrl-and-ixbrl';
    public const CT2024_DOWNLOAD_URL = 'https://www.hmrc.gov.uk/softwaredevelopers/ct/CT2024-v1.0.0.zip';

    public function fetchPackages(): array
    {
        if (!\InterfaceDB::tableExists('hmrc_ct_computation_packages')) {
            return [];
        }
        return \InterfaceDB::fetchAll(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM hmrc_ct_computation_files f WHERE f.package_id = p.id) AS file_count,
                    (SELECT COUNT(*) FROM hmrc_ct_computation_concepts c WHERE c.package_id = p.id) AS concept_count,
                    (SELECT COUNT(*) FROM hmrc_ct_computation_concepts c WHERE c.package_id = p.id AND c.is_dimension = 1) AS dimension_count,
                    (SELECT COUNT(*) FROM ct_filing_mapping_profiles m WHERE m.computation_package_id = p.id AND m.status = :active AND m.compatibility_status = :compatible) AS compatible_profile_count
             FROM hmrc_ct_computation_packages p ORDER BY p.applicable_from DESC, p.id DESC',
            ['active' => 'active', 'compatible' => 'compatible']
        );
    }

    public function resolveForPeriod(string $periodStart, string $periodEnd): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM hmrc_ct_computation_packages
             WHERE package_state = :verified AND applicable_from <= :period_start
               AND (applicable_to IS NULL OR applicable_to >= :period_end)
               AND COALESCE(NULLIF(combined_dpl_entry_point_path, \'\'), NULLIF(entry_point_path, \'\')) IS NOT NULL
             ORDER BY applicable_from DESC, id DESC LIMIT 1',
            ['verified' => 'verified', 'period_start' => $periodStart, 'period_end' => $periodEnd]
        );
        return is_array($row) ? $row : null;
    }

    /** Return the verified catalogue hash, or null when any package file has changed. */
    public function verifiedPackageHash(array $package): ?string
    {
        $packageId = (int)($package['id'] ?? 0);
        $expected = strtolower(trim((string)($package['sha256'] ?? '')));
        if ($packageId <= 0 || preg_match('/^[a-f0-9]{64}$/', $expected) !== 1) {
            return null;
        }
        $files = \InterfaceDB::fetchAll(
            'SELECT archive_path, extracted_path, file_size, sha256
             FROM hmrc_ct_computation_files WHERE package_id = :package_id ORDER BY archive_path',
            ['package_id' => $packageId]
        );
        if ($files === []) {
            return null;
        }
        foreach ($files as $file) {
            $path = (string)($file['extracted_path'] ?? '');
            $recorded = strtolower((string)($file['sha256'] ?? ''));
            $actual = $path !== '' && is_file($path) ? hash_file('sha256', $path) : false;
            if (!is_string($actual) || !hash_equals($recorded, strtolower($actual))) {
                return null;
            }
        }
        $actual = $this->inventoryHash($files);
        return hash_equals($expected, $actual) ? $actual : null;
    }

    public function savePackage(array $input): int
    {
        $id = max(0, (int)($input['id'] ?? 0));
        $taxonomyVersion = trim((string)($input['taxonomy_version'] ?? ''));
        $artifactVersion = trim((string)($input['artifact_version'] ?? ''));
        $from = trim((string)($input['applicable_from'] ?? ''));
        $to = trim((string)($input['applicable_to'] ?? ''));
        $localPath = trim((string)($input['local_path'] ?? ''));
        $entryPoint = trim((string)($input['entry_point_path'] ?? ''));
        $combined = trim((string)($input['combined_dpl_entry_point_path'] ?? ''));
        if ($taxonomyVersion === '' || $artifactVersion === '' || !$this->validDate($from) || ($to !== '' && !$this->validDate($to))) {
            throw new \InvalidArgumentException('Taxonomy version, artifact version and valid applicability dates are required.');
        }
        if ($to !== '' && $to < $from) {
            throw new \InvalidArgumentException('The applicability end cannot precede the start.');
        }
        if ($id <= 0) {
            \InterfaceDB::prepareExecute(
                'INSERT INTO hmrc_ct_computation_packages
                 (taxonomy_version, artifact_version, applicable_from, applicable_to, source_url, download_url, local_path,
                  entry_point_path, combined_dpl_entry_point_path, package_state, checked_at)
                 VALUES (:taxonomy_version, :artifact_version, :applicable_from, :applicable_to, :source_url, :download_url,
                         :local_path, :entry_point_path, :combined_path, :state, CURRENT_TIMESTAMP)',
                ['taxonomy_version' => $taxonomyVersion, 'artifact_version' => $artifactVersion, 'applicable_from' => $from, 'applicable_to' => $to !== '' ? $to : null, 'source_url' => ($input['source_url'] ?? null) ?: self::SOURCE_URL, 'download_url' => ($input['download_url'] ?? null) ?: null, 'local_path' => $localPath !== '' ? $localPath : null, 'entry_point_path' => $entryPoint !== '' ? $entryPoint : null, 'combined_path' => $combined !== '' ? $combined : null, 'state' => 'not_downloaded']
            );
            return $this->lastInsertId();
        }
        $conceptCount = (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM hmrc_ct_computation_concepts WHERE package_id = :id', ['id' => $id]);
        $usableEntryPoint = $combined !== '' ? $combined : $entryPoint;
        $verified = $conceptCount > 0 && $usableEntryPoint !== '' && is_file($usableEntryPoint)
            && ($entryPoint === '' || is_file($entryPoint));
        \InterfaceDB::prepareExecute(
            'UPDATE hmrc_ct_computation_packages SET taxonomy_version = :taxonomy_version, artifact_version = :artifact_version,
             applicable_from = :applicable_from, applicable_to = :applicable_to, source_url = :source_url, download_url = :download_url,
             local_path = :local_path, entry_point_path = :entry_point_path, combined_dpl_entry_point_path = :combined_path,
             package_state = :state, verification_error = :error, checked_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['taxonomy_version' => $taxonomyVersion, 'artifact_version' => $artifactVersion, 'applicable_from' => $from, 'applicable_to' => $to !== '' ? $to : null, 'source_url' => ($input['source_url'] ?? null) ?: self::SOURCE_URL, 'download_url' => ($input['download_url'] ?? null) ?: null, 'local_path' => $localPath !== '' ? $localPath : null, 'entry_point_path' => $entryPoint !== '' ? $entryPoint : null, 'combined_path' => $combined !== '' ? $combined : null, 'state' => $verified ? 'verified' : ($conceptCount > 0 ? 'failed' : 'not_downloaded'), 'error' => $verified ? null : 'A catalogued package and an existing CT computation entry point are required.', 'id' => $id]
        );
        return $id;
    }

    /** Catalogue a locally expanded, administrator-supplied taxonomy package. */
    public function catalogueDirectory(int $packageId, string $directory, string $actor = 'hmrc-computation-catalogue'): array
    {
        $root = realpath($directory);
        if ($packageId <= 0 || $root === false || !is_dir($root)) {
            throw new \InvalidArgumentException('Select an existing computation-taxonomy directory.');
        }
        $manifest = $this->inspectDirectory($root);
        $package = \InterfaceDB::fetchOne(
            'SELECT * FROM hmrc_ct_computation_packages WHERE id = :id LIMIT 1',
            ['id' => $packageId]
        );
        if (!is_array($package)) {
            throw new \RuntimeException('The computation-taxonomy package was not found.');
        }
        if (!empty($manifest['has_manifest'])) {
            $storedTaxonomy = trim((string)($package['taxonomy_version'] ?? ''));
            $storedArtifact = strtoupper(trim((string)($package['artifact_version'] ?? '')));
            if ($storedTaxonomy !== '' && $storedTaxonomy !== (string)$manifest['taxonomy_version']) {
                throw new \RuntimeException('The package manifest taxonomy year does not match the selected catalogue row.');
            }
            if ($storedArtifact !== '' && $storedArtifact !== 'UNCONFIGURED'
                && $storedArtifact !== strtoupper((string)$manifest['artifact_version'])) {
                throw new \RuntimeException('The package manifest artifact version does not match the selected catalogue row.');
            }
        }
        $entryPointPath = trim((string)($manifest['entry_point_path'] ?? ''));
        if ($entryPointPath === '') {
            $entryPointPath = trim((string)($package['entry_point_path'] ?? ''));
        }
        $verificationEntryPoint = $entryPointPath !== ''
            ? $entryPointPath
            : trim((string)($package['combined_dpl_entry_point_path'] ?? ''));
        $files = [];
        $concepts = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
            $extension = strtolower($file->getExtension());
            $files[] = ['archive_path' => $relative, 'extracted_path' => $path, 'file_type' => in_array($extension, ['xsd', 'xml', 'json'], true) ? $extension : (in_array($extension, ['xbrl', 'linkbase'], true) ? 'linkbase' : 'other'), 'file_role' => $verificationEntryPoint !== '' && realpath($path) === realpath($verificationEntryPoint) ? 'entry_point' : null, 'file_size' => $file->getSize(), 'sha256' => hash_file('sha256', $path)];
            if ($extension === 'xsd') {
                $concepts = array_merge($concepts, $this->readConcepts($path));
            }
        }
        $deduplicated = [];
        foreach ($concepts as $concept) {
            $deduplicated[(string)$concept['namespace_uri'] . '|' . (string)$concept['local_name']] = $concept;
        }
        $concepts = array_values($deduplicated);
        if ($concepts === []) {
            throw new \RuntimeException('No XSD concepts were found in the supplied computation taxonomy.');
        }
        $verified = $verificationEntryPoint !== '' && is_file($verificationEntryPoint);
        \InterfaceDB::beginTransaction();
        try {
            \InterfaceDB::prepareExecute('DELETE FROM hmrc_ct_computation_files WHERE package_id = :id', ['id' => $packageId]);
            \InterfaceDB::prepareExecute('DELETE FROM hmrc_ct_computation_concepts WHERE package_id = :id', ['id' => $packageId]);
            foreach ($files as $file) {
                \InterfaceDB::prepareExecute(
                    'INSERT INTO hmrc_ct_computation_files (package_id, archive_path, extracted_path, file_type, file_role, file_size, sha256)
                     VALUES (:package_id, :archive_path, :extracted_path, :file_type, :file_role, :file_size, :sha256)',
                    ['package_id' => $packageId] + $file
                );
            }
            foreach ($concepts as $concept) {
                \InterfaceDB::prepareExecute(
                    'INSERT IGNORE INTO hmrc_ct_computation_concepts
                     (package_id, qname, namespace_uri, local_name, data_type, period_type, substitution_group, is_abstract, is_dimension, is_required)
                     VALUES (:package_id, :qname, :namespace_uri, :local_name, :data_type, :period_type, :substitution_group, :is_abstract, :is_dimension, 0)',
                    ['package_id' => $packageId] + $concept
                );
            }
            \InterfaceDB::prepareExecute(
                'UPDATE hmrc_ct_computation_packages SET local_path = :path, entry_point_path = :entry_point,
                 artifact_version = CASE WHEN artifact_version = :unconfigured AND :manifest_check <> \'\' THEN :manifest_value ELSE artifact_version END,
                 sha256 = :sha256, package_state = :state, verification_error = :error,
                 checked_at = CURRENT_TIMESTAMP WHERE id = :id',
                [
                    'path' => $root,
                    'entry_point' => $entryPointPath !== '' ? $entryPointPath : null,
                    'unconfigured' => 'unconfigured',
                    'manifest_check' => (string)($manifest['artifact_version'] ?? ''),
                    'manifest_value' => (string)($manifest['artifact_version'] ?? ''),
                    'sha256' => $this->inventoryHash($files),
                    'state' => $verified ? 'verified' : 'downloaded',
                    'error' => $verified ? null : 'The taxonomy package did not expose an existing entry point.',
                    'id' => $packageId,
                ]
            );
            \InterfaceDB::commit();
        } catch (\Throwable $exception) {
            \InterfaceDB::rollBack();
            throw $exception;
        }
        $profileId = null;
        if ($verified && \InterfaceDB::tableExists('ct_filing_mapping_profiles')) {
            try {
                $profileId = (new CtFilingMappingService())->prepareMappingsForPackage(
                    CtFilingMappingService::TARGET_COMPUTATION,
                    $packageId,
                    $actor
                );
            } catch (\Throwable $exception) {
                \InterfaceDB::prepareExecute(
                    'UPDATE hmrc_ct_computation_packages
                     SET package_state = :state, verification_error = :error, checked_at = CURRENT_TIMESTAMP
                     WHERE id = :id',
                    ['state' => 'failed', 'error' => $exception->getMessage(), 'id' => $packageId]
                );
                throw $exception;
            }
        }
        return [
            'file_count' => count($files),
            'concept_count' => count($concepts),
            'entry_point_path' => $entryPointPath !== '' ? $entryPointPath : null,
            'package_state' => $verified ? 'verified' : 'downloaded',
            'manifest' => $manifest,
            'mapping_profile_id' => $profileId,
        ];
    }

    /** Admit HMRC's published CT 2024 v1.0.0 package by manifest identity. */
    public function catalogueAccepted2024Directory(string $directory, string $actor = 'system'): array
    {
        return $this->catalogueAcceptedDirectory(
            $directory,
            '2024',
            '2026-03-31',
            self::CT2024_DOWNLOAD_URL,
            $actor
        );
    }

    /**
     * Admit the HMRC CT computational 2025 taxonomy by its manifest identity.
     * HMRC's publication currently lists the package without a public download
     * hyperlink, so the administrator supplies the expanded official package.
     */
    public function catalogueAccepted2025Directory(string $directory, string $actor = 'system'): array
    {
        return $this->catalogueAcceptedDirectory($directory, '2025', null, null, $actor);
    }

    private function catalogueAcceptedDirectory(
        string $directory,
        string $taxonomyVersion,
        ?string $applicableTo,
        ?string $downloadUrl,
        string $actor
    ): array {
        $manifest = $this->inspectDirectory($directory);
        if (empty($manifest['has_manifest'])
            || (string)($manifest['taxonomy_version'] ?? '') !== $taxonomyVersion
            || strtoupper((string)($manifest['artifact_version'] ?? '')) !== 'V1.0.0') {
            throw new \RuntimeException(
                'The supplied directory is not the HMRC CT ' . $taxonomyVersion . ' v1.0.0 taxonomy package.'
            );
        }
        $package = \InterfaceDB::fetchOne(
            'SELECT * FROM hmrc_ct_computation_packages
             WHERE taxonomy_version = :taxonomy
               AND artifact_version IN (:artifact, :placeholder)
             ORDER BY (artifact_version = :artifact_order) DESC, id DESC LIMIT 1',
            [
                'taxonomy' => $taxonomyVersion,
                'artifact' => 'V1.0.0',
                'placeholder' => 'unconfigured',
                'artifact_order' => 'V1.0.0',
            ]
        );
        if (!is_array($package)) {
            \InterfaceDB::prepareExecute(
                'INSERT INTO hmrc_ct_computation_packages
                 (taxonomy_version, artifact_version, applicable_from, applicable_to, source_url, download_url,
                  local_path, entry_point_path, package_state, checked_at)
                 VALUES (:taxonomy, :artifact, :applicable_from, :applicable_to, :source_url, :download_url,
                         :local_path, :entry_point, :state, CURRENT_TIMESTAMP)',
                [
                    'taxonomy' => $taxonomyVersion,
                    'artifact' => 'V1.0.0',
                    'applicable_from' => '2015-04-01',
                    'applicable_to' => $applicableTo,
                    'source_url' => self::SOURCE_URL,
                    'download_url' => $downloadUrl,
                    'local_path' => null,
                    'entry_point' => null,
                    'state' => 'not_downloaded',
                ]
            );
            $packageId = $this->lastInsertId();
        } else {
            $packageId = (int)$package['id'];
        }
        $result = $this->catalogueDirectory($packageId, $directory, $actor);
        if ((string)($result['package_state'] ?? '') !== 'verified') {
            throw new \RuntimeException(
                'The CT ' . $taxonomyVersion . ' taxonomy package did not produce a verified entry point.'
            );
        }
        $profileId = $result['mapping_profile_id'] ?? (new CtFilingMappingService())->prepareMappingsForPackage(
            CtFilingMappingService::TARGET_COMPUTATION,
            $packageId,
            $actor
        );
        return $result + ['package_id' => $packageId, 'mapping_profile_id' => $profileId];
    }

    /** @return array<string, mixed> */
    public function inspectDirectory(string $directory): array
    {
        $root = realpath($directory);
        if ($root === false || !is_dir($root)) {
            throw new \InvalidArgumentException('Select an existing computation-taxonomy directory.');
        }
        $manifestPath = $root . DIRECTORY_SEPARATOR . 'META-INF' . DIRECTORY_SEPARATOR . 'taxonomyPackage.xml';
        if (!is_file($manifestPath)) {
            return [
                'has_manifest' => false,
                'taxonomy_version' => null,
                'artifact_version' => null,
                'identifier' => null,
                'name' => null,
                'entry_point_href' => null,
                'entry_point_path' => null,
            ];
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (!$document->load($manifestPath, LIBXML_NONET)) {
                throw new \RuntimeException('The taxonomy package manifest is not valid XML.');
            }
            $xpath = new \DOMXPath($document);
            $text = static function (\DOMXPath $xpath, string $query): string {
                $node = $xpath->query($query)?->item(0);
                return $node instanceof \DOMNode ? trim($node->textContent) : '';
            };
            $identifier = $text($xpath, '/*[local-name()="taxonomyPackage"]/*[local-name()="identifier"]');
            $name = $text($xpath, '/*[local-name()="taxonomyPackage"]/*[local-name()="name"]');
            $version = $text($xpath, '/*[local-name()="taxonomyPackage"]/*[local-name()="version"]');
            $entryNode = $xpath->query('//*[local-name()="entryPointDocument"]/@href')?->item(0);
            $entryHref = $entryNode instanceof \DOMAttr ? trim($entryNode->value) : '';
            $taxonomyVersion = '';
            foreach ([$identifier, $entryHref, $name] as $candidate) {
                if (preg_match('/(?:^|[^0-9])(20[0-9]{2})(?:-[0-9]{2}-[0-9]{2}|[^0-9]|$)/', $candidate, $match) === 1) {
                    $taxonomyVersion = $match[1];
                    break;
                }
            }
            $entryPath = $this->resolveEntryPoint($root, $entryHref);
            return [
                'has_manifest' => true,
                'taxonomy_version' => $taxonomyVersion !== '' ? $taxonomyVersion : null,
                'artifact_version' => $version !== '' ? 'V' . ltrim($version, 'vV') : null,
                'identifier' => $identifier !== '' ? $identifier : null,
                'name' => $name !== '' ? $name : null,
                'entry_point_href' => $entryHref !== '' ? $entryHref : null,
                'entry_point_path' => $entryPath,
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function readConcepts(string $path): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        if (!$document->load($path, LIBXML_NONET)) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            return [];
        }
        $schema = $document->documentElement;
        $namespace = $schema?->getAttribute('targetNamespace') ?? '';
        $prefix = 'tax';
        if ($schema !== null) {
            foreach ($schema->attributes ?? [] as $attribute) {
                if ($attribute->prefix === 'xmlns' && $attribute->value === $namespace) {
                    $prefix = $attribute->localName;
                    break;
                }
            }
        }
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
        $rows = [];
        foreach ($xpath->query('/xs:schema/xs:element[@name]') ?: [] as $element) {
            if (!$element instanceof \DOMElement) {
                continue;
            }
            $localName = $element->getAttribute('name');
            $type = $element->getAttribute('type');
            $periodType = $element->getAttributeNS('http://www.xbrl.org/2003/instance', 'periodType');
            $substitution = $element->getAttribute('substitutionGroup');
            $rows[] = [
                'qname' => $prefix . ':' . $localName,
                'namespace_uri' => $namespace,
                'local_name' => $localName,
                'data_type' => $type !== '' ? $type : null,
                'period_type' => in_array($periodType, ['instant', 'duration'], true) ? $periodType : null,
                'substitution_group' => $substitution !== '' ? $substitution : null,
                'is_abstract' => strtolower($element->getAttribute('abstract')) === 'true' ? 1 : 0,
                'is_dimension' => str_contains(strtolower($substitution), 'dimension') ? 1 : 0,
            ];
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $rows;
    }

    private function resolveEntryPoint(string $root, string $href): ?string
    {
        $href = trim($href);
        if ($href === '') { return null; }
        $parts = parse_url($href);
        $candidates = [];
        if (is_array($parts)) {
            $host = trim((string)($parts['host'] ?? ''));
            $path = trim((string)($parts['path'] ?? ''), '/');
            if ($path !== '') {
                $candidates[] = $root . DIRECTORY_SEPARATOR
                    . ($host !== '' ? $host . DIRECTORY_SEPARATOR : '')
                    . str_replace('/', DIRECTORY_SEPARATOR, $path);
                $candidates[] = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            }
        }
        if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $href)) {
            $candidates[] = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($href, '/\\'));
        }
        foreach ($candidates as $candidate) {
            $resolved = realpath($candidate);
            if ($resolved !== false && is_file($resolved)) { return $resolved; }
        }
        $basename = basename(str_replace('\\', '/', $href));
        if ($basename === '') { return null; }
        $matches = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && strcasecmp($file->getFilename(), $basename) === 0) {
                $matches[] = $file->getPathname();
            }
        }
        return count($matches) === 1 ? (realpath($matches[0]) ?: $matches[0]) : null;
    }

    private function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    private function lastInsertId(): int
    {
        $sql = strtolower(\InterfaceDB::driverName()) === 'sqlite'
            ? 'SELECT last_insert_rowid()'
            : 'SELECT LAST_INSERT_ID()';
        return (int)(\InterfaceDB::fetchColumn($sql) ?: 0);
    }

    private function inventoryHash(array $files): string
    {
        usort($files, static fn(array $left, array $right): int => (string)$left['archive_path'] <=> (string)$right['archive_path']);
        $inventory = array_map(
            static fn(array $file): array => [
                'archive_path' => (string)$file['archive_path'],
                'file_size' => (int)$file['file_size'],
                'sha256' => strtolower((string)$file['sha256']),
            ],
            $files
        );
        return hash('sha256', (string)json_encode($inventory, JSON_UNESCAPED_SLASHES));
    }
}
