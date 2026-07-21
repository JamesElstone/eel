<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\FrcTaxonomyPackageService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\FrcTaxonomyPackageService $service): void {
        $harness->check(\eel_accounts\Service\FrcTaxonomyPackageService::class, 'returns safe empty values before the taxonomy table is installed', static function () use ($harness, $service): void {
            \InterfaceDB::execute('DROP TABLE IF EXISTS frc_taxonomy_packages');

            $harness->assertSame([], $service->fetchPackages());
            $harness->assertSame(null, $service->activePackage());
        });

        $harness->check(\eel_accounts\Service\FrcTaxonomyPackageService::class, 'returns only a verified active package with an intact archive', static function () use ($harness, $service): void {
            \InterfaceDB::execute(
                "CREATE TABLE IF NOT EXISTS frc_taxonomy_packages (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    taxonomy_version TEXT NOT NULL,
                    artifact_version TEXT NOT NULL,
                    source_url TEXT NOT NULL,
                    download_url TEXT,
                    local_path TEXT,
                    sha256 TEXT,
                    package_state TEXT NOT NULL,
                    is_active INTEGER NOT NULL DEFAULT 0,
                    published_at TEXT,
                    verified_at TEXT
                )"
            );
            \InterfaceDB::execute("DELETE FROM frc_taxonomy_packages WHERE taxonomy_version = '2099'");

            $archivePath = test_tmp_directory() . DIRECTORY_SEPARATOR . 'frc-taxonomy-service-test.zip';
            if (!is_dir(dirname($archivePath))) {
                mkdir(dirname($archivePath), 0775, true);
            }
            file_put_contents($archivePath, 'verified FRC taxonomy fixture');
            $hash = hash_file('sha256', $archivePath);

            try {
                \InterfaceDB::prepareExecute(
                    "INSERT INTO frc_taxonomy_packages
                    (taxonomy_version, artifact_version, source_url, download_url, local_path, sha256, package_state, is_active, published_at, verified_at)
                    VALUES ('2099', 'v1.0.0', :source_url, :download_url, :local_path, :sha256, 'verified', 1, '2099-01-01', '2099-01-01 00:00:00')",
                    [
                        'source_url' => \eel_accounts\Service\FrcTaxonomyPackageService::SOURCE_URL,
                        'download_url' => 'https://www.frc.org.uk/taxonomy.zip',
                        'local_path' => $archivePath,
                        'sha256' => $hash,
                    ]
                );

                $packages = $service->fetchPackages();
                $harness->assertTrue($packages !== []);
                $active = $service->activePackage();
                $harness->assertSame('2099', (string)($active['taxonomy_version'] ?? ''));
                $harness->assertSame(strtolower((string)$hash), (string)($active['sha256'] ?? ''));

                \InterfaceDB::prepareExecute(
                    "UPDATE frc_taxonomy_packages SET sha256 = :sha256 WHERE taxonomy_version = '2099'",
                    ['sha256' => str_repeat('0', 64)]
                );
                $harness->assertSame(null, $service->activePackage());
            } finally {
                \InterfaceDB::execute("DELETE FROM frc_taxonomy_packages WHERE taxonomy_version = '2099'");
                if (is_file($archivePath)) {
                    unlink($archivePath);
                }
            }
        });
    }
);
