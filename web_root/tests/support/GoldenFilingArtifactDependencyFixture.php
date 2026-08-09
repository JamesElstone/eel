<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 */
declare(strict_types=1);

final class GoldenFilingArtifactDependencyFixture
{
    private static ?string $arelleLogDirectory = null;

    /** @return array{computation_package_id:int,rim_package_id:int} */
    public static function ensure(bool $configureArelle = false): array
    {
        if (strtolower((string)InterfaceDB::driverName()) !== 'sqlite') {
            throw new RuntimeException('Golden filing artefact dependencies may only be installed in SQLite.');
        }
        if (!InterfaceDB::inTransaction()) {
            throw new RuntimeException('Golden filing artefact dependencies require an active SQLite test transaction.');
        }
        if ($configureArelle) {
            self::ensureArelleConfiguration();
            self::ensureFrcTaxonomyPackage();
        }
        self::ensureSchema();
        $computationPackageId = self::ensureComputationPackage();
        $rimPackageId = self::ensureRimPackage($configureArelle);
        self::ensureCanonicalSources($computationPackageId, $rimPackageId);
        $computationProfile = self::ensureMappingProfile(
            \eel_accounts\Service\CtFilingMappingService::TARGET_COMPUTATION,
            $computationPackageId
        );
        if ($computationProfile <= 0) {
            throw new RuntimeException('The Golden computation mapping profile could not be prepared.');
        }
        $rimProfile = self::ensureMappingProfile(
            \eel_accounts\Service\CtFilingMappingService::TARGET_RIM,
            $rimPackageId
        );
        if ($rimProfile <= 0) {
            throw new RuntimeException('The Golden CT600 RIM mapping profile could not be prepared.');
        }
        return [
            'computation_package_id' => $computationPackageId,
            'rim_package_id' => $rimPackageId,
        ];
    }

    private static function ensureArelleConfiguration(): void
    {
        $command = rtrim((string)PROJECT_ROOT, '\\/') . DIRECTORY_SEPARATOR
            . 'third_party' . DIRECTORY_SEPARATOR . 'arelle' . DIRECTORY_SEPARATOR
            . 'runtime' . DIRECTORY_SEPARATOR . 'venv' . DIRECTORY_SEPARATOR
            . 'Scripts' . DIRECTORY_SEPARATOR . 'arelleCmdLine.exe';
        if (!is_file($command)) {
            throw new RuntimeException('The Golden filing export requires the local Arelle runtime: ' . $command);
        }
        if (self::$arelleLogDirectory === null) {
            self::$arelleLogDirectory = test_register_cleanup_path(
                test_upload_base_directory() . DIRECTORY_SEPARATOR
                . 'golden-arelle-logs-' . getmypid() . '-' . bin2hex(random_bytes(4))
            );
        }
        $logs = self::$arelleLogDirectory;
        if (!is_dir($logs) && !mkdir($logs, 0777, true) && !is_dir($logs)) {
            throw new RuntimeException('The Golden Arelle log directory could not be created.');
        }
        \AppConfigurationStore::set('arelle', [
            'enabled' => true,
            'arelle_cmd' => $command,
            'timeout_seconds' => 180,
            'logs_path' => $logs,
            'cache_path' => rtrim((string)PROJECT_ROOT, '\\/') . DIRECTORY_SEPARATOR
                . 'third_party' . DIRECTORY_SEPARATOR . 'arelle' . DIRECTORY_SEPARATOR
                . 'runtime' . DIRECTORY_SEPARATOR . 'cache',
            'offline' => true,
            'flags' => [
                '--plugins',
                'validate/UK',
                '--disclosureSystem',
                'hmrc',
                '--validate',
                '--validationExitCode',
            ],
        ]);
    }

    private static function ensureFrcTaxonomyPackage(): void
    {
        $directory = rtrim((string)PROJECT_ROOT, '\\/') . DIRECTORY_SEPARATOR
            . 'third_party' . DIRECTORY_SEPARATOR . 'frc' . DIRECTORY_SEPARATOR . 'taxonomies';
        $matches = glob($directory . DIRECTORY_SEPARATOR . 'frc-2026-v1.0.0-*.zip');
        $matches = is_array($matches) ? array_values(array_filter($matches, 'is_file')) : [];
        if (count($matches) !== 1) {
            throw new RuntimeException('The Golden filing export requires exactly one verified FRC 2026 taxonomy archive.');
        }
        $path = (string)$matches[0];
        $sha256 = hash_file('sha256', $path);
        if (!is_string($sha256) || preg_match('/^[a-f0-9]{64}$/D', strtolower($sha256)) !== 1) {
            throw new RuntimeException('The Golden FRC taxonomy archive could not be fingerprinted.');
        }
        $filename = strtolower(pathinfo($path, PATHINFO_FILENAME));
        if (!str_ends_with($filename, '-' . substr(strtolower($sha256), 0, 16))) {
            throw new RuntimeException('The Golden FRC taxonomy filename does not match its SHA-256 fingerprint.');
        }
        InterfaceDB::prepareExecute(
            'UPDATE frc_taxonomy_packages SET is_active = 0 WHERE is_active = 1'
        );
        InterfaceDB::prepareExecute(
            'DELETE FROM frc_taxonomy_packages
             WHERE taxonomy_version = :taxonomy_version AND artifact_version = :artifact_version',
            ['taxonomy_version' => '2026', 'artifact_version' => 'v1.0.0']
        );
        InterfaceDB::prepareExecute(
            'INSERT INTO frc_taxonomy_packages
             (taxonomy_version, artifact_version, source_url, download_url, local_path, sha256,
              package_state, verification_error, is_active, published_at, verified_at)
             VALUES (:taxonomy_version, :artifact_version, :source_url, :download_url, :local_path, :sha256,
                     :package_state, NULL, 1, :published_at, CURRENT_TIMESTAMP)',
            [
                'taxonomy_version' => '2026',
                'artifact_version' => 'v1.0.0',
                'source_url' => \eel_accounts\Service\FrcTaxonomyPackageService::SOURCE_URL,
                'download_url' => \eel_accounts\Service\FrcTaxonomyPackageService::SOURCE_URL,
                'local_path' => $path,
                'sha256' => strtolower($sha256),
                'package_state' => 'verified',
                'published_at' => '2025-11-18',
            ]
        );
        if (!is_array((new \eel_accounts\Service\FrcTaxonomyPackageService())->activePackage())) {
            throw new RuntimeException('The local FRC 2026 taxonomy archive did not pass production compatibility checks.');
        }
    }

    private static function ensureSchema(): void
    {
        self::resetOutdatedSchema();
        InterfaceDB::execute('CREATE TABLE IF NOT EXISTS hmrc_ct_computation_packages (
            id INTEGER PRIMARY KEY AUTOINCREMENT, taxonomy_version TEXT NOT NULL, artifact_version TEXT NOT NULL,
            applicable_from TEXT NOT NULL, applicable_to TEXT NULL, source_url TEXT NOT NULL, download_url TEXT NULL,
            local_path TEXT NULL, entry_point_path TEXT NULL, combined_dpl_entry_point_path TEXT NULL, sha256 TEXT NULL,
            package_state TEXT NOT NULL, verification_error TEXT NULL, checked_at TEXT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (taxonomy_version, artifact_version)
        )');
        InterfaceDB::execute('CREATE TABLE IF NOT EXISTS hmrc_ct_computation_files (
            id INTEGER PRIMARY KEY AUTOINCREMENT, package_id INTEGER NOT NULL, archive_path TEXT NOT NULL,
            extracted_path TEXT NOT NULL, file_type TEXT NOT NULL, file_role TEXT NULL,
            file_size INTEGER NOT NULL, sha256 TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (package_id, archive_path),
            FOREIGN KEY (package_id) REFERENCES hmrc_ct_computation_packages (id) ON DELETE CASCADE ON UPDATE CASCADE
        )');
        InterfaceDB::execute('CREATE TABLE IF NOT EXISTS hmrc_ct_computation_concepts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, package_id INTEGER NOT NULL, qname TEXT NOT NULL,
            namespace_uri TEXT NOT NULL, local_name TEXT NOT NULL, data_type TEXT NULL, period_type TEXT NULL,
            substitution_group TEXT NULL, is_abstract INTEGER NOT NULL DEFAULT 0,
            is_dimension INTEGER NOT NULL DEFAULT 0, is_required INTEGER NOT NULL DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (package_id, namespace_uri, local_name),
            FOREIGN KEY (package_id) REFERENCES hmrc_ct_computation_packages (id) ON DELETE CASCADE ON UPDATE CASCADE
        )');
        InterfaceDB::execute('CREATE TABLE IF NOT EXISTS hmrc_ct_rim_components (
            id INTEGER PRIMARY KEY AUTOINCREMENT, package_id INTEGER NOT NULL, component_path TEXT NOT NULL,
            parent_path TEXT NULL, element_name TEXT NOT NULL, namespace_uri TEXT NULL, data_type TEXT NULL,
            min_occurs INTEGER NULL, max_occurs TEXT NULL, is_required INTEGER NOT NULL DEFAULT 0,
            sequence_order INTEGER NULL, is_leaf INTEGER NOT NULL DEFAULT 1, source_file_id INTEGER NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (package_id, component_path),
            FOREIGN KEY (package_id) REFERENCES hmrc_ct_rim_packages (id) ON DELETE CASCADE ON UPDATE CASCADE
        )');
        InterfaceDB::execute('CREATE TABLE IF NOT EXISTS ct_filing_mapping_profiles (
            id INTEGER PRIMARY KEY AUTOINCREMENT, target_type TEXT NOT NULL, rim_package_id INTEGER NULL,
            computation_package_id INTEGER NULL, profile_name TEXT NOT NULL, revision_no INTEGER NOT NULL DEFAULT 1,
            status TEXT NOT NULL DEFAULT \'draft\', parent_profile_id INTEGER NULL, content_hash TEXT NULL,
            compatibility_status TEXT NOT NULL DEFAULT \'pending\', compatibility_json TEXT NULL,
            created_by TEXT NOT NULL, validated_by TEXT NULL, validated_at TEXT NULL,
            activated_by TEXT NULL, activated_at TEXT NULL, retired_by TEXT NULL, retired_at TEXT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (target_type, profile_name, revision_no),
            FOREIGN KEY (rim_package_id) REFERENCES hmrc_ct_rim_packages (id) ON DELETE CASCADE ON UPDATE CASCADE,
            FOREIGN KEY (computation_package_id) REFERENCES hmrc_ct_computation_packages (id) ON DELETE CASCADE ON UPDATE CASCADE,
            FOREIGN KEY (parent_profile_id) REFERENCES ct_filing_mapping_profiles (id) ON DELETE SET NULL ON UPDATE CASCADE
        )');
        InterfaceDB::execute('CREATE TABLE IF NOT EXISTS ct600_rim_mappings (
            id INTEGER PRIMARY KEY AUTOINCREMENT, profile_id INTEGER NOT NULL, canonical_key TEXT NOT NULL,
            target_xpath TEXT NOT NULL, value_type TEXT NOT NULL, sign_multiplier REAL NOT NULL DEFAULT 1,
            null_policy TEXT NOT NULL DEFAULT \'omit\', is_required INTEGER NOT NULL DEFAULT 0,
            sort_order INTEGER NOT NULL DEFAULT 100, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (profile_id, target_xpath),
            FOREIGN KEY (profile_id) REFERENCES ct_filing_mapping_profiles (id) ON DELETE CASCADE ON UPDATE CASCADE
        )');
        InterfaceDB::execute('CREATE TABLE IF NOT EXISTS ct_computation_ixbrl_mappings (
            id INTEGER PRIMARY KEY AUTOINCREMENT, profile_id INTEGER NOT NULL, canonical_key TEXT NOT NULL,
            taxonomy_concept TEXT NOT NULL, namespace_uri TEXT NOT NULL, local_name TEXT NOT NULL,
            value_type TEXT NOT NULL, period_type TEXT NOT NULL DEFAULT \'duration\',
            context_profile TEXT NOT NULL DEFAULT \'ct_period\', unit_ref TEXT NULL, decimals_value TEXT NULL,
            dimensions_json TEXT NULL, sign_multiplier REAL NOT NULL DEFAULT 1,
            presentation_section TEXT NOT NULL, presentation_label TEXT NOT NULL,
            null_policy TEXT NOT NULL DEFAULT \'omit\', is_required INTEGER NOT NULL DEFAULT 0,
            sort_order INTEGER NOT NULL DEFAULT 100, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (profile_id, canonical_key),
            FOREIGN KEY (profile_id) REFERENCES ct_filing_mapping_profiles (id) ON DELETE CASCADE ON UPDATE CASCADE
        )');
        InterfaceDB::execute('CREATE TABLE IF NOT EXISTS ct_filing_mapping_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT, profile_id INTEGER NOT NULL, event_type TEXT NOT NULL,
            actor TEXT NOT NULL, detail_json TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (profile_id) REFERENCES ct_filing_mapping_profiles (id) ON DELETE CASCADE ON UPDATE CASCADE
        )');
        InterfaceDB::execute('CREATE TABLE IF NOT EXISTS ct_filing_canonical_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT, target_scope TEXT NOT NULL DEFAULT \'both\',
            canonical_key TEXT NOT NULL, source_label TEXT NOT NULL, value_type TEXT NOT NULL,
            source_section TEXT NOT NULL, is_required INTEGER NOT NULL DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (target_scope, canonical_key)
        )');
    }

    private static function resetOutdatedSchema(): void
    {
        $expectedColumns = [
            'hmrc_ct_computation_packages' => [
                'id', 'taxonomy_version', 'artifact_version', 'applicable_from', 'applicable_to',
                'source_url', 'download_url', 'local_path', 'entry_point_path',
                'combined_dpl_entry_point_path', 'sha256', 'package_state', 'verification_error',
                'checked_at', 'created_at', 'updated_at',
            ],
            'hmrc_ct_computation_files' => [
                'id', 'package_id', 'archive_path', 'extracted_path', 'file_type', 'file_role',
                'file_size', 'sha256', 'created_at',
            ],
            'hmrc_ct_computation_concepts' => [
                'id', 'package_id', 'qname', 'namespace_uri', 'local_name', 'data_type',
                'period_type', 'substitution_group', 'is_abstract', 'is_dimension', 'is_required',
                'created_at',
            ],
            'hmrc_ct_rim_components' => [
                'id', 'package_id', 'component_path', 'parent_path', 'element_name', 'namespace_uri',
                'data_type', 'min_occurs', 'max_occurs', 'is_required', 'sequence_order', 'is_leaf',
                'source_file_id', 'created_at',
            ],
            'ct_filing_mapping_profiles' => [
                'id', 'target_type', 'rim_package_id', 'computation_package_id', 'profile_name',
                'revision_no', 'status', 'parent_profile_id', 'content_hash', 'compatibility_status',
                'compatibility_json', 'created_by', 'validated_by', 'validated_at', 'activated_by',
                'activated_at', 'retired_by', 'retired_at', 'created_at', 'updated_at',
            ],
            'ct600_rim_mappings' => [
                'id', 'profile_id', 'canonical_key', 'target_xpath', 'value_type', 'sign_multiplier',
                'null_policy', 'is_required', 'sort_order', 'created_at',
            ],
            'ct_computation_ixbrl_mappings' => [
                'id', 'profile_id', 'canonical_key', 'taxonomy_concept', 'namespace_uri', 'local_name',
                'value_type', 'period_type', 'context_profile', 'unit_ref', 'decimals_value',
                'dimensions_json', 'sign_multiplier', 'presentation_section', 'presentation_label',
                'null_policy', 'is_required', 'sort_order', 'created_at',
            ],
            'ct_filing_mapping_events' => [
                'id', 'profile_id', 'event_type', 'actor', 'detail_json', 'created_at',
            ],
            'ct_filing_canonical_sources' => [
                'id', 'target_scope', 'canonical_key', 'source_label', 'value_type', 'source_section',
                'is_required', 'created_at',
            ],
        ];

        foreach ($expectedColumns as $table => $expected) {
            if (!InterfaceDB::tableExists($table)) {
                continue;
            }
            $actual = array_values(array_map(
                static fn(array $column): string => (string)($column['name'] ?? ''),
                InterfaceDB::fetchAll('PRAGMA table_info(' . $table . ')')
            ));
            if ($actual !== $expected) {
                self::dropDependencySchema();
                return;
            }
        }
    }

    private static function dropDependencySchema(): void
    {
        foreach ([
            'ct600_rim_mappings',
            'ct_computation_ixbrl_mappings',
            'ct_filing_mapping_events',
            'ct_filing_mapping_profiles',
            'ct_filing_canonical_sources',
            'hmrc_ct_rim_components',
            'hmrc_ct_computation_concepts',
            'hmrc_ct_computation_files',
            'hmrc_ct_computation_packages',
        ] as $table) {
            InterfaceDB::execute('DROP TABLE IF EXISTS ' . $table);
        }
    }

    private static function ensureCanonicalSources(int $computationPackageId, int $rimPackageId): void
    {
        $service = new \eel_accounts\Service\CtFilingMappingService();
        $packages = [
            \eel_accounts\Service\CtFilingMappingService::TARGET_COMPUTATION => InterfaceDB::fetchOne(
                'SELECT taxonomy_version AS version, artifact_version FROM hmrc_ct_computation_packages
                 WHERE id = :id LIMIT 1',
                ['id' => $computationPackageId]
            ),
            \eel_accounts\Service\CtFilingMappingService::TARGET_RIM => InterfaceDB::fetchOne(
                'SELECT form_version AS version, artifact_version FROM hmrc_ct_rim_packages
                 WHERE id = :id LIMIT 1',
                ['id' => $rimPackageId]
            ),
        ];
        foreach ($packages as $target => $package) {
            if (!is_array($package)) {
                throw new RuntimeException('A Golden filing mapping package is unavailable.');
            }
            $template = $service->reviewedTemplate(
                $target,
                (string)$package['version'],
                (string)$package['artifact_version']
            );
            if (!is_array($template)) {
                throw new RuntimeException('The reviewed Golden filing mapping template is unavailable for ' . $target . '.');
            }
            foreach ((array)$template['mappings'] as $mapping) {
                $key = (string)$mapping['canonical_key'];
                $exists = (int)InterfaceDB::fetchColumn(
                    'SELECT COUNT(*) FROM ct_filing_canonical_sources
                     WHERE target_scope = :scope AND canonical_key = :canonical_key',
                    ['scope' => $target, 'canonical_key' => $key]
                );
                if ($exists > 0) {
                    continue;
                }
                InterfaceDB::prepareExecute(
                    'INSERT INTO ct_filing_canonical_sources
                     (target_scope, canonical_key, source_label, value_type, source_section, is_required)
                     VALUES (:scope, :canonical_key, :source_label, :value_type, :source_section, 0)',
                    [
                        'scope' => $target,
                        'canonical_key' => $key,
                        'source_label' => str_replace(['.', '_'], ' ', $key),
                        'value_type' => self::canonicalValueType($key),
                        'source_section' => self::canonicalSection($key),
                    ]
                );
            }
        }
    }

    private static function canonicalValueType(string $key): string
    {
        if (str_ends_with($key, '.start_date') || str_ends_with($key, '.end_date')) {
            return 'date';
        }
        if (str_starts_with($key, 'supported_return_profile.')) {
            return 'boolean';
        }
        if (str_ends_with($key, '_count')) {
            return 'integer';
        }
        if (str_starts_with($key, 'identity.')
            || str_starts_with($key, 'filing_identity.')
            || str_contains($key, 'treatment')) {
            return 'text';
        }
        return 'numeric';
    }

    private static function canonicalSection(string $key): string
    {
        return match (true) {
            str_starts_with($key, 'identity.'),
            str_starts_with($key, 'filing_identity.'),
            str_starts_with($key, 'accounting_period.'),
            str_starts_with($key, 'ct_period.'),
            str_starts_with($key, 'supported_return_profile.') => 'identity',
            str_contains($key, 'allowance'), str_contains($key, 'pool') => 'capital_allowances',
            str_contains($key, 'loss') => 'losses',
            str_contains($key, 'add_back'), str_contains($key, 'adjustment') => 'accounts_adjustments',
            default => 'tax_liability',
        };
    }

    private static function ensureMappingProfile(string $target, int $packageId): int
    {
        $packageColumn = $target === \eel_accounts\Service\CtFilingMappingService::TARGET_RIM
            ? 'rim_package_id' : 'computation_package_id';
        $existing = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM ct_filing_mapping_profiles
             WHERE target_type = :target AND ' . $packageColumn . ' = :package_id AND status = :status
             ORDER BY id DESC LIMIT 1',
            ['target' => $target, 'package_id' => $packageId, 'status' => 'active']
        );
        if ($existing > 0) {
            return $existing;
        }
        $package = $target === \eel_accounts\Service\CtFilingMappingService::TARGET_RIM
            ? InterfaceDB::fetchOne(
                'SELECT form_version AS version, artifact_version FROM hmrc_ct_rim_packages WHERE id = :id',
                ['id' => $packageId]
            )
            : InterfaceDB::fetchOne(
                'SELECT taxonomy_version AS version, artifact_version FROM hmrc_ct_computation_packages WHERE id = :id',
                ['id' => $packageId]
            );
        if (!is_array($package)) {
            throw new RuntimeException('The Golden mapping package could not be found.');
        }
        $mappingService = new \eel_accounts\Service\CtFilingMappingService();
        $template = $mappingService->reviewedTemplate(
            $target,
            (string)$package['version'],
            (string)$package['artifact_version']
        );
        if (!is_array($template)) {
            throw new RuntimeException('The reviewed Golden mapping template is unavailable for ' . $target . '.');
        }
        InterfaceDB::prepareExecute(
            'INSERT INTO ct_filing_mapping_profiles
             (target_type, rim_package_id, computation_package_id, profile_name, revision_no, status,
              parent_profile_id, content_hash, compatibility_status, compatibility_json, created_by,
              validated_by, validated_at, activated_by, activated_at)
             VALUES (:target, :rim_id, :computation_id, :name, 1, :status,
                     NULL, :hash, :compatibility, :compatibility_json, :actor,
                     :actor, CURRENT_TIMESTAMP, :actor, CURRENT_TIMESTAMP)',
            [
                'target' => $target,
                'rim_id' => $target === \eel_accounts\Service\CtFilingMappingService::TARGET_RIM ? $packageId : null,
                'computation_id' => $target === \eel_accounts\Service\CtFilingMappingService::TARGET_COMPUTATION ? $packageId : null,
                'name' => (string)$template['profile_name'],
                'status' => 'active',
                'hash' => hash('sha256', 'pending'),
                'compatibility' => 'compatible',
                'compatibility_json' => '{}',
                'actor' => 'golden_artifact_review',
            ]
        );
        $profileId = (int)InterfaceDB::fetchColumn('SELECT last_insert_rowid()');
        foreach ((array)$template['mappings'] as $index => $mapping) {
            $canonicalKey = (string)$mapping['canonical_key'];
            $valueType = self::canonicalValueType($canonicalKey);
            if ($target === \eel_accounts\Service\CtFilingMappingService::TARGET_RIM) {
                $targetPath = (string)$mapping['target_xpath'];
                $componentCount = (int)InterfaceDB::fetchColumn(
                    'SELECT COUNT(*) FROM hmrc_ct_rim_components
                     WHERE package_id = :package_id AND component_path = :component_path',
                    ['package_id' => $packageId, 'component_path' => $targetPath]
                );
                if ($componentCount !== 1) {
                    throw new RuntimeException('The reviewed CT600 target is absent from the RIM: ' . $targetPath . '.');
                }
                InterfaceDB::prepareExecute(
                    'INSERT INTO ct600_rim_mappings
                     (profile_id, canonical_key, target_xpath, value_type, sign_multiplier,
                      null_policy, is_required, sort_order)
                     VALUES (:profile_id, :canonical_key, :target_xpath, :value_type, 1, :null_policy, 0, :sort_order)',
                    [
                        'profile_id' => $profileId,
                        'canonical_key' => $canonicalKey,
                        'target_xpath' => $targetPath,
                        'value_type' => $valueType,
                        'null_policy' => 'omit',
                        'sort_order' => ($index + 1) * 10,
                    ]
                );
                continue;
            }
            $conceptRows = InterfaceDB::fetchAll(
                'SELECT * FROM hmrc_ct_computation_concepts
                 WHERE package_id = :package_id AND local_name = :local_name
                 ORDER BY id',
                ['package_id' => $packageId, 'local_name' => (string)$mapping['local_name']]
            );
            $preferred = array_values(array_filter(
                $conceptRows,
                static fn(array $concept): bool => str_contains(
                    strtolower((string)$concept['namespace_uri']),
                    '/ct/comp/'
                )
            ));
            $concept = count($preferred) === 1 ? $preferred[0] : (count($conceptRows) === 1 ? $conceptRows[0] : null);
            if (!is_array($concept)) {
                throw new RuntimeException(
                    'The reviewed computation concept is absent or ambiguous: '
                    . (string)$mapping['local_name'] . '.'
                );
            }
            InterfaceDB::prepareExecute(
                'INSERT INTO ct_computation_ixbrl_mappings
                 (profile_id, canonical_key, taxonomy_concept, namespace_uri, local_name,
                  value_type, period_type, context_profile, unit_ref, decimals_value,
                  dimensions_json, sign_multiplier, presentation_section, presentation_label,
                  null_policy, is_required, sort_order)
                 VALUES (:profile_id, :canonical_key, :taxonomy_concept, :namespace_uri, :local_name,
                         :value_type, :period_type, :context_profile, :unit_ref, :decimals_value,
                         NULL, 1, :presentation_section, :presentation_label,
                         :null_policy, 0, :sort_order)',
                [
                    'profile_id' => $profileId,
                    'canonical_key' => $canonicalKey,
                    'taxonomy_concept' => (string)$concept['qname'],
                    'namespace_uri' => (string)$concept['namespace_uri'],
                    'local_name' => (string)$concept['local_name'],
                    'value_type' => $valueType,
                    'period_type' => (string)($mapping['period_type'] ?? $concept['period_type'] ?? 'duration'),
                    'context_profile' => (string)($mapping['context_profile'] ?? 'hmrc_ct_company'),
                    'unit_ref' => $valueType === 'numeric' ? 'GBP' : null,
                    'decimals_value' => $valueType === 'numeric' ? '2' : null,
                    'presentation_section' => self::canonicalSection($canonicalKey),
                    'presentation_label' => str_replace(['.', '_'], ' ', $canonicalKey),
                    'null_policy' => 'omit',
                    'sort_order' => ($index + 1) * 10,
                ]
            );
        }
        $refresh = new ReflectionMethod($mappingService, 'refreshContentHash');
        $refresh->setAccessible(true);
        $refresh->invoke($mappingService, $profileId);
        return $profileId;
    }

    private static function ensureComputationPackage(): int
    {
        $existing = (int)InterfaceDB::fetchColumn(
            "SELECT id FROM hmrc_ct_computation_packages WHERE package_state = 'verified' ORDER BY id DESC LIMIT 1"
        );
        if ($existing > 0) {
            return $existing;
        }
        $directory = PROJECT_ROOT . 'third_party' . DIRECTORY_SEPARATOR . 'hmrc'
            . DIRECTORY_SEPARATOR . 'ct-computation' . DIRECTORY_SEPARATOR . 'CT2024-v1.0.0';
        $archive = dirname($directory) . DIRECTORY_SEPARATOR . 'CT2024-v1.0.0.zip';
        if (!is_dir($directory) || !is_file($archive)) {
            throw new RuntimeException('The verified CT2024 computation taxonomy package is not installed.');
        }
        $catalogue = new \eel_accounts\Service\HmrcCtComputationCatalogueService();
        $inspect = new ReflectionMethod($catalogue, 'inspectDirectory');
        $inspect->setAccessible(true);
        $manifest = (array)$inspect->invoke($catalogue, $directory);
        $readConcepts = new ReflectionMethod($catalogue, 'readConcepts');
        $readConcepts->setAccessible(true);
        $files = [];
        $concepts = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            $archivePath = str_replace('\\', '/', substr($path, strlen($directory) + 1));
            $extension = strtolower($file->getExtension());
            $files[] = [
                'archive_path' => $archivePath,
                'extracted_path' => $path,
                'file_type' => in_array($extension, ['xsd', 'xml', 'json'], true)
                    ? $extension
                    : (in_array($extension, ['xbrl', 'linkbase'], true) ? 'linkbase' : 'other'),
                'file_role' => realpath($path) === realpath((string)($manifest['entry_point_path'] ?? ''))
                    ? 'entry_point' : null,
                'file_size' => $file->getSize(),
                'sha256' => (string)hash_file('sha256', $path),
            ];
            if ($extension === 'xsd') {
                foreach ((array)$readConcepts->invoke($catalogue, $path) as $concept) {
                    $concepts[(string)$concept['namespace_uri'] . '|' . (string)$concept['local_name']] = $concept;
                }
            }
        }
        usort($files, static fn(array $left, array $right): int => $left['archive_path'] <=> $right['archive_path']);
        $inventory = new ReflectionMethod($catalogue, 'inventoryHash');
        $inventory->setAccessible(true);
        $hash = (string)$inventory->invoke($catalogue, $files);
        InterfaceDB::prepareExecute(
            'INSERT INTO hmrc_ct_computation_packages
             (taxonomy_version, artifact_version, applicable_from, applicable_to, source_url, download_url,
              local_path, entry_point_path, combined_dpl_entry_point_path, sha256, package_state, checked_at)
             VALUES (:taxonomy, :artifact, :from_date, NULL, :source, :download,
                     :path, :entry, :combined, :sha, :state, CURRENT_TIMESTAMP)',
            [
                'taxonomy' => (string)$manifest['taxonomy_version'],
                'artifact' => (string)$manifest['artifact_version'],
                'from_date' => '1900-01-01',
                'source' => \eel_accounts\Service\HmrcCtComputationCatalogueService::SOURCE_URL,
                'download' => \eel_accounts\Service\HmrcCtComputationCatalogueService::CT2024_DOWNLOAD_URL,
                'path' => $directory,
                'entry' => (string)$manifest['entry_point_path'],
                'combined' => (string)$manifest['entry_point_path'],
                'sha' => $hash,
                'state' => 'verified',
            ]
        );
        $packageId = (int)InterfaceDB::fetchColumn('SELECT last_insert_rowid()');
        foreach ($files as $file) {
            InterfaceDB::prepareExecute(
                'INSERT INTO hmrc_ct_computation_files
                 (package_id, archive_path, extracted_path, file_type, file_role, file_size, sha256)
                 VALUES (:package_id, :archive_path, :extracted_path, :file_type, :file_role, :file_size, :sha256)',
                ['package_id' => $packageId] + $file
            );
        }
        foreach ($concepts as $concept) {
            $concept = array_replace([
                'qname' => '', 'namespace_uri' => '', 'local_name' => '', 'data_type' => null,
                'period_type' => null, 'substitution_group' => null, 'is_abstract' => 0,
                'is_dimension' => 0, 'is_required' => 0,
            ], $concept);
            InterfaceDB::prepareExecute(
                'INSERT INTO hmrc_ct_computation_concepts
                 (package_id, qname, namespace_uri, local_name, data_type, period_type,
                  substitution_group, is_abstract, is_dimension, is_required)
                 VALUES (:package_id, :qname, :namespace_uri, :local_name, :data_type, :period_type,
                         :substitution_group, :is_abstract, :is_dimension, :is_required)',
                [
                    'package_id' => $packageId,
                    'qname' => (string)$concept['qname'],
                    'namespace_uri' => (string)$concept['namespace_uri'],
                    'local_name' => (string)$concept['local_name'],
                    'data_type' => $concept['data_type'],
                    'period_type' => $concept['period_type'],
                    'substitution_group' => $concept['substitution_group'],
                    'is_abstract' => (int)$concept['is_abstract'],
                    'is_dimension' => (int)$concept['is_dimension'],
                    'is_required' => (int)$concept['is_required'],
                ]
            );
        }
        return $packageId;
    }

    private static function ensureRimPackage(bool $requireArchiveHash): int
    {
        $existing = (int)InterfaceDB::fetchColumn(
            "SELECT id FROM hmrc_ct_rim_packages WHERE package_state = 'verified' ORDER BY id DESC LIMIT 1"
        );
        if ($existing > 0) {
            return $existing;
        }
        $root = PROJECT_ROOT . 'third_party' . DIRECTORY_SEPARATOR . 'hmrc'
            . DIRECTORY_SEPARATOR . 'ct600-rim';
        $directory = $root . DIRECTORY_SEPARATOR . 'ct600-v3-artefacts-v1.994';
        $archive = $root . DIRECTORY_SEPARATOR . 'ct600-v3-artefacts-v1.994.zip';
        if (!is_dir($directory) || !is_file($archive)) {
            throw new RuntimeException('The verified CT600 V3 RIM package is not installed.');
        }
        $inventory = self::rimInventory($directory);
        $xsdCount = count(array_filter(
            $inventory,
            static fn(array $file): bool => (string)$file['file_type'] === 'xsd'
        ));
        $inventoryLines = array_map(
            static fn(array $file): string => (string)$file['archive_path'] . '|' . (string)$file['sha256'],
            $inventory
        );
        $archiveHash = $requireArchiveHash ? hash_file('sha256', $archive) : false;
        if ($requireArchiveHash && (!is_string($archiveHash) || $archiveHash === '')) {
            throw new RuntimeException('The verified CT600 V3 RIM archive could not be fingerprinted.');
        }
        InterfaceDB::prepareExecute(
            'INSERT INTO hmrc_ct_rim_packages
             (form_version, artifact_version, applicable_from, applicable_to, published_at,
              live_from, live_to, hmrc_status, source_url, download_url, local_path, sha256,
              checked_at, package_state, xsd_count, applicability_status)
             VALUES (:form_version, :artifact_version, NULL, NULL, :published_at,
                     :live_from, NULL, :hmrc_status, :source_url, :download_url, :local_path, :sha256,
                     :checked_at, :package_state, :xsd_count, :applicability_status)',
            [
                'form_version' => 'V3',
                'artifact_version' => 'V1.994',
                'published_at' => '2024-01-01 00:00:00',
                'live_from' => '1900-01-01 00:00:00',
                'hmrc_status' => 'live',
                'source_url' => 'https://www.gov.uk/government/publications/corporation-tax-technical-specifications',
                'download_url' => '',
                'local_path' => $archive,
                'sha256' => is_string($archiveHash) && $archiveHash !== ''
                    ? strtolower($archiveHash)
                    : hash('sha256', implode("\n", $inventoryLines)),
                'checked_at' => gmdate('Y-m-d H:i:s'),
                'package_state' => 'verified',
                'xsd_count' => $xsdCount,
                'applicability_status' => 'pending',
            ]
        );
        $packageId = (int)InterfaceDB::fetchColumn('SELECT last_insert_rowid()');
        self::catalogueRimDirectory($packageId, $inventory);
        return $packageId;
    }

    /** @return list<array<string,mixed>> */
    private static function rimInventory(string $directory): array
    {
        $inventory = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $extension = strtolower($file->getExtension());
            if (!$file->isFile() || !in_array($extension, ['xsd', 'sch', 'xslt'], true)) {
                continue;
            }
            $path = $file->getPathname();
            $inventory[] = [
                'archive_path' => str_replace(
                    '\\',
                    '/',
                    ltrim(substr($path, strlen(rtrim($directory, '\\/'))), '\\/')
                ),
                'extracted_path' => $path,
                'file_type' => $extension,
                'file_size' => (int)$file->getSize(),
                'sha256' => (string)hash_file('sha256', $path),
                'file_role' => $extension === 'sch'
                    ? 'schematron'
                    : ($extension === 'xslt'
                        ? 'transform'
                        : (stripos((string)$file->getFilename(), 'envelope') !== false
                            ? 'envelope_schema'
                            : null)),
            ];
        }
        usort($inventory, static fn(array $left, array $right): int => $left['archive_path'] <=> $right['archive_path']);
        return $inventory;
    }

    /** @param list<array<string,mixed>> $inventory */
    private static function catalogueRimDirectory(int $packageId, array $inventory): void
    {
        $catalogued = [];
        $primaryFileId = 0;
        $applicableFrom = null;
        foreach ($inventory as $file) {
            InterfaceDB::prepareExecute(
                'INSERT INTO hmrc_ct_rim_files
                 (package_id, archive_path, extracted_path, file_type, file_size, sha256, file_role)
                 VALUES (:package_id, :archive_path, :extracted_path, :file_type, :file_size, :sha256, :file_role)',
                ['package_id' => $packageId] + $file
            );
            $file['id'] = (int)InterfaceDB::fetchColumn('SELECT last_insert_rowid()');
            if ((string)$file['file_type'] === 'xsd') {
                $xml = @simplexml_load_file((string)$file['extracted_path']);
                if ($xml instanceof SimpleXMLElement
                    && $xml->xpath('//*[local-name()="element" and @name="CompanyTaxReturn"]')) {
                    $dates = $xml->xpath(
                        '//*[local-name()="element" and @name="CompanyTaxReturn"]'
                        . '//*[local-name()="element" and @name="CompanyInformation"]'
                        . '//*[local-name()="element" and @name="PeriodCovered"]'
                        . '//*[local-name()="element" and @name="From"]'
                        . '//*[local-name()="minInclusive"]/@value'
                    );
                    $candidate = count((array)$dates) === 1 ? trim((string)$dates[0]) : '';
                    if ($candidate !== '' && ($applicableFrom === null || $candidate > $applicableFrom)) {
                        $applicableFrom = $candidate;
                        $primaryFileId = (int)$file['id'];
                    }
                }
            }
            $catalogued[] = $file;
        }
        if ($primaryFileId <= 0 || preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string)$applicableFrom) !== 1) {
            throw new RuntimeException('The CT600 RIM primary schema and applicability date could not be identified.');
        }
        InterfaceDB::prepareExecute(
            "UPDATE hmrc_ct_rim_files SET file_role = 'primary_schema' WHERE id = :id",
            ['id' => $primaryFileId]
        );
        $schema = new \eel_accounts\Service\HmrcCtRimSchemaService();
        $inspect = new ReflectionMethod($schema, 'inspectSchemaFiles');
        $inspect->setAccessible(true);
        foreach ((array)$inspect->invoke($schema, $catalogued) as $component) {
            InterfaceDB::prepareExecute(
                'INSERT INTO hmrc_ct_rim_components
                 (package_id, component_path, parent_path, element_name, namespace_uri, data_type,
                  min_occurs, max_occurs, is_required, sequence_order, is_leaf, source_file_id)
                 VALUES (:package_id, :component_path, :parent_path, :element_name, :namespace_uri, :data_type,
                         :min_occurs, :max_occurs, :is_required, :sequence_order, :is_leaf, :source_file_id)',
                ['package_id' => $packageId] + $component
            );
        }
        InterfaceDB::prepareExecute(
            'UPDATE hmrc_ct_rim_packages
             SET applicable_from = :applicable_from,
                 applicability_source_file_id = :source_file_id,
                 applicability_xpath = :xpath,
                 applicability_extracted_at = :extracted_at,
                 applicability_status = :status
             WHERE id = :id',
            [
                'applicable_from' => $applicableFrom,
                'source_file_id' => $primaryFileId,
                'xpath' => 'CompanyTaxReturn/CompanyInformation/PeriodCovered/From/minInclusive/@value',
                'extracted_at' => gmdate('Y-m-d H:i:s'),
                'status' => 'confirmed',
                'id' => $packageId,
            ]
        );
    }
}
