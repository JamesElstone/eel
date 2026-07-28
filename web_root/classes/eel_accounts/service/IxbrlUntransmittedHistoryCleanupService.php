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
        [$currentApprovalId, $currentBundleId] = $this->protectedCurrentApproval($approvalStatus);
        if ($currentApprovalId > 0 && (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*) FROM filing_evidence_bundles WHERE id = :id',
            ['id' => $currentBundleId]
        ) !== 1) {
            throw new \RuntimeException('The current filing approval has no preserved evidence bundle, so history cannot be cleaned safely.');
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
                   AND id <> :protected_bundle_id
                 ORDER BY id DESC',
                [
                    'company_id' => $companyId,
                    'period_id' => $accountingPeriodId,
                    'protected_bundle_id' => $currentBundleId,
                ]
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
                   AND COALESCE(filing_approval_id, 0) <> :protected_approval_id',
                [
                    'company_id' => $companyId,
                    'period_id' => $accountingPeriodId,
                    'protected_approval_id' => $currentApprovalId,
                ]
            );
            $deletedApprovals = \InterfaceDB::execute(
                'DELETE FROM ixbrl_accounts_filing_approvals
                 WHERE company_id = :company_id AND accounting_period_id = :period_id
                   AND id <> :protected_approval_id',
                [
                    'company_id' => $companyId,
                    'period_id' => $accountingPeriodId,
                    'protected_approval_id' => $currentApprovalId,
                ]
            );

            if ($currentBundleId > 0) {
                \InterfaceDB::prepareExecute(
                    'UPDATE filing_evidence_bundles SET predecessor_bundle_id = NULL
                     WHERE id = :current_id',
                    ['current_id' => $currentBundleId]
                );
            }
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

    /** @return array{0:int,1:int} Approval and bundle IDs which must be retained, if any. */
    private function protectedCurrentApproval(array $approvalStatus): array
    {
        if (($approvalStatus['state'] ?? '') !== 'current') {
            // A stale basis is history rather than a filing basis. It can be
            // removed after the submission-state checks above have confirmed
            // that no transmitted or in-flight evidence exists.
            return [0, 0];
        }

        $approval = is_array($approvalStatus['approval'] ?? null) ? (array)$approvalStatus['approval'] : [];
        $approvalId = (int)($approval['id'] ?? 0);
        $bundleId = (int)($approval['evidence_bundle_id'] ?? 0);
        if ($approvalId <= 0 || $bundleId <= 0) {
            throw new \RuntimeException('The current filing approval has no evidence bundle, so history cannot be cleaned safely.');
        }

        return [$approvalId, $bundleId];
    }
}
