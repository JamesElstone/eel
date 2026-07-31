<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\Ct600GenerationService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\Ct600GenerationService $service
    ): void {
        $harness->check(
            \eel_accounts\Service\Ct600GenerationService::class,
            'accepts only the integrity-checked authorisation frozen into the filing approval',
            static function () use ($harness, $service): void {
                $authorisation = [
                    'declarant_name' => 'Jane Director',
                    'declarant_status' => 'Director',
                    'declaration_at' => '2026-07-30 10:15:00',
                    'declarant_party_id' => null,
                    'declarant_director_id' => 44,
                    'declarant_role_id' => null,
                    'original_unfiled_confirmed' => true,
                    'authority_confirmed' => true,
                    'declaration_confirmed' => true,
                ];
                $basisJson = \eel_accounts\Support\Utf8::json(
                    ['corporation_tax_return_authorisation' => $authorisation],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
                );
                $approval = [
                    'id' => 67,
                    'basis_json' => $basisJson,
                    'basis_hash' => hash('sha256', $basisJson),
                    'declarant_name' => 'Jane Director',
                    'declarant_status' => 'Director',
                    'approved_at' => '2026-07-30 10:20:00',
                    'approved_by' => 'user:9',
                ];
                $method = new ReflectionMethod($service, 'frozenDeclaration');
                $method->setAccessible(true);
                $result = (array)$method->invoke($service, $approval);

                $harness->assertTrue((bool)$result['ok']);
                $harness->assertSame(
                    'Jane Director',
                    (string)$result['declaration']['declarant_name']
                );
                $harness->assertSame(
                    44,
                    (int)$result['declaration']['declarant_director_id']
                );

                $approval['basis_hash'] = str_repeat('0', 64);
                $tampered = (array)$method->invoke($service, $approval);
                $harness->assertFalse((bool)$tampered['ok']);
                $harness->assertTrue(str_contains(
                    implode(' ', (array)$tampered['errors']),
                    'integrity check'
                ));
            }
        );

        $harness->check(
            \eel_accounts\Service\Ct600GenerationService::class,
            'requires complete frozen confirmations and does not use submission-form declarations',
            static function () use ($harness, $service): void {
                $authorisation = [
                    'declarant_name' => 'Jane Director',
                    'declarant_status' => 'Director',
                    'declaration_at' => '2026-07-30 10:15:00',
                    'original_unfiled_confirmed' => true,
                    'authority_confirmed' => false,
                    'declaration_confirmed' => true,
                ];
                $basisJson = \eel_accounts\Support\Utf8::json(
                    ['corporation_tax_return_authorisation' => $authorisation],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
                );
                $approval = [
                    'basis_json' => $basisJson,
                    'basis_hash' => hash('sha256', $basisJson),
                    'declarant_name' => 'Jane Director',
                    'declarant_status' => 'Director',
                ];
                $method = new ReflectionMethod($service, 'frozenDeclaration');
                $method->setAccessible(true);
                $result = (array)$method->invoke($service, $approval);
                $harness->assertFalse((bool)$result['ok']);
                $harness->assertTrue(str_contains(
                    implode(' ', (array)$result['errors']),
                    'confirmations'
                ));

                $source = (string)file_get_contents(
                    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes'
                    . DIRECTORY_SEPARATOR . 'eel_accounts' . DIRECTORY_SEPARATOR . 'service'
                    . DIRECTORY_SEPARATOR . 'Ct600GenerationService.php'
                );
                $generate = strstr($source, 'public function generate(');
                $generate = strstr((string)$generate, 'public function status(', true);
                $harness->assertTrue(is_string($generate));
                $harness->assertTrue(str_contains((string)$generate, 'assembleForGeneration'));
                $harness->assertFalse(str_contains((string)$generate, 'prepareForSubmission'));
                $harness->assertFalse(str_contains((string)$generate, "'LIVE'"));
            }
        );

        $harness->check(
            \eel_accounts\Service\Ct600GenerationService::class,
            'registers full prepared-artifact provenance in the migration and master schema',
            static function () use ($harness): void {
                $migration = (string)file_get_contents(
                    dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema'
                    . DIRECTORY_SEPARATOR . 'migrations'
                    . DIRECTORY_SEPARATOR . '2026_07_30_011_ct600_prepared_artifact_provenance.sql'
                );
                $schema = (string)file_get_contents(
                    dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema'
                    . DIRECTORY_SEPARATOR . 'eel_accounts.schema.sql'
                );
                foreach ([
                    'source_manifest_json',
                    'ct_filing_basis_hash',
                    'accounts_sha256',
                    'computations_sha256',
                    'rim_package_sha256',
                    'mapping_content_hash',
                    'irmark',
                    'validation_json',
                ] as $column) {
                    $harness->assertTrue(str_contains($migration, $column));
                    $harness->assertTrue(str_contains($schema, '`' . $column . '`'));
                    $harness->assertTrue(
                        InterfaceDB::columnExists('ct600_generated_artifacts', $column)
                    );
                }
            }
        );

        $harness->check(
            \eel_accounts\Service\Ct600GenerationService::class,
            'keeps render-time manifest checks shallow and reports detailed generation progress',
            static function () use ($harness): void {
                $source = (string)file_get_contents(
                    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes'
                    . DIRECTORY_SEPARATOR . 'eel_accounts' . DIRECTORY_SEPARATOR . 'service'
                    . DIRECTORY_SEPARATOR . 'Ct600GenerationService.php'
                );
                $progressSource = $source . (string)file_get_contents(
                    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes'
                    . DIRECTORY_SEPARATOR . 'eel_accounts' . DIRECTORY_SEPARATOR . 'service'
                    . DIRECTORY_SEPARATOR . 'HmrcSubmissionPackageService.php'
                );
                $statusManifest = strstr($source, 'public function currentManifestForStatus(');
                $statusManifest = strstr(
                    (string)$statusManifest,
                    'public function downloadArtifact(',
                    true
                );
                $harness->assertTrue(is_string($statusManifest));
                $harness->assertTrue(str_contains((string)$statusManifest, 'false'));
                $harness->assertFalse(str_contains((string)$statusManifest, 'loadForSubmission('));

                $download = strstr($source, 'public function downloadArtifact(');
                $download = strstr((string)$download, 'private function sourceContext(', true);
                $harness->assertTrue(is_string($download));
                $harness->assertTrue(str_contains(
                    (string)$download,
                    'status($companyId, $accountingPeriodId, $ctPeriodId, false)'
                ));
                $harness->assertFalse(str_contains(
                    (string)$download,
                    'status($companyId, $accountingPeriodId, $ctPeriodId, true)'
                ));

                foreach ([
                    'Verifying the frozen accounts approval and return authorisation',
                    'Checking the HMRC Accounting iXBRL artifact',
                    'Applying and verifying the HMRC IRmark',
                    'Rechecking the stored CT600 file, attachments and IRmark',
                    'XBRLsubmission',
                    "'element' => 'Computation'",
                ] as $message) {
                    $harness->assertTrue(str_contains($progressSource, $message));
                }
            }
        );
    }
);
