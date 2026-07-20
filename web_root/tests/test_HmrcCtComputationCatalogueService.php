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
