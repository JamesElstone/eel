<?php
declare(strict_types=1);

final class ReceiptDownloadService
{
    private const ALLOWED_CONTENT_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    private string $baseDirectory;
    private int $timeoutSeconds;
    private int $maxBytes;

    public function __construct(
        string $baseDirectory,
        int $timeoutSeconds = 10,
        int $maxBytes = 10485760
    ) {
        $this->baseDirectory = rtrim($baseDirectory, '/\\');
        $this->timeoutSeconds = max(1, $timeoutSeconds);
        $this->maxBytes = max(1024, $maxBytes);
    }

    public function downloadReceiptForTransaction(int $transactionId): array {
        $transaction = $this->fetchTransaction($transactionId);

        if ($transaction === null) {
            return [
                'success' => false,
                'status' => 'failed',
                'message' => 'Transaction not found.',
            ];
        }

        $sourceUrl = trim((string)($transaction['source_document_url'] ?? ''));

        if ($sourceUrl === '') {
            $this->updateTransactionDocumentState($transactionId, [
                'document_download_status' => 'skipped',
                'document_error' => null,
            ]);

            return [
                'success' => true,
                'status' => 'skipped',
                'message' => 'No source document URL is stored for this transaction.',
            ];
        }

        $existingLocalPath = trim((string)($transaction['local_document_path'] ?? ''));

        if ($existingLocalPath !== '' && is_file($this->absolutePathFromRelative($existingLocalPath))) {
            $this->updateTransactionDocumentState($transactionId, [
                'document_download_status' => 'success',
                'document_error' => null,
            ]);

            return [
                'success' => true,
                'status' => 'success',
                'message' => 'A local receipt file is already available.',
                'local_document_path' => $existingLocalPath,
            ];
        }

        $urlValidation = self::validateReceiptUrl($sourceUrl);
        $documentUrlHash = hash('sha256', $sourceUrl);

        if (!$urlValidation['valid']) {
            $this->updateTransactionDocumentState($transactionId, [
                'document_url_hash' => $documentUrlHash,
                'document_download_status' => 'skipped',
                'document_error' => $urlValidation['error'],
            ]);

            return [
                'success' => false,
                'status' => 'skipped',
                'message' => $urlValidation['error'],
            ];
        }

        $reusedPath = $this->findExistingDownloadedPath((int)$transaction['company_id'], $documentUrlHash);

        if ($reusedPath !== null) {
            $this->updateTransactionDocumentState($transactionId, [
                'local_document_path' => $reusedPath,
                'document_url_hash' => $documentUrlHash,
                'document_downloaded_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                'document_download_status' => 'success',
                'document_error' => null,
            ]);

            return [
                'success' => true,
                'status' => 'success',
                'message' => 'Reused an existing local receipt copy for the same document URL.',
                'local_document_path' => $reusedPath,
            ];
        }

        $downloadResult = $this->downloadUrlToTemporaryFile($sourceUrl);

        if (!$downloadResult['success']) {
            $this->updateTransactionDocumentState($transactionId, [
                'document_url_hash' => $documentUrlHash,
                'document_download_status' => 'failed',
                'document_error' => $downloadResult['error'],
            ]);

            return [
                'success' => false,
                'status' => 'failed',
                'message' => $downloadResult['error'],
            ];
        }

        $extension = self::determineExtension(
            $downloadResult['content_type'],
            (string)parse_url($sourceUrl, PHP_URL_PATH)
        );
        $filename = self::buildReceiptFilename(
            (string)$transaction['txn_date'],
            (string)$transaction['amount'],
            (string)$transaction['description'],
            $sourceUrl,
            $transactionId,
            $extension
        );
        $receiptDirectory = $this->receiptDirectoryForCompany((int)$transaction['company_id']);
        $this->ensureDirectoryExists($receiptDirectory);

        $absoluteTarget = $receiptDirectory . DIRECTORY_SEPARATOR . $filename;
        $relativeTarget = $this->relativeReceiptPath((int)$transaction['company_id'], $filename);

        try {
            if (!@rename($downloadResult['temp_path'], $absoluteTarget)) {
                if (!@copy($downloadResult['temp_path'], $absoluteTarget) || !@unlink($downloadResult['temp_path'])) {
                    throw new RuntimeException('The downloaded receipt could not be moved into local storage.');
                }
            }
        } catch (Throwable $exception) {
            @unlink($downloadResult['temp_path']);
            $this->updateTransactionDocumentState($transactionId, [
                'document_url_hash' => $documentUrlHash,
                'document_download_status' => 'failed',
                'document_error' => $exception->getMessage(),
            ]);

            return [
                'success' => false,
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ];
        }

        $this->updateTransactionDocumentState($transactionId, [
            'local_document_path' => $relativeTarget,
            'document_url_hash' => $documentUrlHash,
            'document_downloaded_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'document_download_status' => 'success',
            'document_error' => null,
        ]);

        return [
            'success' => true,
            'status' => 'success',
            'message' => 'Receipt downloaded successfully.',
            'local_document_path' => $relativeTarget,
            'content_type' => $downloadResult['content_type'],
        ];
    }

    public static function validateReceiptUrl(string $url): array {
        $url = trim($url);

        if ($url === '') {
            return ['valid' => false, 'error' => 'No receipt URL was provided.'];
        }

        $parts = parse_url($url);

        if (!is_array($parts)) {
            return ['valid' => false, 'error' => 'The receipt URL could not be parsed.'];
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower(trim((string)($parts['host'] ?? '')));

        if (!in_array($scheme, ['http', 'https'], true)) {
            return ['valid' => false, 'error' => 'Only http and https receipt URLs are allowed.'];
        }

        if ($host === '') {
            return ['valid' => false, 'error' => 'The receipt URL is missing a host name.'];
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return ['valid' => false, 'error' => 'Localhost receipt URLs are not allowed.'];
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (!self::isPublicIpAddress($host)) {
                return ['valid' => false, 'error' => 'Private, loopback, and reserved IP receipt URLs are blocked.'];
            }

            return ['valid' => true, 'host' => $host];
        }

        $resolvedAddresses = self::resolveHostAddresses($host);

        if ($resolvedAddresses === []) {
            return ['valid' => false, 'error' => 'The receipt host could not be resolved to a public IP address.'];
        }

        foreach ($resolvedAddresses as $address) {
            if (!self::isPublicIpAddress($address)) {
                return ['valid' => false, 'error' => 'The receipt host resolves to a blocked private or reserved IP address.'];
            }
        }

        return ['valid' => true, 'host' => $host];
    }

    public static function sanitiseDescriptionForFilename(string $description): string {
        $description = strtolower(trim($description));
        $description = preg_replace('/\s+/', '_', $description) ?? $description;
        $description = preg_replace('/[^a-z0-9_]+/', '', $description) ?? $description;
        $description = preg_replace('/_+/', '_', $description) ?? $description;
        $description = trim($description, '_');

        if ($description === '') {
            $description = 'document';
        }

        return substr($description, 0, 40);
    }

    public static function determineExtension(?string $contentType, string $urlPath = ''): string {
        $normalisedContentType = self::normaliseContentType($contentType);

        if ($normalisedContentType !== null && isset(self::ALLOWED_CONTENT_TYPES[$normalisedContentType])) {
            return self::ALLOWED_CONTENT_TYPES[$normalisedContentType];
        }

        $extension = strtolower(trim((string)pathinfo($urlPath, PATHINFO_EXTENSION)));

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
            return $extension === 'jpeg' ? 'jpg' : $extension;
        }

        return 'bin';
    }

    public static function buildReceiptFilename(
        string $txnDate,
        string $amount,
        string $description,
        string $url,
        int $transactionId,
        string $extension
    ): string {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $txnDate) === 1
            ? str_replace('-', '', $txnDate)
            : 'unknown_date';
        $absoluteAmount = number_format(abs((float)$amount), 2, '.', '');
        $sanitisedDescription = self::sanitiseDescriptionForFilename($description);
        $shortHash = substr(hash('sha256', $url . $transactionId), 0, 8);
        $extension = ltrim(strtolower(trim($extension)), '.');
        $extension = $extension !== '' ? $extension : 'bin';

        return sprintf(
            '%s_%s_%s_%s.%s',
            $date,
            $absoluteAmount,
            $sanitisedDescription,
            $shortHash,
            $extension
        );
    }

    private function fetchTransaction(int $transactionId): ?array {
        $row = InterfaceDB::fetchOne( 'SELECT id,
                    company_id,
                    txn_date,
                    amount,
                    description,
                    source_document_url,
                    local_document_path,
                    document_download_status,
                    document_url_hash
             FROM transactions
             WHERE id = :id
             LIMIT 1', ['id' => $transactionId]);

        return is_array($row) ? $row : null;
    }

    private function findExistingDownloadedPath(int $companyId, string $documentUrlHash): ?string {
        if ($companyId <= 0 || $documentUrlHash === '') {
            return null;
        }

        $path = InterfaceDB::fetchColumn( 'SELECT local_document_path
             FROM transactions
             WHERE company_id = :company_id
               AND document_url_hash = :document_url_hash
               AND document_download_status = :document_download_status
               AND local_document_path IS NOT NULL
               AND local_document_path <> \'\'
             ORDER BY id ASC
             LIMIT 1', [
            'company_id' => $companyId,
            'document_url_hash' => $documentUrlHash,
            'document_download_status' => 'success',
        ]);

        if (!is_string($path) || trim($path) === '') {
            return null;
        }

        $absolutePath = $this->absolutePathFromRelative($path);

        return is_file($absolutePath) ? trim($path) : null;
    }

    private function downloadUrlToTemporaryFile(string $url): array {
        $tempPath = tempnam(sys_get_temp_dir(), 'eel_receipt_');

        if ($tempPath === false) {
            return [
                'success' => false,
                'error' => 'A temporary file could not be created for the receipt download.',
            ];
        }

        $handle = fopen($tempPath, 'wb');

        if ($handle === false) {
            @unlink($tempPath);

            return [
                'success' => false,
                'error' => 'A temporary receipt file could not be opened for writing.',
            ];
        }

        try {
            $response = ReceiptOutbound::downloadToFile($url, $handle, $this->timeoutSeconds, $this->maxBytes);
        } catch (Throwable $exception) {
            fclose($handle);
            @unlink($tempPath);

            return [
                'success' => false,
                'error' => $exception->getMessage(),
            ];
        }

        fclose($handle);
        $httpStatus = (int)($response['status_code'] ?? 0);
        $contentType = self::normaliseContentType((string)($response['content_type'] ?? ''));

        if ($httpStatus !== 200) {
            @unlink($tempPath);

            return [
                'success' => false,
                'error' => 'Receipt download returned HTTP ' . $httpStatus . '.',
            ];
        }

        if ($contentType === null) {
            @unlink($tempPath);

            return [
                'success' => false,
                'error' => 'Receipt download did not return a content type.',
            ];
        }

        if (!isset(self::ALLOWED_CONTENT_TYPES[$contentType])) {
            @unlink($tempPath);

            return [
                'success' => false,
                'error' => $contentType === 'text/html'
                    ? 'Receipt download returned HTML instead of a supported receipt file.'
                    : 'Receipt download returned unsupported content type ' . $contentType . '.',
            ];
        }

        if (!is_file($tempPath) || filesize($tempPath) === 0) {
            @unlink($tempPath);

            return [
                'success' => false,
                'error' => 'Receipt download returned an empty file.',
            ];
        }

        return [
            'success' => true,
            'temp_path' => $tempPath,
            'content_type' => $contentType,
        ];
    }

    private function updateTransactionDocumentState(int $transactionId, array $state): void {
        $fields = [];
        $params = ['id' => $transactionId];

        foreach ([
            'local_document_path',
            'document_url_hash',
            'document_downloaded_at',
            'document_download_status',
            'document_error',
        ] as $field) {
            if (!array_key_exists($field, $state)) {
                continue;
            }

            $fields[] = $field . ' = :' . $field;
            $params[$field] = $state[$field];
        }

        if ($fields === []) {
            return;
        }

        $stmt = InterfaceDB::prepare(
            'UPDATE transactions
             SET ' . implode(', ', $fields) . '
             WHERE id = :id'
        );
        $stmt->execute($params);
    }

    private function receiptDirectoryForCompany(int $companyId): string {
        return HelperFramework::companyUploadSubdirectory($companyId, 'transaction_receipts', $this->baseDirectory);
    }

    private function relativeReceiptPath(int $companyId, string $filename): string {
        return HelperFramework::companyUploadRelativePath($companyId, 'transaction_receipts', $filename);
    }

    private function absolutePathFromRelative(string $relativePath): string {
        $normalisedRelativePath = ltrim(str_replace(['/', '\\'], '/', $relativePath), '/');

        if (str_starts_with($normalisedRelativePath, 'uploads/')) {
            return dirname($this->baseDirectory)
                . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $normalisedRelativePath);
        }

        return $this->baseDirectory
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $normalisedRelativePath);
    }

    private function ensureDirectoryExists(string $directory): void {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('The receipt storage directory could not be created: ' . $directory);
        }
    }

    private static function normaliseContentType(?string $contentType): ?string {
        $contentType = trim((string)$contentType);

        if ($contentType === '') {
            return null;
        }

        $parts = explode(';', strtolower($contentType));
        $base = trim($parts[0]);

        return $base !== '' ? $base : null;
    }

    private static function resolveHostAddresses(string $host): array {
        $addresses = [];

        if (function_exists('dns_get_record')) {
            try {
                $dnsRecords = dns_get_record($host, DNS_A + DNS_AAAA);

                if (is_array($dnsRecords)) {
                    foreach ($dnsRecords as $record) {
                        foreach (['ip', 'ipv6'] as $key) {
                            if (!empty($record[$key]) && filter_var($record[$key], FILTER_VALIDATE_IP) !== false) {
                                $addresses[] = $record[$key];
                            }
                        }
                    }
                }
            } catch (Throwable $exception) {
            }
        }

        $ipv4Addresses = @gethostbynamel($host);

        if (is_array($ipv4Addresses)) {
            foreach ($ipv4Addresses as $address) {
                if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
                    $addresses[] = $address;
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    private static function isPublicIpAddress(string $ipAddress): bool {
        return filter_var(
            $ipAddress,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}



