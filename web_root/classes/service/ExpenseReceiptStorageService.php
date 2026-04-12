<?php
declare(strict_types=1);

final class ExpenseReceiptStorageService
{
    private const ALLOWED_CONTENT_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    private PDO $pdo;
    private string $uploadsRoot;
    private int $maxBytes;

    public function __construct(PDO $pdo, ?string $uploadsRoot = null, int $maxBytes = 10485760) {
        $this->pdo = $pdo;
        $this->uploadsRoot = rtrim($uploadsRoot ?? $this->defaultUploadsRoot(), '/\\');
        $this->maxBytes = max(1024, $maxBytes);
    }

    public function uploadReceipt(int $companyId, int $claimId, int $lineId, array $file): array {
        $line = $this->fetchLineContext($companyId, $claimId, $lineId);
        if ($line === null) {
            return ['success' => false, 'errors' => ['The selected expense line could not be found.']];
        }
        if ((string)($line['claim_status'] ?? '') === 'posted') {
            return ['success' => false, 'errors' => ['Posted claims are locked.']];
        }

        $validation = $this->validateUploadedFile($file);
        if (!empty($validation['errors'])) {
            return ['success' => false, 'errors' => $validation['errors']];
        }

        $companyDirectory = $this->receiptDirectoryForCompany((int)($line['company_id'] ?? 0));
        $this->ensureDirectoryExists($companyDirectory);

        $extension = self::extensionForContentType((string)$validation['content_type']);
        $filename = $this->buildFilename(
            (string)$line['claim_reference_code'],
            (int)$lineId,
            (string)($file['name'] ?? ''),
            $extension
        );
        $targetPath = $companyDirectory . DIRECTORY_SEPARATOR . $filename;
        $relativePath = $this->relativePathForCompany((int)($line['company_id'] ?? 0), $filename);

        if (!move_uploaded_file((string)$file['tmp_name'], $targetPath)) {
            return ['success' => false, 'errors' => ['The uploaded receipt could not be stored on the server.']];
        }

        $existingPath = trim((string)($line['receipt_reference'] ?? ''));
        if ($existingPath !== '') {
            $existingAbsolutePath = $this->absolutePathFromStoredReference(
                (int)($line['company_id'] ?? 0),
                (string)($line['company_number'] ?? ''),
                $existingPath
            );
            if ($existingAbsolutePath !== null && is_file($existingAbsolutePath) && strcasecmp($existingAbsolutePath, $targetPath) !== 0) {
                @unlink($existingAbsolutePath);
            }
        }

        $this->pdo->prepare(
            'UPDATE expense_claim_lines
             SET receipt_reference = :receipt_reference,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND expense_claim_id = :expense_claim_id'
        )->execute([
            'receipt_reference' => $relativePath,
            'id' => $lineId,
            'expense_claim_id' => $claimId,
        ]);

        return [
            'success' => true,
            'receipt_reference' => $relativePath,
            'receipt_suffix' => $extension,
        ];
    }

    public function removeReceipt(int $companyId, int $claimId, int $lineId): array {
        $line = $this->fetchLineContext($companyId, $claimId, $lineId);
        if ($line === null) {
            return ['success' => false, 'errors' => ['The selected expense line could not be found.']];
        }
        if ((string)($line['claim_status'] ?? '') === 'posted') {
            return ['success' => false, 'errors' => ['Posted claims are locked.']];
        }

        $storedReference = trim((string)($line['receipt_reference'] ?? ''));
        if ($storedReference !== '') {
            $absolutePath = $this->absolutePathFromStoredReference(
                (int)($line['company_id'] ?? 0),
                (string)($line['company_number'] ?? ''),
                $storedReference
            );
            if ($absolutePath !== null && is_file($absolutePath)) {
                @unlink($absolutePath);
            }
        }

        $this->pdo->prepare(
            'UPDATE expense_claim_lines
             SET receipt_reference = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND expense_claim_id = :expense_claim_id'
        )->execute([
            'id' => $lineId,
            'expense_claim_id' => $claimId,
        ]);

        return ['success' => true];
    }

    public function outputReceipt(int $companyId, int $lineId): never {
        $line = $this->fetchLineContext($companyId, 0, $lineId);
        if ($line === null) {
            $this->outputNotFound();
        }

        $storedReference = trim((string)($line['receipt_reference'] ?? ''));
        $absolutePath = $this->absolutePathFromStoredReference(
            (int)($line['company_id'] ?? 0),
            (string)($line['company_number'] ?? ''),
            $storedReference
        );

        if ($storedReference === '' || $absolutePath === null || !is_file($absolutePath)) {
            $this->outputNotFound();
        }

        $contentType = $this->detectContentType($absolutePath);
        if (!isset(self::ALLOWED_CONTENT_TYPES[$contentType])) {
            $this->outputNotFound();
        }

        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . (string)filesize($absolutePath));
        header('Content-Disposition: inline; filename="' . basename($absolutePath) . '"');
        readfile($absolutePath);
        exit;
    }

    private function fetchLineContext(int $companyId, int $claimId, int $lineId): ?array {
        if ($companyId <= 0 || $lineId <= 0) {
            return null;
        }

        $sql = 'SELECT l.id,
                       l.expense_claim_id,
                       l.receipt_reference,
                       c.company_id,
                       c.claim_reference_code,
                       c.status AS claim_status,
                       NULLIF(TRIM(COALESCE(co.company_number, \'\')), \'\') AS company_number
                FROM expense_claim_lines l
                INNER JOIN expense_claims c ON c.id = l.expense_claim_id
                INNER JOIN companies co ON co.id = c.company_id
                WHERE l.id = :line_id
                  AND c.company_id = :company_id';

        $params = [
            'line_id' => $lineId,
            'company_id' => $companyId,
        ];

        if ($claimId > 0) {
            $sql .= ' AND l.expense_claim_id = :expense_claim_id';
            $params['expense_claim_id'] = $claimId;
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function validateUploadedFile(array $file): array {
        $errors = [];
        $errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $tmpName = (string)($file['tmp_name'] ?? '');
        $size = (int)($file['size'] ?? 0);

        if ($errorCode !== UPLOAD_ERR_OK) {
            return ['errors' => [$this->uploadErrorMessage($errorCode)]];
        }

        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return ['errors' => ['The uploaded receipt file was not received as a valid HTTP upload.']];
        }

        if ($size <= 0) {
            return ['errors' => ['The uploaded receipt file is empty.']];
        }

        if ($size > $this->maxBytes) {
            return ['errors' => ['The uploaded receipt file exceeds the 10MB size limit.']];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $contentType = (string)($finfo->file($tmpName) ?: '');
        if (!isset(self::ALLOWED_CONTENT_TYPES[$contentType])) {
            $errors[] = 'Only PDF, JPG, PNG, and WEBP receipt files are allowed.';
        }

        return [
            'errors' => $errors,
            'content_type' => $contentType,
        ];
    }

    private function buildFilename(string $claimReferenceCode, int $lineId, string $originalName, string $extension): string {
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        $baseName = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim((string)$baseName)) ?? 'receipt';
        $baseName = trim($baseName, '_');
        if ($baseName === '') {
            $baseName = 'receipt';
        }

        return strtolower($claimReferenceCode) . '_line' . $lineId . '_' . substr(hash('sha256', $originalName . microtime(true)), 0, 8) . '_' . strtolower($baseName) . '.' . $extension;
    }

    private function receiptDirectoryForCompany(int $companyId): string {
        return FrameworkHelper::companyUploadSubdirectory($companyId, 'expense_receipts', $this->uploadsRoot);
    }

    private function relativePathForCompany(int $companyId, string $filename): string {
        return FrameworkHelper::companyUploadRelativePath($companyId, 'expense_receipts', $filename);
    }

    private function absolutePathFromStoredReference(int $companyId, string $companyNumber, string $storedReference): ?string {
        $normalisedReference = trim(str_replace(['/', '\\'], '/', $storedReference), '/');

        if ($normalisedReference === '') {
            return null;
        }

        $candidates = [];
        $allowedRoots = [];

        if ($companyId > 0) {
            $candidates[] = $this->uploadsRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalisedReference);
            $allowedRoots[] = $this->receiptDirectoryForCompany($companyId);
        }

        $sanitisedCompanyNumber = $this->sanitiseCompanyNumberForPath($companyNumber);
        if ($sanitisedCompanyNumber !== '') {
            $candidates[] = $this->uploadsRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalisedReference);
            $allowedRoots[] = $this->uploadsRoot
                . DIRECTORY_SEPARATOR
                . 'company'
                . DIRECTORY_SEPARATOR
                . $sanitisedCompanyNumber
                . DIRECTORY_SEPARATOR
                . 'expense_receipts';
        }

        foreach ($candidates as $candidate) {
            $realFile = realpath($candidate);

            if ($realFile === false) {
                continue;
            }

            foreach ($allowedRoots as $allowedRoot) {
                $realAllowedRoot = realpath($allowedRoot);

                if ($realAllowedRoot === false) {
                    continue;
                }

                $basePrefix = rtrim($realAllowedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                if (str_starts_with($realFile, $basePrefix)) {
                    return $realFile;
                }
            }
        }

        return null;
    }

    private function ensureDirectoryExists(string $directory): void {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('The expense receipt storage directory could not be created.');
        }
    }

    private function defaultUploadsRoot(): string {
        $config = FrameworkHelper::config();
        $configuredPath = trim((string)($config['uploads']['upload_base_dir'] ?? ''));
        if ($configuredPath !== '') {
            return $configuredPath;
        }

        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    }

    private function sanitiseCompanyNumberForPath(string $companyNumber): string {
        $normalised = strtoupper(preg_replace('/\s+/', '', trim($companyNumber)) ?? '');
        return preg_replace('/[^A-Z0-9_-]/', '', $normalised) ?? '';
    }

    private static function extensionForContentType(string $contentType): string {
        return self::ALLOWED_CONTENT_TYPES[$contentType] ?? 'bin';
    }

    private function detectContentType(string $absolutePath): string {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        return (string)($finfo->file($absolutePath) ?: 'application/octet-stream');
    }

    private function uploadErrorMessage(int $errorCode): string {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded receipt file exceeds the allowed upload size.',
            UPLOAD_ERR_PARTIAL => 'The uploaded receipt file was only partially received.',
            UPLOAD_ERR_NO_FILE => 'No receipt file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server upload temporary directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'The uploaded receipt file could not be written to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the receipt file upload.',
            default => 'The receipt file upload failed.',
        };
    }

    private function outputNotFound(): never {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Receipt file not found.';
        exit;
    }
}
