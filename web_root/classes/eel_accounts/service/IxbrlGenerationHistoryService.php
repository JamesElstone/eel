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

        $submissionColumns = \InterfaceDB::tableExists('companies_house_accounts_submissions')
            ? ", (SELECT COUNT(*) FROM companies_house_accounts_submissions submission
                    WHERE submission.ixbrl_generation_run_id = run.id) AS companies_house_count
               , (SELECT COUNT(*) FROM companies_house_accounts_submissions submission
                    WHERE submission.ixbrl_generation_run_id = run.id
                      AND (submission.lifecycle <> 'prepared' OR submission.submitted_at IS NOT NULL)
                 ) AS companies_house_filed_count"
            : ', 0 AS companies_house_count, 0 AS companies_house_filed_count';
        $rows = \InterfaceDB::fetchAll(
            "SELECT run.*, COUNT(fact.id) AS fact_count,
                    approval.approved_at, approval.approved_by,
                    approval.evidence_bundle_id
                    {$submissionColumns}
             FROM ixbrl_generation_runs run
             LEFT JOIN ixbrl_generation_facts fact ON fact.run_id = run.id
             LEFT JOIN ixbrl_accounts_filing_approvals approval
                    ON approval.id = run.filing_approval_id
             WHERE run.company_id = :company_id
               AND run.accounting_period_id = :period_id
             GROUP BY run.id
             ORDER BY CASE WHEN run.filing_approval_id IS NULL THEN 1 ELSE 0 END,
                      run.filing_approval_id DESC,
                      run.id DESC",
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
        );

        foreach ($rows as &$row) {
            $path = trim((string)($row['generated_path'] ?? ''));
            $row['artifact_exists'] = $path !== '' && is_file($path);
        }
        unset($row);

        return $rows;
    }
}
