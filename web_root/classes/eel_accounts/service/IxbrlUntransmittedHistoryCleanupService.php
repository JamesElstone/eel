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
    /**
     * Removes local filing records that have never been transmitted.
     *
     * The newest approval and Corporation Tax audit snapshot for each CT period
     * are retained. Older approvals, evidence bundles and audit snapshots are
     * removable only when they have not been transmitted to a filing authority.
     * Generated files are deliberately not deleted.
     */
    public function clean(
        int $companyId,
        int $accountingPeriodId,
        ?int $retainApprovalId = null,
        string $actor = 'system'
    ): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            throw new \RuntimeException('Select a valid company and accounting period.');
        }

        $automaticApprovalCleanup = $retainApprovalId !== null && $retainApprovalId > 0;
        $retainApprovalId = $retainApprovalId !== null && $retainApprovalId > 0
            ? $retainApprovalId
            : $this->latestApprovalId($companyId, $accountingPeriodId);

        return (array)\InterfaceDB::transaction(function () use (
            $companyId,
            $accountingPeriodId,
            $retainApprovalId,
            $actor,
            $automaticApprovalCleanup
        ): array {
            $deletedCh = \InterfaceDB::execute(
                "DELETE FROM companies_house_accounts_submissions
                 WHERE company_id = :company_id AND accounting_period_id = :period_id
                   AND lifecycle = 'prepared' AND submitted_at IS NULL
                   AND NOT EXISTS (
                       SELECT 1 FROM ixbrl_generation_runs retained_run
                       WHERE retained_run.id = companies_house_accounts_submissions.ixbrl_generation_run_id
                         AND retained_run.filing_approval_id = :retain_approval_id
                   )
                   ",
                [
                    'company_id' => $companyId,
                    'period_id' => $accountingPeriodId,
                    'retain_approval_id' => $retainApprovalId,
                ]
            );
            $deletedHmrc = \InterfaceDB::execute(
                "DELETE FROM hmrc_ct600_submissions
                 WHERE company_id = :company_id AND accounting_period_id = :period_id
                   AND submitted_at IS NULL
                   AND NOT EXISTS (
                       SELECT 1 FROM ixbrl_generation_runs retained_run
                       WHERE retained_run.id = hmrc_ct600_submissions.accounts_run_id
                         AND retained_run.filing_approval_id = :retain_accounts_approval_id
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM ct_period_filing_bases retained_basis
                       WHERE retained_basis.computation_run_id = hmrc_ct600_submissions.computation_run_id
                         AND retained_basis.filing_approval_id = :retain_ct_approval_id
                   )",
                [
                    'company_id' => $companyId,
                    'period_id' => $accountingPeriodId,
                    'retain_accounts_approval_id' => $retainApprovalId,
                    'retain_ct_approval_id' => $retainApprovalId,
                ]
            );

            $approvals = \InterfaceDB::fetchAll(
                "SELECT approval.id
                 FROM ixbrl_accounts_filing_approvals approval
                 WHERE approval.company_id = :company_id
                   AND approval.accounting_period_id = :period_id
                   AND approval.id <> :retain_approval_id
                   AND NOT EXISTS (
                       SELECT 1
                       FROM companies_house_accounts_submissions submission
                       INNER JOIN ixbrl_generation_runs accounts_run
                               ON accounts_run.id = submission.ixbrl_generation_run_id
                       WHERE accounts_run.filing_approval_id = approval.id
                         AND submission.submitted_at IS NOT NULL
                   )
                   AND NOT EXISTS (
                       SELECT 1
                       FROM hmrc_ct600_submissions submission
                       INNER JOIN ixbrl_generation_runs accounts_run
                               ON accounts_run.id = submission.accounts_run_id
                       WHERE accounts_run.filing_approval_id = approval.id
                         AND submission.submitted_at IS NOT NULL
                   )
                   AND NOT EXISTS (
                       SELECT 1
                       FROM hmrc_ct600_submissions submission
                       INNER JOIN ct_period_filing_bases basis
                               ON basis.computation_run_id = submission.computation_run_id
                       WHERE basis.filing_approval_id = approval.id
                         AND submission.submitted_at IS NOT NULL
                   )",
                [
                    'company_id' => $companyId,
                    'period_id' => $accountingPeriodId,
                    'retain_approval_id' => $retainApprovalId,
                ]
            ) ?: [];

            $deletedApprovals = 0;
            foreach ($approvals as $approval) {
                $approvalId = (int)($approval['id'] ?? 0);
                if ($approvalId <= 0) {
                    continue;
                }

                // The foreign key is RESTRICT, so detach local accounts runs
                // before deleting the approval. Its CT filing bases cascade.
                \InterfaceDB::prepareExecute(
                    "UPDATE ixbrl_generation_runs
                     SET filing_approval_id = NULL, filing_approval_hash = NULL
                     WHERE filing_approval_id = :approval_id",
                    ['approval_id' => $approvalId]
                );
                $deletedApprovals += \InterfaceDB::execute(
                    'DELETE FROM ixbrl_accounts_filing_approvals WHERE id = :approval_id',
                    ['approval_id' => $approvalId]
                );
            }

            $clearedCt600Outputs = \InterfaceDB::execute(
                "UPDATE corporation_tax_computation_runs run
                 SET ixbrl_status = 'not_generated',
                     computation_taxonomy_package_id = NULL,
                     computation_taxonomy_package_hash = NULL,
                     ixbrl_mapping_profile_id = NULL,
                     ixbrl_mapping_hash = NULL,
                     ixbrl_tagging_version = NULL,
                     ixbrl_presentation_version = NULL,
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

            $bundleCleanup = (new FilingEvidenceService())->cleanupUnusedHistoricForAccountingPeriod(
                $companyId,
                $accountingPeriodId,
                $actor,
                $automaticApprovalCleanup ? 'approval_replacement' : 'developer'
            );
            $snapshotCleanup = $this->deleteObsoleteTaxAuditSnapshots($companyId, $accountingPeriodId);

            $result = [
                'success' => true,
                'retained_approval_id' => $retainApprovalId,
                'deleted_bundles' => (int)($bundleCleanup['deleted_count'] ?? 0),
                'deleted_approvals' => $deletedApprovals,
                'deleted_runs' => $deletedRuns,
                'cleared_ct600_outputs' => $clearedCt600Outputs,
                'deleted_companies_house_drafts' => $deletedCh,
                'deleted_hmrc_drafts' => $deletedHmrc,
                'deleted_tax_audit_snapshots' => (int)$snapshotCleanup['deleted_snapshots'],
                'deleted_tax_audit_areas' => (int)$snapshotCleanup['deleted_areas'],
                'released_tax_audit_payload_bytes' => (int)$snapshotCleanup['payload_bytes'],
            ];
            $this->recordCleanupAudit(
                $companyId,
                $accountingPeriodId,
                $actor,
                $automaticApprovalCleanup,
                $result
            );
            return $result;
        });
    }

    private function latestApprovalId(int $companyId, int $accountingPeriodId): int
    {
        return max(0, (int)\InterfaceDB::fetchColumn(
            'SELECT id FROM ixbrl_accounts_filing_approvals
             WHERE company_id = :company_id AND accounting_period_id = :period_id
             ORDER BY id DESC LIMIT 1',
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
        ));
    }

    /** @param array<string,mixed> $result */
    private function recordCleanupAudit(
        int $companyId,
        int $accountingPeriodId,
        string $actor,
        bool $automatic,
        array $result
    ): void {
        $deleted = (int)($result['deleted_approvals'] ?? 0)
            + (int)($result['deleted_bundles'] ?? 0)
            + (int)($result['deleted_runs'] ?? 0)
            + (int)($result['deleted_companies_house_drafts'] ?? 0)
            + (int)($result['deleted_hmrc_drafts'] ?? 0)
            + (int)($result['deleted_tax_audit_snapshots'] ?? 0);
        if ($deleted <= 0 || !\InterfaceDB::tableExists('year_end_audit_log')) {
            return;
        }

        \InterfaceDB::prepareExecute(
            'INSERT INTO year_end_audit_log
                (company_id, accounting_period_id, action, action_by, action_at, new_value_json, notes)
             VALUES
                (:company_id, :period_id, :action, :actor, CURRENT_TIMESTAMP, :value, :notes)',
            [
                'company_id' => $companyId,
                'period_id' => $accountingPeriodId,
                'action' => 'unsubmitted_tax_history_cleanup',
                'actor' => substr(trim($actor) !== '' ? trim($actor) : 'system', 0, 100),
                'value' => \eel_accounts\Support\Utf8::json($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'notes' => $automatic
                    ? 'A replacement filing approval removed obsolete unsubmitted Corporation Tax history.'
                    : 'Developer cleanup removed obsolete unsubmitted Corporation Tax history.',
            ]
        );
    }

    /** @return array{deleted_snapshots:int,deleted_areas:int,payload_bytes:int} */
    private function deleteObsoleteTaxAuditSnapshots(int $companyId, int $accountingPeriodId): array
    {
        if (!\InterfaceDB::tableExists('corporation_tax_audit_snapshots')
            || !\InterfaceDB::tableExists('corporation_tax_audit_areas')) {
            return ['deleted_snapshots' => 0, 'deleted_areas' => 0, 'payload_bytes' => 0];
        }

        $lengthFunction = \InterfaceDB::driverName() === 'sqlite' ? 'LENGTH' : 'OCTET_LENGTH';
        $candidates = \InterfaceDB::fetchAll(
            'SELECT snapshot.id,
                    COALESCE(' . $lengthFunction . '(snapshot.calculation_trace_json), 0)
                    + COALESCE((
                        SELECT SUM(' . $lengthFunction . '(area.detail_json))
                        FROM corporation_tax_audit_areas area
                        WHERE area.snapshot_id = snapshot.id
                    ), 0) AS payload_bytes,
                    (
                        SELECT COUNT(*)
                        FROM corporation_tax_audit_areas area
                        WHERE area.snapshot_id = snapshot.id
                    ) AS area_count
             FROM corporation_tax_audit_snapshots snapshot
             INNER JOIN corporation_tax_periods period ON period.id = snapshot.ct_period_id
             WHERE snapshot.company_id = :company_id
               AND snapshot.accounting_period_id = :period_id
               AND (period.latest_computation_run_id IS NULL
                    OR snapshot.computation_run_id <> period.latest_computation_run_id)
               AND snapshot.id <> (
                   SELECT MAX(newest.id)
                   FROM corporation_tax_audit_snapshots newest
                   WHERE newest.ct_period_id = snapshot.ct_period_id
               )
               AND NOT EXISTS (
                   SELECT 1 FROM filing_evidence_ct_snapshots evidence
                   WHERE evidence.tax_audit_snapshot_id = snapshot.id
               )
               AND NOT EXISTS (
                   SELECT 1 FROM hmrc_ct600_submissions submission
                   WHERE submission.computation_run_id = snapshot.computation_run_id
                     AND submission.submitted_at IS NOT NULL
               )
             ORDER BY snapshot.id',
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
        ) ?: [];

        $deletedSnapshots = 0;
        $deletedAreas = 0;
        $payloadBytes = 0;
        foreach ($candidates as $candidate) {
            $snapshotId = (int)($candidate['id'] ?? 0);
            if ($snapshotId <= 0) {
                continue;
            }
            $deletedAreas += \InterfaceDB::execute(
                'DELETE FROM corporation_tax_audit_areas WHERE snapshot_id = :snapshot_id',
                ['snapshot_id' => $snapshotId]
            );
            $deleted = \InterfaceDB::execute(
                'DELETE FROM corporation_tax_audit_snapshots
                 WHERE id = :snapshot_id
                   AND NOT EXISTS (
                       SELECT 1 FROM filing_evidence_ct_snapshots evidence
                       WHERE evidence.tax_audit_snapshot_id = corporation_tax_audit_snapshots.id
                   )',
                ['snapshot_id' => $snapshotId]
            );
            if ($deleted === 1) {
                $deletedSnapshots++;
                $payloadBytes += (int)($candidate['payload_bytes'] ?? 0);
            }
        }

        return [
            'deleted_snapshots' => $deletedSnapshots,
            'deleted_areas' => $deletedAreas,
            'payload_bytes' => $payloadBytes,
        ];
    }

}
