<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class IxbrlExternalValidationService
{
    public function __construct(
        private readonly ?string $validatorConfigPath = null,
        private readonly ?string $validatorRootPath = null,
    ) {
    }

    public function validateLatestRun(int $companyId, int $accountingPeriodId): array
    {
        $builder = new \eel_accounts\Service\IxbrlFactBuilderService();
        $builder->ensureSchema();
        $run = $builder->getLatestRun($companyId, $accountingPeriodId);
        if (!is_array($run) || (int)($run['id'] ?? 0) <= 0) {
            return ['ok' => false, 'status' => 'error', 'errors' => ['No iXBRL generation run exists for this period.']];
        }

        return $this->validateRun((int)$run['id']);
    }

    public function configurationStatus(): array
    {
        $adapterPath = PROJECT_ROOT . 'third_party' . DIRECTORY_SEPARATOR . 'arelle' . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'ArelleIxbrlValidator.php';
        if (!is_file($adapterPath)) {
            return ['installed' => false, 'status' => 'not_configured', 'detail' => 'The Arelle adapter is missing.'];
        }

        require_once $adapterPath;
        return (new \ArelleIxbrlValidator($this->configuration(), $this->validatorRootPath))
            ->configurationStatus();
    }

    public function validateRun(int $runId): array
    {
        $builder = new \eel_accounts\Service\IxbrlFactBuilderService();
        $builder->ensureSchema();
        $run = $this->fetchRun($runId);
        if ($run === null) {
            return ['ok' => false, 'status' => 'error', 'errors' => ['The iXBRL generation run could not be found.']];
        }
        $freshness = $builder->getRunFreshness($runId);
        if ((string)($freshness['state'] ?? '') !== 'current') {
            return [
                'ok' => false,
                'status' => 'stale',
                'errors' => [(string)($freshness['detail'] ?? 'Rebuild iXBRL facts before external validation.')],
            ];
        }

        $path = (string)($run['generated_path'] ?? '');
        if ($path === '' || !is_file($path)) {
            $result = [
                'ok' => false,
                'status' => 'error',
                'validator' => 'arelle',
                'version' => '',
                'errors' => ['Generated iXBRL file was not found.'],
                'warnings' => [],
                'log_path' => '',
                'duration_ms' => 0,
            ];
            $this->storeResult($runId, $result);
            return $result;
        }

        $expectedHash = strtolower(trim((string)($run['output_sha256'] ?? '')));
        $validatedHash = hash_file('sha256', $path);
        if (!is_string($validatedHash) || $validatedHash === '') {
            $result = $this->integrityError('The generated iXBRL file could not be fingerprinted before validation.');
            $this->storeResult($runId, $result);
            return $result;
        }
        $validatedHash = strtolower($validatedHash);
        if ($expectedHash === '' || !hash_equals($expectedHash, $validatedHash)) {
            $result = $this->integrityError('The generated iXBRL file does not match its recorded output fingerprint. Regenerate it before validation.');
            $this->storeResult($runId, $result);
            return $result;
        }

        $result = $this->validateArtifact(
            $path,
            [],
            [],
            IxbrlAuthorityProfileService::HMRC_CT_ACCOUNTS
        );

        try {
            $profile = (new IxbrlAuthorityProfileService())->profile(
                IxbrlAuthorityProfileService::HMRC_CT_ACCOUNTS
            );
            $artifact = (new \eel_accounts\Repository\IxbrlAccountsArtifactRepository())->findByBuildIdentity(
                $runId,
                \eel_accounts\Repository\IxbrlAccountsArtifactRepository::AUTHORITY_HMRC,
                'ordinary',
                $profile->fingerprint(),
                $expectedHash
            );
            if (!is_array($artifact)
                || !hash_equals((string)($artifact['output_sha256'] ?? ''), $expectedHash)
                || (string)($artifact['output_path'] ?? '') !== $path) {
                throw new \RuntimeException(
                    'The HMRC accounts artifact is not bound to this generation run. Regenerate it before validation.'
                );
            }

            $this->storeAuthorityValidationRun($artifact, $run, $profile, $result);
        } catch (\Throwable $exception) {
            $result['ok'] = false;
            $result['status'] = 'error';
            $result['errors'] = array_values(array_unique(array_merge(
                (array)($result['errors'] ?? []),
                [$exception->getMessage()]
            )));
        }
        // Persist the mutable latest-attempt state after the append-only
        // authority ledger attempt, including any ledger persistence failure.
        $this->storeResult($runId, $result);

        return $result;
    }

    /** Validate an immutable derived artifact without changing an ordinary generation run. */
    public function validateArtifact(
        string $path,
        array $taxonomyPackages = [],
        array $ignoredDiagnosticCodes = [],
        string|IxbrlAuthorityProfile|null $authorityProfile = null
    ): array
    {
        $path = trim($path);
        if ($path === '' || !is_file($path)) {
            return [
                'ok' => false,
                'status' => 'error',
                'validator' => 'arelle',
                'version' => '',
                'errors' => ['The iXBRL artifact to validate was not found.'],
                'warnings' => [],
                'log_path' => '',
                'duration_ms' => 0,
                'validated_sha256' => null,
            ];
        }

        $hashBeforeValidation = hash_file('sha256', $path);
        if (!is_string($hashBeforeValidation) || $hashBeforeValidation === '') {
            return $this->integrityError('The iXBRL artifact could not be fingerprinted before validation.');
        }
        $hashBeforeValidation = strtolower($hashBeforeValidation);

        $adapterPath = PROJECT_ROOT . 'third_party' . DIRECTORY_SEPARATOR . 'arelle' . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'ArelleIxbrlValidator.php';
        if (!is_file($adapterPath)) {
            return [
                'ok' => false,
                'status' => 'not_configured',
                'validator' => 'arelle',
                'version' => '',
                'errors' => ['Arelle adapter is missing from third_party/arelle/php.'],
                'warnings' => [],
                'log_path' => '',
                'duration_ms' => 0,
                'validated_sha256' => null,
            ];
        }

        require_once $adapterPath;
        $packages = array_values(array_filter($taxonomyPackages, 'is_string'));
        $managedPackage = null;
        if ($packages === []) {
            $activePackage = (new FrcTaxonomyPackageService())->activePackage();
            if (!is_array($activePackage)) {
                return [
                    'ok' => false, 'status' => 'not_configured', 'validator' => 'arelle', 'version' => '',
                    'errors' => ['No verified FRC accounts taxonomy package is installed.'], 'warnings' => [], 'log_path' => '', 'duration_ms' => 0, 'validated_sha256' => null,
                ];
            }
            $packages[] = (string)$activePackage['local_path'];
            $managedPackage = $activePackage;
        }
        $profile = $authorityProfile instanceof IxbrlAuthorityProfile
            ? $authorityProfile
            : ($authorityProfile !== null
                ? (new IxbrlAuthorityProfileService())->profile($authorityProfile)
                : null);
        $validatorConfiguration = $this->configurationForProfile($profile);
        $validator = new \ArelleIxbrlValidator($validatorConfiguration, $this->validatorRootPath);
        $result = $validator->validate($path, $packages, $ignoredDiagnosticCodes);
        $result['validation_profile_key'] = $profile?->key();
        $result['validation_profile_version'] = $profile?->version();
        $result['validation_profile_fingerprint'] = $profile?->fingerprint();
        $result['validator_options_sha256'] = hash(
            'sha256',
            \eel_accounts\Support\Utf8::json(
                array_values((array)($validatorConfiguration['flags'] ?? [])),
                JSON_UNESCAPED_SLASHES
            )
        );
        if (is_array($managedPackage)) {
            $result['taxonomy_package_id'] = (int)$managedPackage['id'];
            $result['taxonomy_sha256'] = (string)$managedPackage['sha256'];
        }
        $hashAfterValidation = hash_file('sha256', $path);
        if (!is_string($hashAfterValidation)
            || !hash_equals($hashBeforeValidation, strtolower($hashAfterValidation))) {
            return $this->integrityError('The iXBRL artifact changed while Arelle was validating it. Recreate and validate it again.');
        }

        $result['validated_sha256'] = in_array((string)($result['status'] ?? ''), ['passed', 'failed'], true)
            ? $hashBeforeValidation
            : null;

        return $result;
    }

    public function externalStatusForRun(?array $run): array
    {
        if (!is_array($run) || $run === []) {
            $configuration = $this->configurationStatus();
            return [
                'status' => !empty($configuration['installed']) ? 'not_run' : 'not_configured',
                'detail' => !empty($configuration['installed'])
                    ? 'Arelle is installed; generate an export before running external validation.'
                    : (string)($configuration['detail'] ?? 'Arelle has not been configured.'),
                'blocking' => false,
            ];
        }
        $freshness = (array)($run['run_freshness'] ?? []);
        if ($freshness !== [] && (string)($freshness['state'] ?? '') !== 'current') {
            return [
                'status' => 'stale',
                'detail' => (string)($freshness['detail'] ?? 'The latest generated export is stale and must be rebuilt.'),
                'blocking' => false,
            ];
        }

        $status = (string)($run['external_validation_status'] ?? '');
        if ($status === '' || $status === 'not_configured') {
            $status = !empty($this->configurationStatus()['installed']) ? 'not_run' : 'not_configured';
        }
        if ($status === 'passed' && !$this->runHashesMatch($run)) {
            $status = 'tampered';
        }

        return [
            'status' => $status,
            'detail' => match ($status) {
                'passed' => 'Latest export passed Arelle external validation.',
                'failed' => 'Latest export failed Arelle external validation.',
                'error' => 'Arelle external validation could not be completed.',
                'not_run' => 'Arelle is installed; this export has not been externally validated yet.',
                'tampered' => 'The generated export no longer matches the file Arelle validated.',
                default => 'Arelle is not configured.',
            },
            'blocking' => in_array($status, ['failed', 'error', 'tampered'], true),
        ];
    }

    private function fetchRun(int $runId): ?array
    {
        if ($runId <= 0) {
            return null;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT *
             FROM ixbrl_generation_runs
             WHERE id = :id
             LIMIT 1',
            ['id' => $runId]
        );

        return is_array($row) ? $row : null;
    }

    private function storeResult(int $runId, array $result): void
    {
        \InterfaceDB::prepareExecute(
            'UPDATE ixbrl_generation_runs
             SET external_validator = :validator,
                 external_validator_version = :validator_version,
                 external_validation_status = :status,
                 external_validation_errors_json = :errors,
                 external_validation_warnings_json = :warnings,
                 external_validation_log_path = :log_path,
                 external_validated_sha256 = :validated_sha256,
                 external_taxonomy_package_id = :taxonomy_package_id,
                 external_taxonomy_sha256 = :taxonomy_sha256,
                 external_validated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            [
                'validator' => (string)($result['validator'] ?? 'arelle'),
                'validator_version' => trim((string)($result['version'] ?? '')) ?: null,
                'status' => (string)($result['status'] ?? 'error'),
                'errors' => \eel_accounts\Support\Utf8::json($this->storedDiagnostics($result, 'error'), JSON_UNESCAPED_SLASHES),
                'warnings' => \eel_accounts\Support\Utf8::json($this->storedDiagnostics($result, 'warning'), JSON_UNESCAPED_SLASHES),
                'log_path' => (string)($result['log_path'] ?? ''),
                'validated_sha256' => ($result['validated_sha256'] ?? null) !== null
                    ? (string)$result['validated_sha256']
                    : null,
                'taxonomy_package_id' => (int)($result['taxonomy_package_id'] ?? 0) ?: null,
                'taxonomy_sha256' => trim((string)($result['taxonomy_sha256'] ?? '')) ?: null,
                'id' => $runId,
            ]
        );
    }

    /**
     * Persist the complete validation decision against the exact immutable
     * authority artifact. The legacy generation-run columns remain populated
     * as a compatibility read model, but filing code consumes this ledger.
     *
     * @param array<string,mixed> $artifact
     * @param array<string,mixed> $run
     * @param array<string,mixed> $external
     */
    private function storeAuthorityValidationRun(
        array $artifact,
        array $run,
        IxbrlAuthorityProfile $profile,
        array $external
    ): int {
        $path = (string)($artifact['output_path'] ?? '');
        $source = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($source)) {
            throw new \RuntimeException('The HMRC accounts artifact could not be read for authority validation.');
        }
        $authorityResult = (new IxbrlAuthorityValidationService())->validate($source, $profile);
        $authorityStatus = !empty($authorityResult['ok']) ? 'passed' : 'failed';
        $coreStatus = (string)($run['validation_status'] ?? '') === 'passed' ? 'passed' : 'failed';
        $arelleStatus = $this->validationComponentStatus((string)($external['status'] ?? 'error'));
        $overallStatus = $coreStatus === 'passed'
            && $authorityStatus === 'passed'
            && $arelleStatus === 'passed'
                ? 'passed'
                : (in_array('failed', [$coreStatus, $authorityStatus, $arelleStatus], true) ? 'failed' : 'error');
        $validator = trim((string)($external['validator'] ?? 'arelle')) ?: 'arelle';
        $version = trim((string)($external['version'] ?? '')) ?: 'unknown';
        $options = [
            'authority_profile' => $profile->key(),
            'authority_profile_fingerprint' => $profile->fingerprint(),
            'validator_options_sha256' => (string)($external['validator_options_sha256'] ?? ''),
        ];

        return (new \eel_accounts\Repository\IxbrlValidationRunRepository())->create([
            'accounts_artifact_id' => (int)($artifact['id'] ?? 0),
            'authority' => 'HMRC',
            'profile_key' => $profile->key(),
            'profile_version' => $profile->version(),
            'profile_fingerprint' => $profile->fingerprint(),
            'artifact_sha256' => (string)($artifact['output_sha256'] ?? ''),
            'taxonomy_package_id' => (int)($external['taxonomy_package_id'] ?? 0) ?: null,
            'taxonomy_package_sha256' => trim((string)($external['taxonomy_sha256'] ?? '')) ?: null,
            'validator_name' => $validator,
            'validator_version' => $version,
            'validator_fingerprint' => hash(
                'sha256',
                $validator . '|' . $version . '|' . (string)($external['validator_options_sha256'] ?? '')
            ),
            'options' => $options,
            'source_conformance_status' => 'not_applicable',
            'source_conformance_results' => [],
            'core_status' => $coreStatus,
            'core_results' => (array)json_decode((string)($run['validation_errors_json'] ?? '[]'), true),
            'authority_status' => $authorityStatus,
            'authority_results' => $authorityResult,
            'arelle_status' => $arelleStatus,
            'arelle_results' => $external,
            'arelle_log_path' => trim((string)($external['log_path'] ?? '')) ?: null,
            'overall_status' => $overallStatus,
        ]);
    }

    private function validationComponentStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'passed' => 'passed',
            'failed' => 'failed',
            'not_configured', 'not_run' => 'not_configured',
            default => 'error',
        };
    }

    /** @return list<mixed> */
    private function storedDiagnostics(array $result, string $kind): array
    {
        $diagnostics = $result[$kind . '_diagnostics'] ?? null;
        if (is_array($diagnostics) && $diagnostics !== []) {
            return array_values($diagnostics);
        }

        $messages = $result[$kind === 'error' ? 'errors' : 'warnings'] ?? [];
        return is_array($messages) ? array_values($messages) : [];
    }

    private function runHashesMatch(array $run): bool
    {
        $outputHash = strtolower(trim((string)($run['output_sha256'] ?? '')));
        $validatedHash = strtolower(trim((string)($run['external_validated_sha256'] ?? '')));
        $path = trim((string)($run['generated_path'] ?? ''));
        if ($outputHash === '' || $validatedHash === '' || $path === '' || !is_file($path)) {
            return false;
        }
        $fileHash = hash_file('sha256', $path);

        $storedTaxonomyHash = strtolower(trim((string)($run['external_taxonomy_sha256'] ?? '')));
        if ($storedTaxonomyHash !== '') {
            $activePackage = (new FrcTaxonomyPackageService())->activePackage();
            if (!is_array($activePackage) || !hash_equals($storedTaxonomyHash, strtolower((string)$activePackage['sha256']))) {
                return false;
            }
        }

        return is_string($fileHash)
            && hash_equals($outputHash, $validatedHash)
            && hash_equals($outputHash, strtolower($fileHash));
    }

    private function integrityError(string $message): array
    {
        return [
            'ok' => false,
            'status' => 'error',
            'validator' => 'arelle',
            'version' => '',
            'errors' => [$message],
            'warnings' => [],
            'log_path' => '',
            'duration_ms' => 0,
            'validated_sha256' => null,
        ];
    }

    private function configuration(): array
    {
        if ($this->validatorConfigPath !== null) {
            return is_file($this->validatorConfigPath) ? (array)require $this->validatorConfigPath : [];
        }
        $configured = \AppConfigurationStore::get('arelle', []);
        return is_array($configured) ? $configured : [];
    }

    private function configurationForProfile(?IxbrlAuthorityProfile $profile): array
    {
        $configuration = $this->configuration();
        if ($profile === null) {
            return $configuration;
        }

        $flags = $this->commonArelleFlags((array)($configuration['flags'] ?? []));
        if ($profile->authority() === 'HMRC') {
            array_unshift($flags, '--disclosureSystem', 'hmrc');
            array_unshift($flags, '--plugins', 'validate/UK');
        }
        if (!in_array('--validate', $flags, true)) {
            $flags[] = '--validate';
        }
        $configuration['flags'] = array_values($flags);

        return $configuration;
    }

    /** @return list<string> */
    private function commonArelleFlags(array $configured): array
    {
        $flags = [];
        $skipNext = false;
        foreach ($configured as $rawFlag) {
            if ($skipNext) {
                $skipNext = false;
                continue;
            }
            $flag = trim((string)$rawFlag);
            if ($flag === '') {
                continue;
            }
            if (in_array($flag, ['--plugins', '--disclosureSystem'], true)) {
                $skipNext = true;
                continue;
            }
            if (str_starts_with($flag, '--plugins=')
                || str_starts_with($flag, '--disclosureSystem=')
                || in_array($flag, ['--hmrc', '--validateHMRC'], true)) {
                continue;
            }
            $flags[] = $flag;
        }

        return array_values(array_unique($flags));
    }
}
