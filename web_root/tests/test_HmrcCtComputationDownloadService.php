<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

use eel_accounts\Service\HmrcCtComputationCatalogueService;
use eel_accounts\Service\HmrcCtComputationDownloadService;

$harness = new GeneratedServiceClassTestHarness();
$harness->run(HmrcCtComputationDownloadService::class, static function (
    GeneratedServiceClassTestHarness $h,
    HmrcCtComputationDownloadService $service
): void {
    $h->check($service::class, 'downloads, safely expands and catalogues the pinned CT2024 package', static function () use ($h): void {
        $cache = ct2024DownloadTestDirectory('success');
        $fetchCalls = 0;
        $catalogueCalls = 0;
        $archive = ct2024DownloadTestArchive('2024');
        $service = new HmrcCtComputationDownloadService(
            static function (string $url) use (&$fetchCalls, $archive, $h): string {
                $fetchCalls++;
                $h->assertSame(HmrcCtComputationCatalogueService::CT2024_DOWNLOAD_URL, $url);
                return $archive;
            },
            $cache,
            static function (string $directory, string $actor) use (&$catalogueCalls, $h): array {
                $catalogueCalls++;
                $h->assertTrue(is_file($directory . DIRECTORY_SEPARATOR . 'META-INF' . DIRECTORY_SEPARATOR . 'taxonomyPackage.xml'));
                $h->assertTrue(is_file($directory . DIRECTORY_SEPARATOR . 'www.hmrc.gov.uk' . DIRECTORY_SEPARATOR
                    . 'schemas' . DIRECTORY_SEPARATOR . 'ct' . DIRECTORY_SEPARATOR . 'comp'
                    . DIRECTORY_SEPARATOR . '2024-01-01' . DIRECTORY_SEPARATOR . 'ct-comp-2024.xsd'));
                $h->assertSame('download-test', $actor);
                return [
                    'package_id' => 21,
                    'mapping_profile_id' => 34,
                    'file_count' => 2,
                    'concept_count' => 16,
                    'package_state' => 'verified',
                    'local_path' => $directory,
                ];
            }
        );

        try {
            $result = $service->install('download-test');
            if (empty($result['success'])) {
                throw new RuntimeException(implode(' ', (array)($result['errors'] ?? ['CT2024 installation failed.'])));
            }
            $h->assertSame(true, $result['success']);
            $h->assertSame(false, $result['already_installed']);
            $h->assertSame(21, $result['package_id']);
            $h->assertSame(34, $result['profile_id']);
            $h->assertSame(2, $result['file_count']);
            $h->assertSame(16, $result['concept_count']);
            $h->assertSame(1, $fetchCalls);
            $h->assertSame(1, $catalogueCalls);
            $h->assertTrue(is_file($cache . DIRECTORY_SEPARATOR . 'CT2024-v1.0.0.zip'));
            $h->assertTrue(is_dir($cache . DIRECTORY_SEPARATOR . 'CT2024-v1.0.0'));
        } finally {
            ct2024DownloadTestRemoveDirectory($cache);
        }
    });

    $h->check($service::class, 'does not use the network when a verified inventory and active mapping exist', static function () use ($h): void {
        $tables = [
            'hmrc_ct_computation_packages',
            'hmrc_ct_computation_files',
            'hmrc_ct_computation_concepts',
            'ct_filing_mapping_profiles',
        ];
        $existingTables = array_values(array_filter($tables, static fn(string $table): bool => InterfaceDB::tableExists($table)));
        if ($existingTables !== [] && count($existingTables) !== count($tables)) {
            $h->skip('The idempotency fixture requires either the complete catalogue schema or an empty database.');
        }
        $usesExistingSchema = count($existingTables) === count($tables);
        $transactionStarted = false;
        $createdTables = false;
        $cache = ct2024DownloadTestDirectory('idempotent');
        $expanded = $cache . DIRECTORY_SEPARATOR . 'CT2024-v1.0.0';
        if (!mkdir($expanded, 0775, true) && !is_dir($expanded)) {
            throw new RuntimeException('Unable to create the CT2024 idempotency fixture.');
        }
        $file = $expanded . DIRECTORY_SEPARATOR . 'verified.xsd';
        file_put_contents($file, '<schema/>');
        $fileHash = hash_file('sha256', $file);
        $inventoryHash = hash('sha256', (string)json_encode([[
            'archive_path' => 'verified.xsd',
            'file_size' => filesize($file),
            'sha256' => $fileHash,
        ]], JSON_UNESCAPED_SLASHES));

        try {
            if ($usesExistingSchema) {
                InterfaceDB::beginTransaction();
                $transactionStarted = true;
                $package = InterfaceDB::fetchOne(
                    'SELECT id FROM hmrc_ct_computation_packages
                     WHERE taxonomy_version = :taxonomy AND UPPER(artifact_version) = :artifact
                     ORDER BY id DESC LIMIT 1',
                    ['taxonomy' => '2024', 'artifact' => 'V1.0.0']
                );
                if (!is_array($package)) {
                    InterfaceDB::prepareExecute(
                        'INSERT INTO hmrc_ct_computation_packages
                         (taxonomy_version, artifact_version, applicable_from, source_url, local_path, sha256, package_state)
                         VALUES (:taxonomy, :artifact, :applicable, :source, :path, :sha256, :state)',
                        [
                            'taxonomy' => '2024',
                            'artifact' => 'V1.0.0',
                            'applicable' => '2015-04-01',
                            'source' => HmrcCtComputationCatalogueService::SOURCE_URL,
                            'path' => $expanded,
                            'sha256' => $inventoryHash,
                            'state' => 'verified',
                        ]
                    );
                    $packageId = (int)InterfaceDB::fetchColumn('SELECT last_insert_rowid()');
                } else {
                    $packageId = (int)$package['id'];
                    InterfaceDB::prepareExecute(
                        'UPDATE hmrc_ct_computation_packages
                         SET applicable_from = :applicable, source_url = :source, local_path = :path,
                             sha256 = :sha256, package_state = :state, verification_error = NULL
                         WHERE id = :id',
                        [
                            'applicable' => '2015-04-01',
                            'source' => HmrcCtComputationCatalogueService::SOURCE_URL,
                            'path' => $expanded,
                            'sha256' => $inventoryHash,
                            'state' => 'verified',
                            'id' => $packageId,
                        ]
                    );
                }
                InterfaceDB::prepareExecute(
                    'DELETE FROM hmrc_ct_computation_files WHERE package_id = :package_id',
                    ['package_id' => $packageId]
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO hmrc_ct_computation_files
                     (package_id, archive_path, extracted_path, file_type, file_size, sha256)
                     VALUES (:package_id, :archive, :path, :type, :size, :sha256)',
                    [
                        'package_id' => $packageId,
                        'archive' => 'verified.xsd',
                        'path' => $file,
                        'type' => 'xsd',
                        'size' => filesize($file),
                        'sha256' => $fileHash,
                    ]
                );
                $expectedConceptCount = (int)InterfaceDB::fetchColumn(
                    'SELECT COUNT(*) FROM hmrc_ct_computation_concepts WHERE package_id = :package_id',
                    ['package_id' => $packageId]
                );
                if ($expectedConceptCount === 0) {
                    InterfaceDB::prepareExecute(
                        'INSERT INTO hmrc_ct_computation_concepts
                         (package_id, qname, namespace_uri, local_name, is_dimension)
                         VALUES (:package_id, :qname, :namespace_uri, :local_name, 0)',
                        [
                            'package_id' => $packageId,
                            'qname' => 'test:VerifiedInventoryConcept',
                            'namespace_uri' => 'urn:test:ct2024-idempotency',
                            'local_name' => 'VerifiedInventoryConcept',
                        ]
                    );
                    $expectedConceptCount = 1;
                }
                $profile = InterfaceDB::fetchOne(
                    'SELECT id FROM ct_filing_mapping_profiles
                     WHERE target_type = :target AND computation_package_id = :package_id
                       AND status = :status AND compatibility_status = :compatibility
                     ORDER BY id DESC LIMIT 1',
                    [
                        'target' => 'computation_ixbrl',
                        'package_id' => $packageId,
                        'status' => 'active',
                        'compatibility' => 'compatible',
                    ]
                );
                if (!is_array($profile)) {
                    InterfaceDB::prepareExecute(
                        'INSERT INTO ct_filing_mapping_profiles
                         (target_type, computation_package_id, profile_name, revision_no, status,
                          content_hash, compatibility_status, created_by)
                         VALUES (:target, :package_id, :name, 1, :status, :hash, :compatibility, :actor)',
                        [
                            'target' => 'computation_ixbrl',
                            'package_id' => $packageId,
                            'name' => 'ct2024_idempotency_' . bin2hex(random_bytes(4)),
                            'status' => 'active',
                            'hash' => str_repeat('a', 64),
                            'compatibility' => 'compatible',
                            'actor' => 'test',
                        ]
                    );
                    $profileId = (int)InterfaceDB::fetchColumn('SELECT last_insert_rowid()');
                } else {
                    $profileId = (int)$profile['id'];
                }
            } else {
                $createdTables = true;
                InterfaceDB::prepareExecute('CREATE TABLE hmrc_ct_computation_packages (
                    id INTEGER PRIMARY KEY, taxonomy_version TEXT, artifact_version TEXT, applicable_from TEXT,
                    applicable_to TEXT, source_url TEXT, download_url TEXT, local_path TEXT, entry_point_path TEXT,
                    combined_dpl_entry_point_path TEXT, sha256 TEXT, package_state TEXT, verification_error TEXT,
                    checked_at TEXT, created_at TEXT, updated_at TEXT
                )');
                InterfaceDB::prepareExecute('CREATE TABLE hmrc_ct_computation_files (
                    package_id INTEGER, archive_path TEXT, extracted_path TEXT, file_type TEXT, file_role TEXT,
                    file_size INTEGER, sha256 TEXT
                )');
                InterfaceDB::prepareExecute('CREATE TABLE hmrc_ct_computation_concepts (
                    package_id INTEGER, is_dimension INTEGER
                )');
                InterfaceDB::prepareExecute('CREATE TABLE ct_filing_mapping_profiles (
                    id INTEGER PRIMARY KEY, target_type TEXT, rim_package_id INTEGER, computation_package_id INTEGER,
                    profile_name TEXT, revision_no INTEGER, status TEXT, content_hash TEXT,
                    compatibility_status TEXT
                )');
                $packageId = 21;
                $profileId = 34;
                $expectedConceptCount = 1;
                InterfaceDB::prepareExecute(
                    'INSERT INTO hmrc_ct_computation_packages
                     (id, taxonomy_version, artifact_version, applicable_from, source_url, local_path, sha256, package_state)
                     VALUES (:id, :taxonomy, :artifact, :applicable, :source, :path, :sha256, :state)',
                    [
                        'id' => $packageId,
                        'taxonomy' => '2024',
                        'artifact' => 'V1.0.0',
                        'applicable' => '2015-04-01',
                        'source' => HmrcCtComputationCatalogueService::SOURCE_URL,
                        'path' => $expanded,
                        'sha256' => $inventoryHash,
                        'state' => 'verified',
                    ]
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO hmrc_ct_computation_files
                     (package_id, archive_path, extracted_path, file_type, file_size, sha256)
                     VALUES (:package_id, :archive, :path, :type, :size, :sha256)',
                    [
                        'package_id' => $packageId,
                        'archive' => 'verified.xsd',
                        'path' => $file,
                        'type' => 'xsd',
                        'size' => filesize($file),
                        'sha256' => $fileHash,
                    ]
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO hmrc_ct_computation_concepts (package_id, is_dimension) VALUES (:package_id, 0)',
                    ['package_id' => $packageId]
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO ct_filing_mapping_profiles
                     (id, target_type, computation_package_id, profile_name, revision_no, status, content_hash, compatibility_status)
                     VALUES (:id, :target, :package_id, :name, 1, :status, :hash, :compatibility)',
                    [
                        'id' => $profileId,
                        'target' => 'computation_ixbrl',
                        'package_id' => $packageId,
                        'name' => 'reviewed_ct_computation_2024_v1_0_0',
                        'status' => 'active',
                        'hash' => str_repeat('a', 64),
                        'compatibility' => 'compatible',
                    ]
                );
            }

            $fetchCalls = 0;
            $service = new HmrcCtComputationDownloadService(
                static function () use (&$fetchCalls): string {
                    $fetchCalls++;
                    throw new RuntimeException('The verified package should not use the network.');
                },
                $cache
            );
            $result = $service->install('idempotency-test');
            $h->assertSame(true, $result['success']);
            $h->assertSame(true, $result['already_installed']);
            $h->assertSame($packageId, $result['package_id']);
            $h->assertSame($profileId, $result['profile_id']);
            $h->assertSame(1, $result['file_count']);
            $h->assertSame($expectedConceptCount, $result['concept_count']);
            $h->assertSame(0, $fetchCalls);

            $heldLock = fopen($cache . DIRECTORY_SEPARATOR . '.ct2024-install.lock', 'c+');
            if (!is_resource($heldLock) || !flock($heldLock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Unable to hold the idempotency fixture lock.');
            }
            try {
                $locked = $service->install('idempotency-test');
                $h->assertSame(false, $locked['success']);
                $h->assertSame(
                    'verified',
                    InterfaceDB::fetchColumn(
                        'SELECT package_state FROM hmrc_ct_computation_packages WHERE id = :id',
                        ['id' => $packageId]
                    )
                );
            } finally {
                flock($heldLock, LOCK_UN);
                fclose($heldLock);
            }

            file_put_contents($expanded . DIRECTORY_SEPARATOR . 'unexpected.xsd', '<schema/>');
            $changed = $service->install('idempotency-test');
            $h->assertSame(false, $changed['success']);
            $h->assertSame(1, $fetchCalls);
        } finally {
            if ($transactionStarted && InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            } elseif ($createdTables) {
                foreach (array_reverse($tables) as $table) {
                    if (InterfaceDB::tableExists($table)) {
                        InterfaceDB::prepareExecute('DROP TABLE ' . $table);
                    }
                }
            }
            ct2024DownloadTestRemoveDirectory($cache);
        }
    });

    $h->check($service::class, 'rejects a wrong taxonomy manifest and removes its staging directory', static function () use ($h): void {
        $cache = ct2024DownloadTestDirectory('wrong-manifest');
        $installedStates = InterfaceDB::tableExists('hmrc_ct_computation_packages')
            ? InterfaceDB::fetchAll(
                'SELECT id, package_state FROM hmrc_ct_computation_packages
                 WHERE taxonomy_version = :taxonomy_version AND artifact_version = :artifact_version',
                ['taxonomy_version' => '2024', 'artifact_version' => 'V1.0.0']
            )
            : [];
        foreach ($installedStates as $installedState) {
            InterfaceDB::prepareExecute(
                'UPDATE hmrc_ct_computation_packages SET package_state = :package_state WHERE id = :id',
                ['package_state' => 'failed', 'id' => (int)$installedState['id']]
            );
        }
        $catalogueCalls = 0;
        $service = new HmrcCtComputationDownloadService(
            static fn(): string => ct2024DownloadTestArchive('2025'),
            $cache,
            static function () use (&$catalogueCalls): array {
                $catalogueCalls++;
                return ['package_id' => 1, 'mapping_profile_id' => 1];
            }
        );
        try {
            $result = $service->install();
            $h->assertSame(false, $result['success']);
            $h->assertSame(0, $catalogueCalls);
            $h->assertTrue(str_contains(implode(' ', $result['errors']), 'not the accepted HMRC CT2024'));
            $entries = is_dir($cache) ? glob($cache . DIRECTORY_SEPARATOR . '.ct2024-staging-*') : [];
            $h->assertSame([], is_array($entries) ? $entries : []);
            $h->assertFalse(is_dir($cache . DIRECTORY_SEPARATOR . 'CT2024-v1.0.0'));
        } finally {
            foreach ($installedStates as $installedState) {
                InterfaceDB::prepareExecute(
                    'UPDATE hmrc_ct_computation_packages SET package_state = :package_state WHERE id = :id',
                    ['package_state' => (string)$installedState['package_state'], 'id' => (int)$installedState['id']]
                );
            }
            ct2024DownloadTestRemoveDirectory($cache);
        }
    });

    $h->check($service::class, 'rejects unsafe and malformed archives without cataloguing them', static function () use ($h): void {
        foreach ([
            ct2024DownloadTestZip(['../escape.xsd' => '<schema/>']),
            'not-a-zip',
        ] as $index => $archive) {
            $cache = ct2024DownloadTestDirectory('invalid-' . $index);
            $catalogueCalls = 0;
            $service = new HmrcCtComputationDownloadService(
                static fn(): string => $archive,
                $cache,
                static function () use (&$catalogueCalls): array {
                    $catalogueCalls++;
                    return ['package_id' => 1, 'mapping_profile_id' => 1];
                }
            );
            try {
                $result = $service->install();
                $h->assertSame(false, $result['success']);
                $h->assertSame(0, $catalogueCalls);
                $h->assertFalse(is_file(dirname($cache) . DIRECTORY_SEPARATOR . 'escape.xsd'));
            } finally {
                ct2024DownloadTestRemoveDirectory($cache);
            }
        }
    });

    $h->check($service::class, 'removes a newly installed package when catalogue or mapping preparation fails', static function () use ($h): void {
        $cache = ct2024DownloadTestDirectory('catalogue-failure');
        $service = new HmrcCtComputationDownloadService(
            static fn(): string => ct2024DownloadTestArchive('2024'),
            $cache,
            static function (): never {
                throw new RuntimeException('Reviewed mapping preparation failed.');
            }
        );

        try {
            $result = $service->install();
            $h->assertSame(false, $result['success']);
            $h->assertTrue(str_contains(implode(' ', $result['errors']), 'Reviewed mapping preparation failed'));
            $h->assertFalse(is_dir($cache . DIRECTORY_SEPARATOR . 'CT2024-v1.0.0'));
            $h->assertFalse(is_file($cache . DIRECTORY_SEPARATOR . 'CT2024-v1.0.0.zip'));
            $entries = is_dir($cache) ? glob($cache . DIRECTORY_SEPARATOR . '.ct2024-staging-*') : [];
            $h->assertSame([], is_array($entries) ? $entries : []);
        } finally {
            ct2024DownloadTestRemoveDirectory($cache);
        }
    });

    $h->check($service::class, 'refuses to race another installation holding the package lock', static function () use ($h): void {
        $cache = ct2024DownloadTestDirectory('locked');
        if (!mkdir($cache, 0775, true) && !is_dir($cache)) {
            throw new RuntimeException('Unable to create the locked CT2024 fixture.');
        }
        $lock = fopen($cache . DIRECTORY_SEPARATOR . '.ct2024-install.lock', 'c+');
        if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Unable to hold the CT2024 fixture lock.');
        }
        $fetchCalls = 0;
        $service = new HmrcCtComputationDownloadService(
            static function () use (&$fetchCalls): string {
                $fetchCalls++;
                return ct2024DownloadTestArchive('2024');
            },
            $cache,
            static fn(): array => ['package_id' => 1, 'mapping_profile_id' => 1]
        );

        try {
            $result = $service->install();
            $h->assertSame(false, $result['success']);
            $h->assertTrue(str_contains(implode(' ', $result['errors']), 'already running'));
            $h->assertSame(0, $fetchCalls);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            ct2024DownloadTestRemoveDirectory($cache);
        }
    });

    $h->check($service::class, 'refuses any URL other than the pinned HMRC CT2024 URL', static function () use ($h): void {
        $fetchCalls = 0;
        $service = new HmrcCtComputationDownloadService(
            static function () use (&$fetchCalls): string {
                $fetchCalls++;
                return 'unexpected';
            },
            ct2024DownloadTestDirectory('untrusted-url')
        );
        $method = new ReflectionMethod($service, 'fetchArchive');
        try {
            $method->invoke($service, 'https://example.test/CT2024-v1.0.0.zip');
            $h->assertTrue(false);
        } catch (RuntimeException $exception) {
            $h->assertTrue(str_contains($exception->getMessage(), 'not trusted'));
        } finally {
            ct2024DownloadTestRemoveDirectory($service->cacheDirectory());
        }
        $h->assertSame(0, $fetchCalls);

        $redirect = new ReflectionMethod($service, 'resolveRedirectUrl');
        try {
            $redirect->invoke(
                $service,
                HmrcCtComputationCatalogueService::CT2024_DOWNLOAD_URL,
                'https://example.test/untrusted.zip'
            );
            $h->assertTrue(false);
        } catch (RuntimeException $exception) {
            $h->assertTrue(str_contains($exception->getMessage(), 'untrusted'));
        }
        $h->assertSame(
            'https://assets.publishing.service.gov.uk/ct/CT2024-v1.0.0.zip',
            $redirect->invoke(
                $service,
                HmrcCtComputationCatalogueService::CT2024_DOWNLOAD_URL,
                'https://assets.publishing.service.gov.uk/ct/CT2024-v1.0.0.zip'
            )
        );
    });
});

function ct2024DownloadTestDirectory(string $suffix): string
{
    $directory = test_tmp_directory() . DIRECTORY_SEPARATOR . 'ct2024-download-' . $suffix . '-' . bin2hex(random_bytes(4));
    if (!is_dir(dirname($directory)) && !mkdir(dirname($directory), 0775, true) && !is_dir(dirname($directory))) {
        throw new RuntimeException('Unable to create the CT2024 test parent directory.');
    }
    return $directory;
}

function ct2024DownloadTestArchive(string $taxonomyYear): string
{
    $prefix = 'CT' . $taxonomyYear . '-v1.0.0/';
    $schemaName = 'ct-comp-' . $taxonomyYear . '.xsd';
    $schemaPath = 'www.hmrc.gov.uk/schemas/ct/comp/' . $taxonomyYear . '-01-01/' . $schemaName;
    $manifest = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<taxonomyPackage xmlns="http://xbrl.org/2016/taxonomy-package">'
        . '<identifier>http://www.hmrc.gov.uk/schemas/ct/comp/' . $taxonomyYear . '-01-01/v1.0.0/</identifier>'
        . '<name>CT ' . $taxonomyYear . ' Computations Taxonomy</name><version>1.0.0</version>'
        . '<entryPoints><entryPoint><entryPointDocument href="http://www.hmrc.gov.uk/schemas/ct/comp/'
        . $taxonomyYear . '-01-01/' . $schemaName . '"/></entryPoint></entryPoints></taxonomyPackage>';
    return ct2024DownloadTestZip([
        $prefix . 'META-INF/taxonomyPackage.xml' => $manifest,
        $prefix . $schemaPath => '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" targetNamespace="http://www.hmrc.gov.uk/ct"/>',
    ]);
}

/** @param array<string, string> $files */
function ct2024DownloadTestZip(array $files): string
{
    $local = '';
    $central = '';
    $offset = 0;
    $dosTime = 0;
    $dosDate = (1 << 5) | 1;
    foreach ($files as $name => $contents) {
        $name = str_replace('\\', '/', $name);
        $crc = (int)sprintf('%u', crc32($contents));
        $size = strlen($contents);
        $nameLength = strlen($name);
        $localHeader = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, $nameLength, 0) . $name;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, $nameLength, 0, 0, 0, 0, 0, $offset) . $name;
        $local .= $localHeader . $contents;
        $offset += strlen($localHeader) + $size;
    }
    return $local . $central
        . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), strlen($local), 0);
}

function ct2024DownloadTestRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink() || $entry->isFile()) {
            @unlink($entry->getPathname());
        } elseif ($entry->isDir()) {
            @rmdir($entry->getPathname());
        }
    }
    @rmdir($directory);
}
