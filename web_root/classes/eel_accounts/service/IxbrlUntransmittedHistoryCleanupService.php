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

        $approvalStatus = (new IxbrlAccountsFilingApprovalService())->status($companyId, $accountingPeriodId);
        $approval = is_array($approvalStatus['approval'] ?? null) ? (array)$approvalStatus['approval'] : [];
        $currentApprovalId = (int)($approval['id'] ?? 0);
        $currentBundleId = (int)($approval['evidence_bundle_id'] ?? 0);
        if (($approvalStatus['state'] ?? '') !== 'current' || $currentApprovalId <= 0 || $currentBundleId <= 0) {
            throw new \RuntimeException('A current filing approval and evidence bundle are required before cleaning history.');
        }

        $protectedCh = (int)\InterfaceDB::fetchColumn(
            "SELECT COUNT(*) FROM companies_house_accounts_submissions
             WHERE company_id = :company_id AND accounting_period_id = :period_id
               AND (lifecycle <> 'prepared' OR submitted_at IS NOT NULL)",
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
        );
        $protectedHmrc = (int)\InterfaceDB::fetchColumn(
            "SELECT COUNT(*) FROM hmrc_ct600_submissions
             WHERE company_id = :company_id AND accounting_period_id = :period_id
               AND protocol_state NOT IN ('prepared', 'validation_failed', 'ready', 'invalidated')",
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
        );
        if ($protectedCh > 0 || $protectedHmrc > 0) {
            throw new \RuntimeException(
                'Untransmitted history cleanup is unavailable because transmitted or in-flight filing evidence exists.'
            );
        }

        $bundleIds = array_values(array_map(
            static fn(array|int $row): int => is_array($row) ? (int)($row['id'] ?? 0) : (int)$row,
            \InterfaceDB::fetchAll(
                'SELECT id FROM filing_evidence_bundles
                 WHERE company_id = :company_id AND accounting_period_id = :period_id
                   AND id <> :current_id
                 ORDER BY id DESC',
                ['company_id' => $companyId, 'period_id' => $accountingPeriodId, 'current_id' => $currentBundleId]
            )
        ));
        $bundleIds = array_values(array_filter($bundleIds, static fn(int $id): bool => $id > 0));

        return (array)\InterfaceDB::transaction(function () use (
            $companyId,
            $accountingPeriodId,
            $currentApprovalId,
            $currentBundleId,
            $bundleIds
        ): array {
            $deletedCh = \InterfaceDB::execute(
                "DELETE FROM companies_house_accounts_submissions
                 WHERE company_id = :company_id AND accounting_period_id = :period_id
                   AND lifecycle = 'prepared' AND submitted_at IS NULL",
                ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
            );
            $deletedHmrc = \InterfaceDB::execute(
                "DELETE FROM hmrc_ct600_submissions
                 WHERE company_id = :company_id AND accounting_period_id = :period_id
                   AND protocol_state IN ('prepared', 'validation_failed', 'ready', 'invalidated')",
                ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
            );
            $deletedRuns = \InterfaceDB::execute(
                'DELETE FROM ixbrl_generation_runs
                 WHERE company_id = :company_id AND accounting_period_id = :period_id
                   AND (filing_approval_id IS NULL OR filing_approval_id <> :approval_id)',
                ['company_id' => $companyId, 'period_id' => $accountingPeriodId, 'approval_id' => $currentApprovalId]
            );
            $deletedApprovals = \InterfaceDB::execute(
                'DELETE FROM ixbrl_accounts_filing_approvals
                 WHERE company_id = :company_id AND accounting_period_id = :period_id
                   AND id <> :approval_id',
                ['company_id' => $companyId, 'period_id' => $accountingPeriodId, 'approval_id' => $currentApprovalId]
            );

            \InterfaceDB::prepareExecute(
                'UPDATE filing_evidence_bundles SET predecessor_bundle_id = NULL
                 WHERE id = :current_id',
                ['current_id' => $currentBundleId]
            );
            $deletedBundles = 0;
            foreach ($bundleIds as $bundleId) {
                $deletedBundles += \InterfaceDB::execute(
                    'DELETE FROM filing_evidence_bundles WHERE id = :id',
                    ['id' => $bundleId]
                );
            }

            return [
                'success' => true,
                'deleted_bundles' => $deletedBundles,
                'deleted_approvals' => $deletedApprovals,
                'deleted_runs' => $deletedRuns,
                'deleted_companies_house_drafts' => $deletedCh,
                'deleted_hmrc_drafts' => $deletedHmrc,
            ];
        });
    }
}
