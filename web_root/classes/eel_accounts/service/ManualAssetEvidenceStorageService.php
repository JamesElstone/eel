<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class ManualAssetEvidenceStorageService
{
    private const ALLOWED_CONTENT_TYPES = [
        'image/jpeg' => 'jpg',
        'application/pdf' => 'pdf',
    ];

    private string $uploadsRoot;
    private int $maxBytes;
    private \eel_accounts\Service\FileCheckService $fileCheckService;

    public function __construct(?string $uploadsRoot = null, int $maxBytes = 10485760, ?\eel_accounts\Service\FileCheckService $fileCheckService = null)
    {
        $this->uploadsRoot = rtrim($uploadsRoot ?? $this->defaultUploadsRoot(), '/\\');
        $this->maxBytes = max(1024, $maxBytes);
        $this->fileCheckService = $fileCheckService ?? new \eel_accounts\Service\FileCheckService($this->uploadsConfig());
    }

    public function storeEvidence(int $companyId, string $assetCode, array $file): array
    {
        $assetCode = trim($assetCode);
        if ($companyId <= 0 || $assetCode === '') {
            return ['success' => false, 'errors' => ['Asset evidence cannot be stored without a company and asset code.']];
        }

        $validation = $this->validateUploadedFile($file);
        if (!empty($validation['errors'])) {
            return ['success' => false, 'errors' => $validation['errors']];
        }

        $directory = $this->fileCheckService->ensureManualAssetEvidenceDirectory($companyId);
        $extension = self::extensionForContentType((string)$validation['content_type']);
        $sha256 = hash_file('sha256', (string)$file['tmp_name']);
        if (!is_string($sha256) || $sha256 === '') {
            return ['success' => false, 'errors' => ['The asset evidence checksum could not be calculated.']];
        }

        $filename = $this->buildFilename($assetCode, (string)($file['name'] ?? ''), $extension, $sha256);
        $targetPath = $directory . DIRECTORY_SEPARATOR . $filename;
        $relativePath = $this->fileCheckService->getManualAssetEvidenceRelativePath($companyId, $filename);

        if (!move_uploaded_file((string)$file['tmp_name'], $targetPath)) {
            return ['success' => false, 'errors' => ['The uploaded asset evidence could not be stored on the server.']];
        }

        return [
            'success' => true,
            'path' => $relativePath,
            'sha256' => $sha256,
            'original_filename' => $this->sanitiseOriginalFilename((string)($file['name'] ?? 'asset-evidence')),
            'content_type' => (string)$validation['content_type'],
            'size_bytes' => (int)($file['size'] ?? 0),
            'absolute_path' => $targetPath,
        ];
    }

    public function deleteStoredEvidence(array $storedEvidence): void
    {
        $absolutePath = trim((string)($storedEvidence['absolute_path'] ?? ''));
        if ($absolutePath !== '' && is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    private function validateUploadedFile(array $file): array
    {
        $errors = [];
        $errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $tmpName = (string)($file['tmp_name'] ?? '');
        $size = (int)($file['size'] ?? 0);

        if ($errorCode !== UPLOAD_ERR_OK) {
            return ['errors' => [$this->uploadErrorMessage($errorCode)]];
        }

        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return ['errors' => ['The uploaded asset evidence file was not received as a valid HTTP upload.']];
        }

        if ($size <= 0) {
            return ['errors' => ['The uploaded asset evidence file is empty.']];
        }

        if ($size > $this->maxBytes) {
            return ['errors' => ['The uploaded asset evidence file exceeds the 10MB size limit.']];
        }

        $finfo = new \finfo(\FILEINFO_MIME_TYPE);
        $contentType = (string)($finfo->file($tmpName) ?: '');
        if (!isset(self::ALLOWED_CONTENT_TYPES[$contentType])) {
            $errors[] = 'Only PDF and JPG/JPEG asset evidence files are allowed.';
        }

        return [
            'errors' => $errors,
            'content_type' => $contentType,
        ];
    }

    private function buildFilename(string $assetCode, string $originalName, string $extension, string $sha256): string
    {
        $assetCode = preg_replace('/[^A-Za-z0-9_-]+/', '_', $assetCode) ?? 'asset';
        $assetCode = trim($assetCode, '_') !== '' ? trim($assetCode, '_') : 'asset';

        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        $baseName = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim((string)$baseName)) ?? 'evidence';
        $baseName = trim($baseName, '_') !== '' ? trim($baseName, '_') : 'evidence';

        return strtolower($assetCode) . '_' . substr($sha256, 0, 12) . '_' . strtolower($baseName) . '.' . $extension;
    }

    private function sanitiseOriginalFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', trim($filename)));
        $filename = preg_replace('/[^\w .-]+/u', '_', $filename) ?? 'asset-evidence';
        $filename = trim($filename);

        return $filename !== '' ? $filename : 'asset-evidence';
    }

    private function defaultUploadsRoot(): string
    {
        $config = \AppConfigurationStore::config();
        $configuredPath = trim((string)($config['uploads']['upload_base_dir'] ?? ''));
        if ($configuredPath !== '') {
            return $configuredPath;
        }

        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    }

    private function uploadsConfig(): array
    {
        $config = \AppConfigurationStore::config();
        $uploads = is_array($config['uploads'] ?? null) ? $config['uploads'] : [];
        $uploads['upload_base_dir'] = $this->uploadsRoot;

        return $uploads;
    }

    private static function extensionForContentType(string $contentType): string
    {
        return self::ALLOWED_CONTENT_TYPES[$contentType] ?? 'bin';
    }

    private function uploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded asset evidence file exceeds the allowed upload size.',
            UPLOAD_ERR_PARTIAL => 'The uploaded asset evidence file was only partially received.',
            UPLOAD_ERR_NO_FILE => 'Upload evidence that the manual asset exists.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server upload temporary directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'The uploaded asset evidence file could not be written to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the asset evidence upload.',
            default => 'The asset evidence file upload failed.',
        };
    }
}
