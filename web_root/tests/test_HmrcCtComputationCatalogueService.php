<?php
declare(strict_types=1);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
(new GeneratedServiceClassTestHarness())->run(\eel_accounts\Service\HmrcCtComputationCatalogueService::class, static function (GeneratedServiceClassTestHarness $h, \eel_accounts\Service\HmrcCtComputationCatalogueService $service): void {
    $h->check($service::class, 'rejects a missing taxonomy directory', static function () use ($h, $service): void { try { $service->catalogueDirectory(1, '__missing__'); $h->assertTrue(false); } catch (InvalidArgumentException) { $h->assertTrue(true); } });
    $h->check($service::class, 'reads a standard taxonomy-package manifest and resolves its entry point', static function () use ($h, $service): void {
        $root = test_tmp_directory() . DIRECTORY_SEPARATOR . 'ct-2025-taxonomy-package';
        $meta = $root . DIRECTORY_SEPARATOR . 'META-INF';
        $schemaDirectory = $root . DIRECTORY_SEPARATOR . 'www.hmrc.gov.uk' . DIRECTORY_SEPARATOR . 'schemas'
            . DIRECTORY_SEPARATOR . 'ct' . DIRECTORY_SEPARATOR . 'comp' . DIRECTORY_SEPARATOR . '2025-01-01';
        foreach ([$meta, $schemaDirectory] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create a taxonomy fixture directory.');
            }
        }
        file_put_contents($meta . DIRECTORY_SEPARATOR . 'taxonomyPackage.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<tp:taxonomyPackage xmlns:tp="http://xbrl.org/2016/taxonomy-package" xmlns:xlink="http://www.w3.org/1999/xlink">
  <tp:identifier>http://www.hmrc.gov.uk/schemas/ct/comp/2025-01-01/v1.0.0/</tp:identifier>
  <tp:name>CT 2025 Computations Taxonomy</tp:name>
  <tp:version>1.0.0</tp:version>
  <tp:entryPoints><tp:entryPoint><tp:entryPointDocument href="http://www.hmrc.gov.uk/schemas/ct/comp/2025-01-01/ct-comp-2025.xsd"/></tp:entryPoint></tp:entryPoints>
</tp:taxonomyPackage>
XML);
        $entryPoint = $schemaDirectory . DIRECTORY_SEPARATOR . 'ct-comp-2025.xsd';
        file_put_contents($entryPoint, '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"/>');
        $manifest = $service->inspectDirectory($root);
        $h->assertSame(true, $manifest['has_manifest']);
        $h->assertSame('2025', $manifest['taxonomy_version']);
        $h->assertSame('V1.0.0', $manifest['artifact_version']);
        $h->assertSame(realpath($entryPoint), $manifest['entry_point_path']);
    });
    $h->check($service::class, 'recognises the official CT 2024 v1.0.0 manifest identity and entry point layout', static function () use ($h, $service): void {
        $root = test_tmp_directory() . DIRECTORY_SEPARATOR . 'ct-2024-taxonomy-package';
        $meta = $root . DIRECTORY_SEPARATOR . 'META-INF';
        $schemaDirectory = $root . DIRECTORY_SEPARATOR . 'www.hmrc.gov.uk' . DIRECTORY_SEPARATOR . 'schemas'
            . DIRECTORY_SEPARATOR . 'ct' . DIRECTORY_SEPARATOR . 'comp' . DIRECTORY_SEPARATOR . '2024-01-01';
        foreach ([$meta, $schemaDirectory] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create a taxonomy fixture directory.');
            }
        }
        file_put_contents($meta . DIRECTORY_SEPARATOR . 'taxonomyPackage.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<taxonomyPackage xmlns="http://xbrl.org/2016/taxonomy-package" xml:lang="en">
  <identifier>http://www.hmrc.gov.uk/schemas/ct/comp/2024-01-01/v1.0.0/</identifier>
  <name>CT 2024 Computations Taxonomy</name>
  <version>1.0.0</version>
  <entryPoints><entryPoint><entryPointDocument href="http://www.hmrc.gov.uk/schemas/ct/comp/2024-01-01/ct-comp-2024.xsd"/></entryPoint></entryPoints>
</taxonomyPackage>
XML);
        $entryPoint = $schemaDirectory . DIRECTORY_SEPARATOR . 'ct-comp-2024.xsd';
        file_put_contents($entryPoint, '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"/>');
        $manifest = $service->inspectDirectory($root);
        $h->assertSame(true, $manifest['has_manifest']);
        $h->assertSame('2024', $manifest['taxonomy_version']);
        $h->assertSame('V1.0.0', $manifest['artifact_version']);
        $h->assertSame(realpath($entryPoint), $manifest['entry_point_path']);
        $h->assertSame(
            'https://www.hmrc.gov.uk/softwaredevelopers/ct/CT2024-v1.0.0.zip',
            \eel_accounts\Service\HmrcCtComputationCatalogueService::CT2024_DOWNLOAD_URL
        );
    });
    $h->check($service::class, 'returns only manifest and archive resources matching the verified inventory', static function () use ($h, $service): void {
        if (!InterfaceDB::tableExists('hmrc_ct_computation_packages') || !InterfaceDB::tableExists('hmrc_ct_computation_files')) {
            $h->assertTrue(true);
            return;
        }
        $year = '2098';
        $root = test_tmp_directory() . DIRECTORY_SEPARATOR . 'CT' . $year . '-v1.0.0';
        $meta = $root . DIRECTORY_SEPARATOR . 'META-INF';
        $schemaDirectory = $root . DIRECTORY_SEPARATOR . 'www.hmrc.gov.uk' . DIRECTORY_SEPARATOR . 'schemas'
            . DIRECTORY_SEPARATOR . 'ct' . DIRECTORY_SEPARATOR . 'comp' . DIRECTORY_SEPARATOR . $year . '-01-01';
        foreach ([$meta, $schemaDirectory] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create a validation-resource fixture.');
            }
        }
        $schemaRef = 'http://www.hmrc.gov.uk/schemas/ct/comp/' . $year . '-01-01/ct-comp-' . $year . '.xsd';
        $manifest = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<taxonomyPackage xmlns="http://xbrl.org/2016/taxonomy-package">'
            . '<identifier>http://www.hmrc.gov.uk/schemas/ct/comp/' . $year . '-01-01/v1.0.0/</identifier>'
            . '<name>CT ' . $year . ' Computations Taxonomy</name><version>1.0.0</version>'
            . '<entryPoints><entryPoint><entryPointDocument href="' . $schemaRef . '"/></entryPoint></entryPoints>'
            . '</taxonomyPackage>';
        $schema = '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"/>';
        $manifestPath = $meta . DIRECTORY_SEPARATOR . 'taxonomyPackage.xml';
        $schemaPath = $schemaDirectory . DIRECTORY_SEPARATOR . 'ct-comp-' . $year . '.xsd';
        file_put_contents($manifestPath, $manifest);
        file_put_contents($schemaPath, $schema);
        $files = [
            ['archive_path' => 'META-INF/taxonomyPackage.xml', 'extracted_path' => $manifestPath, 'file_size' => strlen($manifest), 'sha256' => hash('sha256', $manifest)],
            ['archive_path' => 'www.hmrc.gov.uk/schemas/ct/comp/' . $year . '-01-01/ct-comp-' . $year . '.xsd', 'extracted_path' => $schemaPath, 'file_size' => strlen($schema), 'sha256' => hash('sha256', $schema)],
        ];
        usort($files, static fn(array $left, array $right): int => $left['archive_path'] <=> $right['archive_path']);
        $inventoryHash = hash('sha256', (string)json_encode(array_map(
            static fn(array $file): array => ['archive_path' => $file['archive_path'], 'file_size' => $file['file_size'], 'sha256' => $file['sha256']],
            $files
        ), JSON_UNESCAPED_SLASHES));
        $archivePath = dirname($root) . DIRECTORY_SEPARATOR . basename($root) . '.zip';
        file_put_contents($archivePath, hmrcCtCatalogueTestZip([
            basename($root) . '/META-INF/taxonomyPackage.xml' => $manifest,
            basename($root) . '/www.hmrc.gov.uk/schemas/ct/comp/' . $year . '-01-01/ct-comp-' . $year . '.xsd' => $schema,
        ]));

        InterfaceDB::beginTransaction();
        try {
            InterfaceDB::prepareExecute(
                'INSERT INTO hmrc_ct_computation_packages
                 (taxonomy_version, artifact_version, applicable_from, source_url, local_path, entry_point_path, sha256, package_state)
                 VALUES (:taxonomy, :artifact, :applicable, :source, :local_path, :entry_point, :sha256, :state)',
                ['taxonomy' => $year, 'artifact' => 'V1.0.0', 'applicable' => '2015-04-01', 'source' => 'https://www.gov.uk/', 'local_path' => $root, 'entry_point' => $schemaPath, 'sha256' => $inventoryHash, 'state' => 'verified']
            );
            $package = InterfaceDB::fetchOne('SELECT * FROM hmrc_ct_computation_packages WHERE taxonomy_version = :taxonomy AND artifact_version = :artifact', ['taxonomy' => $year, 'artifact' => 'V1.0.0']);
            foreach ($files as $file) {
                InterfaceDB::prepareExecute(
                    'INSERT INTO hmrc_ct_computation_files
                     (package_id, archive_path, extracted_path, file_type, file_size, sha256)
                     VALUES (:package_id, :archive_path, :extracted_path, :file_type, :file_size, :sha256)',
                    ['package_id' => (int)$package['id'], 'file_type' => str_ends_with($file['archive_path'], '.xsd') ? 'xsd' : 'xml'] + $file
                );
            }
            $resources = $service->validationResources((array)$package);
            $h->assertSame($schemaRef, $resources['schema_ref']);
            $h->assertSame(realpath($archivePath), $resources['package_archive']);

            file_put_contents($archivePath, hmrcCtCatalogueTestZip([
                basename($root) . '/META-INF/taxonomyPackage.xml' => $manifest,
                basename($root) . '/www.hmrc.gov.uk/schemas/ct/comp/' . $year . '-01-01/ct-comp-' . $year . '.xsd' => '<changed/>',
            ]));
            try {
                $service->validationResources((array)$package);
                $h->assertTrue(false);
            } catch (RuntimeException $exception) {
                $h->assertTrue(str_contains($exception->getMessage(), 'does not match'));
            }
            @unlink($archivePath);
            try {
                $service->validationResources((array)$package);
                $h->assertTrue(false);
            } catch (RuntimeException $exception) {
                $h->assertTrue(str_contains($exception->getMessage(), 'ZIP is missing'));
            }
        } finally {
            InterfaceDB::rollBack();
            @unlink($archivePath);
        }
    });
    $h->check($service::class, 'requires both CT taxonomy accounting-period boundaries to be in range', static function () use ($h, $service): void {
        $createdTable = !InterfaceDB::tableExists('hmrc_ct_computation_packages');
        if ($createdTable) {
            InterfaceDB::prepareExecute(
                'CREATE TABLE hmrc_ct_computation_packages (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    taxonomy_version TEXT NOT NULL,
                    artifact_version TEXT NOT NULL,
                    applicable_from TEXT NOT NULL,
                    applicable_to TEXT NULL,
                    entry_point_path TEXT NULL,
                    combined_dpl_entry_point_path TEXT NULL,
                    package_state TEXT NOT NULL
                )'
            );
        }
        $entryPoint = test_tmp_directory() . DIRECTORY_SEPARATOR . 'ct-2024-resolution-entry-point.xsd';
        file_put_contents($entryPoint, '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"/>');
        InterfaceDB::prepareExecute(
            'INSERT INTO hmrc_ct_computation_packages
             (taxonomy_version, artifact_version, applicable_from, applicable_to, entry_point_path, package_state)
             VALUES (:taxonomy, :artifact, :applicable_from, :applicable_to, :entry_point, :state)',
            [
                'taxonomy' => '2024-test-boundaries',
                'artifact' => 'V1.0.0',
                'applicable_from' => '2015-04-01',
                'applicable_to' => '2026-03-31',
                'entry_point' => $entryPoint,
                'state' => 'verified',
            ]
        );
        $id = (int)InterfaceDB::fetchColumn('SELECT last_insert_rowid()');
        try {
            $inside = $service->resolveForPeriod('2015-04-01', '2026-03-31');
            $h->assertSame($id, (int)($inside['id'] ?? 0));
            $h->assertSame(null, $service->resolveForPeriod('2015-03-31', '2026-03-31'));
            $h->assertSame(null, $service->resolveForPeriod('2015-04-01', '2026-04-01'));
        } finally {
            InterfaceDB::prepareExecute('DELETE FROM hmrc_ct_computation_packages WHERE id = :id', ['id' => $id]);
            if ($createdTable) {
                InterfaceDB::prepareExecute('DROP TABLE hmrc_ct_computation_packages');
            }
        }
    });
});

/** @param array<string, string> $files */
function hmrcCtCatalogueTestZip(array $files): string
{
    $local = '';
    $central = '';
    $offset = 0;
    foreach ($files as $name => $contents) {
        $name = str_replace('\\', '/', $name);
        $crc = (int)sprintf('%u', crc32($contents));
        $size = strlen($contents);
        $nameLength = strlen($name);
        $localHeader = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 33, $crc, $size, $size, $nameLength, 0) . $name;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 33, $crc, $size, $size, $nameLength, 0, 0, 0, 0, 0, $offset) . $name;
        $local .= $localHeader . $contents;
        $offset += strlen($localHeader) + $size;
    }
    return $local . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), strlen($local), 0);
}
