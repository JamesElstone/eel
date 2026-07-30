<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class TransmissionArchiveService
{
    private const TABLE = 'transmission_archives';
    private const PENDING_REFERENCE_PREFIX = 'pending-submission-';
    private const AUTHENTICATION_REFERENCE_PREFIX = 'authentication-check-';

    private string $baseRoot;

    public function __construct(?string $baseRoot = null)
    {
        $this->baseRoot = $this->resolveBaseRoot($baseRoot);
    }

    public static function companiesHousePendingReference(int $submissionId): string
    {
        if ($submissionId <= 0) {
            throw new \InvalidArgumentException('A Companies House internal submission ID is required.');
        }

        return self::PENDING_REFERENCE_PREFIX . $submissionId;
    }

    public static function companiesHouseAuthenticationCheckReference(?string $identifier = null): string
    {
        $identifier = strtolower(trim($identifier ?? bin2hex(random_bytes(12))));
        if (preg_match('/^[a-f0-9]{24}$/D', $identifier) !== 1) {
            throw new \InvalidArgumentException(
                'A Companies House authentication-check archive identifier is required.'
            );
        }

        return self::AUTHENTICATION_REFERENCE_PREFIX . $identifier;
    }

    /**
     * @return array{path:string,sha256:string,bytes:int,archive_id:int,archive_path:string,manifest_path:string}
     */
    public function store(
        int $companyId,
        int $accountingPeriodId,
        string $authority,
        string $environment,
        string $submissionReference,
        string $lifecycle,
        string $filename,
        string $contents
    ): array {
        if ($companyId <= 0 || $contents === '') {
            throw new \InvalidArgumentException('A company and non-empty transmission artifact are required.');
        }
        if (!$this->schemaReady()) {
            throw new \RuntimeException('Run the transmission-archive migration before sending filings.');
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $filename)) {
            throw new \InvalidArgumentException('The transmission archive filename is invalid.');
        }

        $identity = $this->identity($companyId, $authority, $environment, $submissionReference);
        $directory = $identity['directory'];
        $this->ensureDirectory($directory);
        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        $sha256 = hash('sha256', $contents);
        $this->writeImmutable($path, $contents, $sha256);
        $this->verifyStoredFile($path, $sha256, strlen($contents));
        $archiveId = $this->upsertArchive(
            $companyId,
            $accountingPeriodId,
            $identity,
            trim($lifecycle) !== '' ? trim($lifecycle) : 'unknown'
        );
        $this->recordSubmissionArtifact($companyId, $identity, $filename, $path, $sha256);
        $manifest = $this->writeManifest($companyId, $accountingPeriodId, $identity, $lifecycle);

        return [
            'path' => $path,
            'sha256' => $sha256,
            'bytes' => strlen($contents),
            'archive_id' => $archiveId,
            'archive_path' => $directory,
            'manifest_path' => $manifest['path'],
        ];
    }

    /**
     * Promote all pending CompanyData evidence for an internal submission into
     * its permanent Companies House submission-number bundle.
     *
     * @return array{path:string,manifest_path:string,files:int}
     */
    public function promoteCompaniesHousePendingBundle(
        int $companyId,
        int $accountingPeriodId,
        string $environment,
        int $submissionId,
        string $submissionNumber
    ): array {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || $submissionId <= 0) {
            throw new \InvalidArgumentException('A complete Companies House submission is required for promotion.');
        }
        if (preg_match('/^[0-9]{6}$/D', $submissionNumber) !== 1) {
            throw new \InvalidArgumentException('A six-digit Companies House submission number is required.');
        }

        $pendingReference = self::companiesHousePendingReference($submissionId);
        $this->consolidateCompaniesHousePendingBundle(
            $companyId,
            $accountingPeriodId,
            $environment,
            $submissionId
        );
        $source = $this->identity($companyId, 'companies_house', $environment, $pendingReference);
        $target = $this->identity($companyId, 'companies_house', $environment, $submissionNumber);
        $submission = \InterfaceDB::fetchOne(
            'SELECT lifecycle
             FROM companies_house_accounts_submissions
             WHERE id = :id LIMIT 1',
            ['id' => $submissionId]
        );
        $targetLifecycle = trim((string)($submission['lifecycle'] ?? '')) ?: 'prepared';
        $sourceIdentities = [];
        if (is_dir($source['directory'])) {
            $sourceIdentities[$pendingReference] = $source;
        }

        $this->ensureDirectory($target['directory']);
        $copied = 0;
        foreach ($sourceIdentities as $sourceIdentity) {
            foreach ($this->artifactFiles($sourceIdentity['directory']) as $file) {
                $contents = file_get_contents($file['path']);
                if (!is_string($contents) || $contents === '') {
                    throw new \RuntimeException('A pending Companies House evidence file could not be read.');
                }
                $targetPath = $target['directory'] . DIRECTORY_SEPARATOR . $file['filename'];
                $this->writeImmutable($targetPath, $contents, $file['sha256']);
                $this->verifyStoredFile($targetPath, $file['sha256'], $file['bytes']);
                $copied++;
            }
        }

        $this->upsertArchive(
            $companyId,
            $accountingPeriodId,
            $target,
            $targetLifecycle
        );
        $this->writeManifest($companyId, $accountingPeriodId, $target, $targetLifecycle);

        \InterfaceDB::transaction(function () use (
            $companyId,
            $environment,
            $submissionId,
            $submissionNumber,
            $pendingReference,
            $sourceIdentities,
            $target
        ): void {
            foreach ($sourceIdentities as $sourceReference => $sourceIdentity) {
                $this->replaceProtocolPaths(
                    $submissionId,
                    $sourceIdentity['directory'],
                    $target['directory']
                );
                \InterfaceDB::prepareExecute(
                    'DELETE FROM ' . self::TABLE . '
                     WHERE authority = :authority
                       AND environment = :environment
                       AND company_id = :company_id
                       AND submission_reference = :reference',
                    [
                        'authority' => 'companies_house',
                        'environment' => strtoupper($environment),
                        'company_id' => $companyId,
                        'reference' => $sourceReference,
                    ]
                );
            }
            \InterfaceDB::prepareExecute(
                'UPDATE companies_house_company_auth_preflights
                 SET archive_reference = :reference,
                     updated_at = :updated_at
                 WHERE submission_id = :submission_id',
                [
                    'reference' => $submissionNumber,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                    'submission_id' => $submissionId,
                ]
            );
        });

        $this->writeManifest($companyId, $accountingPeriodId, $target, $targetLifecycle);
        foreach ($sourceIdentities as $sourceIdentity) {
            try {
                $this->removeArtifactDirectory($sourceIdentity['directory']);
            } catch (\Throwable) {
                // Promotion is already committed. A later idempotent promotion
                // or maintenance pass can remove the verified duplicate.
            }
        }

        return [
            'path' => $target['directory'],
            'manifest_path' => $target['directory'] . DIRECTORY_SEPARATOR . 'manifest.json',
            'files' => $copied,
        ];
    }

    public function migrateAllCompaniesHousePreflightBundles(): int
    {
        if (!\InterfaceDB::tableExists('companies_house_company_auth_preflights')
            || !\InterfaceDB::tableExists('companies_house_accounts_submissions')) {
            return 0;
        }
        $rows = \InterfaceDB::fetchAll(
            'SELECT DISTINCT s.id AS submission_id,
                    s.company_id,
                    s.accounting_period_id,
                    s.environment,
                    s.submission_number
             FROM companies_house_company_auth_preflights p
             INNER JOIN companies_house_accounts_submissions s ON s.id = p.submission_id
             WHERE p.archive_reference LIKE :legacy_prefix
             ORDER BY s.id ASC',
            ['legacy_prefix' => 'preflight-%']
        );
        $migrated = 0;
        foreach ($rows as $row) {
            $submissionNumber = trim((string)($row['submission_number'] ?? ''));
            if ($submissionNumber !== '') {
                $this->promoteCompaniesHousePendingBundle(
                    (int)$row['company_id'],
                    (int)$row['accounting_period_id'],
                    (string)$row['environment'],
                    (int)$row['submission_id'],
                    $submissionNumber
                );
            } else {
                $this->consolidateCompaniesHousePendingBundle(
                    (int)$row['company_id'],
                    (int)$row['accounting_period_id'],
                    (string)$row['environment'],
                    (int)$row['submission_id']
                );
            }
            $migrated++;
        }

        return $migrated;
    }

    public function consolidateCompaniesHousePendingBundle(
        int $companyId,
        int $accountingPeriodId,
        string $environment,
        int $submissionId
    ): void {
        $legacyReferences = $this->legacyPreflightReferences($submissionId);
        if ($legacyReferences === []) {
            return;
        }
        $pendingReference = self::companiesHousePendingReference($submissionId);
        $target = $this->identity($companyId, 'companies_house', $environment, $pendingReference);
        $this->ensureDirectory($target['directory']);
        $legacyIdentities = [];
        foreach ($legacyReferences as $legacyReference) {
            $legacy = $this->identity($companyId, 'companies_house', $environment, $legacyReference);
            if (!is_dir($legacy['directory'])) {
                continue;
            }
            $legacyIdentities[$legacyReference] = $legacy;
            foreach ($this->artifactFiles($legacy['directory']) as $file) {
                $contents = file_get_contents($file['path']);
                if (!is_string($contents) || $contents === '') {
                    throw new \RuntimeException('A legacy Companies House evidence file could not be read.');
                }
                $targetPath = $target['directory'] . DIRECTORY_SEPARATOR . $file['filename'];
                $this->writeImmutable($targetPath, $contents, $file['sha256']);
                $this->verifyStoredFile($targetPath, $file['sha256'], $file['bytes']);
            }
        }
        if ($legacyIdentities === []) {
            \InterfaceDB::prepareExecute(
                'UPDATE companies_house_company_auth_preflights
                 SET archive_reference = :reference,
                     updated_at = :updated_at
                 WHERE submission_id = :submission_id
                   AND archive_reference LIKE :legacy_prefix',
                [
                    'reference' => $pendingReference,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                    'submission_id' => $submissionId,
                    'legacy_prefix' => 'preflight-%',
                ]
            );
            return;
        }

        $this->upsertArchive($companyId, $accountingPeriodId, $target, 'prepared');
        $this->writeManifest($companyId, $accountingPeriodId, $target, 'prepared');
        \InterfaceDB::transaction(function () use (
            $companyId,
            $environment,
            $submissionId,
            $pendingReference,
            $target,
            $legacyIdentities
        ): void {
            foreach ($legacyIdentities as $legacyReference => $legacyIdentity) {
                $this->replaceProtocolPaths(
                    $submissionId,
                    $legacyIdentity['directory'],
                    $target['directory']
                );
                \InterfaceDB::prepareExecute(
                    'DELETE FROM ' . self::TABLE . '
                     WHERE authority = :authority
                       AND environment = :environment
                       AND company_id = :company_id
                       AND submission_reference = :reference',
                    [
                        'authority' => 'companies_house',
                        'environment' => strtoupper($environment),
                        'company_id' => $companyId,
                        'reference' => $legacyReference,
                    ]
                );
            }
            \InterfaceDB::prepareExecute(
                'UPDATE companies_house_company_auth_preflights
                 SET archive_reference = :reference,
                     updated_at = :updated_at
                 WHERE submission_id = :submission_id',
                [
                    'reference' => $pendingReference,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                    'submission_id' => $submissionId,
                ]
            );
        });
        $this->writeManifest($companyId, $accountingPeriodId, $target, 'prepared');
        foreach ($legacyIdentities as $legacyIdentity) {
            try {
                $this->removeArtifactDirectory($legacyIdentity['directory']);
            } catch (\Throwable) {
            }
        }
    }

    public function updateLifecycle(
        int $companyId,
        int $accountingPeriodId,
        string $authority,
        string $environment,
        string $submissionReference,
        string $lifecycle
    ): void {
        if (!$this->schemaReady()) {
            return;
        }
        $identity = $this->identity($companyId, $authority, $environment, $submissionReference);
        if (!is_dir($identity['directory'])) {
            return;
        }
        $this->upsertArchive($companyId, $accountingPeriodId, $identity, $lifecycle);
        $this->writeManifest($companyId, $accountingPeriodId, $identity, $lifecycle);
    }

    public function refreshManifest(
        int $companyId,
        int $accountingPeriodId,
        string $authority,
        string $environment,
        string $submissionReference,
        string $lifecycle
    ): void {
        $this->updateLifecycle(
            $companyId,
            $accountingPeriodId,
            $authority,
            $environment,
            $submissionReference,
            $lifecycle
        );
    }

    public function find(
        int $companyId,
        string $authority,
        string $environment,
        string $submissionReference
    ): ?array {
        if (!$this->schemaReady()) {
            return null;
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM ' . self::TABLE . '
             WHERE company_id = :company_id
               AND authority = :authority
               AND environment = :environment
               AND submission_reference = :reference',
            [
                'company_id' => $companyId,
                'authority' => $this->authority($authority),
                'environment' => strtoupper(trim($environment)),
                'reference' => $this->segment($submissionReference, 'submission reference'),
            ]
        );

        return is_array($row) ? $row : null;
    }

    private function identity(
        int $companyId,
        string $authority,
        string $environment,
        string $submissionReference
    ): array {
        $company = \InterfaceDB::fetchOne(
            'SELECT company_number FROM companies WHERE id = :id',
            ['id' => $companyId]
        );
        $companyNumber = preg_replace('/[^A-Za-z0-9]/', '', trim((string)($company['company_number'] ?? '')));
        if ($companyNumber === '') {
            throw new \RuntimeException('The company number is required for transmission archive storage.');
        }
        $authority = $this->authority($authority);
        $environment = strtolower($this->segment($environment, 'environment'));
        $reference = $this->segment($submissionReference, 'submission reference');
        $directory = $this->baseRoot
            . DIRECTORY_SEPARATOR . $companyNumber
            . DIRECTORY_SEPARATOR . $authority
            . DIRECTORY_SEPARATOR . $environment;
        if ($authority === 'companies_house'
            && preg_match('/^' . self::PENDING_REFERENCE_PREFIX . '([1-9][0-9]*)$/D', $reference, $matches) === 1) {
            $directory .= DIRECTORY_SEPARATOR . '_pending'
                . DIRECTORY_SEPARATOR . 'submission-' . $matches[1];
        } elseif ($authority === 'companies_house'
            && preg_match(
                '/^' . self::AUTHENTICATION_REFERENCE_PREFIX . '([a-f0-9]{24})$/D',
                $reference,
                $matches
            ) === 1) {
            $directory .= DIRECTORY_SEPARATOR . '_authentication_checks'
                . DIRECTORY_SEPARATOR . 'check-' . $matches[1];
        } else {
            $directory .= DIRECTORY_SEPARATOR . $reference;
        }

        return [
            'company_number' => $companyNumber,
            'authority' => $authority,
            'environment' => strtoupper($environment),
            'submission_reference' => $reference,
            'directory' => $directory,
        ];
    }

    private function upsertArchive(
        int $companyId,
        int $accountingPeriodId,
        array $identity,
        string $lifecycle
    ): int {
        $existing = \InterfaceDB::fetchOne(
            'SELECT id FROM ' . self::TABLE . '
             WHERE company_id = :company_id
               AND authority = :authority
               AND environment = :environment
               AND submission_reference = :reference',
            [
                'company_id' => $companyId,
                'authority' => $identity['authority'],
                'environment' => $identity['environment'],
                'reference' => $identity['submission_reference'],
            ]
        );
        $now = gmdate('Y-m-d H:i:s');
        if (is_array($existing)) {
            \InterfaceDB::prepareExecute(
                'UPDATE ' . self::TABLE . '
                 SET company_id = :company_id,
                     accounting_period_id = :accounting_period_id,
                     lifecycle = :lifecycle,
                     archive_path = :archive_path,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId > 0 ? $accountingPeriodId : null,
                    'lifecycle' => mb_substr($lifecycle, 0, 64),
                    'archive_path' => $identity['directory'],
                    'updated_at' => $now,
                    'id' => (int)$existing['id'],
                ]
            );
            return (int)$existing['id'];
        }
        \InterfaceDB::prepareExecute(
            'INSERT INTO ' . self::TABLE . ' (
                authority, environment, company_id, accounting_period_id,
                submission_reference, lifecycle, archive_path,
                created_at, updated_at
             ) VALUES (
                :authority, :environment, :company_id, :accounting_period_id,
                :reference, :lifecycle, :archive_path, :created_at, :updated_at
             )',
            [
                'authority' => $identity['authority'],
                'environment' => $identity['environment'],
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId > 0 ? $accountingPeriodId : null,
                'reference' => $identity['submission_reference'],
                'lifecycle' => mb_substr($lifecycle, 0, 64),
                'archive_path' => $identity['directory'],
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        $created = \InterfaceDB::fetchOne(
            'SELECT id FROM ' . self::TABLE . '
             WHERE company_id = :company_id
               AND authority = :authority
               AND environment = :environment
               AND submission_reference = :reference
             LIMIT 1',
            [
                'company_id' => $companyId,
                'authority' => $identity['authority'],
                'environment' => $identity['environment'],
                'reference' => $identity['submission_reference'],
            ]
        );
        $archiveId = (int)($created['id'] ?? 0);
        if ($archiveId <= 0) {
            throw new \RuntimeException('The transmission archive record could not be resolved.');
        }

        return $archiveId;
    }

    private function writeManifest(
        int $companyId,
        int $accountingPeriodId,
        array $identity,
        string $lifecycle
    ): array {
        $files = array_map(
            static fn(array $file): array => [
                'filename' => $file['filename'],
                'path' => $file['filename'],
                'bytes' => $file['bytes'],
                'sha256' => $file['sha256'],
            ],
            $this->artifactFiles($identity['directory'])
        );
        $payload = [
            'format' => 'eel-transmission-archive-v2',
            'authority' => $identity['authority'],
            'environment' => $identity['environment'],
            'company_id' => $companyId,
            'company_number' => $identity['company_number'],
            'accounting_period_id' => $accountingPeriodId,
            'submission_reference' => $identity['submission_reference'],
            'lifecycle' => trim($lifecycle) !== '' ? trim($lifecycle) : 'unknown',
            'files' => $files,
            'exchanges' => $this->protocolExchanges($companyId, $identity),
        ];
        $json = \eel_accounts\Support\Utf8::json(
            $payload,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) . "\n";
        $path = $identity['directory'] . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->writeReplaceable($path, $json);
        $sha256 = hash('sha256', $json);
        \InterfaceDB::prepareExecute(
            'UPDATE ' . self::TABLE . '
             SET lifecycle = :lifecycle,
                 manifest_path = :manifest_path,
                 manifest_sha256 = :manifest_sha256,
                 updated_at = :updated_at
             WHERE authority = :authority
               AND company_id = :company_id
               AND environment = :environment
               AND submission_reference = :reference',
            [
                'lifecycle' => mb_substr((string)$payload['lifecycle'], 0, 64),
                'manifest_path' => $path,
                'manifest_sha256' => $sha256,
                'updated_at' => gmdate('Y-m-d H:i:s'),
                'authority' => $identity['authority'],
                'company_id' => $companyId,
                'environment' => $identity['environment'],
                'reference' => $identity['submission_reference'],
            ]
        );

        return ['path' => $path, 'sha256' => $sha256];
    }

    private function recordSubmissionArtifact(
        int $companyId,
        array $identity,
        string $filename,
        string $path,
        string $sha256
    ): void {
        $column = match ($filename) {
            'submission-request.xml' => 'request',
            'submission-response.xml' => 'response',
            default => '',
        };
        if ($column === '') {
            return;
        }
        \InterfaceDB::prepareExecute(
            'UPDATE ' . self::TABLE . '
             SET ' . $column . '_path = :path,
                 ' . $column . '_sha256 = :sha256,
                 updated_at = :updated_at
             WHERE authority = :authority
               AND company_id = :company_id
               AND environment = :environment
               AND submission_reference = :reference',
            [
                'path' => $path,
                'sha256' => $sha256,
                'updated_at' => gmdate('Y-m-d H:i:s'),
                'authority' => $identity['authority'],
                'company_id' => $companyId,
                'environment' => $identity['environment'],
                'reference' => $identity['submission_reference'],
            ]
        );
    }

    /** @return list<array{filename:string,path:string,bytes:int,sha256:string}> */
    private function artifactFiles(string $directory): array
    {
        $files = [];
        foreach (scandir($directory) ?: [] as $filename) {
            if ($filename === '.' || $filename === '..' || $filename === 'manifest.json'
                || str_starts_with($filename, '.archive-')) {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $filename;
            if (!is_file($path)) {
                continue;
            }
            $sha256 = hash_file('sha256', $path);
            $bytes = filesize($path);
            if (!is_string($sha256) || !is_int($bytes)) {
                throw new \RuntimeException('A transmission archive artifact could not be inspected.');
            }
            $files[] = [
                'filename' => $filename,
                'path' => $path,
                'bytes' => $bytes,
                'sha256' => strtolower($sha256),
            ];
        }
        usort($files, static fn(array $left, array $right): int => strcmp($left['filename'], $right['filename']));

        return $files;
    }

    private function verifyStoredFile(string $path, string $sha256, int $bytes): void
    {
        clearstatcache(true, $path);
        $actualBytes = filesize($path);
        $actualSha256 = hash_file('sha256', $path);
        if (!is_int($actualBytes)
            || $actualBytes !== $bytes
            || !is_string($actualSha256)
            || !hash_equals(strtolower($sha256), strtolower($actualSha256))) {
            throw new \RuntimeException('The transmission archive artifact failed its read-back verification.');
        }
    }

    /** @return list<string> */
    private function legacyPreflightReferences(int $submissionId): array
    {
        if (!\InterfaceDB::tableExists('companies_house_company_auth_preflights')) {
            return [];
        }
        $rows = \InterfaceDB::fetchAll(
            'SELECT DISTINCT archive_reference
             FROM companies_house_company_auth_preflights
             WHERE submission_id = :submission_id',
            ['submission_id' => $submissionId]
        );
        $references = [];
        foreach ($rows as $row) {
            $reference = trim((string)($row['archive_reference'] ?? ''));
            if ($reference !== ''
                && preg_match('/^preflight-[1-9][0-9]*$/D', $reference) === 1) {
                $references[] = $reference;
            }
        }

        return array_values(array_unique($references));
    }

    private function replaceProtocolPaths(
        int $submissionId,
        string $sourceDirectory,
        string $targetDirectory
    ): void {
        $targetArchive = \InterfaceDB::fetchOne(
            'SELECT id
             FROM ' . self::TABLE . '
             WHERE authority = :authority
               AND archive_path = :archive_path
             LIMIT 1',
            [
                'authority' => 'companies_house',
                'archive_path' => $targetDirectory,
            ]
        );
        $targetArchiveId = (int)($targetArchive['id'] ?? 0);
        if ($targetArchiveId <= 0) {
            throw new \RuntimeException(
                'The promoted Companies House transmission archive could not be resolved.'
            );
        }
        $replace = static function (mixed $value) use ($sourceDirectory, $targetDirectory): ?string {
            $path = trim((string)$value);
            if ($path === '') {
                return null;
            }
            $sourcePrefix = rtrim($sourceDirectory, '\\/') . DIRECTORY_SEPARATOR;
            if (!str_starts_with($path, $sourcePrefix)) {
                return $path;
            }

            return rtrim($targetDirectory, '\\/') . DIRECTORY_SEPARATOR . substr($path, strlen($sourcePrefix));
        };

        foreach (\InterfaceDB::fetchAll(
            'SELECT id, request_path, response_path
             FROM govtalk_protocol_exchanges
             WHERE authority = :authority
               AND submission_id = :submission_id',
            ['authority' => 'companies_house', 'submission_id' => $submissionId]
        ) as $row) {
            \InterfaceDB::prepareExecute(
                'UPDATE govtalk_protocol_exchanges
                 SET request_path = :request_path,
                     response_path = :response_path,
                     transmission_archive_id = :archive_id,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    'request_path' => $replace($row['request_path'] ?? null),
                    'response_path' => $replace($row['response_path'] ?? null),
                    'archive_id' => $targetArchiveId,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                    'id' => (int)$row['id'],
                ]
            );
        }
        foreach (\InterfaceDB::fetchAll(
            'SELECT id, request_path, response_path
             FROM companies_house_company_auth_preflights
             WHERE submission_id = :submission_id',
            ['submission_id' => $submissionId]
        ) as $row) {
            \InterfaceDB::prepareExecute(
                'UPDATE companies_house_company_auth_preflights
                 SET request_path = :request_path,
                     response_path = :response_path,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    'request_path' => $replace($row['request_path'] ?? null),
                    'response_path' => $replace($row['response_path'] ?? null),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                    'id' => (int)$row['id'],
                ]
            );
        }
    }

    private function removeArtifactDirectory(string $directory): void
    {
        $resolvedRoot = realpath($this->baseRoot);
        $resolvedDirectory = realpath($directory);
        if (!is_string($resolvedRoot)
            || !is_string($resolvedDirectory)
            || !$this->pathWithin($resolvedDirectory, $resolvedRoot)
            || $resolvedDirectory === $resolvedRoot) {
            throw new \RuntimeException('Refusing to remove an unverified transmission archive directory.');
        }
        foreach (scandir($resolvedDirectory) ?: [] as $filename) {
            if ($filename === '.' || $filename === '..') {
                continue;
            }
            $path = $resolvedDirectory . DIRECTORY_SEPARATOR . $filename;
            if (!is_file($path) || !@unlink($path)) {
                throw new \RuntimeException('A migrated transmission archive directory could not be cleaned up.');
            }
        }
        if (!@rmdir($resolvedDirectory)) {
            throw new \RuntimeException('A migrated transmission archive directory could not be removed.');
        }
    }

    /** @return list<array<string,mixed>> */
    private function protocolExchanges(int $companyId, array $identity): array
    {
        if (!\InterfaceDB::tableExists('govtalk_protocol_exchanges')) {
            return [];
        }
        $archive = \InterfaceDB::fetchOne(
            'SELECT id
             FROM ' . self::TABLE . '
             WHERE authority = :authority
               AND environment = :environment
               AND company_id = :company_id
               AND submission_reference = :reference
             LIMIT 1',
            [
                'authority' => (string)$identity['authority'],
                'environment' => (string)$identity['environment'],
                'company_id' => $companyId,
                'reference' => (string)$identity['submission_reference'],
            ]
        );
        $archiveId = (int)($archive['id'] ?? 0);
        if ($archiveId <= 0) {
            return [];
        }

        $result = [];
        foreach (\InterfaceDB::fetchAll(
            'SELECT operation, request_message_class, request_qualifier,
                    request_function, endpoint, transaction_id, correlation_id,
                    exchange_state, outcome_code, outcome_summary,
                    request_path, request_sha256, request_bytes,
                    response_path, response_sha256, response_bytes,
                    response_status_code, response_headers_json,
                    response_headers_sha256, govtalk_errors_json,
                    error_summary, sent_at, received_at
             FROM govtalk_protocol_exchanges
             WHERE transmission_archive_id = :archive_id
             ORDER BY id ASC',
            ['archive_id' => $archiveId]
        ) as $row) {
            $result[] = [
                'operation' => (string)$row['operation'],
                'message_class' => (string)($row['request_message_class'] ?? ''),
                'qualifier' => (string)($row['request_qualifier'] ?? ''),
                'function' => (string)($row['request_function'] ?? ''),
                'endpoint' => (string)($row['endpoint'] ?? ''),
                'transaction_id' => (string)$row['transaction_id'],
                'correlation_id' => (string)($row['correlation_id'] ?? ''),
                'state' => (string)$row['exchange_state'],
                'outcome' => (string)($row['outcome_code'] ?? ''),
                'outcome_summary' => (string)($row['outcome_summary'] ?? ''),
                'request' => $this->manifestArtifact(
                    $identity['directory'],
                    $row['request_path'] ?? null,
                    $row['request_sha256'] ?? null,
                    $row['request_bytes'] ?? null
                ),
                'response' => $this->manifestArtifact(
                    $identity['directory'],
                    $row['response_path'] ?? null,
                    $row['response_sha256'] ?? null,
                    $row['response_bytes'] ?? null
                ),
                'http_status' => $row['response_status_code'] !== null
                    ? (int)$row['response_status_code']
                    : null,
                'response_headers' => $this->decodedJsonArray(
                    $row['response_headers_json'] ?? null
                ),
                'response_headers_sha256' => trim((string)(
                    $row['response_headers_sha256'] ?? ''
                )) ?: null,
                'govtalk_errors' => $this->decodedJsonArray(
                    $row['govtalk_errors_json'] ?? null
                ),
                'sent_at' => $row['sent_at'],
                'received_at' => $row['received_at'],
                'error' => $row['error_summary'],
            ];
        }

        return $result;
    }

    /** @return array<mixed> */
    private function decodedJsonArray(mixed $json): array
    {
        $json = trim((string)$json);
        if ($json === '') {
            return [];
        }
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function manifestArtifact(
        string $directory,
        mixed $pathValue,
        mixed $shaValue,
        mixed $bytesValue = null
    ): ?array
    {
        $path = trim((string)$pathValue);
        $sha256 = strtolower(trim((string)$shaValue));
        $prefix = rtrim($directory, '\\/') . DIRECTORY_SEPARATOR;
        if ($path === '' || !str_starts_with($path, $prefix)) {
            return null;
        }

        return [
            'path' => substr($path, strlen($prefix)),
            'sha256' => $sha256 !== '' ? $sha256 : null,
            'bytes' => $bytesValue !== null ? (int)$bytesValue : null,
        ];
    }

    private function writeImmutable(string $path, string $contents, string $sha256): void
    {
        if (is_file($path)) {
            $existing = (string)hash_file('sha256', $path);
            if (!hash_equals($sha256, $existing)) {
                throw new \RuntimeException('An immutable transmission artifact already exists with different bytes.');
            }
            return;
        }
        $this->atomicWrite($path, $contents);
    }

    private function writeReplaceable(string $path, string $contents): void
    {
        $this->atomicWrite($path, $contents, true);
    }

    private function atomicWrite(string $path, string $contents, bool $replace = false): void
    {
        $directory = dirname($path);
        $temporary = tempnam($directory, '.archive-');
        if (!is_string($temporary) || $temporary === '') {
            throw new \RuntimeException('Unable to stage a transmission archive artifact.');
        }
        try {
            $written = file_put_contents($temporary, $contents, LOCK_EX);
            if ($written !== strlen($contents)) {
                throw new \RuntimeException('The transmission archive artifact was not written completely.');
            }
            @chmod($temporary, 0600);
            if ($replace && is_file($path) && !@unlink($path)) {
                throw new \RuntimeException('Unable to replace the transmission archive manifest.');
            }
            if (!$this->renameWithRetry($temporary, $path)) {
                throw new \RuntimeException('Unable to publish the transmission archive artifact atomically.');
            }
            $temporary = '';
            @chmod($path, 0600);
        } finally {
            if ($temporary !== '' && is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function renameWithRetry(string $source, string $target): bool
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            if (@rename($source, $target)) {
                return true;
            }

            if ($attempt < 4) {
                clearstatcache(true, $source);
                clearstatcache(true, $target);
                usleep(10_000 * ($attempt + 1));
            }
        }

        return false;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create protected transmission archive storage.');
        }
        @chmod($directory, 0700);
    }

    private function resolveBaseRoot(?string $baseRoot): string
    {
        $baseRoot = trim((string)$baseRoot);
        if ($baseRoot === '') {
            $uploads = \eel_accounts\Store\AccountingConfigurationStore::uploads();
            $baseRoot = trim((string)($uploads['upload_base_dir'] ?? ''));
        }
        if ($baseRoot === '') {
            $baseRoot = rtrim((string)PROJECT_ROOT, '\\/') . DIRECTORY_SEPARATOR . 'files';
        }
        if (!preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/D', $baseRoot)) {
            throw new \RuntimeException('Transmission archive storage must use an absolute path.');
        }
        $this->ensureDirectory($baseRoot);
        $resolved = realpath($baseRoot);
        if (!is_string($resolved) || $resolved === '') {
            throw new \RuntimeException('Unable to resolve transmission archive storage.');
        }
        $publicRoot = realpath((string)APP_ROOT);
        if (is_string($publicRoot)
            && $this->pathWithin($resolved, $publicRoot)
            && !\eel_accounts\Store\AccountingConfigurationStore::isConfiguredTestUploadPath($resolved)) {
            throw new \RuntimeException('Transmission archives must not be stored beneath the public web root.');
        }

        return rtrim($resolved, '\\/');
    }

    private function authority(string $authority): string
    {
        $authority = strtolower(trim($authority));
        if (!in_array($authority, ['companies_house', 'hmrc'], true)) {
            throw new \InvalidArgumentException('Transmission authority must be Companies House or HMRC.');
        }

        return $authority;
    }

    private function segment(string $value, string $label): string
    {
        $value = trim($value);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $value)) {
            throw new \InvalidArgumentException('The transmission ' . $label . ' is invalid.');
        }

        return $value;
    }

    private function schemaReady(): bool
    {
        return \InterfaceDB::tableExists(self::TABLE);
    }

    private function pathWithin(string $path, string $parent): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $parent = rtrim(str_replace('\\', '/', $parent), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $parent = strtolower($parent);
        }

        return $path === $parent || str_starts_with($path, $parent . '/');
    }
}
