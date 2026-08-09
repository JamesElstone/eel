<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Synchronises missing CT600 XML artifacts without altering filing history. */
final class Ct600GenerationArtifactCleanupService
{
    /** @return array{success: bool, deleted_count: int, present_count: int, skipped_count: int, errors: list<string>} */
    public function removeMissingArtifacts(int $companyId, int $accountingPeriodId): array
    {
        $inspection = $this->inspectMissingArtifacts($companyId, $accountingPeriodId);
        if (empty($inspection['success'])) {
            return $inspection;
        }

        $deletable = array_map('intval', (array)($inspection['deletable_artifact_ids'] ?? []));
        if ($deletable !== []) {
            \InterfaceDB::transaction(static function () use ($deletable): void {
                foreach ($deletable as $artifactId) {
                    \InterfaceDB::prepareExecute(
                        'DELETE FROM ct600_generated_artifacts WHERE id = :id',
                        ['id' => $artifactId]
                    );
                }
            });
            \eel_accounts\Support\RequestCache::clear();
        }

        return array_replace($inspection, ['deleted_count' => count($deletable)]);
    }

    /** @return array{success: bool, deleted_count: int, present_count: int, skipped_count: int, deletable_artifact_ids: list<int>, errors: list<string>} */
    public function inspectMissingArtifacts(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return $this->failure('Select a valid company and accounting period.');
        }
        if (!\InterfaceDB::tableExists('ct600_generated_artifacts')) {
            return $this->failure('The CT600 artifact registry is unavailable.');
        }

        $submissionsExist = \InterfaceDB::tableExists('hmrc_ct600_submissions');
        $artifacts = \InterfaceDB::fetchAll(
            'SELECT id, ct_period_id, output_path
             FROM ct600_generated_artifacts
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             ORDER BY id',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );

        $deletable = [];
        $presentCount = 0;
        $skippedCount = 0;
        foreach ($artifacts as $artifact) {
            $path = trim((string)($artifact['output_path'] ?? ''));
            if ($path !== '' && is_file($path)) {
                $presentCount++;
                continue;
            }
            $submission = $submissionsExist ? \InterfaceDB::fetchOne(
                "SELECT id FROM hmrc_ct600_submissions
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                   AND ct_period_id = :ct_period_id
                   AND ct600_xml_path = :output_path
                   AND status <> 'draft'
                 LIMIT 1",
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'ct_period_id' => (int)$artifact['ct_period_id'],
                    'output_path' => $path,
                ]
            ) : null;
            if (!empty($submission)) {
                $skippedCount++;
                continue;
            }
            $deletable[] = (int)$artifact['id'];
        }

        return [
            'success' => true,
            'deleted_count' => 0,
            'present_count' => $presentCount,
            'skipped_count' => $skippedCount,
            'deletable_artifact_ids' => $deletable,
            'errors' => [],
        ];
    }

    /** @return array{success: false, deleted_count: 0, present_count: 0, skipped_count: 0, deletable_artifact_ids: list<int>, errors: list<string>} */
    private function failure(string $error): array
    {
        return [
            'success' => false,
            'deleted_count' => 0,
            'present_count' => 0,
            'skipped_count' => 0,
            'deletable_artifact_ids' => [],
            'errors' => [$error],
        ];
    }
}
