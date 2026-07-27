<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Performs narrow persisted-state and file-integrity checks for artifact downloads. */
final class IxbrlArtifactDownloadService
{
    public function accounts(int $companyId, int $accountingPeriodId): array
    {
        $row = $this->accountsRow($companyId, $accountingPeriodId);
        if (!is_array($row)) {
            return $this->failure('missing', 'No current Accounting iXBRL artifact is available.');
        }
        $error = $this->accountsRowError($row);
        return $error !== null
            ? $this->failure('stale', $error)
            : $this->verifiedFile($row, 'generated_path', 'generated_filename', 'output_sha256', 'external_validated_sha256');
    }

    public function companiesHouse(int $companyId, int $accountingPeriodId): array
    {
        $accounts = $this->accounts($companyId, $accountingPeriodId);
        if (empty($accounts['ok'])) {
            return $this->failure('stale', 'Generate and validate the current HMRC Accounting iXBRL before downloading revised accounts.');
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT id, lifecycle, ixbrl_generation_run_id, revised_artifact_path,
                    revised_artifact_sha256
             FROM companies_house_accounts_submissions
             WHERE company_id = :company_id AND accounting_period_id = :period_id
             ORDER BY id DESC LIMIT 1',
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
        );
        if (!is_array($row)) {
            return $this->failure('missing', 'No Companies House revised-accounts artifact is prepared.');
        }
        $error = $this->companiesHouseRowError($row, (int)($accounts['run_id'] ?? 0));
        if ($error !== null) {
            return $this->failure('stale', $error);
        }
        return $this->verifiedFile(
            $row,
            'revised_artifact_path',
            '',
            'revised_artifact_sha256',
            'revised_artifact_sha256',
            (int)$accounts['run_id']
        );
    }

    public function computation(int $companyId, int $accountingPeriodId, int $ctPeriodId): array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT run.id, run.generated_path, run.generated_filename, run.output_sha256,
                    run.external_validated_sha256, run.status, run.ixbrl_status,
                    run.validation_status, run.external_validation_status,
                    run.filing_basis_hash, basis.basis_hash AS approved_basis_hash,
                    approval.basis_hash AS approval_hash, approval.basis_json AS approval_json,
                    review.is_locked, approval.year_end_review_id, review.id AS current_review_id,
                    approval.year_end_locked_at, review.locked_at AS current_locked_at
             FROM corporation_tax_periods period
             INNER JOIN corporation_tax_computation_runs run ON run.id = period.latest_computation_run_id
             INNER JOIN ixbrl_accounts_filing_approvals approval
               ON approval.id = (
                    SELECT MAX(a.id) FROM ixbrl_accounts_filing_approvals a
                    WHERE a.company_id = period.company_id
                      AND a.accounting_period_id = period.accounting_period_id
               )
             INNER JOIN ct_period_filing_bases basis
               ON basis.filing_approval_id = approval.id
              AND basis.ct_period_id = period.id
              AND basis.computation_run_id = run.id
             INNER JOIN year_end_reviews review
               ON review.company_id = period.company_id
              AND review.accounting_period_id = period.accounting_period_id
             WHERE period.id = :ct_period_id
               AND period.company_id = :company_id
               AND period.accounting_period_id = :period_id
               AND period.status <> :superseded
             LIMIT 1',
            [
                'ct_period_id' => $ctPeriodId,
                'company_id' => $companyId,
                'period_id' => $accountingPeriodId,
                'superseded' => 'superseded',
            ]
        );
        if (!is_array($row)) {
            return $this->failure('missing', 'No current Corporation Tax iXBRL artifact is available for this period.');
        }
        $error = $this->computationRowError($row);
        if ($error !== null) {
            return $this->failure('stale', $error);
        }
        return $this->verifiedFile($row, 'generated_path', 'generated_filename', 'output_sha256', 'external_validated_sha256');
    }

    private function accountsRow(int $companyId, int $accountingPeriodId): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT run.id, run.generated_path, run.generated_filename, run.output_sha256,
                    run.external_validated_sha256, run.status, run.validation_status,
                    run.external_validation_status, run.filing_approval_id, run.filing_approval_hash,
                    approval.id AS approval_id, approval.basis_hash AS approval_hash,
                    approval.basis_json AS approval_json, approval.year_end_review_id,
                    approval.year_end_locked_at, review.id AS current_review_id,
                    review.locked_at AS current_locked_at, review.is_locked
             FROM ixbrl_generation_runs run
             INNER JOIN ixbrl_accounts_filing_approvals approval ON approval.id = run.filing_approval_id
             INNER JOIN year_end_reviews review
               ON review.company_id = run.company_id
              AND review.accounting_period_id = run.accounting_period_id
             WHERE run.company_id = :company_id AND run.accounting_period_id = :period_id
               AND run.id = (
                    SELECT MAX(r.id) FROM ixbrl_generation_runs r
                    WHERE r.company_id = :company_id_latest
                      AND r.accounting_period_id = :period_id_latest
               )
               AND approval.id = (
                    SELECT MAX(a.id) FROM ixbrl_accounts_filing_approvals a
                    WHERE a.company_id = :company_id_approval
                      AND a.accounting_period_id = :period_id_approval
               )
             LIMIT 1',
            [
                'company_id' => $companyId,
                'period_id' => $accountingPeriodId,
                'company_id_latest' => $companyId,
                'period_id_latest' => $accountingPeriodId,
                'company_id_approval' => $companyId,
                'period_id_approval' => $accountingPeriodId,
            ]
        );
        return is_array($row) ? $row : null;
    }

    private function accountsRowError(array $row): ?string
    {
        if (empty($row['is_locked'])
            || (int)$row['year_end_review_id'] !== (int)$row['current_review_id']
            || (string)$row['year_end_locked_at'] !== (string)$row['current_locked_at']) {
            return 'Year End has changed since this Accounting iXBRL was approved.';
        }
        if (!hash_equals((string)$row['approval_hash'], hash('sha256', (string)$row['approval_json']))
            || (int)$row['filing_approval_id'] !== (int)$row['approval_id']
            || !hash_equals((string)$row['filing_approval_hash'], (string)$row['approval_hash'])) {
            return 'The Accounting iXBRL does not belong to the current intact filing approval.';
        }
        if ((string)$row['status'] !== 'generated'
            || (string)$row['validation_status'] !== 'passed'
            || (string)$row['external_validation_status'] !== 'passed') {
            return 'The current Accounting iXBRL has not completed validation.';
        }
        return null;
    }

    private function companiesHouseRowError(array $row, int $accountsRunId): ?string
    {
        if ((int)($row['ixbrl_generation_run_id'] ?? 0) !== $accountsRunId) {
            return 'This Companies House iXBRL belongs to an earlier Accounting iXBRL run.';
        }
        if (!in_array((string)($row['lifecycle'] ?? ''), [
            'prepared', 'submitting', 'transport_unknown', 'pending', 'parked', 'accepted',
        ], true)) {
            return 'The Companies House artifact is not in a downloadable filing state.';
        }
        return null;
    }

    private function computationRowError(array $row): ?string
    {
        $approvalJson = (string)($row['approval_json'] ?? '');
        if (empty($row['is_locked'])
            || (int)$row['year_end_review_id'] !== (int)$row['current_review_id']
            || (string)$row['year_end_locked_at'] !== (string)$row['current_locked_at']
            || !hash_equals((string)$row['approval_hash'], hash('sha256', $approvalJson))
            || !hash_equals((string)$row['filing_basis_hash'], (string)$row['approved_basis_hash'])
            || (string)$row['status'] !== 'generated'
            || (string)$row['ixbrl_status'] !== 'validated'
            || (string)$row['validation_status'] !== 'passed'
            || (string)$row['external_validation_status'] !== 'passed') {
            return 'The Corporation Tax iXBRL artifact is not current and fully validated.';
        }
        return null;
    }

    private function verifiedFile(
        array $row,
        string $pathKey,
        string $filenameKey,
        string $outputHashKey,
        string $validatedHashKey,
        int $runId = 0
    ): array {
        $path = trim((string)($row[$pathKey] ?? ''));
        $outputHash = strtolower(trim((string)($row[$outputHashKey] ?? '')));
        $validatedHash = strtolower(trim((string)($row[$validatedHashKey] ?? '')));
        if ($path === '' || !is_file($path)) {
            return $this->failure('missing', 'The validated iXBRL artifact file was not found.');
        }
        if ($outputHash === '' || $validatedHash === '' || !hash_equals($outputHash, $validatedHash)) {
            return $this->failure('unvalidated', 'The iXBRL artifact validation fingerprints are incomplete or mismatched.');
        }
        $fileHash = hash_file('sha256', $path);
        if (!is_string($fileHash) || !hash_equals($outputHash, strtolower($fileHash))) {
            return $this->failure('tampered', 'The iXBRL artifact has changed since validation.');
        }
        return [
            'ok' => true,
            'state' => 'ready',
            'run_id' => $runId > 0 ? $runId : (int)($row['id'] ?? 0),
            'path' => $path,
            'filename' => $filenameKey !== ''
                ? basename((string)($row[$filenameKey] ?? basename($path)))
                : basename($path),
            'hash' => $outputHash,
            'errors' => [],
        ];
    }

    private function failure(string $state, string $message): array
    {
        return ['ok' => false, 'state' => $state, 'path' => null, 'filename' => null, 'errors' => [$message]];
    }
}
