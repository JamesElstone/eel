<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Locates the one immutable accounts artifact that is safe to hand to a filing provider. */
final class IxbrlFilingArtifactService
{
    public function locate(int $companyId, int $accountingPeriodId, bool $approvalPinnedOnly = false): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return $this->failure('missing', 'Select a valid company and accounting period.');
        }
        if (!\InterfaceDB::tableExists('ixbrl_accounts_artifacts')
            || !\InterfaceDB::tableExists('ixbrl_validation_runs')) {
            return $this->failure(
                'missing',
                'Apply the authority-specific iXBRL artifact migration before preparing an HMRC filing.'
            );
        }

        $profile = (new IxbrlAuthorityProfileService())->profile(
            IxbrlAuthorityProfileService::HMRC_CT_ACCOUNTS
        );
        $artifact = (new \eel_accounts\Repository\IxbrlAccountsArtifactRepository())->findCurrent(
            $companyId,
            $accountingPeriodId,
            \eel_accounts\Repository\IxbrlAccountsArtifactRepository::AUTHORITY_HMRC,
            'ordinary',
            $profile->key()
        );
        if (!is_array($artifact)) {
            return $this->failure(
                'missing',
                'No HMRC accounts iXBRL artifact exists for this period. Generate the HMRC accounts file.'
            );
        }

        $runId = (int)($artifact['generation_run_id'] ?? 0);
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM ixbrl_generation_runs WHERE id = :id LIMIT 1',
            ['id' => $runId]
        );
        if (!is_array($row)) {
            return $this->failure('missing', 'The HMRC accounts artifact generation run was not found.', $runId);
        }
        if (!hash_equals((string)($artifact['profile_fingerprint'] ?? ''), $profile->fingerprint())
            || (string)($artifact['profile_version'] ?? '') !== $profile->version()
            || (string)($artifact['transformation_registry_uri'] ?? '') !== $profile->transformationNamespace()) {
            return $this->failure(
                'stale',
                'The HMRC accounts iXBRL was built under an obsolete authority profile. Regenerate it.',
                $runId
            );
        }

        $freshness = $approvalPinnedOnly
            ? $this->approvalPinnedFreshness($row)
            : (new IxbrlFactBuilderService())->getRunFreshness($runId);
        if ((string)($freshness['state'] ?? '') !== 'current') {
            return $this->failure(
                'stale',
                (string)($freshness['detail'] ?? 'The generated accounts iXBRL is stale and must be rebuilt.'),
                $runId
            );
        }

        if ((int)($artifact['filing_approval_id'] ?? 0) !== (int)($row['filing_approval_id'] ?? 0)
            || !hash_equals(
                (string)($artifact['filing_approval_hash'] ?? ''),
                (string)($row['filing_approval_hash'] ?? '')
            )) {
            return $this->failure(
                'tampered',
                'The HMRC accounts artifact is not bound to its approved generation basis.',
                $runId
            );
        }

        $path = trim((string)($artifact['output_path'] ?? ''));
        if ($path === '' || !is_file($path)) {
            return $this->failure('missing', 'The HMRC accounts iXBRL file was not found.', $runId);
        }
        $outputHash = strtolower(trim((string)($artifact['output_sha256'] ?? '')));
        $fileHash = (new IxbrlArtifactFingerprintService())->sha256($path);
        if ($outputHash === '' || !is_string($fileHash) || !hash_equals($outputHash, $fileHash)) {
            return $this->failure(
                'tampered',
                'The HMRC accounts iXBRL file has changed since its immutable artifact was recorded.',
                $runId
            );
        }
        if ((string)($row['validation_status'] ?? '') !== 'passed'
            || (string)($row['external_validation_status'] ?? '') !== 'passed'
            || trim((string)($row['external_validator'] ?? '')) === ''
            || trim((string)($row['external_validator_version'] ?? '')) === ''
            || !hash_equals(
                $outputHash,
                strtolower(trim((string)($row['external_validated_sha256'] ?? '')))
            )) {
            return $this->failure(
                'unvalidated',
                'The latest HMRC accounts iXBRL validation attempt is not filing-ready.',
                $runId
            );
        }

        $validation = (new \eel_accounts\Repository\IxbrlValidationRunRepository())->latestForArtifact(
            (int)($artifact['id'] ?? 0),
            $outputHash,
            $profile->fingerprint()
        );
        if (!is_array($validation) || (string)($validation['overall_status'] ?? '') !== 'passed') {
            return $this->failure(
                'unvalidated',
                'The latest HMRC accounts iXBRL validation decision has not passed the HMRC authority profile and Arelle validation.',
                $runId
            );
        }

        return [
            'ok' => true,
            'state' => 'ready',
            'run_id' => $runId,
            'artifact_id' => (int)($artifact['id'] ?? 0),
            'validation_run_id' => (int)($validation['id'] ?? 0),
            'filing_approval_id' => (int)($artifact['filing_approval_id'] ?? 0),
            'path' => $path,
            'filename' => (string)($artifact['output_filename'] ?? basename($path)),
            'warnings' => [],
            'errors' => [],
            'hash' => $outputHash,
            'basis_hash' => (string)($row['basis_hash'] ?? ''),
            'taxonomy_profile' => (string)($artifact['taxonomy_profile'] ?? ''),
            'taxonomy_package_id' => (int)($validation['taxonomy_package_id'] ?? 0),
            'taxonomy_package_hash' => (string)($validation['taxonomy_package_sha256'] ?? ''),
            'validation_status' => (string)($validation['overall_status'] ?? ''),
            'authority_profile' => (string)($artifact['profile_key'] ?? ''),
            'authority_profile_version' => (string)($artifact['profile_version'] ?? ''),
            'authority_profile_fingerprint' => (string)($artifact['profile_fingerprint'] ?? ''),
        ];
    }

    private function failure(string $state, string $message, int $runId = 0): array
    {
        return [
            'ok' => false,
            'state' => $state,
            'run_id' => $runId,
            'artifact_id' => 0,
            'validation_run_id' => 0,
            'filing_approval_id' => 0,
            'path' => null,
            'filename' => null,
            'warnings' => [],
            'errors' => [$message],
            'hash' => null,
            'basis_hash' => null,
        ];
    }

    /**
     * Read-model check for status cards. A current filing approval already
     * recomputes the accounts/disclosures source basis, so confirming that the
     * generated artifact is pinned to that approval is sufficient here. The
     * full source rebuild remains mandatory when a package is prepared.
     *
     * @param array<string,mixed> $run
     * @return array{state:string,detail:string,built_hash?:string}
     */
    private function approvalPinnedFreshness(array $run): array
    {
        $approval = (new IxbrlAccountsFilingApprovalService())->statusForReadModel(
            (int)($run['company_id'] ?? 0),
            (int)($run['accounting_period_id'] ?? 0)
        );
        $current = (array)($approval['approval'] ?? []);
        $builtHash = trim((string)($run['basis_hash'] ?? ''));
        if ($builtHash === ''
            || (string)($run['basis_version'] ?? '') !== IxbrlTaxonomyProfileService::BASIS_VERSION
            || (string)($approval['state'] ?? '') !== 'current'
            || (int)($run['filing_approval_id'] ?? 0) !== (int)($current['id'] ?? 0)
            || !hash_equals(
                (string)($run['filing_approval_hash'] ?? ''),
                (string)($current['basis_hash'] ?? '')
            )) {
            return [
                'state' => 'stale',
                'detail' => 'The facts do not belong to the current approved filing basis. Approve the disclosures again.',
                'built_hash' => $builtHash,
            ];
        }

        return [
            'state' => 'current',
            'detail' => 'The iXBRL facts are pinned to the current approved filing basis.',
            'built_hash' => $builtHash,
        ];
    }
}
