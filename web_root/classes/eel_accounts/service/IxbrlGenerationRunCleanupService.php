<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Synchronises missing accounts artifacts without deleting approved fact snapshots. */
final class IxbrlGenerationRunCleanupService
{
    /** @return array{success: bool, deleted_count: int, reset_count: int, deleted_draft_count: int, present_count: int, skipped_count: int, skipped_run_ids: list<int>, errors: list<string>} */
    public function removeMissingArtifacts(int $companyId, int $accountingPeriodId): array
    {
        $inspection = $this->inspectMissingArtifacts($companyId, $accountingPeriodId);
        if (empty($inspection['success'])) {
            return $inspection;
        }

        $deletable = array_map('intval', (array)($inspection['deletable_run_ids'] ?? []));
        $resettable = array_map('intval', (array)($inspection['resettable_run_ids'] ?? []));
        $submissionTableExists = \InterfaceDB::tableExists('companies_house_accounts_submissions');
        $deletedDraftCount = 0;
        $synchronised = array_merge($deletable, $resettable);
        if ($synchronised !== []) {
            \InterfaceDB::transaction(static function () use ($synchronised, $deletable, $resettable, $submissionTableExists, &$deletedDraftCount): void {
                foreach ($synchronised as $runId) {
                    if ($submissionTableExists) {
                        $deletedDraftCount += \InterfaceDB::execute(
                            "DELETE FROM companies_house_accounts_submissions
                             WHERE ixbrl_generation_run_id = :id
                               AND lifecycle = 'prepared'
                               AND submitted_at IS NULL",
                            ['id' => $runId]
                        );
                    }
                }
                foreach ($resettable as $runId) {
                    \InterfaceDB::prepareExecute(
                        "UPDATE ixbrl_generation_runs
                         SET status = 'ready', export_type = 'preview',
                             generated_filename = NULL, generated_path = NULL,
                             output_sha256 = NULL, generated_at = NULL,
                             validation_status = 'not_validated', validation_errors_json = NULL,
                             external_validator = NULL, external_validator_version = NULL,
                             external_validation_status = 'not_configured',
                             external_validation_errors_json = NULL,
                             external_validation_warnings_json = NULL,
                             external_validation_log_path = NULL,
                             external_validated_at = NULL, external_validated_sha256 = NULL,
                             external_taxonomy_package_id = NULL, external_taxonomy_sha256 = NULL,
                             error_message = NULL
                         WHERE id = :id",
                        ['id' => $runId]
                    );
                }
                foreach ($deletable as $runId) {
                    \InterfaceDB::prepareExecute(
                        'DELETE FROM ixbrl_generation_runs WHERE id = :id',
                        ['id' => $runId]
                    );
                }
            });
        }

        return array_replace($inspection, [
            'deleted_count' => count($deletable),
            'reset_count' => count($resettable),
            'deleted_draft_count' => $deletedDraftCount,
        ]);
    }

    /** @return array{success: bool, deleted_count: int, reset_count: int, deleted_draft_count: int, present_count: int, skipped_count: int, skipped_run_ids: list<int>, deletable_run_ids: list<int>, resettable_run_ids: list<int>, errors: list<string>} */
    public function inspectMissingArtifacts(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return $this->failure('Select a valid company and accounting period.');
        }
        if (!\InterfaceDB::tableExists('ixbrl_generation_runs')) {
            return $this->failure('The iXBRL generation-run table is unavailable.');
        }

        $submissionTableExists = \InterfaceDB::tableExists('companies_house_accounts_submissions');
        $referenceSql = $submissionTableExists
            ? "EXISTS (
                   SELECT 1
                   FROM companies_house_accounts_submissions submission
                   WHERE submission.ixbrl_generation_run_id = run.id
                     AND (submission.lifecycle <> 'prepared' OR submission.submitted_at IS NOT NULL)
               )"
            : '0';
        $runs = \InterfaceDB::fetchAll(
            'SELECT run.id, run.generated_path, COUNT(fact.id) AS fact_count,
                    ' . $referenceSql . ' AS companies_house_referenced
             FROM ixbrl_generation_runs run
             LEFT JOIN ixbrl_generation_facts fact ON fact.run_id = run.id
             WHERE run.company_id = :company_id
               AND run.accounting_period_id = :accounting_period_id
               AND run.generated_path IS NOT NULL
               AND LENGTH(TRIM(run.generated_path)) > 0
             GROUP BY run.id, run.generated_path',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );

        $deletable = [];
        $resettable = [];
        $presentCount = 0;
        $skippedRunIds = [];
        foreach ($runs as $run) {
            $path = trim((string)($run['generated_path'] ?? ''));
            if ($path !== '' && is_file($path)) {
                $presentCount++;
                continue;
            }
            if (!empty($run['companies_house_referenced'])) {
                $skippedRunIds[] = (int)$run['id'];
                continue;
            }
            if ((int)($run['fact_count'] ?? 0) > 0) {
                $resettable[] = (int)$run['id'];
            } else {
                $deletable[] = (int)$run['id'];
            }
        }

        return [
            'success' => true,
            'deleted_count' => 0,
            'reset_count' => 0,
            'deleted_draft_count' => 0,
            'present_count' => $presentCount,
            'skipped_count' => count($skippedRunIds),
            'skipped_run_ids' => $skippedRunIds,
            'deletable_run_ids' => $deletable,
            'resettable_run_ids' => $resettable,
            'errors' => [],
        ];
    }

    /** @return array{success: false, deleted_count: 0, reset_count: 0, deleted_draft_count: 0, present_count: 0, skipped_count: 0, skipped_run_ids: list<int>, deletable_run_ids: list<int>, resettable_run_ids: list<int>, errors: list<string>} */
    private function failure(string $error): array
    {
        return [
            'success' => false,
            'deleted_count' => 0,
            'reset_count' => 0,
            'deleted_draft_count' => 0,
            'present_count' => 0,
            'skipped_count' => 0,
            'skipped_run_ids' => [],
            'deletable_run_ids' => [],
            'resettable_run_ids' => [],
            'errors' => [$error],
        ];
    }
}
