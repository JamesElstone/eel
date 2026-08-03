<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

use eel_accounts\Repository\IxbrlAccountsArtifactRepository;
use eel_accounts\Repository\IxbrlValidationRunRepository;

(new GeneratedServiceClassTestHarness())->run(
    IxbrlAccountsArtifactRepository::class,
    static function (GeneratedServiceClassTestHarness $harness, IxbrlAccountsArtifactRepository $artifacts): void {
        $harness->check(
            IxbrlAccountsArtifactRepository::class,
            'registers authority artifacts, validation evidence, approval links and submission links',
            static function () use ($harness): void {
                $migrationPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema'
                    . DIRECTORY_SEPARATOR . 'migrations'
                    . DIRECTORY_SEPARATOR . '2026_08_03_001_authority_specific_ixbrl_artifacts.sql';
                $migration = (string)file_get_contents($migrationPath);
                $versionMigration = (string)file_get_contents(
                    dirname($migrationPath) . DIRECTORY_SEPARATOR
                    . '2026_08_03_002_ct_period_filing_basis_versions.sql'
                );
                $constraintMigration = (string)file_get_contents(
                    dirname($migrationPath) . DIRECTORY_SEPARATOR
                    . '2026_08_03_004_ixbrl_artifact_validation_constraints.sql'
                );
                $schema = (string)file_get_contents(
                    dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema'
                    . DIRECTORY_SEPARATOR . 'eel_accounts.schema.sql'
                );

                foreach (['ixbrl_accounts_artifacts', 'ixbrl_validation_runs', 'hmrc_ct_filing_approvals'] as $table) {
                    $harness->assertTrue(str_contains($migration, 'CREATE TABLE IF NOT EXISTS ' . $table));
                    $harness->assertTrue(str_contains($schema, 'CREATE TABLE `' . $table . '`'));
                    $harness->assertTrue(InterfaceDB::tableExists($table));
                }
                foreach ([
                    'generation_run_id', 'authority', 'filing_kind', 'profile_fingerprint',
                    'render_model_sha256', 'output_path', 'output_sha256',
                ] as $column) {
                    $harness->assertTrue(InterfaceDB::columnExists('ixbrl_accounts_artifacts', $column));
                }
                foreach ([
                    'accounts_artifact_id', 'computation_run_id', 'artifact_sha256',
                    'validator_fingerprint', 'options_fingerprint', 'core_results_json',
                    'authority_results_json', 'arelle_results_json', 'overall_status',
                ] as $column) {
                    $harness->assertTrue(InterfaceDB::columnExists('ixbrl_validation_runs', $column));
                }
                $harness->assertTrue(str_contains(
                    $constraintMigration,
                    'ADD CONSTRAINT chk_ixbrl_validation_single_target CHECK ('
                ));
                $harness->assertTrue(str_contains(
                    $constraintMigration,
                    'ON DELETE CASCADE ON UPDATE RESTRICT'
                ));
                foreach (['companies_house_accounts_submissions', 'ct600_generated_artifacts', 'hmrc_ct600_submissions'] as $table) {
                    $harness->assertTrue(InterfaceDB::columnExists($table, 'accounts_artifact_id'));
                    $harness->assertTrue(InterfaceDB::columnExists($table, 'accounts_validation_run_id'));
                }
                foreach (['ct600_generated_artifacts', 'hmrc_ct600_submissions'] as $table) {
                    $harness->assertTrue(InterfaceDB::columnExists($table, 'hmrc_ct_filing_approval_id'));
                    $harness->assertTrue(InterfaceDB::columnExists($table, 'computation_validation_run_id'));
                }
                $harness->assertTrue(InterfaceDB::columnExists('ct_period_filing_bases', 'hmrc_ct_filing_approval_id'));
                $harness->assertTrue(str_contains(
                    $versionMigration,
                    'uq_ct_period_filing_basis_approval_period_version'
                ));
                $harness->assertTrue(str_contains(
                    $versionMigration,
                    'DROP INDEX IF EXISTS uq_ct_period_filing_basis_approval_period'
                ));
                $harness->assertTrue(str_contains(
                    $schema,
                    'UNIQUE KEY `uq_ct_period_filing_basis_approval_period_version` (`filing_approval_id`,`ct_period_id`,`basis_hash`)'
                ));
                $harness->assertFalse(str_contains(
                    $schema,
                    'UNIQUE KEY `uq_ct_period_filing_basis_approval_period` (`filing_approval_id`,`ct_period_id`)'
                ));
            }
        );

        $harness->check(
            IxbrlAccountsArtifactRepository::class,
            'keeps HMRC and Companies House artifacts independent and pins validation to exact bytes',
            static function () use ($harness, $artifacts): void {
                InterfaceDB::beginTransaction();
                $lastInsertId = static fn(): int => (int)InterfaceDB::fetchColumn(
                    strtolower((string)InterfaceDB::driverName()) === 'sqlite'
                        ? 'SELECT last_insert_rowid()'
                        : 'SELECT LAST_INSERT_ID()'
                );
                $run = InterfaceDB::fetchOne(
                    'SELECT r.id, r.company_id, r.accounting_period_id,
                            r.filing_approval_id, r.filing_approval_hash
                     FROM ixbrl_generation_runs r
                     INNER JOIN ixbrl_accounts_filing_approvals approval
                       ON approval.id = r.filing_approval_id
                      AND approval.basis_hash = r.filing_approval_hash
                     ORDER BY r.id DESC LIMIT 1'
                );
                if (!is_array($run)) {
                    $token = bin2hex(random_bytes(6));
                    InterfaceDB::prepareExecute(
                        'INSERT INTO companies (company_name) VALUES (:name)',
                        ['name' => 'Authority artifact ' . $token]
                    );
                    $companyId = $lastInsertId();
                    InterfaceDB::prepareExecute(
                        'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
                         VALUES (:company_id, :label, :period_start, :period_end)',
                        [
                            'company_id' => $companyId,
                            'label' => 'Artifact ' . $token,
                            'period_start' => '2025-01-01',
                            'period_end' => '2025-12-31',
                        ]
                    );
                    $periodId = $lastInsertId();
                    InterfaceDB::prepareExecute(
                        'INSERT INTO ixbrl_accounts_disclosures
                            (company_id, accounting_period_id, created_by, updated_by)
                         VALUES (:company_id, :period_id, :actor, :actor)',
                        ['company_id' => $companyId, 'period_id' => $periodId, 'actor' => 'test']
                    );
                    $disclosureId = $lastInsertId();
                    InterfaceDB::prepareExecute(
                        'INSERT INTO year_end_reviews
                            (company_id, accounting_period_id, is_locked, locked_at, locked_by)
                         VALUES (:company_id, :period_id, 1, CURRENT_TIMESTAMP, :actor)',
                        ['company_id' => $companyId, 'period_id' => $periodId, 'actor' => 'test']
                    );
                    $reviewId = $lastInsertId();
                    $basisJson = '{"test":"authority-artifact"}';
                    $basisHash = hash('sha256', $basisJson);
                    InterfaceDB::prepareExecute(
                        'INSERT INTO ixbrl_accounts_filing_approvals (
                            company_id, accounting_period_id, disclosure_id, disclosure_revision,
                            year_end_review_id, year_end_locked_at, basis_version, basis_hash,
                            basis_json, approved_by
                         ) VALUES (
                            :company_id, :period_id, :disclosure_id, 1,
                            :review_id, CURRENT_TIMESTAMP, :basis_version, :basis_hash,
                            :basis_json, :actor
                         )',
                        [
                            'company_id' => $companyId,
                            'period_id' => $periodId,
                            'disclosure_id' => $disclosureId,
                            'review_id' => $reviewId,
                            'basis_version' => 'test-v1',
                            'basis_hash' => $basisHash,
                            'basis_json' => $basisJson,
                            'actor' => 'test',
                        ]
                    );
                    $approvalId = $lastInsertId();
                    InterfaceDB::prepareExecute(
                        'INSERT INTO ixbrl_generation_runs (
                            company_id, accounting_period_id, filing_approval_id, filing_approval_hash
                         ) VALUES (:company_id, :period_id, :approval_id, :approval_hash)',
                        [
                            'company_id' => $companyId,
                            'period_id' => $periodId,
                            'approval_id' => $approvalId,
                            'approval_hash' => $basisHash,
                        ]
                    );
                    $run = [
                        'id' => $lastInsertId(),
                        'company_id' => $companyId,
                        'accounting_period_id' => $periodId,
                        'filing_approval_id' => $approvalId,
                        'filing_approval_hash' => $basisHash,
                    ];
                }

                $hmrcPath = tempnam(test_tmp_directory(), 'hmrc-accounts-');
                $chPath = tempnam(test_tmp_directory(), 'ch-accounts-');
                $computationPath = null;
                if (!is_string($hmrcPath) || !is_string($chPath)) {
                    $harness->skip('Could not create authority-artifact test files.');
                }
                file_put_contents($hmrcPath, '<html data-authority="hmrc"></html>');
                file_put_contents($chPath, '<?xml version="1.0"?><html data-authority="companies-house"></html>');

                try {
                    $common = [
                        'generation_run_id' => (int)$run['id'],
                        'company_id' => (int)$run['company_id'],
                        'accounting_period_id' => (int)$run['accounting_period_id'],
                        'filing_approval_id' => (int)$run['filing_approval_id'],
                        'filing_approval_hash' => (string)$run['filing_approval_hash'],
                        'profile_version' => '1.0.0',
                        'taxonomy_profile' => 'frc-2026-frs-105',
                    ];
                    $hmrcRecord = $common + [
                        'authority' => 'HMRC',
                        'filing_kind' => 'ordinary',
                        'profile_key' => 'hmrc_ct_accounts',
                        'profile_fingerprint' => hash('sha256', 'hmrc-profile'),
                        'render_model_sha256' => hash('sha256', 'approved-model'),
                        'transformation_registry_uri' => 'http://www.xbrl.org/inlineXBRL/transformation/2011-07-31',
                        'output_path' => $hmrcPath,
                        'output_sha256' => hash_file('sha256', $hmrcPath),
                    ];
                    $chRecord = $common + [
                        'authority' => 'COMPANIES_HOUSE',
                        'filing_kind' => 'original',
                        'profile_key' => 'companies_house_accounts',
                        'profile_fingerprint' => hash('sha256', 'ch-profile'),
                        'render_model_sha256' => hash('sha256', 'approved-model'),
                        'transformation_registry_uri' => 'http://www.xbrl.org/inlineXBRL/transformation/2015-02-26',
                        'output_path' => $chPath,
                        'output_sha256' => hash_file('sha256', $chPath),
                    ];

                    $hmrcId = $artifacts->create($hmrcRecord);
                    $chId = $artifacts->create($chRecord);
                    $harness->assertTrue($hmrcId > 0 && $chId > $hmrcId);
                    $hmrcProfile = (new \eel_accounts\Service\IxbrlAuthorityProfileService())->profile(
                        \eel_accounts\Service\IxbrlAuthorityProfileService::HMRC_CT_ACCOUNTS
                    );
                    $harness->assertThrows(
                        static fn(): int => (new \eel_accounts\Service\IxbrlValidationEvidenceService())
                            ->createAccountsArtifact(
                                array_replace($hmrcRecord, ['authority' => 'COMPANIES_HOUSE']),
                                $hmrcProfile
                            ),
                        InvalidArgumentException::class
                    );
                    $harness->assertThrows(
                        static fn(): int => $artifacts->create(array_replace(
                            $hmrcRecord,
                            ['filing_kind' => 'revised']
                        )),
                        InvalidArgumentException::class
                    );
                    $harness->assertSame($hmrcId, $artifacts->create($hmrcRecord));
                    $harness->assertSame(
                        $hmrcId,
                        (int)($artifacts->findCurrent(
                            (int)$run['company_id'],
                            (int)$run['accounting_period_id'],
                            'HMRC'
                        )['id'] ?? 0)
                    );
                    $harness->assertSame(
                        $chId,
                        (int)($artifacts->findCurrent(
                            (int)$run['company_id'],
                            (int)$run['accounting_period_id'],
                            'COMPANIES_HOUSE',
                            'original'
                        )['id'] ?? 0)
                    );

                    $validations = new IxbrlValidationRunRepository();
                    $validatorOptionsHash = hash('sha256', '["--plugins","validate/UK"]');
                    $options = [
                        'plugins' => ['validate/UK'],
                        'profile' => 'hmrc_ct_accounts',
                        'validator_options_sha256' => $validatorOptionsHash,
                    ];
                    $passedArelleResult = [
                        'status' => 'passed',
                        'validator' => 'Arelle',
                        'version' => 'test',
                        'validated_sha256' => (string)$hmrcRecord['output_sha256'],
                        'validation_profile_key' => (string)$hmrcRecord['profile_key'],
                        'validation_profile_version' => (string)$hmrcRecord['profile_version'],
                        'validation_profile_fingerprint' => (string)$hmrcRecord['profile_fingerprint'],
                        'validator_options_sha256' => $validatorOptionsHash,
                        'errors' => [],
                        'warnings' => [],
                    ];
                    $passedValidationRecord = [
                        'accounts_artifact_id' => $hmrcId,
                        'validator_name' => 'Arelle',
                        'validator_version' => 'test',
                        'validator_fingerprint' => hash(
                            'sha256',
                            'Arelle|test|' . $validatorOptionsHash
                        ),
                        'options' => $options,
                        'options_fingerprint' => hash(
                            'sha256',
                            \eel_accounts\Support\PersistentJson::encode(
                                $options,
                                JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
                            )
                        ),
                        'source_conformance_status' => 'passed',
                        'source_conformance_results' => ['errors' => []],
                        'core_status' => 'passed',
                        'core_results' => ['errors' => []],
                        'authority_status' => 'passed',
                        'authority_results' => ['errors' => []],
                        'arelle_status' => 'passed',
                        'arelle_results' => $passedArelleResult,
                        'overall_status' => 'passed',
                    ];
                    $validationId = $validations->create($passedValidationRecord);
                    $harness->assertTrue($validationId > 0);
                    $validationCount = (int)InterfaceDB::fetchColumn(
                        'SELECT COUNT(*) FROM ixbrl_validation_runs WHERE accounts_artifact_id = :artifact_id',
                        ['artifact_id' => $hmrcId]
                    );
                    $harness->assertThrows(
                        static fn(): int => $validations->create(array_replace(
                            $passedValidationRecord,
                            [
                                'taxonomy_package_id' => 999001,
                                'taxonomy_package_sha256' => str_repeat('f', 64),
                            ]
                        )),
                        RuntimeException::class
                    );
                    $harness->assertSame(
                        $validationCount,
                        (int)InterfaceDB::fetchColumn(
                            'SELECT COUNT(*) FROM ixbrl_validation_runs WHERE accounts_artifact_id = :artifact_id',
                            ['artifact_id' => $hmrcId]
                        ),
                        'Mismatched taxonomy evidence must not create a validation ledger row.'
                    );
                    foreach ([
                        'validated_sha256' => str_repeat('b', 64),
                        'validation_profile_fingerprint' => str_repeat('c', 64),
                        'validator' => 'not-arelle',
                        'version' => 'a-different-validator-version',
                        'validator_options_sha256' => str_repeat('e', 64),
                    ] as $field => $mismatch) {
                        $mismatchedResult = array_replace($passedArelleResult, [$field => $mismatch]);
                        $harness->assertThrows(
                            static fn(): int => $validations->create(array_replace(
                                $passedValidationRecord,
                                ['arelle_results' => $mismatchedResult]
                            )),
                            RuntimeException::class
                        );
                        $harness->assertSame(
                            $validationCount,
                            (int)InterfaceDB::fetchColumn(
                                'SELECT COUNT(*) FROM ixbrl_validation_runs WHERE accounts_artifact_id = :artifact_id',
                                ['artifact_id' => $hmrcId]
                            ),
                            'Rejected Arelle evidence must not create a passed validation ledger row.'
                        );
                    }
                    $currentValidation = $validations->latestPassedForArtifact(
                        $hmrcId,
                        (string)$hmrcRecord['output_sha256'],
                        (string)$hmrcRecord['profile_fingerprint']
                    );
                    $harness->assertSame($validationId, (int)($currentValidation['id'] ?? 0));
                    $failedValidationId = $validations->create([
                        'accounts_artifact_id' => $hmrcId,
                        'validator_name' => 'Arelle',
                        'validator_version' => 'test',
                        'validator_fingerprint' => hash('sha256', 'validator'),
                        'options' => $options,
                        'options_fingerprint' => hash(
                            'sha256',
                            \eel_accounts\Support\PersistentJson::encode(
                                $options,
                                JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
                            )
                        ),
                        'source_conformance_status' => 'passed',
                        'source_conformance_results' => ['errors' => []],
                        'core_status' => 'passed',
                        'core_results' => ['errors' => []],
                        'authority_status' => 'failed',
                        'authority_results' => ['errors' => [['code' => 'test.latest-decision']]],
                        'arelle_status' => 'passed',
                        'arelle_results' => ['errors' => [], 'warnings' => []],
                        'overall_status' => 'failed',
                    ]);
                    $latestDecision = $validations->latestForArtifact(
                        $hmrcId,
                        (string)$hmrcRecord['output_sha256'],
                        (string)$hmrcRecord['profile_fingerprint']
                    );
                    $harness->assertSame($failedValidationId, (int)($latestDecision['id'] ?? 0));
                    $harness->assertSame('failed', (string)($latestDecision['overall_status'] ?? ''));
                    $harness->assertSame(
                        $validationId,
                        (int)($validations->latestPassedForArtifact(
                            $hmrcId,
                            (string)$hmrcRecord['output_sha256'],
                            (string)$hmrcRecord['profile_fingerprint']
                        )['id'] ?? 0),
                        'The historic pass remains queryable, but filing locators must use the latest decision.'
                    );
                    InterfaceDB::prepareExecute(
                        'UPDATE ixbrl_validation_runs
                         SET taxonomy_package_id = :package_id,
                             taxonomy_package_sha256 = :package_hash
                         WHERE id = :id',
                        [
                            'package_id' => 999001,
                            'package_hash' => str_repeat('f', 64),
                            'id' => $validationId,
                        ]
                    );
                    $packageAssertion = new ReflectionMethod(
                        \eel_accounts\Service\HmrcSubmissionPackageService::class,
                        'assertHmrcArtifact'
                    );
                    $packageAssertion->setAccessible(true);
                    $harness->assertThrows(
                        static fn(): mixed => $packageAssertion->invoke(
                            new \eel_accounts\Service\HmrcSubmissionPackageService(),
                            [
                                'path' => $hmrcPath,
                                'hash' => (string)$hmrcRecord['output_sha256'],
                                'artifact_id' => $hmrcId,
                                'validation_run_id' => $validationId,
                                'authority_profile' => $hmrcProfile->key(),
                                'authority_profile_fingerprint' => $hmrcProfile->fingerprint(),
                                'taxonomy_package_id' => 999001,
                                'taxonomy_package_hash' => str_repeat('f', 64),
                            ],
                            \eel_accounts\Service\IxbrlAuthorityProfileService::HMRC_CT_ACCOUNTS,
                            'Accounts'
                        ),
                        RuntimeException::class
                    );
                    InterfaceDB::prepareExecute(
                        'UPDATE ixbrl_validation_runs
                         SET taxonomy_package_id = NULL,
                             taxonomy_package_sha256 = NULL
                         WHERE id = :id',
                        ['id' => $validationId]
                    );

                    $periodDates = InterfaceDB::fetchOne(
                        'SELECT period_start, period_end FROM accounting_periods
                         WHERE id = :period_id AND company_id = :company_id LIMIT 1',
                        [
                            'period_id' => (int)$run['accounting_period_id'],
                            'company_id' => (int)$run['company_id'],
                        ]
                    );
                    $harness->assertTrue(is_array($periodDates));
                    $nextSequence = 1 + (int)InterfaceDB::fetchColumn(
                        'SELECT COALESCE(MAX(sequence_no), 0) FROM corporation_tax_periods
                         WHERE accounting_period_id = :period_id',
                        ['period_id' => (int)$run['accounting_period_id']]
                    );
                    InterfaceDB::prepareExecute(
                        'INSERT INTO corporation_tax_periods (
                            company_id, accounting_period_id, sequence_no, period_start, period_end
                         ) VALUES (
                            :company_id, :period_id, :sequence_no, :period_start, :period_end
                         )',
                        [
                            'company_id' => (int)$run['company_id'],
                            'period_id' => (int)$run['accounting_period_id'],
                            'sequence_no' => $nextSequence,
                            'period_start' => (string)($periodDates['period_start'] ?? ''),
                            'period_end' => (string)($periodDates['period_end'] ?? ''),
                        ]
                    );
                    $ctPeriodId = $lastInsertId();
                    $computationPath = tempnam(test_tmp_directory(), 'hmrc-computation-');
                    if (!is_string($computationPath)) {
                        $harness->skip('Could not create the computation validation target.');
                    }
                    file_put_contents($computationPath, '<html data-authority="hmrc-computation"></html>');
                    $computationHash = (string)hash_file('sha256', $computationPath);
                    InterfaceDB::prepareExecute(
                        'INSERT INTO corporation_tax_computation_runs (
                            company_id, accounting_period_id, ct_period_id,
                            period_start, period_end, status, computation_hash, summary_json,
                            generated_path, generated_filename, output_sha256
                         ) VALUES (
                            :company_id, :period_id, :ct_period_id,
                            :period_start, :period_end, :status, :computation_hash, :summary_json,
                            :generated_path, :generated_filename, :output_sha256
                         )',
                        [
                            'company_id' => (int)$run['company_id'],
                            'period_id' => (int)$run['accounting_period_id'],
                            'ct_period_id' => $ctPeriodId,
                            'period_start' => (string)($periodDates['period_start'] ?? ''),
                            'period_end' => (string)($periodDates['period_end'] ?? ''),
                            'status' => 'generated',
                            'computation_hash' => hash('sha256', 'computation-' . $ctPeriodId),
                            'summary_json' => '{}',
                            'generated_path' => $computationPath,
                            'generated_filename' => basename($computationPath),
                            'output_sha256' => $computationHash,
                        ]
                    );
                    $computationRunId = $lastInsertId();
                    $computationProfile = (new \eel_accounts\Service\IxbrlAuthorityProfileService())->profile(
                        \eel_accounts\Service\IxbrlAuthorityProfileService::HMRC_CT_COMPUTATION
                    );
                    $computationArelle = [
                        'status' => 'passed',
                        'validator' => 'arelle',
                        'version' => 'test',
                        'validated_sha256' => $computationHash,
                        'validation_profile_key' => $computationProfile->key(),
                        'validation_profile_version' => $computationProfile->version(),
                        'validation_profile_fingerprint' => $computationProfile->fingerprint(),
                        'validator_options_sha256' => $validatorOptionsHash,
                        'errors' => [],
                        'warnings' => [],
                    ];
                    $validationEvidence = new \eel_accounts\Service\IxbrlValidationEvidenceService();
                    $computationValidationId = $validationEvidence->recordComputationValidation(
                        $computationRunId,
                        $computationHash,
                        $computationProfile,
                        [],
                        ['ok' => true, 'errors' => []],
                        $computationArelle
                    );
                    $harness->assertTrue($computationValidationId > 0);
                    $computationValidationRow = InterfaceDB::fetchOne(
                        'SELECT accounts_artifact_id, computation_run_id
                         FROM ixbrl_validation_runs WHERE id = :id LIMIT 1',
                        ['id' => $computationValidationId]
                    );
                    $harness->assertTrue(is_array($computationValidationRow));
                    $harness->assertSame(null, $computationValidationRow['accounts_artifact_id'] ?? null);
                    $harness->assertSame(
                        $computationRunId,
                        (int)($computationValidationRow['computation_run_id'] ?? 0)
                    );
                    if (strtolower((string)InterfaceDB::driverName()) !== 'sqlite') {
                        $harness->assertThrows(
                            static fn(): int => InterfaceDB::execute(
                                'UPDATE ixbrl_validation_runs
                                 SET accounts_artifact_id = :artifact_id
                                 WHERE id = :id',
                                ['artifact_id' => $hmrcId, 'id' => $computationValidationId]
                            ),
                            PDOException::class
                        );
                    }
                    $computationValidationCount = (int)InterfaceDB::fetchColumn(
                        'SELECT COUNT(*) FROM ixbrl_validation_runs WHERE computation_run_id = :run_id',
                        ['run_id' => $computationRunId]
                    );
                    foreach ([
                        'validated_sha256' => str_repeat('d', 64),
                        'validation_profile_key' => 'hmrc_ct_accounts',
                        'validation_profile_version' => 'not-the-current-version',
                        'validator' => 'not-arelle',
                    ] as $field => $mismatch) {
                        $harness->assertThrows(
                            static fn(): int => $validationEvidence->recordComputationValidation(
                                $computationRunId,
                                $computationHash,
                                $computationProfile,
                                [],
                                ['ok' => true, 'errors' => []],
                                array_replace($computationArelle, [$field => $mismatch])
                            ),
                            RuntimeException::class
                        );
                        $harness->assertSame(
                            $computationValidationCount,
                            (int)InterfaceDB::fetchColumn(
                                'SELECT COUNT(*) FROM ixbrl_validation_runs WHERE computation_run_id = :run_id',
                                ['run_id' => $computationRunId]
                            )
                        );
                    }
                    $harness->assertThrows(
                        static fn(): int => $validations->create([
                            'accounts_artifact_id' => $hmrcId,
                            'computation_run_id' => 1,
                        ]),
                        InvalidArgumentException::class
                    );
                } finally {
                    InterfaceDB::rollBack();
                    @unlink($hmrcPath);
                    @unlink($chPath);
                    if (is_string($computationPath)) {
                        @unlink($computationPath);
                    }
                }
            }
        );
    }
);
