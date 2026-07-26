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
        $writePackage = static function (string $path): void {
            $root = 'FRC-2026-Taxonomy-v1.0.0/';
            $files = [
                $root . 'META-INF/taxonomyPackage.xml' =>
                '<?xml version="1.0" encoding="UTF-8"?>'
                . '<taxonomyPackage xmlns="http://xbrl.org/2016/taxonomy-package">'
                . '<identifier>https://xbrl.frc.org.uk/fr/2026-01-01/v1-0-0</identifier>'
                . '<version>1.0.0</version><entryPoints><entryPoint><name>FRS-102</name>'
                . '<entryPointDocument href="' . \eel_accounts\Service\IxbrlTaxonomyProfileService::SCHEMA_REF . '"/>'
                . '</entryPoint></entryPoints></taxonomyPackage>',
                $root . 'FRS-102/2026-01-01/FRS-102-2026-01-01.xsd' =>
                '<?xml version="1.0" encoding="UTF-8"?>'
                . '<schema xmlns="http://www.w3.org/2001/XMLSchema" '
                . 'targetNamespace="http://xbrl.frc.org.uk/FRS-102/2026-01-01"/>',
            ];
            $local = '';
            $central = '';
            foreach ($files as $name => $content) {
                $offset = strlen($local);
                $size = strlen($content);
                $crc = (int)hexdec(hash('crc32b', $content));
                $nameLength = strlen($name);
                $local .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 33, $crc, $size, $size, $nameLength, 0)
                    . $name . $content;
                $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 33, $crc, $size, $size, $nameLength, 0, 0, 0, 0, 0, $offset)
                    . $name;
            }
            $zip = $local . $central
                . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), strlen($local), 0);
            if (file_put_contents($path, $zip) === false) {
                throw new RuntimeException('Could not create the taxonomy-package test fixture.');
            }
        };

        $harness->check(\eel_accounts\Service\FrcTaxonomyPackageService::class, 'returns safe empty values before the taxonomy table is installed', static function () use ($harness, $service): void {
            \InterfaceDB::execute('DROP TABLE IF EXISTS frc_taxonomy_packages');

            $harness->assertSame([], $service->fetchPackages());
            $harness->assertSame(null, $service->activePackage());
        });

        $harness->check(\eel_accounts\Service\FrcTaxonomyPackageService::class, 'returns only a verified active package with an intact archive', static function () use ($harness, $service, $writePackage): void {
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
            $writePackage($archivePath);
            $hash = hash_file('sha256', $archivePath);
            $harness->assertTrue(is_string($hash));
            $trustedFixtureService = new \eel_accounts\Service\FrcTaxonomyPackageService(
                new \eel_accounts\Service\IxbrlTaxonomyCompatibilityService(
                    [],
                    (string)$hash
                )
            );

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

                $packages = $trustedFixtureService->fetchPackages();
                $harness->assertTrue($packages !== []);
                $active = $trustedFixtureService->activePackage();
                $harness->assertSame('2099', (string)($active['taxonomy_version'] ?? ''));
                $harness->assertSame(strtolower((string)$hash), (string)($active['sha256'] ?? ''));

                \InterfaceDB::prepareExecute(
                    "UPDATE frc_taxonomy_packages SET sha256 = :sha256 WHERE taxonomy_version = '2099'",
                    ['sha256' => str_repeat('0', 64)]
                );
                $harness->assertSame(null, $trustedFixtureService->activePackage());
            } finally {
                \InterfaceDB::execute("DELETE FROM frc_taxonomy_packages WHERE taxonomy_version = '2099'");
                if (is_file($archivePath)) {
                    unlink($archivePath);
                }
            }
        });
    }
);
