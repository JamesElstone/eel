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
    \eel_accounts\Service\IxbrlGenerationRunCleanupService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\IxbrlGenerationRunCleanupService $service): void {
        $harness->check(\eel_accounts\Service\IxbrlGenerationRunCleanupService::class, 'removes missing runs and unsent drafts but retains transmitted filings', static function () use ($harness, $service): void {
            InterfaceDB::beginTransaction();
            $presentPath = tempnam(test_tmp_directory(), 'ixbrl-cleanup-');
            if ($presentPath === false) {
                $harness->skip('Could not create an iXBRL cleanup artifact fixture.');
            }
            file_put_contents($presentPath, '<html></html>');
            try {
                $token = bin2hex(random_bytes(5));
                InterfaceDB::prepareExecute(
                    'INSERT INTO companies (company_name) VALUES (:name)',
                    ['name' => 'iXBRL cleanup ' . $token]
                );
                $companyId = (int)InterfaceDB::fetchColumn('SELECT MAX(id) FROM companies');
                InterfaceDB::prepareExecute(
                    'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
                     VALUES (:company_id, :label, :period_start, :period_end)',
                    ['company_id' => $companyId, 'label' => 'Cleanup ' . $token, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31']
                );
                $periodId = (int)InterfaceDB::fetchColumn('SELECT MAX(id) FROM accounting_periods');
                $missingPath = $presentPath . '-missing';
                $insertRun = static function (string $path) use ($companyId, $periodId): int {
                    InterfaceDB::prepareExecute(
                        'INSERT INTO ixbrl_generation_runs (company_id, accounting_period_id, generated_path)
                         VALUES (:company_id, :period_id, :path)',
                        ['company_id' => $companyId, 'period_id' => $periodId, 'path' => $path]
                    );
                    return (int)InterfaceDB::fetchColumn('SELECT MAX(id) FROM ixbrl_generation_runs');
                };
                $presentRunId = $insertRun($presentPath);
                $missingRunId = $insertRun($missingPath);
                $draftRunId = $insertRun($missingPath . '-draft');
                $factRunId = $insertRun($missingPath . '-facts');
                $submittedRunId = $insertRun($missingPath . '-submitted');
                InterfaceDB::prepareExecute(
                    "INSERT INTO ixbrl_generation_facts (
                        run_id, fact_key, taxonomy_concept, label, value_type,
                        text_value, context_ref
                     ) VALUES (
                        :run_id, 'test_fact', 'test:Fact', 'Test fact', 'text',
                        'approved value', 'current'
                     )",
                    ['run_id' => $factRunId]
                );
                InterfaceDB::prepareExecute(
                    "INSERT INTO companies_house_accounts_eligibility (
                        company_id, accounting_period_id, original_transaction_id,
                        original_document_external_id, original_filing_channel,
                        decision, evidence_text, decided_by, decided_at
                     ) VALUES (
                        :company_id, :period_id, :transaction_id,
                        :external_id, 'test', 'eligible', 'test', 'test', CURRENT_TIMESTAMP
                     )",
                    [
                        'company_id' => $companyId,
                        'period_id' => $periodId,
                        'transaction_id' => 'cleanup-' . $token,
                        'external_id' => 'cleanup-' . $token,
                    ]
                );
                $eligibilityId = (int)InterfaceDB::fetchColumn('SELECT MAX(id) FROM companies_house_accounts_eligibility');
                $insertSubmission = static function (int $runId, string $lifecycle, ?string $submittedAt) use ($companyId, $periodId, $eligibilityId, $token): int {
                    $identity = hash('sha256', $token . '-' . $runId . '-' . $lifecycle);
                    InterfaceDB::prepareExecute(
                        'INSERT INTO companies_house_accounts_submissions (
                            eligibility_id, company_id, accounting_period_id,
                            original_transaction_id, original_document_external_id,
                            ixbrl_generation_run_id, environment, lifecycle,
                            revised_artifact_path, revised_artifact_sha256,
                            basis_hash, idempotency_key, revision_declarations_json,
                            prepared_by, submitted_by, submitted_at
                         ) VALUES (
                            :eligibility_id, :company_id, :period_id,
                            :transaction_id, :external_id,
                            :run_id, \'TEST\', :lifecycle,
                            :artifact_path, :sha256,
                            :basis_hash, :idempotency_key, \'{}\',
                            \'test\', :submitted_by, :submitted_at
                         )',
                        [
                            'eligibility_id' => $eligibilityId,
                            'company_id' => $companyId,
                            'period_id' => $periodId,
                            'transaction_id' => 'cleanup-' . $token,
                            'external_id' => 'cleanup-' . $token,
                            'run_id' => $runId,
                            'lifecycle' => $lifecycle,
                            'artifact_path' => 'missing-' . $identity . '.xhtml',
                            'sha256' => $identity,
                            'basis_hash' => $identity,
                            'idempotency_key' => $identity,
                            'submitted_by' => $submittedAt === null ? null : 'test',
                            'submitted_at' => $submittedAt,
                        ]
                    );
                    return (int)InterfaceDB::fetchColumn('SELECT MAX(id) FROM companies_house_accounts_submissions');
                };
                $draftSubmissionId = $insertSubmission($draftRunId, 'prepared', null);
                $submittedSubmissionId = $insertSubmission($submittedRunId, 'pending', '2026-01-01 00:00:00');
                $result = $service->removeMissingArtifacts($companyId, $periodId);

                $harness->assertSame(true, (bool)$result['success']);
                $harness->assertSame(2, (int)$result['deleted_count']);
                $harness->assertSame(1, (int)$result['reset_count']);
                $harness->assertSame(1, (int)$result['deleted_draft_count']);
                $harness->assertSame(1, (int)$result['present_count']);
                $harness->assertSame(1, (int)$result['skipped_count']);
                $harness->assertSame([$submittedRunId], $result['skipped_run_ids']);
                $harness->assertSame(1, (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM ixbrl_generation_runs WHERE id = :id', ['id' => $presentRunId]));
                $harness->assertSame(0, (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM ixbrl_generation_runs WHERE id = :id', ['id' => $missingRunId]));
                $harness->assertSame(0, (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM ixbrl_generation_runs WHERE id = :id', ['id' => $draftRunId]));
                $harness->assertSame(0, (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM companies_house_accounts_submissions WHERE id = :id', ['id' => $draftSubmissionId]));
                $harness->assertSame(1, (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM ixbrl_generation_runs WHERE id = :id', ['id' => $factRunId]));
                $harness->assertSame(1, (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM ixbrl_generation_facts WHERE run_id = :id', ['id' => $factRunId]));
                $resetRun = InterfaceDB::fetchOne('SELECT status, generated_path FROM ixbrl_generation_runs WHERE id = :id', ['id' => $factRunId]);
                $harness->assertSame('ready', (string)($resetRun['status'] ?? ''));
                $harness->assertSame('', (string)($resetRun['generated_path'] ?? ''));
                $harness->assertSame(1, (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM ixbrl_generation_runs WHERE id = :id', ['id' => $submittedRunId]));
                $harness->assertSame(1, (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM companies_house_accounts_submissions WHERE id = :id', ['id' => $submittedSubmissionId]));
            } finally {
                @unlink($presentPath);
                InterfaceDB::rollBack();
            }
        });
    }
);
