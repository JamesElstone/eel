<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Resolves an Arelle diagnostic log only from persisted, accounting-scoped validation state. */
final class IxbrlArelleLogDownloadService
{
    public function resolve(
        int $companyId,
        int $accountingPeriodId,
        string $scope,
        int $runId = 0,
        int $ctPeriodId = 0,
        int $submissionId = 0
    ): array {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return $this->failure('Select a valid company and accounting period.');
        }

        $scope = strtolower(trim($scope));
        $path = match ($scope) {
            'accounts' => $this->accountsPath($companyId, $accountingPeriodId, $runId),
            'computation' => $this->computationPath(
                $companyId,
                $accountingPeriodId,
                $ctPeriodId,
                $runId
            ),
            'companies_house' => $this->companiesHousePath(
                $companyId,
                $accountingPeriodId,
                $submissionId
            ),
            default => null,
        };
        if ($path === null || trim($path) === '') {
            return $this->failure('No Arelle diagnostic log is available for this iXBRL validation.');
        }

        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
            return $this->failure('The retained Arelle diagnostic log was not found.');
        }
        $basename = basename(str_replace('\\', '/', $resolved));
        if (preg_match('/^arelle_validation_[A-Za-z0-9_-]+\.log$/', $basename) !== 1) {
            return $this->failure('The retained Arelle diagnostic log has an invalid filename.');
        }

        $identity = $submissionId > 0 ? $submissionId : $runId;
        return [
            'ok' => true,
            'path' => $resolved,
            'filename' => 'arelle-' . str_replace('_', '-', $scope)
                . ($identity > 0 ? '-' . $identity : '') . '.log',
        ];
    }

    private function accountsPath(int $companyId, int $accountingPeriodId, int $runId): ?string
    {
        if ($runId <= 0 || !\InterfaceDB::tableExists('ixbrl_generation_runs')) {
            return null;
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT external_validation_log_path
             FROM ixbrl_generation_runs
             WHERE id = :run_id
               AND company_id = :company_id
               AND accounting_period_id = :period_id
             LIMIT 1',
            [
                'run_id' => $runId,
                'company_id' => $companyId,
                'period_id' => $accountingPeriodId,
            ]
        );

        return is_array($row) ? trim((string)($row['external_validation_log_path'] ?? '')) : null;
    }

    private function computationPath(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        int $runId
    ): ?string {
        if ($ctPeriodId <= 0 || $runId <= 0
            || !\InterfaceDB::tableExists('corporation_tax_periods')
            || !\InterfaceDB::tableExists('corporation_tax_computation_runs')) {
            return null;
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT run.external_validation_log_path
             FROM corporation_tax_periods period
             INNER JOIN corporation_tax_computation_runs run ON run.id = :run_id
             WHERE period.id = :ct_period_id
               AND period.company_id = :company_id
               AND period.accounting_period_id = :period_id
               AND run.company_id = period.company_id
               AND run.accounting_period_id = period.accounting_period_id
               AND run.ct_period_id = period.id
             LIMIT 1',
            [
                'run_id' => $runId,
                'ct_period_id' => $ctPeriodId,
                'company_id' => $companyId,
                'period_id' => $accountingPeriodId,
            ]
        );

        return is_array($row) ? trim((string)($row['external_validation_log_path'] ?? '')) : null;
    }

    private function companiesHousePath(
        int $companyId,
        int $accountingPeriodId,
        int $submissionId
    ): ?string {
        if ($submissionId <= 0
            || !\InterfaceDB::tableExists('companies_house_accounts_submissions')
            || !\InterfaceDB::tableExists('filing_evidence_artifacts')) {
            return null;
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT submission.filing_type, artifact.metadata_json
             FROM companies_house_accounts_submissions submission
             INNER JOIN filing_evidence_artifacts artifact
               ON artifact.bundle_id = submission.evidence_bundle_id
              AND artifact.artifact_role = CASE
                    WHEN submission.filing_type = :original
                    THEN :original_role
                    ELSE :revised_role
                  END
             WHERE submission.id = :submission_id
               AND submission.company_id = :company_id
               AND submission.accounting_period_id = :period_id
             ORDER BY artifact.id DESC
             LIMIT 1',
            [
                'submission_id' => $submissionId,
                'company_id' => $companyId,
                'period_id' => $accountingPeriodId,
                'original' => 'original',
                'original_role' => 'companies_house_original_accounts_ixbrl',
                'revised_role' => 'companies_house_revised_accounts_ixbrl',
            ]
        );
        if (!is_array($row)) {
            return null;
        }
        $metadata = json_decode((string)($row['metadata_json'] ?? ''), true);
        $validation = is_array($metadata) && is_array($metadata['arelle_validation'] ?? null)
            ? $metadata['arelle_validation']
            : [];

        return trim((string)($validation['log_path'] ?? ''));
    }

    private function failure(string $message): array
    {
        return ['ok' => false, 'errors' => [$message]];
    }
}
