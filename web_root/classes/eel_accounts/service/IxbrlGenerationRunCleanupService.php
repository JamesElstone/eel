<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Removes missing-file accounts iXBRL runs and unsent Companies House drafts that depend on them. */
final class IxbrlGenerationRunCleanupService
{
    /** @return array{success: bool, deleted_count: int, deleted_draft_count: int, present_count: int, skipped_count: int, skipped_run_ids: list<int>, errors: list<string>} */
    public function removeMissingArtifacts(int $companyId, int $accountingPeriodId): array
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
            'SELECT run.id, run.generated_path, ' . $referenceSql . ' AS companies_house_referenced
             FROM ixbrl_generation_runs run
             WHERE run.company_id = :company_id
               AND run.accounting_period_id = :accounting_period_id
               AND run.generated_path IS NOT NULL
               AND LENGTH(TRIM(run.generated_path)) > 0',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );

        $missing = [];
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
            $missing[] = (int)$run['id'];
        }

        $deletedDraftCount = 0;
        if ($missing !== []) {
            \InterfaceDB::transaction(static function () use ($missing, $submissionTableExists, &$deletedDraftCount): void {
                foreach ($missing as $runId) {
                    if ($submissionTableExists) {
                        $deletedDraftCount += \InterfaceDB::execute(
                            "DELETE FROM companies_house_accounts_submissions
                             WHERE ixbrl_generation_run_id = :id
                               AND lifecycle = 'prepared'
                               AND submitted_at IS NULL",
                            ['id' => $runId]
                        );
                    }
                    \InterfaceDB::prepareExecute(
                        'DELETE FROM ixbrl_generation_runs WHERE id = :id',
                        ['id' => $runId]
                    );
                }
            });
        }

        return [
            'success' => true,
            'deleted_count' => count($missing),
            'deleted_draft_count' => $deletedDraftCount,
            'present_count' => $presentCount,
            'skipped_count' => count($skippedRunIds),
            'skipped_run_ids' => $skippedRunIds,
            'errors' => [],
        ];
    }

    /** @return array{success: false, deleted_count: 0, deleted_draft_count: 0, present_count: 0, skipped_count: 0, skipped_run_ids: list<int>, errors: list<string>} */
    private function failure(string $error): array
    {
        return [
            'success' => false,
            'deleted_count' => 0,
            'deleted_draft_count' => 0,
            'present_count' => 0,
            'skipped_count' => 0,
            'skipped_run_ids' => [],
            'errors' => [$error],
        ];
    }
}
