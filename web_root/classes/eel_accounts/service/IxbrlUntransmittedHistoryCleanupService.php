<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class IxbrlUntransmittedHistoryCleanupService
{
    public function clean(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            throw new \RuntimeException('Select a valid company and accounting period.');
        }

        return (array)\InterfaceDB::transaction(function () use ($companyId, $accountingPeriodId): array {
            $deletedCh = \InterfaceDB::execute(
                "DELETE FROM companies_house_accounts_submissions
                 WHERE company_id = :company_id AND accounting_period_id = :period_id
                   AND lifecycle = 'prepared' AND submitted_at IS NULL
                   AND COALESCE(evidence_bundle_id, 0) = 0
                   AND NOT EXISTS (
                       SELECT 1 FROM ixbrl_generation_runs run
                       WHERE run.id = companies_house_accounts_submissions.ixbrl_generation_run_id
                         AND run.filing_approval_id IS NOT NULL
                   )",
                ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
            );
            $deletedHmrc = \InterfaceDB::execute(
                "DELETE FROM hmrc_ct600_submissions
                 WHERE company_id = :company_id AND accounting_period_id = :period_id
                   AND protocol_state IN ('prepared', 'validation_failed', 'ready', 'invalidated')
                   AND COALESCE(evidence_bundle_id, 0) = 0",
                ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
            );
            $clearedCt600Outputs = \InterfaceDB::execute(
                "UPDATE corporation_tax_computation_runs run
                 SET ixbrl_status = 'not_generated',
                     computation_taxonomy_package_id = NULL,
                     computation_taxonomy_package_hash = NULL,
                     ixbrl_mapping_profile_id = NULL,
                     ixbrl_mapping_hash = NULL,
                     filing_basis_version = NULL,
                     filing_basis_hash = NULL,
                     generated_path = NULL,
                     generated_filename = NULL,
                     taxonomy_profile = NULL,
                     validation_status = 'not_validated',
                     validation_errors_json = NULL,
                     external_validator = NULL,
                     external_validator_version = NULL,
                     external_validation_status = 'not_configured',
                     external_validation_errors_json = NULL,
                     external_validation_warnings_json = NULL,
                     external_validation_log_path = NULL,
                     external_validated_at = NULL,
                     output_sha256 = NULL,
                     external_validated_sha256 = NULL,
                     ixbrl_generated_at = NULL
                 WHERE run.company_id = :company_id AND run.accounting_period_id = :period_id
                   AND (run.ixbrl_status <> 'not_generated'
                        OR run.generated_filename IS NOT NULL
                        OR run.generated_path IS NOT NULL)
                   AND NOT EXISTS (
                       SELECT 1 FROM ct_period_filing_bases basis
                       WHERE basis.computation_run_id = run.id
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM hmrc_ct600_submissions submission
                       WHERE submission.computation_run_id = run.id
                   )",
                ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
            );
            $deletedRuns = \InterfaceDB::execute(
                'DELETE FROM ixbrl_generation_runs
                 WHERE company_id = :company_id AND accounting_period_id = :period_id
                   AND filing_approval_id IS NULL
                   AND NOT EXISTS (
                       SELECT 1 FROM companies_house_accounts_submissions submission
                       WHERE submission.ixbrl_generation_run_id = ixbrl_generation_runs.id
                   )',
                [
                    'company_id' => $companyId,
                    'period_id' => $accountingPeriodId,
                ]
            );

            return [
                'success' => true,
                'deleted_bundles' => 0,
                'deleted_approvals' => 0,
                'deleted_runs' => $deletedRuns,
                'cleared_ct600_outputs' => $clearedCt600Outputs,
                'deleted_companies_house_drafts' => $deletedCh,
                'deleted_hmrc_drafts' => $deletedHmrc,
            ];
        });
    }

}
