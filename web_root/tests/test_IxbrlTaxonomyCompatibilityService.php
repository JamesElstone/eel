<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlTaxonomyCompatibilityService::class,
    static function (
        GeneratedServiceClassTestHarness $h,
        \eel_accounts\Service\IxbrlTaxonomyCompatibilityService $service
    ): void {
        $writePackage = static function (string $path): void {
            $root = 'FRC-2026-Taxonomy-v1.0.0/';
            $files = [
                $root . 'META-INF/taxonomyPackage.xml' =>
                '<?xml version="1.0" encoding="UTF-8"?>'
                . '<taxonomyPackage xmlns="http://xbrl.org/2016/taxonomy-package" xml:lang="en">'
                . '<identifier>https://xbrl.frc.org.uk/fr/2026-01-01/v1-0-0</identifier>'
                . '<name>FRC 2026 Taxonomy</name><version>1.0.0</version><entryPoints><entryPoint>'
                . '<name>FRS-102</name><entryPointDocument href="'
                . \eel_accounts\Service\IxbrlTaxonomyProfileService::SCHEMA_REF
                . '"/></entryPoint></entryPoints></taxonomyPackage>',
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

        $h->check($service::class, 'records the exact official 2026 FRS-102 profile and evidence sources', static function () use ($h, $service): void {
            $policy = $service->policy();
            $h->assertSame('2026', (string)($policy['taxonomy_version'] ?? ''));
            $h->assertSame('FRS-102', (string)($policy['entry_point_name'] ?? ''));
            $h->assertSame(
                \eel_accounts\Service\IxbrlTaxonomyProfileService::SCHEMA_REF,
                (string)($policy['schema_ref'] ?? '')
            );
            $h->assertSame('FRS_105', (string)($policy['accounting_standard'] ?? ''));
            $h->assertSame('2015-04-01', (string)($policy['reporting_period_start_from'] ?? ''));
            $h->assertSame('2026-04-01', (string)($policy['companies_house_gateway_available_from'] ?? ''));
            foreach ($service->evidence() as $url) {
                $h->assertTrue(str_starts_with($url, 'https://'));
            }
        });

        $h->check($service::class, 'accepts the Elstone 2022 to 2023 period for a July 2026 filing under published policy', static function () use ($h, $service): void {
            $assessment = $service->assess(
                'FRS_105',
                '2022-09-05',
                '2023-09-30',
                '2026-07-26'
            );
            $h->assertSame(true, (bool)($assessment['compatible'] ?? false));
            $h->assertSame(true, (bool)($assessment['reporting_compatible'] ?? false));
            $h->assertSame(true, (bool)($assessment['gateway_date_compatible'] ?? false));
            $h->assertSame(false, (bool)($assessment['gateway_response_confirmed'] ?? true));
            $h->assertSame([], (array)($assessment['errors'] ?? []));
        });

        $h->check($service::class, 'fails closed outside the collector date windows or with the wrong profile', static function () use ($h, $service): void {
            $oldPeriod = $service->assess('FRS_105', '2015-03-31', '2016-03-30', '2026-07-26');
            $h->assertSame(false, (bool)($oldPeriod['compatible'] ?? true));
            $h->assertTrue(str_contains(implode(' ', (array)$oldPeriod['errors']), '2015-04-01'));

            $earlyGateway = $service->assess('FRS_105', '2022-09-05', '2023-09-30', '2026-03-31');
            $h->assertSame(false, (bool)($earlyGateway['compatible'] ?? true));
            $h->assertTrue(str_contains(implode(' ', (array)$earlyGateway['errors']), '2026-04-01'));

            $wrongProfile = $service->assess(
                'FRS_102',
                '2022-09-05',
                '2023-09-30',
                '2026-07-26',
                'https://example.test/wrong.xsd'
            );
            $h->assertSame(false, (bool)($wrongProfile['reporting_compatible'] ?? true));
            $h->assertTrue(count((array)$wrongProfile['errors']) >= 2);
        });

        $h->check($service::class, 'supports explicit collector-policy overrides without claiming a Gateway response', static function () use ($h): void {
            $configured = new \eel_accounts\Service\IxbrlTaxonomyCompatibilityService([
                'companies_house_gateway_available_from' => '2027-01-01',
            ]);
            $assessment = $configured->assess(
                'FRS_105',
                '2022-09-05',
                '2023-09-30',
                '2026-07-26'
            );
            $h->assertSame(false, (bool)($assessment['compatible'] ?? true));
            $h->assertSame(false, (bool)($assessment['gateway_response_confirmed'] ?? true));
            $h->assertTrue(str_contains(implode(' ', (array)$assessment['errors']), '2027-01-01'));
        });

        $h->check($service::class, 'pins the official archive digest before accepting its package identity and FRS-102 entry point', static function () use ($h, $service, $writePackage): void {
            $path = test_tmp_directory() . DIRECTORY_SEPARATOR . 'frc-2026-compatibility-test.zip';
            $writePackage($path);
            try {
                $untrusted = $service->inspectPackage($path);
                $h->assertSame(false, (bool)($untrusted['compatible'] ?? true));
                $h->assertTrue(str_contains(
                    implode(' ', (array)($untrusted['errors'] ?? [])),
                    'trusted official'
                ));

                $fixtureHash = hash_file('sha256', $path);
                $h->assertTrue(is_string($fixtureHash));
                $parserService = new \eel_accounts\Service\IxbrlTaxonomyCompatibilityService(
                    [],
                    (string)$fixtureHash
                );
                $inspection = $parserService->inspectPackage($path);
                $h->assertSame(true, (bool)($inspection['compatible'] ?? false));
                $h->assertSame(
                    'https://xbrl.frc.org.uk/fr/2026-01-01/v1-0-0',
                    (string)($inspection['package_identifier'] ?? '')
                );
                $h->assertSame('1.0.0', (string)($inspection['package_version'] ?? ''));
                $h->assertSame(
                    \eel_accounts\Service\IxbrlTaxonomyProfileService::SCHEMA_REF,
                    (string)($inspection['schema_ref'] ?? '')
                );
                $h->assertTrue(preg_match('/^[a-f0-9]{64}$/D', (string)($inspection['sha256'] ?? '')) === 1);
                $h->assertSame(
                    strtolower((string)$fixtureHash),
                    (string)($inspection['trusted_sha256'] ?? '')
                );

                $mismatchedPolicy = new \eel_accounts\Service\IxbrlTaxonomyCompatibilityService([
                    'package_version' => '9.9.9',
                ], (string)$fixtureHash);
                $mismatch = $mismatchedPolicy->inspectPackage($path);
                $h->assertSame(false, (bool)($mismatch['compatible'] ?? true));
                $h->assertTrue(str_contains(implode(' ', (array)$mismatch['errors']), 'package version'));
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        });
    }
);
