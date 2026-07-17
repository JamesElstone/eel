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

        $result = $this->validateArtifact($path);
        $this->storeResult($runId, $result);

        return $result;
    }

    /** Validate an immutable derived artifact without changing an ordinary generation run. */
    public function validateArtifact(string $path): array
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
        $validator = new \ArelleIxbrlValidator($this->validatorConfigPath, $this->validatorRootPath);
        $result = $validator->validate($path);
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
            return ['status' => 'not_configured', 'detail' => 'No generated export exists yet.', 'blocking' => false];
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
        if ($status === '') {
            $status = 'not_configured';
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
                'tampered' => 'The generated export no longer matches the file Arelle validated.',
                default => 'Arelle is not configured or has not been run for this export.',
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
                 external_validation_status = :status,
                 external_validation_errors_json = :errors,
                 external_validation_warnings_json = :warnings,
                 external_validation_log_path = :log_path,
                 external_validated_sha256 = :validated_sha256,
                 external_validated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            [
                'validator' => (string)($result['validator'] ?? 'arelle'),
                'status' => (string)($result['status'] ?? 'error'),
                'errors' => json_encode((array)($result['errors'] ?? []), JSON_UNESCAPED_SLASHES),
                'warnings' => json_encode((array)($result['warnings'] ?? []), JSON_UNESCAPED_SLASHES),
                'log_path' => (string)($result['log_path'] ?? ''),
                'validated_sha256' => ($result['validated_sha256'] ?? null) !== null
                    ? (string)$result['validated_sha256']
                    : null,
                'id' => $runId,
            ]
        );
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
}
