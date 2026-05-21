<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class CompanyOrphanedFileCleanupService
{
    private string $uploadBaseDirectory;
    private FileCheckService $fileCheckService;

    public function __construct(
        string $defaultStatementDirectory = '',
        string $receiptDownloadBaseDirectory = '',
        string $expenseUploadsRoot = ''
    ) {
        $this->uploadBaseDirectory = rtrim($expenseUploadsRoot !== '' ? $expenseUploadsRoot : $this->defaultUploadsRoot(), '/\\');
        $this->fileCheckService = new FileCheckService($this->uploadsConfig());
    }

    public function deleteOrphanedTransferredFiles(int $companyId, ?string $actor = null): array {
        if (!$this->companyExists($companyId)) {
            return [
                'success' => false,
                'errors' => ['The selected company could not be found.'],
                'counts' => [],
            ];
        }

        $counts = [
            'statement_files_deleted' => 0,
            'transaction_receipts_deleted' => 0,
            'expense_receipts_deleted' => 0,
            'files_failed' => 0,
        ];
        $errors = [];

        $statementResult = $this->deleteOrphanedStatementFiles($companyId);
        $counts['statement_files_deleted'] = $statementResult['deleted'];
        $counts['files_failed'] += $statementResult['failed'];
        $errors = array_merge($errors, $statementResult['errors']);

        $transactionReceiptResult = $this->deleteOrphanedTransactionReceipts($companyId);
        $counts['transaction_receipts_deleted'] = $transactionReceiptResult['deleted'];
        $counts['files_failed'] += $transactionReceiptResult['failed'];
        $errors = array_merge($errors, $transactionReceiptResult['errors']);

        $expenseReceiptResult = $this->deleteOrphanedExpenseReceipts($companyId);
        $counts['expense_receipts_deleted'] = $expenseReceiptResult['deleted'];
        $counts['files_failed'] += $expenseReceiptResult['failed'];
        $errors = array_merge($errors, $expenseReceiptResult['errors']);

        error_log('[company_orphaned_file_cleanup] ' . json_encode([
            'company_id' => $companyId,
            'actor' => $actor !== null && trim($actor) !== '' ? trim($actor) : 'unknown',
            'timestamp' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
            'counts' => $counts,
            'errors' => $errors,
        ], JSON_UNESCAPED_SLASHES));

        return [
            'success' => $errors === [],
            'errors' => $errors,
            'counts' => $counts,
        ];
    }

    private function deleteOrphanedStatementFiles(int $companyId): array {
        $referencedFiles = $this->fetchReferencedStatementFiles($companyId);
        $directories = array_unique(array_filter([
            $this->fileCheckService->getStatementDirectory($companyId),
        ]));

        return $this->deleteUnreferencedFilesInDirectories(
            $directories,
            static fn(string $filename): bool => $filename !== '' && $filename !== '.' && $filename !== '..',
            $referencedFiles,
            'statement upload'
        );
    }

    private function deleteOrphanedTransactionReceipts(int $companyId): array {
        if ($companyId <= 0) {
            return ['deleted' => 0, 'failed' => 0, 'errors' => []];
        }

        $directories = array_unique([
            $this->fileCheckService->getTransactionReceiptDirectory($companyId),
        ]);

        return $this->deleteUnreferencedFilesInDirectories(
            $directories,
            static fn(string $filename): bool => $filename !== '' && $filename !== '.' && $filename !== '..',
            $this->fetchReferencedTransactionReceiptFiles($companyId),
            'downloaded receipt'
        );
    }

    private function deleteOrphanedExpenseReceipts(int $companyId): array {
        if ($companyId <= 0) {
            return ['deleted' => 0, 'failed' => 0, 'errors' => []];
        }

        $directories = array_unique([
            $this->fileCheckService->getExpenseReceiptDirectory($companyId),
        ]);

        return $this->deleteUnreferencedFilesInDirectories(
            $directories,
            static fn(string $filename): bool => $filename !== '' && $filename !== '.' && $filename !== '..',
            $this->fetchReferencedExpenseReceiptFiles($companyId),
            'expense receipt'
        );
    }

    private function deleteUnreferencedFilesInDirectories(
        array $directories,
        callable $shouldConsiderFile,
        array $referencedFiles,
        string $label
    ): array {
        $deleted = 0;
        $failed = 0;
        $errors = [];

        foreach ($directories as $directory) {
            $result = $this->deleteUnreferencedFiles($directory, $shouldConsiderFile, $referencedFiles, $label);
            $deleted += (int)($result['deleted'] ?? 0);
            $failed += (int)($result['failed'] ?? 0);
            $errors = array_merge($errors, $result['errors'] ?? []);
        }

        return [
            'deleted' => $deleted,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    private function deleteUnreferencedFiles(
        string $directory,
        callable $shouldConsiderFile,
        array $referencedFiles,
        string $label
    ): array {
        if ($directory === '' || !is_dir($directory)) {
            return ['deleted' => 0, 'failed' => 0, 'errors' => []];
        }

        $deleted = 0;
        $failed = 0;
        $errors = [];
        $entries = scandir($directory);

        if (!is_array($entries)) {
            return [
                'deleted' => 0,
                'failed' => 1,
                'errors' => ['The ' . $label . ' directory could not be scanned: ' . $directory],
            ];
        }

        foreach ($entries as $entry) {
            $filename = trim((string)$entry);

            if (!$shouldConsiderFile($filename)) {
                continue;
            }

            $absolutePath = $directory . DIRECTORY_SEPARATOR . $filename;

            if (!is_file($absolutePath) || isset($referencedFiles[$filename])) {
                continue;
            }

            if (@unlink($absolutePath)) {
                $deleted++;
                continue;
            }

            $failed++;
            $errors[] = sprintf('The orphaned %s file could not be deleted: %s', $label, $absolutePath);
        }

        return [
            'deleted' => $deleted,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    private function companyExists(int $companyId): bool {
        if ($companyId <= 0) {
            return false;
        }

        return InterfaceDB::fetchColumn(
            'SELECT 1
             FROM companies
             WHERE id = :company_id
             LIMIT 1',
            ['company_id' => $companyId]
        ) !== false;
    }

    private function fetchReferencedStatementFiles(int $companyId): array {
        return $this->fetchBasenameLookup(
            InterfaceDB::prepareExecute( 'SELECT stored_filename
             FROM statement_uploads
             WHERE company_id = :company_id
               AND stored_filename IS NOT NULL
               AND stored_filename <> \'\'', ['company_id' => $companyId])->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    private function fetchReferencedTransactionReceiptFiles(int $companyId): array {
        return $this->fetchBasenameLookup(
            InterfaceDB::prepareExecute( 'SELECT local_document_path
             FROM transactions
             WHERE company_id = :company_id
               AND local_document_path IS NOT NULL
               AND local_document_path <> \'\'', ['company_id' => $companyId])->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    private function fetchReferencedExpenseReceiptFiles(int $companyId): array {
        return $this->fetchBasenameLookup(
            InterfaceDB::prepareExecute( 'SELECT l.receipt_reference
             FROM expense_claim_lines l
             INNER JOIN expense_claims c ON c.id = l.expense_claim_id
             WHERE c.company_id = :company_id
               AND l.receipt_reference IS NOT NULL
               AND l.receipt_reference <> \'\'', ['company_id' => $companyId])->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    private function fetchBasenameLookup(array $paths): array {
        $lookup = [];

        foreach ($paths as $path) {
            $basename = basename(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim((string)$path)));

            if ($basename === '' || $basename === '.' || $basename === '..') {
                continue;
            }

            $lookup[$basename] = true;
        }

        return $lookup;
    }

    private function uploadsConfig(): array
    {
        $config = AppConfigurationStore::config();
        $uploads = is_array($config['uploads'] ?? null) ? $config['uploads'] : [];
        $uploads['upload_base_dir'] = $this->uploadBaseDirectory;

        return $uploads;
    }

    private function defaultUploadsRoot(): string
    {
        $config = AppConfigurationStore::config();
        $configuredPath = trim((string)($config['uploads']['upload_base_dir'] ?? ''));

        if ($configuredPath !== '') {
            return $configuredPath;
        }

        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'uploads';
    }
}



