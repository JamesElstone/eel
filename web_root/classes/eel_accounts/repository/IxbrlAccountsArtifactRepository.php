<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Repository;

use eel_accounts\Service\IxbrlArtifactFingerprintService;

/** Persists and locates immutable authority-specific statutory-accounts iXBRL artifacts. */
final class IxbrlAccountsArtifactRepository
{
    public const AUTHORITY_HMRC = 'HMRC';
    public const AUTHORITY_COMPANIES_HOUSE = 'COMPANIES_HOUSE';

    /** @var list<string> */
    private const AUTHORITIES = [self::AUTHORITY_HMRC, self::AUTHORITY_COMPANIES_HOUSE];

    /** @var list<string> */
    private const FILING_KINDS = ['ordinary', 'original', 'revised'];

    /**
     * @param array<string,mixed> $record
     */
    public function create(array $record): int
    {
        $this->assertSchema();

        $runId = $this->positiveInt($record, 'generation_run_id');
        $companyId = $this->positiveInt($record, 'company_id');
        $periodId = $this->positiveInt($record, 'accounting_period_id');
        $authority = $this->authority((string)($record['authority'] ?? ''));
        $filingKind = $this->filingKind((string)($record['filing_kind'] ?? 'ordinary'));
        if (($authority === self::AUTHORITY_HMRC && $filingKind !== 'ordinary')
            || ($authority === self::AUTHORITY_COMPANIES_HOUSE
                && !in_array($filingKind, ['original', 'revised'], true))) {
            throw new \InvalidArgumentException(
                'The iXBRL artifact filing kind is not valid for its authority.'
            );
        }
        $profileKey = $this->requiredString($record, 'profile_key', 100);
        $profileVersion = $this->requiredString($record, 'profile_version', 64);
        $profileFingerprint = $this->sha256($record, 'profile_fingerprint');
        $renderModelHash = $this->sha256($record, 'render_model_sha256');
        $registryUri = $this->requiredString($record, 'transformation_registry_uri', 1000);
        $outputPath = $this->requiredString($record, 'output_path', 1000);
        $outputFilename = trim((string)($record['output_filename'] ?? basename($outputPath)));
        if ($outputFilename === '' || mb_strlen($outputFilename) > 255 || basename($outputFilename) !== $outputFilename) {
            throw new \InvalidArgumentException('The iXBRL artifact filename is invalid.');
        }
        $outputHash = $this->sha256($record, 'output_sha256');
        $generationStatus = trim((string)($record['generation_status'] ?? 'generated'));
        if (!in_array($generationStatus, ['generated', 'failed'], true)) {
            throw new \InvalidArgumentException('The iXBRL artifact generation status is invalid.');
        }

        $actualHash = (new IxbrlArtifactFingerprintService())->sha256($outputPath);
        if (!is_string($actualHash) || !hash_equals($outputHash, $actualHash)) {
            throw new \RuntimeException('The iXBRL artifact does not match its immutable SHA-256 fingerprint.');
        }

        $run = \InterfaceDB::fetchOne(
            'SELECT company_id, accounting_period_id, filing_approval_id, filing_approval_hash
             FROM ixbrl_generation_runs WHERE id = :id LIMIT 1',
            ['id' => $runId]
        );
        if (!is_array($run)
            || (int)($run['company_id'] ?? 0) !== $companyId
            || (int)($run['accounting_period_id'] ?? 0) !== $periodId) {
            throw new \RuntimeException('The iXBRL artifact does not belong to the selected generation run.');
        }

        $approvalId = (int)($record['filing_approval_id'] ?? $run['filing_approval_id'] ?? 0);
        $approvalHash = $this->normaliseSha256(
            (string)($record['filing_approval_hash'] ?? $run['filing_approval_hash'] ?? ''),
            'filing_approval_hash'
        );
        if ($approvalId <= 0) {
            throw new \InvalidArgumentException('An approved statutory-accounts basis is required for an authority artifact.');
        }
        if ((int)($run['filing_approval_id'] ?? 0) !== $approvalId
            || !hash_equals(strtolower((string)($run['filing_approval_hash'] ?? '')), $approvalHash)) {
            throw new \RuntimeException('The iXBRL artifact approval does not match its generation run.');
        }

        $approval = \InterfaceDB::fetchOne(
            'SELECT company_id, accounting_period_id, basis_hash
             FROM ixbrl_accounts_filing_approvals WHERE id = :id LIMIT 1',
            ['id' => $approvalId]
        );
        if (!is_array($approval)
            || (int)($approval['company_id'] ?? 0) !== $companyId
            || (int)($approval['accounting_period_id'] ?? 0) !== $periodId
            || !hash_equals(strtolower((string)($approval['basis_hash'] ?? '')), $approvalHash)) {
            throw new \RuntimeException('The iXBRL artifact approval snapshot failed its ownership or integrity check.');
        }

        $taxonomyPackageId = $this->nullablePositiveInt($record['taxonomy_package_id'] ?? null);
        $taxonomyPackageHash = $this->nullableSha256($record['taxonomy_package_sha256'] ?? null, 'taxonomy_package_sha256');
        if (($taxonomyPackageId === null) !== ($taxonomyPackageHash === null)) {
            throw new \InvalidArgumentException('The taxonomy package identity and hash must be recorded together.');
        }

        $normalised = [
            'generation_run_id' => $runId,
            'company_id' => $companyId,
            'accounting_period_id' => $periodId,
            'filing_approval_id' => $approvalId,
            'filing_approval_hash' => $approvalHash,
            'authority' => $authority,
            'filing_kind' => $filingKind,
            'profile_key' => $profileKey,
            'profile_version' => $profileVersion,
            'profile_fingerprint' => $profileFingerprint,
            'render_model_sha256' => $renderModelHash,
            'transformation_registry_uri' => $registryUri,
            'taxonomy_profile' => $this->nullableString($record['taxonomy_profile'] ?? null, 100),
            'taxonomy_package_id' => $taxonomyPackageId,
            'taxonomy_package_sha256' => $taxonomyPackageHash,
            'generation_status' => $generationStatus,
            'output_path' => $outputPath,
            'output_filename' => $outputFilename,
            'output_sha256' => $outputHash,
            'generated_at' => $this->nullableString($record['generated_at'] ?? null, 32),
        ];

        $existing = $this->findByBuildIdentity(
            $runId,
            $authority,
            $filingKind,
            $profileFingerprint,
            $renderModelHash
        );
        if (is_array($existing)) {
            $this->assertSameImmutableArtifact($existing, $normalised);
            return (int)$existing['id'];
        }

        \InterfaceDB::prepareExecute(
            'INSERT INTO ixbrl_accounts_artifacts (
                generation_run_id, company_id, accounting_period_id,
                filing_approval_id, filing_approval_hash, authority, filing_kind,
                profile_key, profile_version, profile_fingerprint, render_model_sha256,
                transformation_registry_uri, taxonomy_profile, taxonomy_package_id,
                taxonomy_package_sha256, generation_status, output_path, output_filename,
                output_sha256, generated_at
             ) VALUES (
                :generation_run_id, :company_id, :accounting_period_id,
                :filing_approval_id, :filing_approval_hash, :authority, :filing_kind,
                :profile_key, :profile_version, :profile_fingerprint, :render_model_sha256,
                :transformation_registry_uri, :taxonomy_profile, :taxonomy_package_id,
                :taxonomy_package_sha256, :generation_status, :output_path, :output_filename,
                :output_sha256, COALESCE(:generated_at, CURRENT_TIMESTAMP)
             )',
            $normalised
        );

        return $this->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function findById(int $artifactId): ?array
    {
        if ($artifactId <= 0 || !\InterfaceDB::tableExists('ixbrl_accounts_artifacts')) {
            return null;
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM ixbrl_accounts_artifacts WHERE id = :id LIMIT 1',
            ['id' => $artifactId]
        );
        return is_array($row) ? $this->normaliseRow($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function findCurrent(
        int $companyId,
        int $accountingPeriodId,
        string $authority,
        string $filingKind = 'ordinary',
        ?string $profileKey = null
    ): ?array {
        if ($companyId <= 0 || $accountingPeriodId <= 0
            || !\InterfaceDB::tableExists('ixbrl_accounts_artifacts')) {
            return null;
        }
        $params = [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'authority' => $this->authority($authority),
            'filing_kind' => $this->filingKind($filingKind),
        ];
        $profileClause = '';
        if ($profileKey !== null && trim($profileKey) !== '') {
            $params['profile_key'] = trim($profileKey);
            $profileClause = ' AND profile_key = :profile_key';
        }
        $row = \InterfaceDB::fetchOne(
            "SELECT * FROM ixbrl_accounts_artifacts
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND authority = :authority
               AND filing_kind = :filing_kind
               AND generation_status = 'generated'" . $profileClause . '
             ORDER BY id DESC LIMIT 1',
            $params
        );
        return is_array($row) ? $this->normaliseRow($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function findByBuildIdentity(
        int $runId,
        string $authority,
        string $filingKind,
        string $profileFingerprint,
        string $renderModelHash
    ): ?array {
        if (!\InterfaceDB::tableExists('ixbrl_accounts_artifacts')) {
            return null;
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM ixbrl_accounts_artifacts
             WHERE generation_run_id = :run_id
               AND authority = :authority
               AND filing_kind = :filing_kind
               AND profile_fingerprint = :profile_fingerprint
               AND render_model_sha256 = :render_model_sha256
             LIMIT 1',
            [
                'run_id' => $runId,
                'authority' => $this->authority($authority),
                'filing_kind' => $this->filingKind($filingKind),
                'profile_fingerprint' => $this->normaliseSha256($profileFingerprint, 'profile_fingerprint'),
                'render_model_sha256' => $this->normaliseSha256($renderModelHash, 'render_model_sha256'),
            ]
        );
        return is_array($row) ? $this->normaliseRow($row) : null;
    }

    private function assertSchema(): void
    {
        if (!\InterfaceDB::tableExists('ixbrl_accounts_artifacts')) {
            throw new \RuntimeException('Apply the authority-specific iXBRL artifact migration before generating filing artifacts.');
        }
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $candidate */
    private function assertSameImmutableArtifact(array $existing, array $candidate): void
    {
        foreach ([
            'company_id', 'accounting_period_id', 'filing_approval_id', 'filing_approval_hash',
            'profile_key', 'profile_version', 'transformation_registry_uri', 'taxonomy_profile',
            'taxonomy_package_id', 'taxonomy_package_sha256', 'generation_status', 'output_path',
            'output_filename', 'output_sha256',
        ] as $field) {
            if ((string)($existing[$field] ?? '') !== (string)($candidate[$field] ?? '')) {
                throw new \RuntimeException('An immutable iXBRL artifact build identity already exists with different evidence.');
            }
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normaliseRow(array $row): array
    {
        foreach (['id', 'generation_run_id', 'company_id', 'accounting_period_id', 'filing_approval_id'] as $field) {
            $row[$field] = (int)($row[$field] ?? 0);
        }
        foreach (['taxonomy_package_id'] as $field) {
            $row[$field] = isset($row[$field]) ? (int)$row[$field] : null;
        }
        return $row;
    }

    /** @param array<string,mixed> $record */
    private function positiveInt(array $record, string $field): int
    {
        $value = (int)($record[$field] ?? 0);
        if ($value <= 0) {
            throw new \InvalidArgumentException($field . ' must be a positive integer.');
        }
        return $value;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalised = (int)$value;
        if ($normalised <= 0) {
            throw new \InvalidArgumentException('A nullable database identity must be positive when supplied.');
        }
        return $normalised;
    }

    /** @param array<string,mixed> $record */
    private function requiredString(array $record, string $field, int $maxLength): string
    {
        $value = trim((string)($record[$field] ?? ''));
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
            throw new \InvalidArgumentException('An optional artifact value exceeds its storage limit.');
        }
        return $normalised;
    }

    /** @param array<string,mixed> $record */
    private function sha256(array $record, string $field): string
    {
        return $this->normaliseSha256((string)($record[$field] ?? ''), $field);
    }

    private function nullableSha256(mixed $value, string $field): ?string
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        return $this->normaliseSha256((string)$value, $field);
    }

    private function normaliseSha256(string $value, string $field): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \InvalidArgumentException($field . ' must be a SHA-256 fingerprint.');
        }
        return $value;
    }

    private function authority(string $authority): string
    {
        $authority = strtoupper(trim($authority));
        if (!in_array($authority, self::AUTHORITIES, true)) {
            throw new \InvalidArgumentException('The iXBRL artifact authority is invalid.');
        }
        return $authority;
    }

    private function filingKind(string $filingKind): string
    {
        $filingKind = strtolower(trim($filingKind));
        if (!in_array($filingKind, self::FILING_KINDS, true)) {
            throw new \InvalidArgumentException('The iXBRL artifact filing kind is invalid.');
        }
        return $filingKind;
    }

    private function lastInsertId(): int
    {
        $sql = strtolower((string)\InterfaceDB::driverName()) === 'sqlite'
            ? 'SELECT last_insert_rowid()'
            : 'SELECT LAST_INSERT_ID()';
        return (int)(\InterfaceDB::fetchColumn($sql) ?: 0);
    }
}
