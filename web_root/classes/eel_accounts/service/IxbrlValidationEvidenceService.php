<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

use eel_accounts\Repository\IxbrlAccountsArtifactRepository;
use eel_accounts\Repository\IxbrlValidationRunRepository;

/** Persists the immutable artifact and its authority-specific validation decision. */
final class IxbrlValidationEvidenceService
{
    /** @param array<string,mixed> $record */
    public function createAccountsArtifact(array $record, IxbrlAuthorityProfile $profile): int
    {
        $authority = strtoupper(trim((string)($record['authority'] ?? '')));
        if ($authority === '' || $authority !== $profile->authority()) {
            throw new \InvalidArgumentException(
                'The accounts artifact authority must match its authority validation profile.'
            );
        }
        $record['authority'] = $authority;
        $record['profile_key'] = $profile->key();
        $record['profile_version'] = $profile->version();
        $record['profile_fingerprint'] = $profile->fingerprint();
        $record['transformation_registry_uri'] = $profile->transformationNamespace();

        return (new IxbrlAccountsArtifactRepository())->create($record);
    }

    /**
     * @param array<string,mixed> $authorityResult
     * @param array<string,mixed> $externalResult
     * @param array<string,mixed> $sourceResults
     * @param array<string,mixed>|list<mixed> $coreResults
     */
    public function recordAccountsValidation(
        int $artifactId,
        IxbrlAuthorityProfile $profile,
        string $sourceStatus,
        array $sourceResults,
        string $coreStatus,
        array $coreResults,
        array $authorityResult,
        array $externalResult
    ): int {
        $authorityStatus = !empty($authorityResult['ok']) ? 'passed' : 'failed';
        $arelleStatus = $this->componentStatus((string)($externalResult['status'] ?? 'error'));
        $sourceStatus = $this->componentStatus($sourceStatus);
        $coreStatus = $this->componentStatus($coreStatus);
        $overallStatus = in_array($sourceStatus, ['passed', 'not_applicable'], true)
            && $coreStatus === 'passed'
            && $authorityStatus === 'passed'
            && $arelleStatus === 'passed'
                ? 'passed'
                : (in_array('failed', [$sourceStatus, $coreStatus, $authorityStatus, $arelleStatus], true)
                    ? 'failed'
                    : 'error');
        $validator = trim((string)($externalResult['validator'] ?? 'arelle')) ?: 'arelle';
        $version = trim((string)($externalResult['version'] ?? '')) ?: 'unknown';
        $options = [
            'authority_profile' => $profile->key(),
            'authority_profile_fingerprint' => $profile->fingerprint(),
            'validator_options_sha256' => (string)($externalResult['validator_options_sha256'] ?? ''),
        ];
        $taxonomyId = (int)($externalResult['taxonomy_package_id'] ?? 0);
        $taxonomyHash = strtolower(trim((string)($externalResult['taxonomy_sha256'] ?? '')));
        if ($taxonomyId <= 0 || preg_match('/^[a-f0-9]{64}$/D', $taxonomyHash) !== 1) {
            $taxonomyId = 0;
            $taxonomyHash = '';
        }

        return (new IxbrlValidationRunRepository())->create([
            'accounts_artifact_id' => $artifactId,
            'authority' => $profile->authority(),
            'profile_key' => $profile->key(),
            'profile_version' => $profile->version(),
            'profile_fingerprint' => $profile->fingerprint(),
            'taxonomy_package_id' => $taxonomyId > 0 ? $taxonomyId : null,
            'taxonomy_package_sha256' => $taxonomyHash !== '' ? $taxonomyHash : null,
            'validator_name' => $validator,
            'validator_version' => $version,
            'validator_fingerprint' => hash(
                'sha256',
                $validator . '|' . $version . '|' . (string)($externalResult['validator_options_sha256'] ?? '')
            ),
            'options' => $options,
            'source_conformance_status' => $sourceStatus,
            'source_conformance_results' => $sourceResults,
            'core_status' => $coreStatus,
            'core_results' => $coreResults,
            'authority_status' => $authorityStatus,
            'authority_results' => $authorityResult,
            'arelle_status' => $arelleStatus,
            'arelle_results' => $externalResult,
            'arelle_log_path' => trim((string)($externalResult['log_path'] ?? '')) ?: null,
            'overall_status' => $overallStatus,
        ]);
    }

    /**
     * @param array<string,mixed> $authorityResult
     * @param array<string,mixed> $externalResult
     * @param array<string,mixed>|list<mixed> $coreResults
     */
    public function recordComputationValidation(
        int $computationRunId,
        string $artifactSha256,
        IxbrlAuthorityProfile $profile,
        array $coreResults,
        array $authorityResult,
        array $externalResult,
        string $coreStatus = 'passed'
    ): int {
        $authorityStatus = !empty($authorityResult['ok']) ? 'passed' : 'failed';
        $arelleStatus = $this->componentStatus((string)($externalResult['status'] ?? 'error'));
        $coreStatus = $this->componentStatus($coreStatus);
        $overallStatus = $coreStatus === 'passed'
            && $authorityStatus === 'passed'
            && $arelleStatus === 'passed'
            ? 'passed'
            : (in_array('failed', [$coreStatus, $authorityStatus, $arelleStatus], true) ? 'failed' : 'error');
        $validator = trim((string)($externalResult['validator'] ?? 'arelle')) ?: 'arelle';
        $version = trim((string)($externalResult['version'] ?? '')) ?: 'unknown';
        $options = [
            'authority_profile' => $profile->key(),
            'authority_profile_fingerprint' => $profile->fingerprint(),
            'validator_options_sha256' => (string)($externalResult['validator_options_sha256'] ?? ''),
        ];
        $taxonomyId = (int)($externalResult['taxonomy_package_id'] ?? 0);
        $taxonomyHash = strtolower(trim((string)($externalResult['taxonomy_sha256'] ?? '')));
        if ($taxonomyId <= 0 || preg_match('/^[a-f0-9]{64}$/D', $taxonomyHash) !== 1) {
            $taxonomyId = 0;
            $taxonomyHash = '';
        }

        return (new IxbrlValidationRunRepository())->create([
            'computation_run_id' => $computationRunId,
            'authority' => $profile->authority(),
            'profile_key' => $profile->key(),
            'profile_version' => $profile->version(),
            'profile_fingerprint' => $profile->fingerprint(),
            'artifact_sha256' => $artifactSha256,
            'taxonomy_package_id' => $taxonomyId > 0 ? $taxonomyId : null,
            'taxonomy_package_sha256' => $taxonomyHash !== '' ? $taxonomyHash : null,
            'validator_name' => $validator,
            'validator_version' => $version,
            'validator_fingerprint' => hash(
                'sha256',
                $validator . '|' . $version . '|' . (string)($externalResult['validator_options_sha256'] ?? '')
            ),
            'options' => $options,
            'source_conformance_status' => 'passed',
            'source_conformance_results' => [],
            'core_status' => $coreStatus,
            'core_results' => $coreResults,
            'authority_status' => $authorityStatus,
            'authority_results' => $authorityResult,
            'arelle_status' => $arelleStatus,
            'arelle_results' => $externalResult,
            'arelle_log_path' => trim((string)($externalResult['log_path'] ?? '')) ?: null,
            'overall_status' => $overallStatus,
        ]);
    }

    private function componentStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'passed' => 'passed',
            'failed' => 'failed',
            'not_applicable' => 'not_applicable',
            'not_configured', 'not_run' => 'not_configured',
            default => 'error',
        };
    }
}
