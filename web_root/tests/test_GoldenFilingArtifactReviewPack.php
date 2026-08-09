<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GoldenFilingArtifactReviewPack.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GoldenFilingArtifactDependencyFixture.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->check('GoldenFilingArtifactReviewPack', 'recognises only the explicit export flag', static function () use ($harness): void {
    $harness->assertTrue(GoldenFilingArtifactReviewPack::requested(['runner.php', '--export-filing-artifacts']));
    $harness->assertFalse(GoldenFilingArtifactReviewPack::requested(['runner.php']));
    $harness->assertFalse(GoldenFilingArtifactReviewPack::requested(['runner.php', '--export-filing-artifact']));
});

$harness->check('GoldenFilingArtifactReviewPack', 'installs verified filing dependencies only inside the SQLite test transaction', static function () use ($harness): void {
    InterfaceDB::beginTransaction();
    foreach ([
        'ct600_rim_mappings', 'ct_computation_ixbrl_mappings', 'ct_filing_mapping_events',
        'ct_filing_mapping_profiles', 'ct_filing_canonical_sources', 'hmrc_ct_rim_components',
        'hmrc_ct_computation_concepts', 'hmrc_ct_computation_files', 'hmrc_ct_computation_packages',
    ] as $table) {
        InterfaceDB::execute('DROP TABLE IF EXISTS ' . $table);
    }
    InterfaceDB::execute('CREATE TABLE ct_filing_mapping_profiles (
        id INTEGER PRIMARY KEY AUTOINCREMENT, target_type TEXT NOT NULL,
        profile_name TEXT NOT NULL, status TEXT NOT NULL,
        compatibility_report_json TEXT NULL
    )');
    $dependencies = GoldenFilingArtifactDependencyFixture::ensure();
    $harness->assertTrue((int)$dependencies['computation_package_id'] > 0);
    $harness->assertTrue((int)$dependencies['rim_package_id'] > 0);
    $harness->assertSame(
        2,
        (int)InterfaceDB::fetchColumn(
            "SELECT COUNT(*) FROM ct_filing_mapping_profiles WHERE status = 'active'"
        )
    );
    $profileColumns = array_map(
        static fn(array $column): string => (string)$column['name'],
        InterfaceDB::fetchAll('PRAGMA table_info(ct_filing_mapping_profiles)')
    );
    foreach (['parent_profile_id', 'compatibility_json', 'validated_by', 'activated_by', 'retired_by'] as $column) {
        $harness->assertTrue(in_array($column, $profileColumns, true));
    }
});

$harness->check('GoldenFilingArtifactReviewPack', 'keeps the master schema aligned with filing and charity migrations', static function () use ($harness): void {
    $master = (string)file_get_contents(
        dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'eel_accounts.schema.sql'
    );
    foreach ([
        'hmrc_ct_computation_packages', 'hmrc_ct_computation_files', 'hmrc_ct_computation_concepts',
        'hmrc_ct_rim_components', 'ct_filing_mapping_profiles', 'ct600_rim_mappings',
        'ct_computation_ixbrl_mappings', 'ct_filing_mapping_events', 'ct_filing_canonical_sources',
        'ixbrl_accounts_artifacts', 'ixbrl_validation_runs', 'hmrc_ct_filing_approvals',
        'hmrc_ct_filing_approval_period_bases', 'transaction_charitable_donation_verifications',
    ] as $table) {
        $harness->assertTrue(str_contains($master, 'CREATE TABLE `' . $table . '`'));
    }
    foreach ([
        '`parent_profile_id` bigint(20) DEFAULT NULL',
        '`compatibility_json` longtext DEFAULT NULL',
        '`directors_report_exempt_section_415a` tinyint(1) NOT NULL DEFAULT 1',
        '`profit_loss_not_delivered_section_444` tinyint(1) NOT NULL DEFAULT 1',
    ] as $definition) {
        $harness->assertTrue(str_contains($master, $definition));
    }
});

$harness->check('GoldenFilingArtifactReviewPack', 'publishes a complete 18-file review pack with valid links and hashes', static function () use ($harness): void {
    $root = goldenArtifactReviewTestRoot('complete');
    $uploadRoot = $root . DIRECTORY_SEPARATOR . 'uploads';
    $outputRoot = $root . DIRECTORY_SEPARATOR . 'published';
    mkdir($uploadRoot, 0775, true);
    $pack = new GoldenFilingArtifactReviewPack($uploadRoot, $outputRoot, '20260808T120000Z-a1b2c3d4');
    goldenArtifactReviewCaptureAllPeriods($pack, $uploadRoot);
    $published = $pack->publish();

    $harness->assertTrue(!empty($published['success']));
    $harness->assertSame(18, (int)$published['artifact_count']);
    $manifest = json_decode((string)file_get_contents((string)$published['manifest_path']), true);
    $harness->assertTrue(is_array($manifest));
    $harness->assertSame('complete', (string)$manifest['status']);
    $harness->assertCount(18, (array)$manifest['artifacts']);
    $harness->assertCount(18, (array)$manifest['stages']['expected']);
    $harness->assertCount(18, (array)$manifest['stages']['successful']);
    $harness->assertCount(0, (array)$manifest['stages']['failed']);
    $harness->assertCount(0, (array)$manifest['stages']['missing']);
    $companiesHouseArtifacts = [];
    foreach ((array)$manifest['artifacts'] as $artifact) {
        $path = dirname((string)$published['manifest_path']) . DIRECTORY_SEPARATOR
            . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string)$artifact['relative_path']);
        $harness->assertTrue(is_file($path));
        $harness->assertSame((string)$artifact['sha256'], (string)hash_file('sha256', $path));
        if ((string)($artifact['kind'] ?? '') === 'companies_house_accounts_ixbrl') {
            $companiesHouseArtifacts[] = (array)$artifact;
        }
    }
    $harness->assertCount(4, $companiesHouseArtifacts);
    $included = array_values(array_filter(
        $companiesHouseArtifacts,
        static fn(array $artifact): bool => (string)($artifact['profit_loss_delivery'] ?? '') === 'included'
    ));
    $omitted = array_values(array_filter(
        $companiesHouseArtifacts,
        static fn(array $artifact): bool => (string)($artifact['profit_loss_delivery'] ?? '') === 'omitted'
    ));
    $harness->assertCount(2, $included);
    $harness->assertCount(2, $omitted);
    foreach ($included as $artifact) {
        $harness->assertTrue(str_ends_with(
            str_replace('\\', '/', (string)$artifact['relative_path']),
            '/companies-house-accounts-with-profit-and-loss.xhtml'
        ));
    }
    foreach ($omitted as $artifact) {
        $harness->assertTrue(str_ends_with(
            str_replace('\\', '/', (string)$artifact['relative_path']),
            '/companies-house-accounts-without-profit-and-loss.xhtml'
        ));
    }
    $index = (string)file_get_contents((string)$published['index_path']);
    $harness->assertSame(18, substr_count($index, 'open artefact'));
    $harness->assertSame(2, substr_count($index, '<td>included</td>'));
    $harness->assertSame(2, substr_count($index, '<td>omitted</td>'));
});

$harness->check('GoldenFilingArtifactReviewPack', 'retains a safe diagnostic pack for missing and out-of-root files', static function () use ($harness): void {
    $root = goldenArtifactReviewTestRoot('partial');
    $uploadRoot = $root . DIRECTORY_SEPARATOR . 'uploads';
    $outputRoot = $root . DIRECTORY_SEPARATOR . 'published';
    mkdir($uploadRoot, 0775, true);
    $outsidePath = $root . DIRECTORY_SEPARATOR . 'outside.xhtml';
    file_put_contents($outsidePath, '<unsafe>');
    $descriptor = goldenArtifactReviewDescriptor(
        'hmrc_accounts_ixbrl',
        $outsidePath,
        hash('sha256', '<unsafe>')
    );
    $descriptor['validation_status'] = 'failed';
    $pack = new GoldenFilingArtifactReviewPack($uploadRoot, $outputRoot, '20260808T120001Z-b1c2d3e4');
    $pack->capturePeriod(
        ['id' => 9100, 'company_name' => '<Golden & Co>', 'companies_house_number' => '00910000'],
        ['id' => 9111, 'label' => 'Golden', 'period_start' => '2022-09-05', 'period_end' => '2023-09-30'],
        [],
        ['outcome' => 'partial', 'stages' => [
            'hmrc_accounts' => ['outcome' => 'failed', 'artifact' => $descriptor, 'errors' => ['<unsafe failure>']],
            'companies_house_accounts' => ['errors' => ['missing']],
        ]]
    );
    $published = $pack->publish();

    $harness->assertFalse(!empty($published['success']));
    $manifest = json_decode((string)file_get_contents((string)$published['manifest_path']), true);
    $harness->assertCount(1, (array)$manifest['stages']['failed']);
    $harness->assertCount(1, (array)$manifest['stages']['missing']);
    $harness->assertTrue(is_file((string)$published['index_path']));
    $index = (string)file_get_contents((string)$published['index_path']);
    $harness->assertTrue(str_contains($index, '&lt;unsafe failure&gt;'));
    $harness->assertFalse(str_contains($index, '<unsafe failure>'));
    $harness->assertTrue(str_contains(implode(' ', (array)$published['errors']), 'outside the configured test upload root'));
});

function goldenArtifactReviewTestRoot(string $suffix): string
{
    $root = test_register_cleanup_path(
        test_tmp_directory() . DIRECTORY_SEPARATOR . 'golden-artifact-review-' . $suffix . '-' . bin2hex(random_bytes(3))
    );
    mkdir($root, 0775, true);
    return $root;
}

function goldenArtifactReviewCaptureAllPeriods(GoldenFilingArtifactReviewPack $pack, string $uploadRoot): void
{
    $ctIds = [[9211, 9212], [9221], [9231], [9241]];
    foreach ([9111, 9112, 9113, 9114] as $offset => $periodId) {
        $periodDirectory = $uploadRoot . DIRECTORY_SEPARATOR . 'source-' . $periodId;
        mkdir($periodDirectory, 0775, true);
        $ctPeriods = [];
        $computationStages = [];
        $ct600Stages = [];
        foreach ($ctIds[$offset] as $sequence => $ctId) {
            $ctPeriods[] = [
                'id' => $ctId,
                'sequence_no' => $sequence + 1,
                'period_start' => '202' . (2 + $offset) . '-10-01',
                'period_end' => '202' . (3 + $offset) . '-09-30',
            ];
            $computationStages[$ctId] = goldenArtifactReviewStage(
                $periodDirectory,
                'hmrc-computation-' . $ctId . '.xhtml',
                'hmrc_computation_ixbrl',
                '<html>computation ' . $ctId . '</html>',
                $periodId,
                $ctId
            );
            $ct600Stages[$ctId] = goldenArtifactReviewStage(
                $periodDirectory,
                'ct600-' . $ctId . '.xml',
                'ct600_xml',
                '<ct600>' . $ctId . '</ct600>',
                $periodId,
                $ctId
            );
        }
        $includeCompaniesHouseProfitLoss = in_array($periodId, [9112, 9114], true);
        $pack->capturePeriod(
            ['id' => 9100, 'company_name' => 'Golden Company', 'companies_house_number' => '00910000'],
            [
                'id' => $periodId,
                'label' => 'Golden period ' . $periodId,
                'period_start' => '202' . (2 + $offset) . '-10-01',
                'period_end' => '202' . (3 + $offset) . '-09-30',
            ],
            $ctPeriods,
            [
                'success' => true,
                'outcome' => 'complete',
                'stages' => [
                    'hmrc_accounts' => goldenArtifactReviewStage(
                        $periodDirectory,
                        'hmrc-accounts.xhtml',
                        'hmrc_accounts_ixbrl',
                        '<html>HMRC accounts ' . $periodId . '</html>',
                        $periodId
                    ),
                    'companies_house_accounts' => goldenArtifactReviewStage(
                        $periodDirectory,
                        'companies-house-accounts.xhtml',
                        'companies_house_accounts_ixbrl',
                        '<html>Companies House ' . $periodId . '</html>',
                        $periodId
                    ),
                    'hmrc_computations' => $computationStages,
                    'hmrc_ct600' => $ct600Stages,
                ],
            ],
            $includeCompaniesHouseProfitLoss
        );
    }
}

/** @return array<string,mixed> */
function goldenArtifactReviewStage(
    string $directory,
    string $filename,
    string $kind,
    string $contents,
    int $accountingPeriodId,
    ?int $ctPeriodId = null
): array {
    $path = $directory . DIRECTORY_SEPARATOR . $filename;
    file_put_contents($path, $contents);
    $descriptor = goldenArtifactReviewDescriptor($kind, $path, hash('sha256', $contents));
    $descriptor['accounting_period_id'] = $accountingPeriodId;
    $descriptor['ct_period_id'] = $ctPeriodId;
    return ['outcome' => 'succeeded', 'errors' => [], 'warnings' => [], 'artifact' => $descriptor];
}

/** @return array<string,mixed> */
function goldenArtifactReviewDescriptor(string $kind, string $path, string $sha256): array
{
    return [
        'kind' => $kind,
        'authority' => $kind === 'companies_house_accounts_ixbrl' ? 'COMPANIES_HOUSE' : 'HMRC',
        'filename' => basename($path),
        'path' => $path,
        'sha256' => $sha256,
        'validation_status' => 'passed',
        'validation_log_path' => '',
        'validation' => ['status' => 'passed', 'validator' => 'test'],
    ];
}
