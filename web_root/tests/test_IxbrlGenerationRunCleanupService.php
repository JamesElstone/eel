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
        $harness->check(\eel_accounts\Service\IxbrlGenerationRunCleanupService::class, 'removes only missing unreferenced artifacts', static function () use ($harness, $service): void {
            InterfaceDB::beginTransaction();
            $presentPath = tempnam(sys_get_temp_dir(), 'ixbrl-cleanup-');
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
                $result = $service->removeMissingArtifacts($companyId, $periodId);

                $harness->assertSame(true, (bool)$result['success']);
                $harness->assertSame(1, (int)$result['deleted_count']);
                $harness->assertSame(1, (int)$result['present_count']);
                $harness->assertSame(0, (int)$result['skipped_count']);
                $harness->assertSame([], $result['skipped_run_ids']);
                $harness->assertSame(1, (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM ixbrl_generation_runs WHERE id = :id', ['id' => $presentRunId]));
                $harness->assertSame(0, (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM ixbrl_generation_runs WHERE id = :id', ['id' => $missingRunId]));
            } finally {
                @unlink($presentPath);
                InterfaceDB::rollBack();
            }
        });
    }
);
