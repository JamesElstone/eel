<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class IxbrlGenerationHistoryService
{
    public function fetch(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [];
        }

        $params = ['company_id' => $companyId, 'period_id' => $accountingPeriodId];
        $rows = [];
        if (\InterfaceDB::tableExists('ixbrl_generation_runs')) {
            $rows = array_merge($rows, $this->accountsRows($params));
        }
        if (\InterfaceDB::tableExists('corporation_tax_computation_runs')
            && \InterfaceDB::tableExists('ct_period_filing_bases')) {
            $rows = array_merge($rows, $this->ct600Rows($params));
        }
        if (\InterfaceDB::tableExists('companies_house_accounts_submissions')) {
            $rows = array_merge($rows, $this->companiesHouseRows($params));
        }

        foreach ($rows as &$row) {
            $path = trim((string)($row['generated_path'] ?? ''));
            $row['artifact_exists'] = $path !== '' && is_file($path);
            if (trim((string)($row['generated_filename'] ?? '')) === '' && $path !== '') {
                $row['generated_filename'] = basename($path);
            }
        }
        unset($row);

        usort($rows, fn(array $left, array $right): int => $this->compareRows($left, $right));
        $latestKey = $this->latestRowKey($rows);
        foreach ($rows as &$row) {
            $row['is_latest'] = $this->rowKey($row) === $latestKey;
        }
        unset($row);

        return $rows;
    }

    private function accountsRows(array $params): array
    {
        return \InterfaceDB::fetchAll(
            "SELECT 'hmrc_accounting' AS output_type, 'HMRC Accounting' AS output_label,
                    run.id AS source_id, run.id AS run_id, '' AS ct_period_label,
                    run.filing_approval_id, approval.evidence_bundle_id, approval.approved_at,
                    (SELECT COUNT(*) FROM ixbrl_generation_facts fact WHERE fact.run_id = run.id) AS fact_count,
                    run.status AS output_status, run.generated_filename, run.generated_path,
                    run.validation_status, run.external_validation_status,
                    run.created_at, run.generated_at,
                    COALESCE(run.generated_at, run.created_at) AS history_at
             FROM ixbrl_generation_runs run
             LEFT JOIN ixbrl_accounts_filing_approvals approval ON approval.id = run.filing_approval_id
             WHERE run.company_id = :company_id AND run.accounting_period_id = :period_id",
            $params
        ) ?: [];
    }

    private function ct600Rows(array $params): array
    {
        return \InterfaceDB::fetchAll(
            "SELECT 'hmrc_ct600' AS output_type, 'HMRC CT600' AS output_label,
                    run.id AS source_id, run.id AS run_id,
                    CONCAT('CT period ', period.sequence_no, ' (', period.period_start, ' to ', period.period_end, ')') AS ct_period_label,
                    basis.filing_approval_id, approval.evidence_bundle_id, approval.approved_at,
                    NULL AS fact_count, run.ixbrl_status AS output_status,
                    run.generated_filename, run.generated_path,
                    run.validation_status, run.external_validation_status,
                    run.generated_at AS created_at, run.ixbrl_generated_at AS generated_at,
                    COALESCE(run.ixbrl_generated_at, run.generated_at) AS history_at
             FROM corporation_tax_computation_runs run
             INNER JOIN corporation_tax_periods period ON period.id = run.ct_period_id
             LEFT JOIN ct_period_filing_bases basis
                    ON basis.computation_run_id = run.id
                   AND basis.company_id = run.company_id
                   AND basis.accounting_period_id = run.accounting_period_id
             LEFT JOIN ixbrl_accounts_filing_approvals approval ON approval.id = basis.filing_approval_id
             WHERE run.company_id = :company_id AND run.accounting_period_id = :period_id
               AND (run.ixbrl_status <> 'not_generated'
                    OR run.generated_filename IS NOT NULL
                    OR run.generated_path IS NOT NULL)",
            $params
        ) ?: [];
    }

    private function companiesHouseRows(array $params): array
    {
        $evidenceJoin = \InterfaceDB::tableExists('filing_evidence_artifacts')
            ? "LEFT JOIN filing_evidence_artifacts artifact
                    ON artifact.bundle_id = submission.evidence_bundle_id
                   AND artifact.storage_path = COALESCE(submission.artifact_path, submission.revised_artifact_path)"
            : '';
        $artifactColumns = $evidenceJoin === ''
            ? "'generated' AS output_status, '' AS generated_filename,
                    'not_validated' AS validation_status, 'not_validated' AS external_validation_status"
            : "COALESCE(artifact.artifact_status, 'generated') AS output_status,
                    artifact.filename AS generated_filename,
                    'not_validated' AS validation_status,
                    COALESCE(artifact.validation_status, 'not_validated') AS external_validation_status";

        return \InterfaceDB::fetchAll(
            "SELECT CONCAT('companies_house_', submission.filing_type) AS output_type,
                    CONCAT('Companies House ', UPPER(LEFT(submission.filing_type, 1)), SUBSTRING(submission.filing_type, 2)) AS output_label,
                    submission.id AS source_id, submission.ixbrl_generation_run_id AS run_id,
                    '' AS ct_period_label, run.filing_approval_id,
                    COALESCE(approval.evidence_bundle_id, submission.evidence_bundle_id) AS evidence_bundle_id,
                    approval.approved_at,
                    (SELECT COUNT(*) FROM ixbrl_generation_facts fact WHERE fact.run_id = run.id) AS fact_count,
                    {$artifactColumns},
                    COALESCE(submission.artifact_path, submission.revised_artifact_path) AS generated_path,
                    submission.prepared_at AS created_at, submission.prepared_at AS generated_at,
                    submission.prepared_at AS history_at
             FROM companies_house_accounts_submissions submission
             LEFT JOIN ixbrl_generation_runs run ON run.id = submission.ixbrl_generation_run_id
             LEFT JOIN ixbrl_accounts_filing_approvals approval ON approval.id = run.filing_approval_id
             {$evidenceJoin}
             WHERE submission.company_id = :company_id AND submission.accounting_period_id = :period_id
               AND COALESCE(submission.artifact_path, submission.revised_artifact_path) IS NOT NULL
               AND COALESCE(submission.artifact_path, submission.revised_artifact_path) <> ''",
            $params
        ) ?: [];
    }

    private function compareRows(array $left, array $right): int
    {
        $leftApprovalId = (int)($left['filing_approval_id'] ?? 0);
        $rightApprovalId = (int)($right['filing_approval_id'] ?? 0);
        if (($leftApprovalId <= 0) !== ($rightApprovalId <= 0)) {
            return $leftApprovalId <= 0 ? 1 : -1;
        }
        if ($leftApprovalId !== $rightApprovalId) {
            return $rightApprovalId <=> $leftApprovalId;
        }
        $time = strcmp((string)($right['history_at'] ?? ''), (string)($left['history_at'] ?? ''));
        if ($time !== 0) {
            return $time;
        }
        return (int)($right['source_id'] ?? 0) <=> (int)($left['source_id'] ?? 0);
    }

    private function latestRowKey(array $rows): string
    {
        $latest = [];
        foreach ($rows as $row) {
            if ($latest === []
                || strcmp((string)($row['history_at'] ?? ''), (string)($latest['history_at'] ?? '')) > 0
                || ((string)($row['history_at'] ?? '') === (string)($latest['history_at'] ?? '')
                    && (int)($row['source_id'] ?? 0) > (int)($latest['source_id'] ?? 0))) {
                $latest = $row;
            }
        }
        return $latest === [] ? '' : $this->rowKey($latest);
    }

    private function rowKey(array $row): string
    {
        return (string)($row['output_type'] ?? '') . ':' . (int)($row['source_id'] ?? 0)
            . ':' . (int)($row['filing_approval_id'] ?? 0);
    }
}
