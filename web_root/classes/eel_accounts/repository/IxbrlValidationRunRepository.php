<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Repository;

use eel_accounts\Service\IxbrlArtifactFingerprintService;
use eel_accounts\Support\PersistentJson;

/** Append-only validation evidence for authority-specific accounts and CT-computation artifacts. */
final class IxbrlValidationRunRepository
{
    /** @var list<string> */
    private const AUTHORITIES = ['HMRC', 'COMPANIES_HOUSE'];

    /** @var list<string> */
    private const COMPONENT_STATUSES = ['not_run', 'not_applicable', 'not_configured', 'passed', 'failed', 'error'];

    /** @var list<string> */
    private const OVERALL_STATUSES = ['passed', 'failed', 'error'];

    /**
     * @param array<string,mixed> $record
     */
    public function create(array $record): int
    {
        $this->assertSchema();

        $artifactId = $this->nullablePositiveInt($record['accounts_artifact_id'] ?? null);
        $computationRunId = $this->nullablePositiveInt($record['computation_run_id'] ?? null);
        if (($artifactId === null) === ($computationRunId === null)) {
            throw new \InvalidArgumentException('A validation run must target exactly one accounts artifact or computation run.');
        }

        $target = $artifactId !== null
            ? $this->accountsTarget($artifactId)
            : $this->computationTarget((int)$computationRunId);

        $authority = $this->authority((string)($record['authority'] ?? $target['authority'] ?? ''));
        $profileKey = $this->requiredString($record, 'profile_key', 100, $target['profile_key'] ?? null);
        $profileVersion = $this->requiredString($record, 'profile_version', 64, $target['profile_version'] ?? null);
        $profileFingerprint = $this->sha256(
            (string)($record['profile_fingerprint'] ?? $target['profile_fingerprint'] ?? ''),
            'profile_fingerprint'
        );
        $artifactHash = $this->sha256(
            (string)($record['artifact_sha256'] ?? $target['artifact_sha256'] ?? ''),
            'artifact_sha256'
        );

        $actualHash = (new IxbrlArtifactFingerprintService())->sha256((string)($target['output_path'] ?? ''));
        if (!is_string($actualHash) || !hash_equals($artifactHash, $actualHash)) {
            throw new \RuntimeException('The validation target does not match its immutable SHA-256 fingerprint.');
        }

        if (isset($target['authority']) && $authority !== (string)$target['authority']) {
            throw new \RuntimeException('The validation authority does not match the target artifact.');
        }
        foreach (['profile_key', 'profile_version', 'profile_fingerprint', 'artifact_sha256'] as $field) {
            if (isset($target[$field]) && !hash_equals((string)$target[$field], (string)match ($field) {
                'profile_key' => $profileKey,
                'profile_version' => $profileVersion,
                'profile_fingerprint' => $profileFingerprint,
                default => $artifactHash,
            })) {
                throw new \RuntimeException('The validation ' . str_replace('_', ' ', $field) . ' does not match the target artifact.');
            }
        }

        $targetTaxonomyPackageId = $this->nullablePositiveInt($target['taxonomy_package_id'] ?? null);
        $targetTaxonomyHash = $this->nullableSha256(
            $target['taxonomy_package_sha256'] ?? null,
            'target taxonomy_package_sha256'
        );
        if (($targetTaxonomyPackageId === null) !== ($targetTaxonomyHash === null)) {
            throw new \RuntimeException(
                'The validation target has incomplete immutable taxonomy-package evidence.'
            );
        }
        $taxonomyPackageId = $this->nullablePositiveInt(
            $record['taxonomy_package_id'] ?? $targetTaxonomyPackageId
        );
        $taxonomyHash = $this->nullableSha256(
            $record['taxonomy_package_sha256'] ?? $targetTaxonomyHash,
            'taxonomy_package_sha256'
        );
        if (($taxonomyPackageId === null) !== ($taxonomyHash === null)) {
            throw new \InvalidArgumentException('The taxonomy package identity and hash must be recorded together.');
        }
        if ($taxonomyPackageId !== $targetTaxonomyPackageId
            || ($taxonomyHash === null) !== ($targetTaxonomyHash === null)
            || ($taxonomyHash !== null && !hash_equals((string)$targetTaxonomyHash, $taxonomyHash))) {
            throw new \RuntimeException(
                'The validation taxonomy package does not match the immutable target artifact.'
            );
        }

        $optionsJson = $this->jsonValue($record, 'options', 'options_json');
        $optionsFingerprint = $this->sha256(
            (string)($record['options_fingerprint'] ?? hash('sha256', $optionsJson ?? 'null')),
            'options_fingerprint'
        );
        if (isset($record['options_fingerprint'])
            && !hash_equals($optionsFingerprint, hash('sha256', $optionsJson ?? 'null'))) {
            throw new \RuntimeException('The validator options do not match their fingerprint.');
        }

        $sourceStatus = $this->componentStatus((string)($record['source_conformance_status'] ?? 'not_run'));
        $coreStatus = $this->componentStatus((string)($record['core_status'] ?? 'not_run'));
        $authorityStatus = $this->componentStatus((string)($record['authority_status'] ?? 'not_run'));
        $arelleStatus = $this->componentStatus((string)($record['arelle_status'] ?? 'not_run'));
        $overallStatus = strtolower(trim((string)($record['overall_status'] ?? '')));
        if (!in_array($overallStatus, self::OVERALL_STATUSES, true)) {
            throw new \InvalidArgumentException('The overall validation status is invalid.');
        }
        if ($overallStatus === 'passed'
            && (!in_array($sourceStatus, ['passed', 'not_applicable'], true)
                || $coreStatus !== 'passed'
                || $authorityStatus !== 'passed'
                || $arelleStatus !== 'passed')) {
            throw new \RuntimeException('A passed validation run requires every applicable validation layer to pass.');
        }

        $validatorName = $this->requiredString($record, 'validator_name', 100);
        $validatorVersion = $this->requiredString($record, 'validator_version', 100);
        $validatorFingerprint = $this->sha256(
            (string)($record['validator_fingerprint'] ?? ''),
            'validator_fingerprint'
        );
        $sourceResultsJson = $this->jsonValue(
            $record,
            'source_conformance_results',
            'source_conformance_results_json'
        );
        $coreResultsJson = $this->jsonValue($record, 'core_results', 'core_results_json');
        $authorityResultsJson = $this->jsonValue(
            $record,
            'authority_results',
            'authority_results_json'
        );
        $arelleResultsJson = $this->jsonValue($record, 'arelle_results', 'arelle_results_json');
        if ($overallStatus === 'passed') {
            $this->assertPassedExternalEvidence(
                $arelleResultsJson,
                $optionsJson,
                $artifactHash,
                $profileKey,
                $profileVersion,
                $profileFingerprint,
                $validatorName,
                $validatorVersion,
                $validatorFingerprint
            );
        }

        $params = [
            'accounts_artifact_id' => $artifactId,
            'computation_run_id' => $computationRunId,
            'company_id' => (int)$target['company_id'],
            'accounting_period_id' => (int)$target['accounting_period_id'],
            'ct_period_id' => $target['ct_period_id'] ?? null,
            'authority' => $authority,
            'profile_key' => $profileKey,
            'profile_version' => $profileVersion,
            'profile_fingerprint' => $profileFingerprint,
            'artifact_sha256' => $artifactHash,
            'taxonomy_package_id' => $taxonomyPackageId,
            'taxonomy_package_sha256' => $taxonomyHash,
            'validator_name' => $validatorName,
            'validator_version' => $validatorVersion,
            'validator_fingerprint' => $validatorFingerprint,
            'options_json' => $optionsJson,
            'options_fingerprint' => $optionsFingerprint,
            'source_conformance_status' => $sourceStatus,
            'source_conformance_results_json' => $sourceResultsJson,
            'core_status' => $coreStatus,
            'core_results_json' => $coreResultsJson,
            'authority_status' => $authorityStatus,
            'authority_results_json' => $authorityResultsJson,
            'arelle_status' => $arelleStatus,
            'arelle_results_json' => $arelleResultsJson,
            'arelle_log_path' => $this->nullableString($record['arelle_log_path'] ?? null, 1000),
            'overall_status' => $overallStatus,
            'validated_at' => $this->nullableString($record['validated_at'] ?? null, 32),
        ];

        \InterfaceDB::prepareExecute(
            'INSERT INTO ixbrl_validation_runs (
                accounts_artifact_id, computation_run_id, company_id, accounting_period_id,
                ct_period_id, authority, profile_key, profile_version, profile_fingerprint,
                artifact_sha256, taxonomy_package_id, taxonomy_package_sha256,
                validator_name, validator_version, validator_fingerprint, options_json,
                options_fingerprint, source_conformance_status, source_conformance_results_json,
                core_status, core_results_json, authority_status, authority_results_json,
                arelle_status, arelle_results_json, arelle_log_path, overall_status, validated_at
             ) VALUES (
                :accounts_artifact_id, :computation_run_id, :company_id, :accounting_period_id,
                :ct_period_id, :authority, :profile_key, :profile_version, :profile_fingerprint,
                :artifact_sha256, :taxonomy_package_id, :taxonomy_package_sha256,
                :validator_name, :validator_version, :validator_fingerprint, :options_json,
                :options_fingerprint, :source_conformance_status, :source_conformance_results_json,
                :core_status, :core_results_json, :authority_status, :authority_results_json,
                :arelle_status, :arelle_results_json, :arelle_log_path, :overall_status,
                COALESCE(:validated_at, CURRENT_TIMESTAMP)
             )',
            $params
        );

        return $this->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function findById(int $validationRunId): ?array
    {
        if ($validationRunId <= 0 || !\InterfaceDB::tableExists('ixbrl_validation_runs')) {
            return null;
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM ixbrl_validation_runs WHERE id = :id LIMIT 1',
            ['id' => $validationRunId]
        );
        return is_array($row) ? $this->normaliseRow($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function latestForArtifact(
        int $artifactId,
        ?string $artifactSha256 = null,
        ?string $profileFingerprint = null
    ): ?array
    {
        return $this->latest(
            'accounts_artifact_id',
            $artifactId,
            $artifactSha256,
            $profileFingerprint,
            false
        );
    }

    /** @return array<string,mixed>|null */
    public function latestForComputation(
        int $computationRunId,
        ?string $artifactSha256 = null,
        ?string $profileFingerprint = null
    ): ?array {
        return $this->latest(
            'computation_run_id',
            $computationRunId,
            $artifactSha256,
            $profileFingerprint,
            false
        );
    }

    /** @return array<string,mixed>|null */
    public function latestPassedForArtifact(
        int $artifactId,
        ?string $artifactSha256 = null,
        ?string $profileFingerprint = null
    ): ?array {
        return $this->latest(
            'accounts_artifact_id',
            $artifactId,
            $artifactSha256,
            $profileFingerprint,
            true
        );
    }

    /** @return array<string,mixed>|null */
    public function latestPassedForComputation(
        int $computationRunId,
        ?string $artifactSha256 = null,
        ?string $profileFingerprint = null
    ): ?array {
        return $this->latest(
            'computation_run_id',
            $computationRunId,
            $artifactSha256,
            $profileFingerprint,
            true
        );
    }

    /** @return array<string,mixed> */
    private function accountsTarget(int $artifactId): array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT company_id, accounting_period_id, authority, profile_key, profile_version,
                    profile_fingerprint, output_sha256 AS artifact_sha256,
                    output_path, taxonomy_package_id, taxonomy_package_sha256
             FROM ixbrl_accounts_artifacts WHERE id = :id LIMIT 1',
            ['id' => $artifactId]
        );
        if (!is_array($row)) {
            throw new \RuntimeException('The accounts iXBRL artifact to validate was not found.');
        }
        $row['ct_period_id'] = null;
        return $row;
    }

    /** @return array<string,mixed> */
    private function computationTarget(int $runId): array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT company_id, accounting_period_id, ct_period_id,
                    output_sha256 AS artifact_sha256,
                    generated_path AS output_path,
                    computation_taxonomy_package_id AS taxonomy_package_id,
                    computation_taxonomy_package_hash AS taxonomy_package_sha256
             FROM corporation_tax_computation_runs WHERE id = :id LIMIT 1',
            ['id' => $runId]
        );
        if (!is_array($row)) {
            throw new \RuntimeException('The computation iXBRL run to validate was not found.');
        }
        $row['authority'] = 'HMRC';
        return $row;
    }

    /** @return array<string,mixed>|null */
    private function latest(
        string $targetColumn,
        int $targetId,
        ?string $artifactSha256,
        ?string $profileFingerprint,
        bool $passedOnly
    ): ?array {
        if ($targetId <= 0 || !in_array($targetColumn, ['accounts_artifact_id', 'computation_run_id'], true)
            || !\InterfaceDB::tableExists('ixbrl_validation_runs')) {
            return null;
        }
        $params = ['target_id' => $targetId];
        $where = $targetColumn . ' = :target_id';
        if ($passedOnly) {
            $where .= " AND overall_status = 'passed'";
        }
        if ($artifactSha256 !== null && trim($artifactSha256) !== '') {
            $params['artifact_sha256'] = $this->sha256($artifactSha256, 'artifact_sha256');
            $where .= ' AND artifact_sha256 = :artifact_sha256';
        }
        if ($profileFingerprint !== null && trim($profileFingerprint) !== '') {
            $params['profile_fingerprint'] = $this->sha256($profileFingerprint, 'profile_fingerprint');
            $where .= ' AND profile_fingerprint = :profile_fingerprint';
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM ixbrl_validation_runs WHERE ' . $where . ' ORDER BY id DESC LIMIT 1',
            $params
        );
        return is_array($row) ? $this->normaliseRow($row) : null;
    }

    private function assertSchema(): void
    {
        if (!\InterfaceDB::tableExists('ixbrl_validation_runs')) {
            throw new \RuntimeException('Apply the authority-specific iXBRL validation migration before validating filing artifacts.');
        }
    }

    private function assertPassedExternalEvidence(
        ?string $arelleResultsJson,
        ?string $optionsJson,
        string $artifactHash,
        string $profileKey,
        string $profileVersion,
        string $profileFingerprint,
        string $validatorName,
        string $validatorVersion,
        string $validatorFingerprint
    ): void {
        $arelleResults = $this->decodedJsonObject(
            $arelleResultsJson,
            'A passed validation run requires the complete Arelle result.'
        );
        $options = $this->decodedJsonObject(
            $optionsJson,
            'A passed validation run requires the exact validator options.'
        );

        $resultValidator = trim((string)($arelleResults['validator'] ?? ''));
        $resultVersion = trim((string)($arelleResults['version'] ?? ''));
        $optionsHash = strtolower(trim((string)($arelleResults['validator_options_sha256'] ?? '')));
        $recordedOptionsHash = strtolower(trim((string)($options['validator_options_sha256'] ?? '')));
        if (strtolower($validatorName) !== 'arelle'
            || strtolower($resultValidator) !== 'arelle'
            || !hash_equals(strtolower($validatorName), strtolower($resultValidator))
            || $validatorVersion === ''
            || strtolower($validatorVersion) === 'unknown'
            || !hash_equals($validatorVersion, $resultVersion)
            || preg_match('/^[a-f0-9]{64}$/D', $optionsHash) !== 1
            || !hash_equals($optionsHash, $recordedOptionsHash)
            || !hash_equals(
                $validatorFingerprint,
                hash('sha256', $validatorName . '|' . $validatorVersion . '|' . $optionsHash)
            )) {
            throw new \RuntimeException(
                'A passed validation run must identify the exact Arelle validator and options used.'
            );
        }

        $validatedHash = strtolower(trim((string)($arelleResults['validated_sha256'] ?? '')));
        if (!hash_equals($artifactHash, $validatedHash)) {
            throw new \RuntimeException(
                'The passed Arelle result does not belong to the validation target bytes.'
            );
        }

        $resultProfileKey = trim((string)($arelleResults['validation_profile_key'] ?? ''));
        $resultProfileVersion = trim((string)($arelleResults['validation_profile_version'] ?? ''));
        $resultProfileFingerprint = strtolower(trim((string)(
            $arelleResults['validation_profile_fingerprint'] ?? ''
        )));
        if (!hash_equals($profileKey, $resultProfileKey)
            || !hash_equals($profileVersion, $resultProfileVersion)
            || !hash_equals($profileFingerprint, $resultProfileFingerprint)) {
            throw new \RuntimeException(
                'The passed Arelle result does not belong to the recorded authority profile.'
            );
        }
    }

    /** @return array<string,mixed> */
    private function decodedJsonObject(?string $json, string $message): array
    {
        if ($json === null || trim($json) === '') {
            throw new \RuntimeException($message);
        }
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException($message);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \RuntimeException($message);
        }
        return $decoded;
    }

    /** @param array<string,mixed> $record */
    private function requiredString(array $record, string $field, int $maxLength, mixed $fallback = null): string
    {
        $value = trim((string)($record[$field] ?? $fallback ?? ''));
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException($field . ' is required and must not exceed ' . $maxLength . ' characters.');
        }
        return $value;
    }

    private function nullableString(mixed $value, int $maxLength): ?string
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        $normalised = trim((string)$value);
        if (mb_strlen($normalised) > $maxLength) {
            throw new \InvalidArgumentException('A validation evidence value exceeds its storage limit.');
        }
        return $normalised;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalised = (int)$value;
        if ($normalised <= 0) {
            throw new \InvalidArgumentException('A validation target identity must be positive.');
        }
        return $normalised;
    }

    private function authority(string $authority): string
    {
        $authority = strtoupper(trim($authority));
        if (!in_array($authority, self::AUTHORITIES, true)) {
            throw new \InvalidArgumentException('The validation authority is invalid.');
        }
        return $authority;
    }

    private function componentStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if (!in_array($status, self::COMPONENT_STATUSES, true)) {
            throw new \InvalidArgumentException('A validation layer status is invalid.');
        }
        return $status;
    }

    private function sha256(string $value, string $field): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \InvalidArgumentException($field . ' must be a SHA-256 fingerprint.');
        }
        return $value;
    }

    private function nullableSha256(mixed $value, string $field): ?string
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        return $this->sha256((string)$value, $field);
    }

    /** @param array<string,mixed> $record */
    private function jsonValue(array $record, string $arrayKey, string $jsonKey): ?string
    {
        if (array_key_exists($arrayKey, $record)) {
            $value = $record[$arrayKey];
            if ($value === null) {
                return null;
            }
            if (!is_array($value)) {
                throw new \InvalidArgumentException($arrayKey . ' must be an array when supplied.');
            }
            return $this->canonicalJson($value);
        }
        if (!array_key_exists($jsonKey, $record) || $record[$jsonKey] === null || trim((string)$record[$jsonKey]) === '') {
            return null;
        }
        $decoded = json_decode((string)$record[$jsonKey], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException($jsonKey . ' must contain a JSON object or array.');
        }
        return $this->canonicalJson($decoded);
    }

    /** @param array<mixed> $value */
    private function canonicalJson(array $value): string
    {
        $normalise = function (mixed $item) use (&$normalise): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (array_is_list($item)) {
                return array_map($normalise, $item);
            }
            ksort($item, SORT_STRING);
            foreach ($item as $key => $child) {
                $item[$key] = $normalise($child);
            }
            return $item;
        };
        return PersistentJson::encode(
            $normalise($value),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normaliseRow(array $row): array
    {
        foreach (['id', 'company_id', 'accounting_period_id'] as $field) {
            $row[$field] = (int)($row[$field] ?? 0);
        }
        foreach (['accounts_artifact_id', 'computation_run_id', 'ct_period_id', 'taxonomy_package_id'] as $field) {
            $row[$field] = isset($row[$field]) ? (int)$row[$field] : null;
        }
        return $row;
    }

    private function lastInsertId(): int
    {
        $sql = strtolower((string)\InterfaceDB::driverName()) === 'sqlite'
            ? 'SELECT last_insert_rowid()'
            : 'SELECT LAST_INSERT_ID()';
        return (int)(\InterfaceDB::fetchColumn($sql) ?: 0);
    }
}
