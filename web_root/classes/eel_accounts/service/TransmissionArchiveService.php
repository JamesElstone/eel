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

    private string $baseRoot;

    public function __construct(?string $baseRoot = null)
    {
        $this->baseRoot = $this->resolveBaseRoot($baseRoot);
    }

    /**
     * @return array{path:string,sha256:string,bytes:int,archive_path:string,manifest_path:string}
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
        $this->upsertArchive(
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
            'archive_path' => $directory,
            'manifest_path' => $manifest['path'],
        ];
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
            . DIRECTORY_SEPARATOR . $environment
            . DIRECTORY_SEPARATOR . $reference;

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
    ): void {
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
            return;
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
    }

    private function writeManifest(
        int $companyId,
        int $accountingPeriodId,
        array $identity,
        string $lifecycle
    ): array {
        $files = [];
        foreach (scandir($identity['directory']) ?: [] as $filename) {
            if ($filename === '.' || $filename === '..' || $filename === 'manifest.json'
                || str_starts_with($filename, '.archive-')) {
                continue;
            }
            $path = $identity['directory'] . DIRECTORY_SEPARATOR . $filename;
            if (!is_file($path)) {
                continue;
            }
            $files[] = [
                'filename' => $filename,
                'bytes' => (int)filesize($path),
                'sha256' => (string)hash_file('sha256', $path),
            ];
        }
        usort($files, static fn(array $left, array $right): int => strcmp($left['filename'], $right['filename']));
        $payload = [
            'format' => 'eel-transmission-archive-v1',
            'authority' => $identity['authority'],
            'environment' => $identity['environment'],
            'company_id' => $companyId,
            'company_number' => $identity['company_number'],
            'accounting_period_id' => $accountingPeriodId,
            'submission_reference' => $identity['submission_reference'],
            'lifecycle' => trim($lifecycle) !== '' ? trim($lifecycle) : 'unknown',
            'files' => $files,
        ];
        $json = json_encode(
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
            if (!@rename($temporary, $path)) {
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
        if (is_string($publicRoot) && $this->pathWithin($resolved, $publicRoot)) {
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
