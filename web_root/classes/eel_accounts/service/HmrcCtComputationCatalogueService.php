<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class HmrcCtComputationCatalogueService
{
    public const SOURCE_URL = 'https://www.gov.uk/government/publications/corporation-tax-technical-specifications-xbrl-and-ixbrl';

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
             WHERE package_state = :verified AND applicable_from <= :period_end
               AND (applicable_to IS NULL OR applicable_to >= :period_start)
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
            return (int)\InterfaceDB::lastInsertId();
        }
        $conceptCount = (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM hmrc_ct_computation_concepts WHERE package_id = :id', ['id' => $id]);
        $verified = $conceptCount > 0 && $combined !== '' && is_file($combined) && ($entryPoint === '' || is_file($entryPoint));
        \InterfaceDB::prepareExecute(
            'UPDATE hmrc_ct_computation_packages SET taxonomy_version = :taxonomy_version, artifact_version = :artifact_version,
             applicable_from = :applicable_from, applicable_to = :applicable_to, source_url = :source_url, download_url = :download_url,
             local_path = :local_path, entry_point_path = :entry_point_path, combined_dpl_entry_point_path = :combined_path,
             package_state = :state, verification_error = :error, checked_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['taxonomy_version' => $taxonomyVersion, 'artifact_version' => $artifactVersion, 'applicable_from' => $from, 'applicable_to' => $to !== '' ? $to : null, 'source_url' => ($input['source_url'] ?? null) ?: self::SOURCE_URL, 'download_url' => ($input['download_url'] ?? null) ?: null, 'local_path' => $localPath !== '' ? $localPath : null, 'entry_point_path' => $entryPoint !== '' ? $entryPoint : null, 'combined_path' => $combined !== '' ? $combined : null, 'state' => $verified ? 'verified' : ($conceptCount > 0 ? 'failed' : 'not_downloaded'), 'error' => $verified ? null : 'A catalogued package and an existing combined CT/DPL entry point are required.', 'id' => $id]
        );
        return $id;
    }

    /** Catalogue a locally expanded, administrator-supplied taxonomy package. */
    public function catalogueDirectory(int $packageId, string $directory): array
    {
        $root = realpath($directory);
        if ($packageId <= 0 || $root === false || !is_dir($root)) {
            throw new \InvalidArgumentException('Select an existing computation-taxonomy directory.');
        }
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
            $files[] = ['archive_path' => $relative, 'extracted_path' => $path, 'file_type' => in_array($extension, ['xsd', 'xml', 'json'], true) ? $extension : (in_array($extension, ['xbrl', 'linkbase'], true) ? 'linkbase' : 'other'), 'file_size' => $file->getSize(), 'sha256' => hash_file('sha256', $path)];
            if ($extension === 'xsd') {
                $concepts = array_merge($concepts, $this->readConcepts($path));
            }
        }
        if ($concepts === []) {
            throw new \RuntimeException('No XSD concepts were found in the supplied computation taxonomy.');
        }
        \InterfaceDB::beginTransaction();
        try {
            \InterfaceDB::prepareExecute('DELETE FROM hmrc_ct_computation_files WHERE package_id = :id', ['id' => $packageId]);
            \InterfaceDB::prepareExecute('DELETE FROM hmrc_ct_computation_concepts WHERE package_id = :id', ['id' => $packageId]);
            foreach ($files as $file) {
                \InterfaceDB::prepareExecute(
                    'INSERT INTO hmrc_ct_computation_files (package_id, archive_path, extracted_path, file_type, file_size, sha256)
                     VALUES (:package_id, :archive_path, :extracted_path, :file_type, :file_size, :sha256)',
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
                'UPDATE hmrc_ct_computation_packages SET local_path = :path, sha256 = :sha256,
                 package_state = :state, verification_error = NULL, checked_at = CURRENT_TIMESTAMP WHERE id = :id',
                ['path' => $root, 'sha256' => $this->inventoryHash($files), 'state' => 'downloaded', 'id' => $packageId]
            );
            \InterfaceDB::commit();
        } catch (\Throwable $exception) {
            \InterfaceDB::rollBack();
            throw $exception;
        }
        return ['file_count' => count($files), 'concept_count' => count($concepts)];
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

    private function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
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
