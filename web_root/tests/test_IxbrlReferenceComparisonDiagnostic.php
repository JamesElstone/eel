<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support'
    . DIRECTORY_SEPARATOR . 'ixbrl'
    . DIRECTORY_SEPARATOR . 'ReferenceComparisonDiagnostic.php';

use eel_accounts\Tests\Support\Ixbrl\ReferenceComparisonDiagnostic;

(new GeneratedServiceClassTestHarness())->run(
    ReferenceComparisonDiagnostic::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $fixtureDirectory = test_tmp_directory()
            . DIRECTORY_SEPARATOR . 'ixbrl'
            . DIRECTORY_SEPARATOR . 'references';
        $goldenPath = $fixtureDirectory
            . DIRECTORY_SEPARATOR . 'companies-house-reference.xhtml';
        $eelPath = $fixtureDirectory
            . DIRECTORY_SEPARATOR . 'eel-reference-original.xhtml';
        $laterEelPath = $fixtureDirectory
            . DIRECTORY_SEPARATOR . 'eel-reference-later.xhtml';
        if (!is_file($goldenPath)
            || !is_file($eelPath)
            || !is_file($laterEelPath)) {
            $harness->skip(
                'Private iXBRL references are not installed below the configured test temporary directory.'
            );
        }

        $harness->check(
            ReferenceComparisonDiagnostic::class,
            'reports the expected structural differences between the immutable references',
            static function () use ($harness, $goldenPath, $eelPath): void {
                $diagnostic = new ReferenceComparisonDiagnostic();
                $comparison = $diagnostic->compare([$goldenPath, $eelPath]);
                $documents = (array)($comparison['documents'] ?? []);

                $harness->assertSame(2, count($documents));
                $golden = (array)$documents[0];
                $eel = (array)$documents[1];

                $harness->assertSame(
                    '1c75c1d7066b810d78658b68f3bf16e7e14d9b1668be7b92c566b439d621a915',
                    (string)$golden['sha256']
                );
                $harness->assertSame(
                    'd6378e7bf3ef31ce2cd08d1da58a2edc80cf04cd4f350f89183b384c033353a8',
                    (string)$eel['sha256']
                );
                $harness->assertSame(
                    ['https://xbrl.frc.org.uk/FRS-102/2023-01-01/FRS-102-2023-01-01.xsd'],
                    $golden['taxonomy_entry_points']
                );
                $harness->assertSame(
                    ['https://xbrl.frc.org.uk/FRS-102/2026-01-01/FRS-102-2026-01-01.xsd'],
                    $eel['taxonomy_entry_points']
                );
                $harness->assertSame(32, (int)$golden['fact_count']);
                $harness->assertSame(55, (int)$eel['fact_count']);
                $harness->assertSame(10, (int)$golden['context_count']);
                $harness->assertSame(14, (int)$eel['context_count']);
                $harness->assertSame(1, (int)$golden['unit_count']);
                $harness->assertSame(2, (int)$eel['unit_count']);
                $harness->assertSame(true, (bool)$golden['accounts_type']['fact_present']);
                $harness->assertSame(true, (bool)$golden['accounts_type']['dimension_present']);
                $harness->assertSame(false, (bool)$eel['accounts_type']['fact_present']);
                $harness->assertSame(false, (bool)$eel['accounts_type']['dimension_present']);
                $harness->assertSame(
                    [
                        'accountspage' => 3,
                        'titlepage' => 1,
                        'pagebreak' => 2,
                        'keepTogether' => 1,
                    ],
                    $golden['page_wrapper_counts']
                );
                $harness->assertSame(
                    [
                        'accountspage' => 0,
                        'titlepage' => 0,
                        'pagebreak' => 0,
                        'keepTogether' => 0,
                    ],
                    $eel['page_wrapper_counts']
                );
                $harness->assertTrue(
                    in_array(
                        'Micro-entity Balance Sheet as at 30 September 2023',
                        (array)$golden['visible_statement_headings'],
                        true
                    )
                );
                $harness->assertTrue(
                    in_array('Profit and loss account', (array)$eel['visible_statement_headings'], true)
                );
                $harness->assertSame(
                    'http://xbrl.frc.org.uk/fr/2023-01-01/core',
                    (string)$golden['root_namespaces']['uk-core']
                );
                $harness->assertSame(
                    'http://xbrl.frc.org.uk/fr/2026-01-01/core',
                    (string)$eel['root_namespaces']['core']
                );
            }
        );

        $harness->check(
            ReferenceComparisonDiagnostic::class,
            'retains both supplied EEL snapshots with evidence identifiers as their only difference',
            static function () use ($harness, $eelPath, $laterEelPath): void {
                $earlier = file_get_contents($eelPath);
                $later = file_get_contents($laterEelPath);
                $harness->assertTrue(is_string($earlier));
                $harness->assertTrue(is_string($later));

                $pattern = '/EEL-AR-(?:[A-F0-9]{4}-){7}[A-F0-9]{4}/i';
                $earlierWithoutIds = preg_replace($pattern, 'EEL-AR-REDACTED', (string)$earlier);
                $laterWithoutIds = preg_replace($pattern, 'EEL-AR-REDACTED', (string)$later);

                $harness->assertSame($earlierWithoutIds, $laterWithoutIds);
            }
        );

        $harness->check(
            ReferenceComparisonDiagnostic::class,
            'encodes a stable readable JSON report',
            static function () use ($harness, $goldenPath, $eelPath): void {
                $diagnostic = new ReferenceComparisonDiagnostic();
                $json = $diagnostic->encode($diagnostic->compare([$goldenPath, $eelPath]));
                $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

                $harness->assertSame(2, count((array)$decoded['documents']));
                $harness->assertTrue(str_contains($json, '"taxonomy_entry_points"'));
                $harness->assertTrue(str_contains($json, '"visible_statement_headings"'));
                $harness->assertTrue(str_ends_with($json, PHP_EOL));
            }
        );
    }
);
