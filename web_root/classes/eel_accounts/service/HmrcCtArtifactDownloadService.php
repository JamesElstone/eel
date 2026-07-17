<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Resolves protected CT600 artifacts only after submission ownership checks. */
final class HmrcCtArtifactDownloadService
{
    public function __construct(
        private ?object $repository = null,
        private ?object $storage = null,
    ) {
    }

    /** @return array{path:string,filename:string,content_type:string,size_bytes:int} */
    public function resolve(
        int $submissionId,
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        string $artifact,
    ): array {
        if ($submissionId <= 0 || $companyId <= 0 || $accountingPeriodId <= 0 || $ctPeriodId <= 0) {
            throw new \InvalidArgumentException('A valid owned CT600 package is required for download.');
        }

        $row = $this->repository()->fetchOwned(
            $submissionId,
            $companyId,
            $accountingPeriodId,
            $ctPeriodId
        );
        if (!is_array($row)) {
            throw new \DomainException('The requested CT600 package does not belong to the authenticated accounting context.');
        }

        $artifact = strtolower(trim($artifact));
        [$pathField, $hashField, $extension, $contentType, $label] = match ($artifact) {
            'ct600' => ['ct600_xml_path', 'ct600_sha256', 'xml', 'application/xml; charset=UTF-8', 'ct600'],
            'accounts' => ['accounts_ixbrl_path', 'accounts_sha256', 'html', 'text/html; charset=UTF-8', 'accounts-ixbrl'],
            'computations' => ['computations_ixbrl_path', 'computations_sha256', 'html', 'text/html; charset=UTF-8', 'computations-ixbrl'],
            'manifest' => ['manifest_path', 'package_hash', 'json', 'application/json; charset=UTF-8', 'manifest'],
            'receipt' => ['response_body_path', 'response_sha256', 'xml', 'application/xml; charset=UTF-8', 'hmrc-receipt'],
            default => throw new \InvalidArgumentException('Unsupported CT600 artifact download.'),
        };

        $storageKey = trim((string)($row[$pathField] ?? ''));
        $sha256 = strtolower(trim((string)($row[$hashField] ?? '')));
        if ($storageKey === '' || !preg_match('/^[a-f0-9]{64}$/D', $sha256)) {
            throw new \RuntimeException('The requested CT600 artifact is not available with a verified fingerprint.');
        }

        // Read first to force byte-for-byte verification, then resolve the
        // protected absolute path only inside this authenticated boundary.
        $contents = $this->storage()->readVerified($storageKey, $sha256);
        $path = $this->storage()->resolveForRead($storageKey);
        $environment = strtolower((string)($row['environment'] ?? $row['mode'] ?? 'test'));
        $filename = sprintf(
            '%s-ct-period-%d-%s-submission-%d.%s',
            $label,
            $ctPeriodId,
            preg_replace('/[^a-z0-9_-]+/', '-', $environment) ?: 'test',
            $submissionId,
            $extension
        );

        return [
            'path' => $path,
            'filename' => $filename,
            'content_type' => $contentType,
            'size_bytes' => strlen($contents),
        ];
    }

    private function repository(): object
    {
        return $this->repository ??= new HmrcCtSubmissionRepository();
    }

    private function storage(): object
    {
        return $this->storage ??= new HmrcCtArtifactStorageService();
    }
}
