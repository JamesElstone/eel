<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class FileCheckService
{
    private array $uploads;
    private ?\eel_accounts\Repository\CompanyRepository $companyRepository;
    private ?\Closure $companyNumberResolver;
    private ?\Closure $companyUploadBaseResolver;

    public function __construct(
        ?array $uploads = null,
        ?\eel_accounts\Repository\CompanyRepository $companyRepository = null,
        ?\Closure $companyNumberResolver = null,
        ?\Closure $companyUploadBaseResolver = null
    )
    {
        if ($uploads === null) {
            $config = \AppConfigurationStore::config();
            $uploads = is_array($config['uploads'] ?? null) ? $config['uploads'] : [];
        }

        $this->uploads = $uploads;
        $this->companyRepository = $companyRepository;
        $this->companyNumberResolver = $companyNumberResolver;
        $this->companyUploadBaseResolver = $companyUploadBaseResolver;
    }

    private function directoryExists(string $directoryPath): bool
    {
        return $directoryPath !== '' && is_dir($directoryPath);
    }

    private function canRead(string $directoryPath): bool
    {
        if (!$this->directoryExists($directoryPath)) {
            return false;
        }

        return is_readable($directoryPath);
    }

    private function canWriteTemporaryFile(string $directoryPath): bool
    {
        if (!$this->directoryExists($directoryPath)) {
            return false;
        }

        $tempFile = rtrim($directoryPath, '\\/') . DIRECTORY_SEPARATOR . '.eel_filecheck_' . uniqid('', true) . '.tmp';
        $result = @file_put_contents($tempFile, 'ok', LOCK_EX);

        if ($result === false) {
            return false;
        }

        @unlink($tempFile);

        return true;
    }

    private function canWriteTemporaryDirectory(string $directoryPath): bool
    {
        if (!$this->directoryExists($directoryPath)) {
            return false;
        }

        $tempDirectory = rtrim($directoryPath, '\\/') . DIRECTORY_SEPARATOR . '.eel_filecheck_' . uniqid('', true);

        if (!@mkdir($tempDirectory, 0755)) {
            return false;
        }

        @rmdir($tempDirectory);

        return true;
    }

    private function createDirectory(string $directoryPath): bool
    {
        if ($directoryPath === '') {
            return false;
        }

        if (is_dir($directoryPath)) {
            return true;
        }

        return @mkdir($directoryPath, 0755, true) || is_dir($directoryPath);
    }

    public function ensureUploadBaseDirectoryExists(): string
    {
        $baseDirectory = $this->getUpload();

        if ($baseDirectory === '') {
            throw new \RuntimeException('The configured upload base directory is empty.');
        }

        if (!is_dir($baseDirectory)) {
            throw new \RuntimeException('The configured upload base directory does not exist: ' . $baseDirectory);
        }

        if (!is_readable($baseDirectory)) {
            throw new \RuntimeException('The configured upload base directory is not readable: ' . $baseDirectory);
        }

        if (!$this->canWriteTemporaryFile($baseDirectory) && !$this->canWriteTemporaryDirectory($baseDirectory)) {
            throw new \RuntimeException('The configured upload base directory is not writable: ' . $baseDirectory);
        }

        return rtrim($baseDirectory, '\\/');
    }

    public function getUpload(): string
    {
        return trim((string)($this->uploads['upload_base_dir'] ?? ''));
    }

    public static function defaultUploadBaseDirectory(): string
    {
        try {
            $configuredPath = trim((string)\AppConfigurationStore::get('uploads.upload_base_dir', ''));
        } catch (\Throwable) {
            $configuredPath = '';
        }

        if ($configuredPath !== '' && is_dir($configuredPath)) {
            return rtrim($configuredPath, '\\/');
        }

        $projectRoot = defined('PROJECT_ROOT')
            ? (string)PROJECT_ROOT
            : dirname(__DIR__, 4) . DIRECTORY_SEPARATOR;

        return rtrim($projectRoot, '\\/') . DIRECTORY_SEPARATOR . 'files';
    }

    public function getUploadBaseDirectoryForCompany(int $companyId): string
    {
        if ($companyId <= 0) {
            throw new \InvalidArgumentException('Company id must be greater than zero.');
        }

        $resolvedBase = '';

        if ($this->companyUploadBaseResolver !== null) {
            $resolvedBase = trim((string)($this->companyUploadBaseResolver)($companyId));
        } else {
            $resolvedBase = $this->getUpload();

            if ($resolvedBase === '' || !is_dir($resolvedBase)) {
                $resolvedBase = self::defaultUploadBaseDirectory();
            }
        }

        return rtrim($resolvedBase, '\\/');
    }

    public function resolveCompanyRelativePath(int $companyId, string $relativePath): ?string
    {
        $relativePath = trim(str_replace(['\\', '/'], '/', $relativePath));

        if ($relativePath === '' || str_contains($relativePath, "\0") || $this->isAbsolutePath($relativePath)) {
            return null;
        }

        $segments = [];
        foreach (explode('/', $relativePath) as $segment) {
            $segment = trim($segment);

            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..' || str_contains($segment, ':')) {
                return null;
            }

            $segments[] = $segment;
        }

        if ($segments === []) {
            return null;
        }

        $baseDirectory = $this->getUploadBaseDirectoryForCompany($companyId);

        if ($baseDirectory === '') {
            return null;
        }

        return $this->joinPath($baseDirectory, implode(DIRECTORY_SEPARATOR, $segments));
    }

    public function getPathDebug(): bool
    {
        return (bool)($this->uploads['show_base_path_details'] ?? false);
    }

    public function inspectDirectory(string $path, string $description): array
    {
        $pathExists = $this->directoryExists($path);
        $canRead = $pathExists && $this->canRead($path);
        $canWrite = $pathExists && (
            $this->canWriteTemporaryFile($path)
            || $this->canWriteTemporaryDirectory($path)
        );
        $state = $pathExists && $canRead && $canWrite;
        $detail = 'The ' . $description . ' path exists and read / write access confirmed OK.';

        if (!$pathExists) {
            $detail = 'The ' . $description . ' path does not exist.';
        } elseif (!$canRead && !$canWrite) {
            $detail = 'The ' . $description . ' path exists, but no read / write access to it.';
        } elseif (!$canRead) {
            $detail = 'The ' . $description . ' path exists, but no read access to it.';
        } elseif (!$canWrite) {
            $detail = 'The ' . $description . ' path exists, but no write access to it.';
        }

        return [
            'state' => $state,
            'detail' => $detail,
            'exists' => $pathExists,
            'can_read' => $canRead,
            'can_write' => $canWrite,
        ];
    }

    public function inspectUploadBase(): array
    {
        return $this->inspectDirectory($this->getUpload(), 'upload base directory');
    }

    public function inspectCompanyUpload(int $companyId): array
    {
        return $this->inspectDirectory($this->getCompanyUpload($companyId), 'company upload directory');
    }

    public function inspectCompanyUploadDirectories(int $companyId): array
    {
        $directories = $this->getCompanyUploadDirectories($companyId);

        return [
            'company' => $this->inspectDirectory($directories['company'], 'company upload directory'),
            'statement' => $this->inspectDirectory($directories['statement'], 'statement upload directory'),
            'expense' => $this->inspectDirectory($directories['expense'], 'expense receipt directory'),
            'receipt' => $this->inspectDirectory($directories['receipt'], 'transaction receipt directory'),
        ];
    }

    public function getCompanyUpload(int $companyId): string
    {
        $baseDirectory = $this->getUploadBaseDirectoryForCompany($companyId);

        return $this->companyUploadForResolvedPathSegment($baseDirectory, $this->companyPathSegment($companyId));
    }

    public function getStatementDirectory(int $companyId): string
    {
        $baseDirectory = $this->getUploadBaseDirectoryForCompany($companyId);

        return $this->categoryUploadForResolvedPathSegment($baseDirectory, $this->companyPathSegment($companyId), 'statements');
    }

    public function getExpenseReceiptDirectory(int $companyId): string
    {
        $baseDirectory = $this->getUploadBaseDirectoryForCompany($companyId);

        return $this->categoryUploadForResolvedPathSegment($baseDirectory, $this->companyPathSegment($companyId), 'expense_receipts');
    }

    public function getTransactionReceiptDirectory(int $companyId): string
    {
        $baseDirectory = $this->getUploadBaseDirectoryForCompany($companyId);

        return $this->categoryUploadForResolvedPathSegment($baseDirectory, $this->companyPathSegment($companyId), 'transaction_receipts');
    }

    public function getManualAssetEvidenceDirectory(int $companyId): string
    {
        $baseDirectory = $this->getUploadBaseDirectoryForCompany($companyId);

        return $this->categoryUploadForResolvedPathSegment($baseDirectory, $this->companyPathSegment($companyId), 'manual_asset_evidence');
    }

    public function getCompaniesHouseDirectory(int $companyId): string
    {
        $baseDirectory = $this->getUploadBaseDirectoryForCompany($companyId);

        return $this->categoryUploadForResolvedPathSegment($baseDirectory, $this->companyPathSegment($companyId), 'companies_house');
    }

    public function getIxbrlDirectory(int $companyId): string
    {
        $baseDirectory = $this->getUploadBaseDirectoryForCompany($companyId);

        return $this->categoryUploadForResolvedPathSegment($baseDirectory, $this->companyPathSegment($companyId), 'ixbrl');
    }

    public function getStatementRelativePath(int $companyId, string $filename): string
    {
        return $this->categoryRelativePathForResolvedPathSegment($this->companyPathSegment($companyId), 'statements', $filename);
    }

    public function getExpenseReceiptRelativePath(int $companyId, string $filename): string
    {
        return $this->categoryRelativePathForResolvedPathSegment($this->companyPathSegment($companyId), 'expense_receipts', $filename);
    }

    public function getTransactionReceiptRelativePath(int $companyId, string $filename): string
    {
        return $this->categoryRelativePathForResolvedPathSegment($this->companyPathSegment($companyId), 'transaction_receipts', $filename);
    }

    public function getManualAssetEvidenceRelativePath(int $companyId, string $filename): string
    {
        return $this->categoryRelativePathForResolvedPathSegment($this->companyPathSegment($companyId), 'manual_asset_evidence', $filename);
    }

    public function ensureCompanyUploadDirectory(int $companyId): string
    {
        $baseDirectory = $this->getUploadBaseDirectoryForCompany($companyId);

        return $this->ensureManagedUploadDirectory(
            $this->companyUploadForResolvedPathSegment($baseDirectory, $this->companyPathSegment($companyId)),
            $baseDirectory
        );
    }

    public function ensureStatementDirectory(int $companyId): string
    {
        $baseDirectory = $this->getUploadBaseDirectoryForCompany($companyId);

        return $this->ensureManagedUploadDirectory(
            $this->categoryUploadForResolvedPathSegment($baseDirectory, $this->companyPathSegment($companyId), 'statements'),
            $baseDirectory
        );
    }

    public function ensureExpenseReceiptDirectory(int $companyId): string
    {
        $baseDirectory = $this->getUploadBaseDirectoryForCompany($companyId);

        return $this->ensureManagedUploadDirectory(
            $this->categoryUploadForResolvedPathSegment($baseDirectory, $this->companyPathSegment($companyId), 'expense_receipts'),
            $baseDirectory
        );
    }

    public function ensureTransactionReceiptDirectory(int $companyId): string
    {
        $baseDirectory = $this->getUploadBaseDirectoryForCompany($companyId);

        return $this->ensureManagedUploadDirectory(
            $this->categoryUploadForResolvedPathSegment($baseDirectory, $this->companyPathSegment($companyId), 'transaction_receipts'),
            $baseDirectory
        );
    }

    public function ensureManualAssetEvidenceDirectory(int $companyId): string
    {
        $baseDirectory = $this->getUploadBaseDirectoryForCompany($companyId);

        return $this->ensureManagedUploadDirectory(
            $this->categoryUploadForResolvedPathSegment($baseDirectory, $this->companyPathSegment($companyId), 'manual_asset_evidence'),
            $baseDirectory
        );
    }

    public function ensureCompaniesHouseDirectory(int $companyId): string
    {
        $baseDirectory = $this->getUploadBaseDirectoryForCompany($companyId);

        return $this->ensureManagedUploadDirectory(
            $this->categoryUploadForResolvedPathSegment($baseDirectory, $this->companyPathSegment($companyId), 'companies_house'),
            $baseDirectory
        );
    }

    public function getCompanyUploadDirectories(int $companyId): array
    {
        $baseDirectory = $this->getUploadBaseDirectoryForCompany($companyId);
        $companyPathSegment = $this->companyPathSegment($companyId);

        return [
            'company' => $this->companyUploadForResolvedPathSegment($baseDirectory, $companyPathSegment),
            'statement' => $this->categoryUploadForResolvedPathSegment($baseDirectory, $companyPathSegment, 'statements'),
            'expense' => $this->categoryUploadForResolvedPathSegment($baseDirectory, $companyPathSegment, 'expense_receipts'),
            'receipt' => $this->categoryUploadForResolvedPathSegment($baseDirectory, $companyPathSegment, 'transaction_receipts'),
            'manual_asset_evidence' => $this->categoryUploadForResolvedPathSegment($baseDirectory, $companyPathSegment, 'manual_asset_evidence'),
            'companies_house' => $this->categoryUploadForResolvedPathSegment($baseDirectory, $companyPathSegment, 'companies_house'),
        ];
    }

    public function ensureCompanyUploadDirectories(int $companyId): bool
    {
        $baseDirectory = $this->getUploadBaseDirectoryForCompany($companyId);

        foreach ($this->getCompanyUploadDirectories($companyId) as $directoryPath) {
            $this->ensureManagedUploadDirectory($directoryPath, $baseDirectory);
        }

        return true;
    }

    private function ensureManagedUploadDirectory(string $directoryPath, ?string $companyBaseDirectory = null): string
    {
        $baseDirectory = $companyBaseDirectory === null
            ? $this->ensureUploadBaseDirectoryExists()
            : $this->ensureExistingUploadBaseDirectory($companyBaseDirectory);
        $normalisedDirectory = rtrim($this->joinPath($directoryPath), '\\/');

        if ($normalisedDirectory === '') {
            throw new \RuntimeException('The upload directory path is empty.');
        }

        $basePrefix = $baseDirectory . DIRECTORY_SEPARATOR;
        if ($normalisedDirectory !== $baseDirectory && !str_starts_with($normalisedDirectory, $basePrefix)) {
            throw new \RuntimeException('The upload directory is outside the configured upload base directory: ' . $normalisedDirectory);
        }

        if (!$this->createDirectory($normalisedDirectory)) {
            throw new \RuntimeException('The upload directory could not be created: ' . $normalisedDirectory);
        }

        if (!is_readable($normalisedDirectory)) {
            throw new \RuntimeException('The upload directory is not readable: ' . $normalisedDirectory);
        }

        if (!$this->canWriteTemporaryFile($normalisedDirectory) && !$this->canWriteTemporaryDirectory($normalisedDirectory)) {
            throw new \RuntimeException('The upload directory is not writable: ' . $normalisedDirectory);
        }

        return $normalisedDirectory;
    }

    private function companyUploadForResolvedPathSegment(string $baseDirectory, string $companyPathSegment): string
    {
        return $this->joinPath($baseDirectory, $this->normaliseCompanyPathSegment($companyPathSegment)) . DIRECTORY_SEPARATOR;
    }

    private function categoryUploadForResolvedPathSegment(string $baseDirectory, string $companyPathSegment, string $category): string
    {
        return $this->joinPath(
            $baseDirectory,
            $this->normaliseCompanyPathSegment($companyPathSegment),
            $this->relativeDirectoryForCategory($category)
        ) . DIRECTORY_SEPARATOR;
    }

    private function ensureExistingUploadBaseDirectory(string $baseDirectory): string
    {
        $baseDirectory = rtrim(trim($baseDirectory), '\\/');

        if ($baseDirectory === '') {
            throw new \RuntimeException('The configured upload base directory is empty.');
        }

        if (!is_dir($baseDirectory)) {
            throw new \RuntimeException('The configured upload base directory does not exist: ' . $baseDirectory);
        }

        if (!is_readable($baseDirectory)) {
            throw new \RuntimeException('The configured upload base directory is not readable: ' . $baseDirectory);
        }

        if (!$this->canWriteTemporaryFile($baseDirectory) && !$this->canWriteTemporaryDirectory($baseDirectory)) {
            throw new \RuntimeException('The configured upload base directory is not writable: ' . $baseDirectory);
        }

        return $baseDirectory;
    }

    private function categoryRelativePathForResolvedPathSegment(string $companyPathSegment, string $category, string $filename): string
    {
        $companyPathSegment = $this->normaliseCompanyPathSegment($companyPathSegment);
        $categoryPathSegment = $this->relativeDirectoryForCategory($category);
        $normalisedFilename = ltrim(str_replace(['\\', '/'], '/', trim($filename)), '/');

        if ($companyPathSegment === '' || $categoryPathSegment === '' || $normalisedFilename === '') {
            throw new \InvalidArgumentException('Company upload relative path inputs must not be empty.');
        }

        return str_replace(\DIRECTORY_SEPARATOR, '/', $this->joinPath($companyPathSegment, $categoryPathSegment, $normalisedFilename));
    }

    private function companyPathSegment(int $companyId): string
    {
        if ($companyId <= 0) {
            throw new \InvalidArgumentException('Company id must be greater than zero.');
        }

        if ($this->companyNumberResolver !== null) {
            $companyNumber = trim((string)($this->companyNumberResolver)($companyId));
        } else {
            $company = ($this->companyRepository ?? new \eel_accounts\Repository\CompanyRepository())->fetchCompanyDetails($companyId);
            $companyNumber = trim((string)($company['company_number'] ?? ''));
        }

        if ($companyNumber === '') {
            throw new \RuntimeException('The selected company does not have a company number for upload storage.');
        }

        return $companyNumber;
    }

    private function normaliseCompanyPathSegment(string $companyPathSegment): string
    {
        $normalised = strtoupper(preg_replace('/\s+/', '', trim($companyPathSegment)) ?? '');
        $normalised = preg_replace('/[^A-Z0-9_-]/', '', $normalised) ?? '';

        if ($normalised === '') {
            throw new \InvalidArgumentException('Company number must not be empty.');
        }

        return $normalised;
    }

    private function relativeDirectoryForCategory(string $category): string
    {
        $category = trim($category);
        $configuredPath = match ($category) {
            'statements' => (string)($this->uploads['statement_relative_path'] ?? './statements/'),
            'expense_receipts' => (string)($this->uploads['expense_receipts_relative_path'] ?? './expense_receipts/'),
            'transaction_receipts' => (string)($this->uploads['transaction_receipts_relative_path'] ?? './transaction_receipts/'),
            'manual_asset_evidence' => (string)($this->uploads['manual_asset_evidence_relative_path'] ?? './manual_asset_evidence/'),
            default => $category,
        };

        $normalisedPath = $this->joinPath($configuredPath);

        if ($normalisedPath === '') {
            $normalisedPath = preg_replace('/[^A-Za-z0-9_-]+/', '', $category) ?? '';
        }

        if ($normalisedPath === '') {
            throw new \InvalidArgumentException('Company upload category path must not be empty.');
        }

        return $normalisedPath;
    }

    private function joinPath(string ...$segments): string
    {
        $parts = [];

        foreach ($segments as $index => $segment) {
            $normalised = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($segment));

            if ($normalised === '') {
                continue;
            }

            $pathParts = array_values(array_filter(
                explode(\DIRECTORY_SEPARATOR, $normalised),
                static fn (string $part): bool => $part !== '' && $part !== '.'
            ));

            if ($pathParts === []) {
                continue;
            }

            if ($index === 0) {
                $prefix = '';

                if (preg_match('/^[A-Za-z]:$/', $pathParts[0]) === 1) {
                    $prefix = array_shift($pathParts) . DIRECTORY_SEPARATOR;
                }

                if ($pathParts === []) {
                    $parts[] = rtrim($prefix, '\\/');
                    continue;
                }

                $parts[] = $prefix . implode(\DIRECTORY_SEPARATOR, $pathParts);
                continue;
            }

            $parts[] = implode(\DIRECTORY_SEPARATOR, $pathParts);
        }

        return implode(\DIRECTORY_SEPARATOR, $parts);
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{1,2})/', $path) === 1
            || preg_match('/^[A-Za-z]:/', $path) === 1;
    }
}
