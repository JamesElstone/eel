<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class StatementUploadService
{
    public const SOURCE_TYPE = '';
    public const CURRENCY_DEFAULT_OPTION_GBP = '__default_currency_gbp__';
    public const MAX_BATCH_UPLOAD_FILES = 12;

    private const WORKFLOW_UPLOADED = 'uploaded';
    private const WORKFLOW_MAPPED = 'mapped';
    private const WORKFLOW_STAGED = 'staged';
    private const WORKFLOW_COMMITTED = 'committed';
    private const WORKFLOW_COMPLETED = 'completed';

    private const FIELD_DEFINITIONS = [
        'account' => ['label' => 'Account', 'required' => false],
        'created' => ['label' => 'Transaction Date', 'required' => false],
        'processed' => ['label' => 'Processed Date', 'required' => false],
        'type' => ['label' => 'Type', 'required' => false],
        'description' => ['label' => 'Description', 'required' => true],
        'amount' => ['label' => 'Amount', 'required' => true],
        'balance' => ['label' => 'Statement Balance', 'required' => false],
        'currency' => ['label' => 'Currency', 'required' => false],
        'category' => ['label' => 'Category', 'required' => false],
        'document' => ['label' => 'Document', 'required' => false],
    ];

    private const HEADER_ALIASES = [
        'account' => ['account', 'bank account', 'account label'],
        'created' => ['created', 'created at', 'source created', 'booking date', 'transaction date', 'date'],
        'processed' => ['processed', 'processed at', 'settled', 'settled at', 'posted', 'posted at'],
        'type' => ['type', 'transaction type', 'statement type'],
        'description' => ['description', 'details', 'transaction description', 'narrative'],
        'amount' => ['amount', 'transaction amount', 'value'],
        'balance' => ['balance', 'running balance', 'statement balance', 'account balance', 'current balance'],
        'currency' => ['currency', 'curr'],
        'category' => ['category', 'source category', 'expense category'],
        'document' => ['document', 'document url', 'receipt', 'receipt url', 'attachment', 'attachment url'],
    ];

    private string $uploadBaseDirectory;
    private ?TransactionCategorisationService $categorisationService;
    private ?ReceiptDownloadService $receiptDownloadService;

    public function __construct(
        string $defaultUploadDirectory,
        ?TransactionCategorisationService $categorisationService = null,
        ?ReceiptDownloadService $receiptDownloadService = null
    ) {
        $this->uploadBaseDirectory = rtrim($defaultUploadDirectory, DIRECTORY_SEPARATOR);
        $this->categorisationService = $categorisationService;
        $this->receiptDownloadService = $receiptDownloadService;
    }

    public function importUploadedStatement(array $post, array $files): array {
        return $this->createUploadFromHttpRequest($post, $files);
    }

    public function importUploadedStatements(array $post, array $files): array {
        $uploads = $this->extractUploadedFiles($files);
        $uploadCount = count($uploads);

        if ($uploadCount === 0) {
            return $this->failureResult(400, ['At least one CSV file must be uploaded as statement_files[].'], []);
        }

        if ($uploadCount > self::MAX_BATCH_UPLOAD_FILES) {
            return $this->failureResult(
                400,
                [sprintf('You can upload at most %d CSV files at once.', self::MAX_BATCH_UPLOAD_FILES)],
                []
            );
        }

        if ($uploadCount === 1) {
            return $this->createUploadFromHttpRequest($post, ['statement_file' => $uploads[0]]);
        }

        $items = [];
        $warnings = [];
        $errors = [];
        $uploadedCount = 0;
        $duplicateCount = 0;
        $failedCount = 0;
        $lastSuccessfulUploadId = 0;
        $filesSeenBeforeCount = 0;

        foreach ($uploads as $upload) {
            $displayFilename = $this->sanitiseOriginalFilename((string)($upload['original_name'] ?? $upload['name'] ?? 'statement.csv'));
            $result = $this->createUploadFromHttpRequest($post, ['statement_file' => $upload]);
            $itemWarnings = array_values(array_map('strval', is_array($result['warnings'] ?? null) ? $result['warnings'] : []));
            $itemErrors = array_values(array_map('strval', is_array($result['errors'] ?? null) ? $result['errors'] : []));
            $alreadyUploaded = !empty($result['already_uploaded']);
            $success = !empty($result['success']);
            $matchedExistingFileHash = !empty($result['matched_existing_file_hash']);

            if ($success) {
                $lastSuccessfulUploadId = (int)($result['statement_upload_id'] ?? $lastSuccessfulUploadId);
                if ($matchedExistingFileHash) {
                    $filesSeenBeforeCount++;
                }

                if ($alreadyUploaded) {
                    $duplicateCount++;
                } else {
                    $uploadedCount++;
                }
            } else {
                $failedCount++;
            }

            foreach ($itemWarnings as $warning) {
                $warnings[] = $displayFilename . ': ' . $warning;
            }

            foreach ($itemErrors as $error) {
                $errors[] = $displayFilename . ': ' . $error;
            }

            $items[] = [
                'filename' => $displayFilename,
                'success' => $success,
                'already_uploaded' => $alreadyUploaded,
                'matched_existing_file_hash' => $matchedExistingFileHash,
                'resume_allowed' => !empty($result['resume_allowed']),
                'statement_upload_id' => isset($result['statement_upload_id']) ? (int)$result['statement_upload_id'] : null,
                'workflow_status' => (string)($result['workflow_status'] ?? ''),
                'warnings' => $itemWarnings,
                'errors' => $itemErrors,
            ];
        }

        return [
            'http_status' => $failedCount > 0 ? ($uploadedCount > 0 || $duplicateCount > 0 ? 207 : 400) : 201,
            'success' => $uploadedCount > 0 || $duplicateCount > 0,
            'already_uploaded' => false,
            'batch_upload' => true,
            'statement_upload_id' => $lastSuccessfulUploadId > 0 ? $lastSuccessfulUploadId : null,
            'rows_parsed' => 0,
            'rows_inserted' => 0,
            'rows_duplicate' => 0,
            'files_total' => $uploadCount,
            'files_uploaded' => $uploadedCount,
            'files_already_uploaded' => $duplicateCount,
            'files_seen_before' => $filesSeenBeforeCount,
            'files_failed' => $failedCount,
            'items' => $items,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    public function createUploadFromHttpRequest(array $post, array $files): array {
        $warnings = [];
        $errors = [];

        $companyId = $this->requirePositiveInteger($post['company_id'] ?? null, 'company_id', $errors);
        $accountId = $this->requirePositiveInteger($post['account_id'] ?? null, 'account_id', $errors);
        $upload = $files['statement_file'] ?? $files['csv_file'] ?? null;

        if (!is_array($upload)) {
            $errors[] = 'A CSV file must be uploaded as statement_file.';
        }

        if ($errors !== []) {
            return $this->failureResult(400, $errors, $warnings);
        }

        $account = $this->fetchCompanyAccount($companyId, (int)$accountId);

        if ($account === null) {
            return $this->failureResult(404, ['The selected upload account could not be found for this company.'], $warnings);
        }

        $uploadedFile = $this->validateUploadedFile($upload, $errors);

        if ($errors !== []) {
            return $this->failureResult(400, $errors, $warnings);
        }

        $fileSha256 = hash_file('sha256', $uploadedFile['tmp_name']);
        $existingUpload = $this->findUploadByFileHash($companyId, $fileSha256);

        if ($existingUpload !== null) {
            $warnings[] = $this->buildDuplicateFileWarning($existingUpload);
        }

        $sourceHeaders = $this->readSourceHeaders($uploadedFile['tmp_name'], $errors);

        if ($errors !== []) {
            return $this->failureResult(422, $errors, $warnings);
        }

        $uploadDirectory = $this->resolveUploadDirectory($companyId);
        $storedFilename = $this->buildStoredFilename();
        $storedPath = $uploadDirectory . DIRECTORY_SEPARATOR . $storedFilename;

        $this->ensureUploadDirectoryExists($uploadDirectory);

        if (!move_uploaded_file($uploadedFile['tmp_name'], $storedPath)) {
            return $this->failureResult(500, ['The uploaded file could not be stored on disk.'], $warnings);
        }

        try {
            InterfaceDB::beginTransaction();

            $this->insertStatementUpload([
                'company_id' => $companyId,
                'tax_year_id' => null,
                'account_id' => $accountId,
                'source_type' => self::SOURCE_TYPE,
                'workflow_status' => self::WORKFLOW_UPLOADED,
                'statement_month' => (new DateTimeImmutable('first day of this month'))->format('Y-m-d'),
                'original_filename' => $uploadedFile['original_name'],
                'stored_filename' => $storedFilename,
                'file_sha256' => $fileSha256,
                'source_headers_json' => $this->encodeJson($sourceHeaders),
                'rows_parsed' => 0,
                'rows_inserted' => 0,
                'rows_duplicate' => 0,
                'rows_valid' => 0,
                'rows_invalid' => 0,
                'rows_duplicate_within_upload' => 0,
                'rows_duplicate_existing' => 0,
                'rows_ready_to_import' => 0,
                'rows_committed' => 0,
                'upload_notes' => null,
            ]);

            $statementUploadId = $this->findStatementUploadId($companyId, $storedFilename);

            if ($statementUploadId === null) {
                throw new RuntimeException('The statement upload row was inserted but could not be reloaded.');
            }

            $autoMapping = $this->resolveInitialMapping($companyId, (int)$accountId, $sourceHeaders);

            $this->upsertStatementImportMapping($statementUploadId, $sourceHeaders, $autoMapping);

            InterfaceDB::commit();
        } catch (Throwable $exception) {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }

            if (is_file($storedPath)) {
                @unlink($storedPath);
            }

            return $this->failureResult(500, ['The staged CSV upload failed: ' . $exception->getMessage()], $warnings);
        }

        return [
            'http_status' => 201,
            'success' => true,
            'already_uploaded' => false,
            'matched_existing_file_hash' => $existingUpload !== null,
            'resume_allowed' => true,
            'statement_upload_id' => $statementUploadId,
            'rows_parsed' => 0,
            'rows_inserted' => 0,
            'rows_duplicate' => 0,
            'workflow_status' => self::WORKFLOW_UPLOADED,
            'source_headers' => $sourceHeaders,
            'auto_mapping' => $autoMapping,
            'warnings' => $warnings,
            'errors' => [],
        ];
    }

    public function saveFieldMapping(array $post): array {
        $warnings = [];
        $errors = [];

        $companyId = $this->requirePositiveInteger($post['company_id'] ?? null, 'company_id', $errors);
        $uploadId = $this->requirePositiveInteger($post['upload_id'] ?? null, 'upload_id', $errors);
        $accountId = $this->requirePositiveInteger($post['account_id'] ?? null, 'account_id', $errors);

        if ($errors !== []) {
            return $this->failureResult(400, $errors, $warnings);
        }

        $upload = $this->fetchUpload($companyId, $uploadId);

        if ($upload === null) {
            return $this->failureResult(404, ['The selected staged upload could not be found.'], $warnings);
        }

        if ((int)($upload['rows_committed'] ?? 0) > 0) {
            return $this->failureResult(409, ['This upload has already been committed and its field mapping can no longer be changed.'], $warnings);
        }

        $account = $this->fetchCompanyAccount($companyId, (int)$accountId);

        if ($account === null) {
            return $this->failureResult(404, ['The selected upload account could not be found for this company.'], $warnings);
        }

        $sourceHeaders = $this->decodeJsonArray((string)($upload['source_headers_json'] ?? ''));
        $mapping = [];

        foreach (array_keys(self::FIELD_DEFINITIONS) as $fieldName) {
            $postedValue = trim((string)($post['mapping_' . $fieldName] ?? ''));

            if ($postedValue === '') {
                $mapping[$fieldName] = null;
                continue;
            }

            if ($fieldName === 'currency' && $postedValue === self::CURRENCY_DEFAULT_OPTION_GBP) {
                $mapping[$fieldName] = [
                    'default_value' => 'GBP',
                    'label' => '£ GBP',
                ];
                continue;
            }

            $matchedIndex = $this->findHeaderIndexByOriginalLabel($sourceHeaders, $postedValue);

            if ($matchedIndex === null) {
                $errors[] = sprintf('The selected source header for %s is no longer available in this file.', self::FIELD_DEFINITIONS[$fieldName]['label']);
                continue;
            }

            $mapping[$fieldName] = [
                'header' => $sourceHeaders[$matchedIndex],
                'index' => $matchedIndex,
            ];
        }

        $errors = array_merge($errors, $this->validateRequiredFieldMapping($mapping));

        if ($errors !== []) {
            return $this->failureResult(422, $errors, $warnings);
        }

        $this->updateUploadAccount($uploadId, (int)$accountId);
        $this->upsertStatementImportMapping($uploadId, $sourceHeaders, $mapping);
        $this->updateUploadWorkflow($uploadId, self::WORKFLOW_MAPPED);

        return [
            'http_status' => 200,
            'success' => true,
            'already_uploaded' => false,
            'statement_upload_id' => $uploadId,
            'rows_parsed' => (int)($upload['rows_parsed'] ?? 0),
            'rows_inserted' => (int)($upload['rows_inserted'] ?? 0),
            'rows_duplicate' => (int)($upload['rows_duplicate'] ?? 0),
            'warnings' => $warnings,
            'errors' => [],
            'mapping' => $mapping,
        ];
    }

    public function fetchUpload(int $companyId, int $uploadId): ?array {
        $row = InterfaceDB::fetchOne( 'SELECT su.*,
                    ty.period_start,
                    ty.period_end,
                    ca.account_name,
                    ca.account_type,
                    ca.institution_name,
                    ca.internal_transfer_marker
             FROM statement_uploads su
             LEFT JOIN tax_years ty ON ty.id = su.tax_year_id
             LEFT JOIN company_accounts ca ON ca.id = su.account_id
             WHERE su.id = :id
               AND su.company_id = :company_id
             LIMIT 1', [
            'id' => $uploadId,
            'company_id' => $companyId,
        ]);

        return is_array($row) ? $row : null;
    }

    public function fetchUploadMapping(int $uploadId): ?array {
        $row = InterfaceDB::fetchOne( 'SELECT id, upload_id, source_type, original_headers_json, mapping_json, created_at, updated_at
             FROM statement_import_mappings
             WHERE upload_id = :upload_id
             LIMIT 1', ['upload_id' => $uploadId]);

        return is_array($row) ? $row : null;
    }

    public function fetchUploadPreview(int $companyId, int $uploadId): array {
        $upload = $this->fetchUpload($companyId, $uploadId);
        $mapping = $this->fetchUploadMapping($uploadId);

        if ($upload === null) {
            return [];
        }

        $rows = InterfaceDB::fetchAll( 'SELECT tir.id,
                    tir.`row_number`,
                    source_account,
                    source_created,
                    source_processed,
                    source_description,
                    source_amount,
                    source_balance,
                    source_currency,
                    source_category,
                    source_document_url,
                    tir.tax_year_id,
                    COALESCE(ty.label, \'\') AS tax_year_label,
                    chosen_txn_date,
                    chosen_date_source,
                    normalised_description,
                    normalised_amount,
                    normalised_balance,
                    normalised_currency,
                    validation_status,
                    validation_notes,
                    is_duplicate_within_upload,
                    is_duplicate_existing,
                    committed_transaction_id,
                    committed_at
             FROM statement_import_rows tir
             LEFT JOIN tax_years ty
                ON ty.id = tir.tax_year_id
             WHERE tir.upload_id = :upload_id
             ORDER BY tir.`row_number` ASC, tir.id ASC', ['upload_id' => $uploadId]);

        return [
            'upload' => $upload,
            'mapping' => $mapping,
            'source_sample' => $this->readUploadSourceSample($upload, 2),
            'rows' => $rows,
            'summary' => [
                'rows_parsed' => (int)($upload['rows_parsed'] ?? 0),
                'rows_valid' => (int)($upload['rows_valid'] ?? 0),
                'rows_invalid' => (int)($upload['rows_invalid'] ?? 0),
                'rows_duplicate_within_upload' => (int)($upload['rows_duplicate_within_upload'] ?? 0),
                'rows_duplicate_existing' => (int)($upload['rows_duplicate_existing'] ?? 0),
                'rows_ready_to_import' => (int)($upload['rows_ready_to_import'] ?? 0),
                'rows_committed' => (int)($upload['rows_committed'] ?? 0),
            ],
        ];
    }

    public function fetchAccountMappingPreview(int $companyId, int $accountId, string $sourceType = self::SOURCE_TYPE): array {
        $uploadId = $this->findLatestMappedUploadIdForAccount($companyId, $accountId, $sourceType);

        if ($uploadId === null) {
            return [];
        }

        return $this->fetchUploadPreview($companyId, $uploadId);
    }

    public function uploadHasAccountMapping(int $companyId, int $uploadId): bool {
        return (bool)($this->describeUploadAccountMappingStatus($companyId, $uploadId)['has_mapping'] ?? false);
    }

    public function describeUploadAccountMappingStatus(int $companyId, int $uploadId): array {
        $upload = $this->fetchUpload($companyId, $uploadId);

        if ($upload === null) {
            return [
                'has_mapping' => false,
                'extra_headers' => [],
            ];
        }

        if ($this->uploadWorkflowHasConfirmedMapping((string)($upload['workflow_status'] ?? ''))) {
            return [
                'has_mapping' => true,
                'extra_headers' => [],
            ];
        }

        $mappingRow = $this->fetchLatestAccountMappingRow(
            $companyId,
            (int)($upload['account_id'] ?? 0),
            (string)($upload['source_type'] ?? self::SOURCE_TYPE),
            $uploadId
        );

        if ($mappingRow === null) {
            return [
                'has_mapping' => false,
                'extra_headers' => [],
            ];
        }

        $currentHeaders = $this->decodeJsonArray((string)($upload['source_headers_json'] ?? ''));
        $mappedHeaders = $this->decodeJsonArray((string)($mappingRow['original_headers_json'] ?? ''));

        if ($currentHeaders === [] || $mappedHeaders === []) {
            return [
                'has_mapping' => false,
                'extra_headers' => [],
            ];
        }

        $extraHeaders = $this->findAdditionalSourceHeaders($currentHeaders, $mappedHeaders);

        return [
            'has_mapping' => $extraHeaders === [],
            'extra_headers' => $extraHeaders,
        ];
    }

    public static function fieldDefinitions(): array {
        return self::FIELD_DEFINITIONS;
    }

    public static function autoMapHeaders(array $sourceHeaders): array {
        $mapping = [];

        foreach (array_keys(self::FIELD_DEFINITIONS) as $fieldName) {
            $mapping[$fieldName] = null;
        }

        foreach ($sourceHeaders as $index => $header) {
            $normalisedHeader = self::normaliseHeaderName((string)$header);

            foreach (self::HEADER_ALIASES as $fieldName => $aliases) {
                if ($mapping[$fieldName] !== null) {
                    continue;
                }

                foreach ($aliases as $alias) {
                    if ($normalisedHeader === self::normaliseHeaderName($alias)) {
                        $mapping[$fieldName] = [
                            'header' => (string)$header,
                            'index' => (int)$index,
                        ];
                        break 2;
                    }
                }
            }
        }

        return $mapping;
    }

    public static function normaliseHeaderName(string $header): string {
        $header = trim($header);
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
        $header = preg_replace('/\s+/', ' ', $header) ?? $header;

        return strtolower($header);
    }

    public static function parseDateTimeValue(?string $value): ?DateTimeImmutable {
        return HelperFramework::parseDateTimeValue($value);
    }

    public static function normaliseMoneyValue($value): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim((string)$value);

        if ($value === '') {
            return null;
        }

        $negative = false;

        if ($value[0] === '(' && substr($value, -1) === ')') {
            $negative = true;
            $value = substr($value, 1, -1);
        }

        $value = str_replace([',', '£', ' '], '', $value);

        if ($value === '' || !preg_match('/^[+-]?\d+(?:\.\d{1,2})?$/', $value)) {
            return null;
        }

        if ($negative && $value[0] !== '-') {
            $value = '-' . ltrim($value, '+');
        }

        return number_format((float)$value, 2, '.', '');
    }

    public static function normaliseText(?string $value): ?string {
        $value = trim((string)$value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return $value !== '' ? $value : null;
    }

    public static function normaliseCurrency(?string $value, string $defaultCurrency, array &$notes = []): ?string {
        $value = strtoupper(trim((string)$value));
        $value = preg_replace('/[^A-Z0-9]/', '', $value) ?? $value;

        if ($value !== '') {
            return substr($value, 0, 10);
        }

        $defaultCurrency = strtoupper(trim($defaultCurrency));

        if ($defaultCurrency !== '') {
            return substr($defaultCurrency, 0, 10);
        }

        return null;
    }

    public static function buildRowHash(
        int $companyId,
        string $chosenTxnDate,
        string $normalisedDescription,
        string $normalisedAmount,
        ?string $normalisedBalance,
        ?string $normalisedCurrency,
        ?string $sourceAccount
    ): string {
        /*
         * The chosen ledger date is the stable date anchor for dedupe.
         * Raw Created/Processed values are deliberately excluded so the same
         * economic transaction still dedupes if ANNA changes timestamp
         * formatting or only one of those source timestamps is present.
         * The running balance is included so repeated same-day card payments
         * with the same amount/description stay distinct when the statement
         * itself shows them as separate rows.
         */
        $parts = [
            (string)$companyId,
            trim($chosenTxnDate),
            self::hashText($normalisedDescription),
            trim($normalisedAmount),
            self::hashText($normalisedBalance),
            self::hashText($normalisedCurrency),
            self::hashText($sourceAccount),
        ];

        return hash('sha256', implode('|', $parts));
    }

    public function stageUploadRows(int $companyId, int $uploadId, string $defaultCurrency = 'GBP'): array {
        $warnings = [];
        $upload = $this->fetchUpload($companyId, $uploadId);

        if ($upload === null) {
            return $this->failureResult(404, ['The selected staged upload could not be found.'], $warnings);
        }

        if ((int)($upload['rows_committed'] ?? 0) > 0) {
            return $this->failureResult(409, ['This upload has already been committed and cannot be restaged.'], $warnings);
        }

        $mapping = $this->fetchUploadMapping($uploadId);

        if ($mapping === null) {
            return $this->failureResult(422, ['Save the field mapping before previewing rows.'], $warnings);
        }

        $parsedMapping = $this->decodeJsonObject((string)$mapping['mapping_json']);

        if ($parsedMapping === []) {
            return $this->failureResult(422, ['The saved field mapping could not be read.'], $warnings);
        }

        $mappingErrors = $this->validateRequiredFieldMapping($parsedMapping);

        if ($mappingErrors !== []) {
            return $this->failureResult(422, $mappingErrors, $warnings);
        }

        $storedPath = $this->storedFilePathForUpload($upload);

        if (!is_file($storedPath)) {
            return $this->failureResult(500, ['The uploaded CSV file could not be found on disk for staging.'], $warnings);
        }

        $companyTaxYears = $this->fetchCompanyAccountingPeriods((int)$upload['company_id']);
        $sourceHeaders = $this->decodeJsonArray((string)($upload['source_headers_json'] ?? ''));
        $preparedRows = $this->parseCsvIntoStageRows(
            $storedPath,
            $sourceHeaders,
            $parsedMapping,
            $companyId,
            '',
            '',
            $defaultCurrency,
            $warnings,
            (string)($upload['account_name'] ?? ''),
            $companyTaxYears
        );

        $rowHashes = [];

        foreach ($preparedRows as $row) {
            if (!empty($row['row_hash'])) {
                $rowHashes[] = (string)$row['row_hash'];
            }
        }

        $existingHashes = $this->fetchExistingTransactionHashes($companyId, $rowHashes);
        $seenUploadHashes = [];
        $summary = [
            'rows_parsed' => count($preparedRows),
            'rows_valid' => 0,
            'rows_invalid' => 0,
            'rows_duplicate_within_upload' => 0,
            'rows_duplicate_existing' => 0,
            'rows_ready_to_import' => 0,
            'rows_duplicate' => 0,
            'date_range_start' => null,
            'date_range_end' => null,
            'resolved_tax_year_ids' => [],
        ];

        foreach ($preparedRows as &$row) {
            if ($row['validation_status'] === 'valid' && !empty($row['row_hash'])) {
                $hash = (string)$row['row_hash'];

                if (isset($seenUploadHashes[$hash])) {
                    $row['is_duplicate_within_upload'] = 1;
                    $row['validation_notes'] = $this->appendValidationNote(
                        (string)$row['validation_notes'],
                        'Duplicate of an earlier row in the same upload.'
                    );
                } else {
                    $seenUploadHashes[$hash] = true;
                }

                if (isset($existingHashes[$hash])) {
                    $row['is_duplicate_existing'] = 1;
                    $row['validation_notes'] = $this->appendValidationNote(
                        (string)$row['validation_notes'],
                        'Matches a transaction already committed for this company.'
                    );
                }
            }

            if ($row['validation_status'] === 'valid') {
                $summary['rows_valid']++;

                if ((int)$row['is_duplicate_within_upload'] === 1) {
                    $summary['rows_duplicate_within_upload']++;
                }

                if ((int)$row['is_duplicate_existing'] === 1) {
                    $summary['rows_duplicate_existing']++;
                }

                if ((int)$row['is_duplicate_within_upload'] === 0 && (int)$row['is_duplicate_existing'] === 0) {
                    $summary['rows_ready_to_import']++;
                }

                if (!empty($row['chosen_txn_date'])) {
                    $summary['date_range_start'] = $summary['date_range_start'] === null || $row['chosen_txn_date'] < $summary['date_range_start']
                        ? $row['chosen_txn_date']
                        : $summary['date_range_start'];
                    $summary['date_range_end'] = $summary['date_range_end'] === null || $row['chosen_txn_date'] > $summary['date_range_end']
                        ? $row['chosen_txn_date']
                        : $summary['date_range_end'];
                }

                if ((int)($row['tax_year_id'] ?? 0) > 0) {
                    $summary['resolved_tax_year_ids'][(int)$row['tax_year_id']] = true;
                }
            } else {
                $summary['rows_invalid']++;
            }
        }
        unset($row);

        $summary['rows_duplicate'] = count(array_filter(
            $preparedRows,
            static fn(array $row): bool => (int)$row['is_duplicate_within_upload'] === 1 || (int)$row['is_duplicate_existing'] === 1
        ));

        if ($summary['rows_parsed'] === 0) {
            $warnings[] = 'This CSV contains headers but no transaction rows, so there is nothing to preview or import.';
        }

        $statementMonth = $summary['date_range_start'] !== null
            ? substr((string)$summary['date_range_start'], 0, 7) . '-01'
            : (string)$upload['statement_month'];

        try {
            InterfaceDB::beginTransaction();

            $deleteRows = InterfaceDB::prepare('DELETE FROM statement_import_rows WHERE upload_id = :upload_id');
            $deleteRows->execute(['upload_id' => $uploadId]);

            $insertRow = InterfaceDB::prepare(
                'INSERT INTO statement_import_rows (
                    upload_id,
                    `row_number`,
                    raw_json,
                    source_account,
                    source_created,
                    source_processed,
                    source_description,
                    source_amount,
                    source_balance,
                    source_currency,
                    source_category,
                    source_document_url,
                    tax_year_id,
                    chosen_txn_date,
                    chosen_date_source,
                    normalised_description,
                    normalised_amount,
                    normalised_balance,
                    normalised_currency,
                    row_hash,
                    validation_status,
                    validation_notes,
                    is_duplicate_within_upload,
                    is_duplicate_existing
                ) VALUES (
                    :upload_id,
                    :row_number,
                    :raw_json,
                    :source_account,
                    :source_created,
                    :source_processed,
                    :source_description,
                    :source_amount,
                    :source_balance,
                    :source_currency,
                    :source_category,
                    :source_document_url,
                    :tax_year_id,
                    :chosen_txn_date,
                    :chosen_date_source,
                    :normalised_description,
                    :normalised_amount,
                    :normalised_balance,
                    :normalised_currency,
                    :row_hash,
                    :validation_status,
                    :validation_notes,
                    :is_duplicate_within_upload,
                    :is_duplicate_existing
                )'
            );

            foreach ($preparedRows as $row) {
                $insertRow->execute([
                    'upload_id' => $uploadId,
                    'row_number' => $row['row_number'],
                    'raw_json' => $row['raw_json'],
                    'source_account' => $row['source_account'],
                    'source_created' => $row['source_created'],
                    'source_processed' => $row['source_processed'],
                    'source_description' => $row['source_description'],
                    'source_amount' => $row['source_amount'],
                    'source_balance' => $row['source_balance'],
                    'source_currency' => $row['source_currency'],
                    'source_category' => $row['source_category'],
                    'source_document_url' => $row['source_document_url'],
                    'tax_year_id' => $row['tax_year_id'],
                    'chosen_txn_date' => $row['chosen_txn_date'],
                    'chosen_date_source' => $row['chosen_date_source'],
                    'normalised_description' => $row['normalised_description'],
                    'normalised_amount' => $row['normalised_amount'],
                    'normalised_balance' => $row['normalised_balance'],
                    'normalised_currency' => $row['normalised_currency'],
                    'row_hash' => $row['row_hash'],
                    'validation_status' => $row['validation_status'],
                    'validation_notes' => $row['validation_notes'],
                    'is_duplicate_within_upload' => $row['is_duplicate_within_upload'],
                    'is_duplicate_existing' => $row['is_duplicate_existing'],
                ]);
            }

            $update = InterfaceDB::prepare(
                'UPDATE statement_uploads
                 SET workflow_status = :workflow_status,
                     tax_year_id = :tax_year_id,
                     statement_month = :statement_month,
                     date_range_start = :date_range_start,
                     date_range_end = :date_range_end,
                     rows_parsed = :rows_parsed,
                     rows_duplicate = :rows_duplicate,
                     rows_valid = :rows_valid,
                     rows_invalid = :rows_invalid,
                     rows_duplicate_within_upload = :rows_duplicate_within_upload,
                     rows_duplicate_existing = :rows_duplicate_existing,
                     rows_ready_to_import = :rows_ready_to_import,
                     last_staged_at = :last_staged_at,
                     upload_notes = :upload_notes
                 WHERE id = :id'
            );
            $update->execute([
                'workflow_status' => self::WORKFLOW_STAGED,
                'tax_year_id' => count($summary['resolved_tax_year_ids']) === 1 ? (int)array_key_first($summary['resolved_tax_year_ids']) : null,
                'statement_month' => $statementMonth,
                'date_range_start' => $summary['date_range_start'],
                'date_range_end' => $summary['date_range_end'],
                'rows_parsed' => $summary['rows_parsed'],
                'rows_duplicate' => $summary['rows_duplicate'],
                'rows_valid' => $summary['rows_valid'],
                'rows_invalid' => $summary['rows_invalid'],
                'rows_duplicate_within_upload' => $summary['rows_duplicate_within_upload'],
                'rows_duplicate_existing' => $summary['rows_duplicate_existing'],
                'rows_ready_to_import' => $summary['rows_ready_to_import'],
                'last_staged_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                'upload_notes' => $warnings !== [] ? implode(PHP_EOL, $warnings) : null,
                'id' => $uploadId,
            ]);

            InterfaceDB::commit();
        } catch (Throwable $exception) {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }

            return $this->failureResult(500, ['The import preview could not be staged: ' . $exception->getMessage()], $warnings);
        }

        return [
            'http_status' => 200,
            'success' => true,
            'already_uploaded' => false,
            'statement_upload_id' => $uploadId,
            'rows_parsed' => $summary['rows_parsed'],
            'rows_inserted' => (int)($upload['rows_inserted'] ?? 0),
            'rows_duplicate' => $summary['rows_duplicate'],
            'warnings' => $warnings,
            'errors' => [],
            'summary' => $summary,
        ];
    }

    public function commitUpload(int $companyId, int $uploadId): array {
        $warnings = [];
        $upload = $this->fetchUpload($companyId, $uploadId);

        if ($upload === null) {
            return $this->failureResult(404, ['The selected staged upload could not be found.'], $warnings);
        }

        $eligibleRows = $this->fetchEligibleRowsForCommit($uploadId);
        $mapping = $this->fetchUploadMapping($uploadId);
        $parsedMapping = is_array($mapping) ? $this->decodeJsonObject((string)($mapping['mapping_json'] ?? '')) : [];
        $insertedTransactionIds = [];
        $rowsInserted = 0;

        try {
            InterfaceDB::beginTransaction();

            $insertTransaction = InterfaceDB::prepare(
                'INSERT IGNORE INTO transactions (
                    company_id,
                    tax_year_id,
                    statement_upload_id,
                    account_id,
                    txn_date,
                    txn_type,
                    description,
                    reference,
                    amount,
                    currency,
                    balance,
                    counterparty_name,
                    card,
                    dedupe_hash,
                    source_type,
                    source_account_label,
                    source_created_at,
                    source_processed_at,
                    source_category,
                    source_document_url,
                    document_url_hash,
                    document_download_status,
                    nominal_account_id,
                    transfer_account_id,
                    is_internal_transfer,
                    category_status,
                    notes
                ) VALUES (
                    :company_id,
                    :tax_year_id,
                    :statement_upload_id,
                    :account_id,
                    :txn_date,
                    :txn_type,
                    :description,
                    :reference,
                    :amount,
                    :currency,
                    :balance,
                    :counterparty_name,
                    :card,
                    :dedupe_hash,
                    :source_type,
                    :source_account_label,
                    :source_created_at,
                    :source_processed_at,
                    :source_category,
                    :source_document_url,
                    :document_url_hash,
                    :document_download_status,
                    :nominal_account_id,
                    :transfer_account_id,
                    :is_internal_transfer,
                    :category_status,
                    :notes
                )'
            );
            $updateStagedRow = InterfaceDB::prepare(
                'UPDATE statement_import_rows
                 SET committed_transaction_id = :committed_transaction_id,
                     committed_at = :committed_at
                 WHERE id = :id'
            );

            foreach ($eligibleRows as $row) {
                $sourceCreatedAt = self::parseDateTimeValue((string)($row['source_created'] ?? ''));
                $sourceProcessedAt = self::parseDateTimeValue((string)($row['source_processed'] ?? ''));
                $sourceDocumentUrl = trim((string)($row['source_document_url'] ?? ''));
                $importedTxnType = $this->extractMappedFieldFromRawJson(
                    (string)($row['raw_json'] ?? ''),
                    is_array($parsedMapping['type'] ?? null) ? $parsedMapping['type'] : null,
                    'type'
                );
                $isInternalTransfer = $this->matchesTransferMarker(
                    $importedTxnType,
                    isset($upload['internal_transfer_marker']) ? (string)$upload['internal_transfer_marker'] : null
                );

                $insertTransaction->execute([
                    'company_id' => (int)$upload['company_id'],
                    'tax_year_id' => (int)$row['tax_year_id'],
                    'statement_upload_id' => $uploadId,
                    'account_id' => (int)($upload['account_id'] ?? 0) > 0 ? (int)$upload['account_id'] : null,
                    'txn_date' => (string)$row['chosen_txn_date'],
                    'txn_type' => $importedTxnType,
                    'description' => (string)$row['normalised_description'],
                    'reference' => null,
                    'amount' => (string)$row['normalised_amount'],
                    'currency' => $row['normalised_currency'] !== null ? (string)$row['normalised_currency'] : null,
                    'balance' => $row['normalised_balance'] !== null ? (string)$row['normalised_balance'] : null,
                    'counterparty_name' => null,
                    'card' => null,
                    'dedupe_hash' => (string)$row['row_hash'],
                    'source_type' => self::SOURCE_TYPE,
                    'source_account_label' => $row['source_account'] !== null ? (string)$row['source_account'] : (($upload['account_name'] ?? null) !== null ? (string)$upload['account_name'] : null),
                    'source_created_at' => $sourceCreatedAt instanceof DateTimeImmutable ? $sourceCreatedAt->format('Y-m-d H:i:s') : null,
                    'source_processed_at' => $sourceProcessedAt instanceof DateTimeImmutable ? $sourceProcessedAt->format('Y-m-d H:i:s') : null,
                    'source_category' => $row['source_category'] !== null ? (string)$row['source_category'] : null,
                    'source_document_url' => $sourceDocumentUrl !== '' ? $sourceDocumentUrl : null,
                    'document_url_hash' => $sourceDocumentUrl !== '' ? hash('sha256', $sourceDocumentUrl) : null,
                    'document_download_status' => $sourceDocumentUrl !== '' ? 'pending' : 'skipped',
                    'nominal_account_id' => null,
                    'transfer_account_id' => null,
                    'is_internal_transfer' => $isInternalTransfer ? 1 : 0,
                    'category_status' => 'uncategorised',
                    'notes' => $row['validation_notes'] !== null && trim((string)$row['validation_notes']) !== '' ? (string)$row['validation_notes'] : null,
                ]);

                if ($insertTransaction->rowCount() !== 1) {
                    continue;
                }

                $transactionId = $this->findTransactionIdByDedupeHash((int)$upload['company_id'], (string)$row['row_hash']);

                if ($transactionId === null) {
                    throw new RuntimeException('A committed transaction could not be reloaded after insert.');
                }

                if ($this->categorisationService !== null) {
                    if (!$isInternalTransfer) {
                        $this->categorisationService->applyAutoCategoryToTransaction($transactionId);
                    }
                }

                $updateStagedRow->execute([
                    'committed_transaction_id' => $transactionId,
                    'committed_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                    'id' => (int)$row['id'],
                ]);

                $insertedTransactionIds[] = $transactionId;
                $rowsInserted++;
            }

            $committedCount = $this->countCommittedRows($uploadId);
            $updateUpload = InterfaceDB::prepare(
                'UPDATE statement_uploads
                 SET workflow_status = :workflow_status,
                     rows_inserted = :rows_inserted,
                     rows_committed = :rows_committed,
                     rows_ready_to_import = :rows_ready_to_import,
                     committed_at = :committed_at
                 WHERE id = :id'
            );
            $updateUpload->execute([
                'workflow_status' => self::WORKFLOW_COMMITTED,
                'rows_inserted' => $committedCount,
                'rows_committed' => $committedCount,
                'rows_ready_to_import' => 0,
                'committed_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                'id' => $uploadId,
            ]);

            InterfaceDB::commit();
        } catch (Throwable $exception) {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }

            return $this->failureResult(500, ['The staged import could not be committed: ' . $exception->getMessage()], $warnings);
        }

        $documentSummary = [
            'pending' => 0,
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        if ($this->receiptDownloadService !== null) {
            foreach ($insertedTransactionIds as $transactionId) {
                $downloadResult = $this->receiptDownloadService->downloadReceiptForTransaction($transactionId);
                $status = (string)($downloadResult['status'] ?? 'failed');

                if (!isset($documentSummary[$status])) {
                    $status = 'failed';
                }

                $documentSummary[$status]++;

                if (!empty($downloadResult['message']) && $status === 'failed') {
                    $warnings[] = 'Receipt download for transaction #' . $transactionId . ' failed: ' . $downloadResult['message'];
                }
            }
        }

        $this->updateUploadWorkflow($uploadId, self::WORKFLOW_COMPLETED);

        return [
            'http_status' => 200,
            'success' => true,
            'already_uploaded' => false,
            'statement_upload_id' => $uploadId,
            'rows_parsed' => (int)($upload['rows_parsed'] ?? 0),
            'rows_inserted' => $rowsInserted,
            'rows_duplicate' => (int)($upload['rows_duplicate'] ?? 0),
            'warnings' => $warnings,
            'errors' => [],
            'document_summary' => $documentSummary,
        ];
    }

    public function uploadNeedsPreviewRefresh(int $companyId, int $uploadId): bool {
        $upload = $this->fetchUpload($companyId, $uploadId);

        if ($upload === null) {
            return false;
        }

        if ((int)($upload['rows_committed'] ?? 0) > 0) {
            return false;
        }

        $workflowStatus = trim((string)($upload['workflow_status'] ?? ''));

        if (!in_array($workflowStatus, [
            self::WORKFLOW_UPLOADED,
            self::WORKFLOW_MAPPED,
            self::WORKFLOW_STAGED,
        ], true)) {
            return false;
        }

        if ((int)($upload['rows_parsed'] ?? 0) === 0) {
            return true;
        }

        $lastStagedAt = trim((string)($upload['last_staged_at'] ?? ''));

        if ($lastStagedAt === '') {
            return true;
        }

        return InterfaceDB::fetchColumn(
            'SELECT 1
             FROM transactions
             WHERE company_id = :company_id
               AND statement_upload_id <> :upload_id
               AND created_at > :last_staged_at
             LIMIT 1',
            [
                'company_id' => $companyId,
                'upload_id' => $uploadId,
                'last_staged_at' => $lastStagedAt,
            ]
        ) !== false;
    }

    public function retryReceiptDownload(int $companyId, int $transactionId): array {
        if ($this->receiptDownloadService === null) {
            return [
                'success' => false,
                'status' => 'failed',
                'message' => 'Receipt downloads are not configured.',
            ];
        }

        $stmt = InterfaceDB::prepare(
            'SELECT id
             FROM transactions
             WHERE id = :id
               AND company_id = :company_id
             LIMIT 1'
        );
        $stmt->execute([
            'id' => $transactionId,
            'company_id' => $companyId,
        ]);

        if ($stmt->fetchColumn() === false) {
            return [
                'success' => false,
                'status' => 'failed',
                'message' => 'The selected transaction could not be found for this company.',
            ];
        }

        return $this->receiptDownloadService->downloadReceiptForTransaction($transactionId);
    }

    public function recalculateCompanyChecksums(int $companyId): array {
        $warnings = [];
        if ($companyId <= 0) {
            return $this->failureResult(400, ['The selected company could not be found.'], $warnings);
        }

        $rows = InterfaceDB::fetchAll( 'SELECT id,
                    upload_id,
                    source_account,
                    chosen_txn_date,
                    normalised_description,
                    normalised_amount,
                    normalised_balance,
                    normalised_currency,
                    committed_transaction_id
             FROM statement_import_rows
             WHERE upload_id IN (
                 SELECT id
                 FROM statement_uploads
                 WHERE company_id = :company_id
             )
             ORDER BY upload_id ASC, `row_number` ASC, id ASC', ['company_id' => $companyId]);

        if (!is_array($rows) || $rows === []) {
            return [
                'http_status' => 200,
                'success' => true,
                'already_uploaded' => false,
                'rows_updated' => 0,
                'transactions_updated' => 0,
                'warnings' => [],
                'errors' => [],
            ];
        }

        $updateRow = InterfaceDB::prepare(
            'UPDATE statement_import_rows
             SET row_hash = :row_hash
             WHERE id = :id'
        );
        $updateTransaction = InterfaceDB::prepare(
            'UPDATE transactions
             SET dedupe_hash = :dedupe_hash
             WHERE id = :id
               AND company_id = :company_id'
        );

        $rowsUpdated = 0;
        $transactionsUpdated = 0;

        try {
            InterfaceDB::beginTransaction();

            foreach ($rows as $row) {
                $chosenTxnDate = trim((string)($row['chosen_txn_date'] ?? ''));
                $normalisedDescription = self::normaliseText((string)($row['normalised_description'] ?? ''));
                $normalisedAmount = self::normaliseMoneyValue((string)($row['normalised_amount'] ?? ''));
                $normalisedBalance = self::normaliseMoneyValue((string)($row['normalised_balance'] ?? ''));
                $normalisedCurrency = self::normaliseCurrency((string)($row['normalised_currency'] ?? ''), '');
                $sourceAccount = self::normaliseText((string)($row['source_account'] ?? ''));

                if (
                    $chosenTxnDate === ''
                    || $normalisedDescription === null
                    || $normalisedAmount === null
                    || (float)$normalisedAmount === 0.0
                    || $normalisedCurrency === null
                ) {
                    continue;
                }

                $rowHash = self::buildRowHash(
                    $companyId,
                    $chosenTxnDate,
                    $normalisedDescription,
                    $normalisedAmount,
                    $normalisedBalance,
                    $normalisedCurrency,
                    $sourceAccount
                );

                $updateRow->execute([
                    'row_hash' => $rowHash,
                    'id' => (int)$row['id'],
                ]);
                $rowsUpdated++;

                if ((int)($row['committed_transaction_id'] ?? 0) > 0) {
                    $updateTransaction->execute([
                        'dedupe_hash' => $rowHash,
                        'id' => (int)$row['committed_transaction_id'],
                        'company_id' => $companyId,
                    ]);
                    $transactionsUpdated += $updateTransaction->rowCount();
                }
            }

            InterfaceDB::commit();
        } catch (Throwable $exception) {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }

            return $this->failureResult(500, ['Checksums could not be recalculated: ' . $exception->getMessage()], $warnings);
        }

        return [
            'http_status' => 200,
            'success' => true,
            'already_uploaded' => false,
            'rows_updated' => $rowsUpdated,
            'transactions_updated' => $transactionsUpdated,
            'warnings' => $warnings,
            'errors' => [],
        ];
    }

    private function parseCsvIntoStageRows(
        string $filename,
        array $sourceHeaders,
        array $mapping,
        int $companyId,
        string $taxYearStart,
        string $taxYearEnd,
        string $defaultCurrency,
        array &$warnings,
        ?string $fallbackSourceAccount = null,
        ?array $companyTaxYears = null
    ): array {
        $rows = [];
        $handle = fopen($filename, 'rb');

        if ($handle === false) {
            throw new RuntimeException('The uploaded CSV could not be opened for staging.');
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '\\');

            if (!is_array($header)) {
                throw new RuntimeException('The uploaded CSV is empty.');
            }

            $rowNumber = 1;

            while (($rawRow = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $rowNumber++;

                if ($this->isBlankCsvRow($rawRow)) {
                    continue;
                }

                $rows[] = $this->prepareStageRow(
                    $rowNumber,
                    $rawRow,
                    $sourceHeaders,
                    $mapping,
                    $companyId,
                    $taxYearStart,
                    $taxYearEnd,
                    $defaultCurrency,
                    $warnings,
                    $fallbackSourceAccount,
                    $companyTaxYears
                );
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    private function prepareStageRow(
        int $rowNumber,
        array $rawRow,
        array $sourceHeaders,
        array $mapping,
        int $companyId,
        string $taxYearStart,
        string $taxYearEnd,
        string $defaultCurrency,
        array &$warnings,
        ?string $fallbackSourceAccount = null,
        ?array $companyTaxYears = null
    ): array {
        $notes = [];
        $blockingNotes = [];
        $rawPayload = [
            'headers' => array_values($sourceHeaders),
            'values' => array_values($rawRow),
            'column_count' => count($rawRow),
        ];

        if (count($rawRow) !== count($sourceHeaders)) {
            $message = sprintf(
                'This row has %d columns but the header has %d columns.',
                count($rawRow),
                count($sourceHeaders)
            );
            $notes[] = $message;
            $blockingNotes[] = $message;
        }

        $sourceAccount = $this->extractMappedValue($rawRow, $mapping['account'] ?? null);
        if (($sourceAccount === null || trim((string)$sourceAccount) === '') && trim((string)$fallbackSourceAccount) !== '') {
            $sourceAccount = trim((string)$fallbackSourceAccount);
        }
        $sourceCreated = $this->extractMappedValue($rawRow, $mapping['created'] ?? null);
        $sourceProcessed = $this->extractMappedValue($rawRow, $mapping['processed'] ?? null);
        $sourceDescription = $this->extractMappedValue($rawRow, $mapping['description'] ?? null);
        $sourceAmount = $this->extractMappedValue($rawRow, $mapping['amount'] ?? null);
        $sourceBalance = $this->extractMappedValue($rawRow, $mapping['balance'] ?? null);
        $sourceCurrency = $this->extractMappedValue($rawRow, $mapping['currency'] ?? null);
        $sourceCategory = $this->extractMappedValue($rawRow, $mapping['category'] ?? null);
        $sourceDocumentUrl = $this->extractMappedValue($rawRow, $mapping['document'] ?? null);

        $normalisedDescription = self::normaliseText($sourceDescription);

        if ($normalisedDescription === null) {
            $message = 'Description is missing.';
            $notes[] = $message;
            $blockingNotes[] = $message;
        }

        $normalisedAmount = self::normaliseMoneyValue($sourceAmount);

        if ($normalisedAmount === null) {
            $message = 'Amount could not be parsed as a signed decimal.';
            $notes[] = $message;
            $blockingNotes[] = $message;
        } elseif ((float)$normalisedAmount === 0.0) {
            $message = 'Zero-value amounts are shown in preview but cannot be committed.';
            $notes[] = $message;
            $blockingNotes[] = $message;
        }

        $normalisedBalance = self::normaliseMoneyValue($sourceBalance);

        if (self::normaliseText($sourceBalance) !== null && $normalisedBalance === null) {
            $notes[] = 'Balance could not be parsed as a signed decimal.';
        }

        $currencyNotes = [];
        $normalisedCurrency = self::normaliseCurrency($sourceCurrency, $defaultCurrency, $currencyNotes);
        $notes = array_merge($notes, $currencyNotes);

        if ($normalisedCurrency === null) {
            $message = 'Currency is missing and no company default currency is available.';
            $notes[] = $message;
            $blockingNotes[] = $message;
        }

        $processedDate = self::parseDateTimeValue($sourceProcessed);
        $createdDate = self::parseDateTimeValue($sourceCreated);
        $chosenTxnDate = null;
        $chosenDateSource = null;

        if ($processedDate instanceof DateTimeImmutable) {
            $chosenTxnDate = $processedDate->format('Y-m-d');
            $chosenDateSource = 'processed';
        } elseif ($createdDate instanceof DateTimeImmutable) {
            $chosenTxnDate = $createdDate->format('Y-m-d');
            $chosenDateSource = 'created';
        } else {
            $message = 'Neither Processed nor Created contains a usable date.';
            $notes[] = $message;
            $blockingNotes[] = $message;
        }

        $resolvedTaxYear = null;

        if ($chosenTxnDate !== null) {
            $usingFallbackPeriodWindow = (!is_array($companyTaxYears) || $companyTaxYears === [])
                && $taxYearStart !== ''
                && $taxYearEnd !== '';
            $availableTaxYears = is_array($companyTaxYears) && $companyTaxYears !== []
                ? $companyTaxYears
                : ($usingFallbackPeriodWindow
                    ? [[
                        'id' => null,
                        'label' => '',
                        'period_start' => $taxYearStart,
                        'period_end' => $taxYearEnd,
                    ]]
                    : []);

            $resolvedTaxYear = $this->resolveAccountingPeriodForDate($chosenTxnDate, $availableTaxYears);

            if ($resolvedTaxYear === null) {
                $message = $usingFallbackPeriodWindow
                    ? 'The chosen transaction date falls outside the selected accounting period.'
                    : 'No accounting period exists for the chosen transaction date. Go to Companies -> Accounting Periods to add one.';
                $notes[] = $message;
                $blockingNotes[] = $message;
            }
        }

        $rowHash = null;

        if (
            $chosenTxnDate !== null
            && $normalisedDescription !== null
            && $normalisedAmount !== null
            && (float)$normalisedAmount !== 0.0
            && $normalisedCurrency !== null
        ) {
            $rowHash = self::buildRowHash(
                $companyId,
                $chosenTxnDate,
                $normalisedDescription,
                $normalisedAmount,
                $normalisedBalance,
                $normalisedCurrency,
                self::normaliseText($sourceAccount)
            );
        }

        if ($sourceDocumentUrl !== null) {
            $sourceDocumentUrl = trim((string)$sourceDocumentUrl);

            if ($sourceDocumentUrl === '') {
                $sourceDocumentUrl = null;
            } elseif (!filter_var($sourceDocumentUrl, FILTER_VALIDATE_URL)) {
                $notes[] = 'Document URL is present but malformed.';
            }
        }

        $validationStatus = $blockingNotes === [] ? 'valid' : 'invalid';

        if ($validationStatus === 'invalid' && count($notes) > 1) {
            $warnings[] = 'Row ' . $rowNumber . ' staged as invalid: ' . implode(' ', $notes);
        }

        return [
            'row_number' => $rowNumber,
            'raw_json' => $this->encodeJson($rawPayload),
            'source_account' => self::normaliseText($sourceAccount),
            'source_created' => self::normaliseText($sourceCreated),
            'source_processed' => self::normaliseText($sourceProcessed),
            'source_description' => $sourceDescription !== null ? trim((string)$sourceDescription) : null,
            'source_amount' => self::normaliseText($sourceAmount),
            'source_balance' => self::normaliseText($sourceBalance),
            'source_currency' => self::normaliseText($sourceCurrency),
            'source_category' => self::normaliseText($sourceCategory),
            'source_document_url' => self::normaliseText($sourceDocumentUrl),
            'tax_year_id' => isset($resolvedTaxYear['id']) ? (int)$resolvedTaxYear['id'] : null,
            'tax_year_label' => isset($resolvedTaxYear['label']) ? self::normaliseText((string)$resolvedTaxYear['label']) : null,
            'chosen_txn_date' => $chosenTxnDate,
            'chosen_date_source' => $chosenDateSource,
            'normalised_description' => $normalisedDescription,
            'normalised_amount' => $normalisedAmount,
            'normalised_balance' => $normalisedBalance,
            'normalised_currency' => $normalisedCurrency,
            'row_hash' => $rowHash,
            'validation_status' => $validationStatus,
            'validation_notes' => $notes !== [] ? implode(' ', $notes) : null,
            'is_duplicate_within_upload' => 0,
            'is_duplicate_existing' => 0,
        ];
    }

    private function extractMappedValue(array $rawRow, $mappingEntry): ?string {
        if (!is_array($mappingEntry)) {
            return null;
        }

        if (array_key_exists('default_value', $mappingEntry)) {
            $value = trim((string)$mappingEntry['default_value']);

            return $value !== '' ? $value : null;
        }

        if (!array_key_exists('index', $mappingEntry)) {
            return null;
        }

        $index = (int)$mappingEntry['index'];

        if (!array_key_exists($index, $rawRow)) {
            return null;
        }

        $value = trim((string)$rawRow[$index]);

        return $value !== '' ? $value : null;
    }

    private function fetchEligibleRowsForCommit(int $uploadId): array {
        return InterfaceDB::fetchAll( 'SELECT id,
                    raw_json,
                    source_account,
                    source_created,
                    source_processed,
                    source_category,
                    source_document_url,
                    tax_year_id,
                    chosen_txn_date,
                    normalised_description,
                    normalised_amount,
                    normalised_balance,
                    normalised_currency,
                    row_hash,
                    validation_notes
             FROM statement_import_rows
             WHERE upload_id = :upload_id
               AND validation_status = :validation_status
               AND tax_year_id IS NOT NULL
               AND is_duplicate_within_upload = 0
               AND is_duplicate_existing = 0
               AND committed_transaction_id IS NULL
             ORDER BY `row_number` ASC, id ASC', [
            'upload_id' => $uploadId,
            'validation_status' => 'valid',
        ]);
    }

    private function countCommittedRows(int $uploadId): int {
        return InterfaceDB::countWhereNotNull('statement_import_rows', 'committed_transaction_id', [
            'upload_id' => $uploadId,
        ]);
    }

    public function backfillTransactionTypesFromStagedImportJson(int $companyId): array {
        if ($companyId <= 0) {
            return [
                'success' => false,
                'errors' => ['Select a company before running the transaction type backfill.'],
            ];
        }

        $stmt = InterfaceDB::prepare(
            'SELECT t.id,
                    t.txn_type,
                    t.account_id,
                    COALESCE(ca.internal_transfer_marker, \'\') AS internal_transfer_marker,
                    sir.raw_json,
                    COALESCE(sim.mapping_json, \'\') AS mapping_json
             FROM transactions t
             INNER JOIN statement_import_rows sir ON sir.committed_transaction_id = t.id
             LEFT JOIN company_accounts ca ON ca.id = t.account_id
             LEFT JOIN statement_import_mappings sim ON sim.upload_id = t.statement_upload_id
             WHERE t.company_id = :company_id
               AND (t.txn_type IS NULL OR TRIM(t.txn_type) = \'\')
             ORDER BY t.id ASC'
        );
        $stmt->execute(['company_id' => $companyId]);
        $rows = $stmt->fetchAll();

        $summary = [
            'success' => true,
            'rows_scanned' => count($rows),
            'rows_updated' => 0,
            'rows_skipped' => 0,
            'rows_failed' => 0,
            'errors' => [],
        ];

        $update = InterfaceDB::prepare(
            'UPDATE transactions
             SET txn_type = :txn_type,
                 is_internal_transfer = :is_internal_transfer
             WHERE id = :id'
        );

        $ownsTransaction = !InterfaceDB::inTransaction();

        if ($ownsTransaction) {
            InterfaceDB::beginTransaction();
        }

        try {
            foreach ($rows as $row) {
                try {
                    $mapping = $this->decodeJsonObject((string)($row['mapping_json'] ?? ''));
                    $txnType = $this->extractMappedFieldFromRawJson(
                        (string)($row['raw_json'] ?? ''),
                        is_array($mapping['type'] ?? null) ? $mapping['type'] : null,
                        'type'
                    );

                    if ($txnType === null) {
                        $summary['rows_skipped']++;
                        continue;
                    }

                    $update->execute([
                        'id' => (int)$row['id'],
                        'txn_type' => $txnType,
                        'is_internal_transfer' => $this->matchesTransferMarker(
                            $txnType,
                            (string)($row['internal_transfer_marker'] ?? '')
                        ) ? 1 : 0,
                    ]);
                    $summary['rows_updated']++;
                } catch (Throwable $exception) {
                    $summary['rows_failed']++;
                    $summary['errors'][] = 'Transaction #' . (int)$row['id'] . ': ' . $exception->getMessage();
                }
            }

            if ($ownsTransaction) {
                InterfaceDB::commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }

            return [
                'success' => false,
                'errors' => ['The transaction type backfill failed: ' . $exception->getMessage()],
            ];
        }

        return $summary;
    }

    private function extractMappedFieldFromRawJson(string $rawJson, ?array $mappingEntry, string $fieldName): ?string {
        if (is_array($mappingEntry) && array_key_exists('default_value', $mappingEntry)) {
            $value = trim((string)$mappingEntry['default_value']);

            return $value !== '' ? $value : null;
        }

        $decoded = $this->decodeJsonObject($rawJson);
        $headers = is_array($decoded['headers'] ?? null)
            ? array_values(array_map('strval', $decoded['headers']))
            : [];
        $values = is_array($decoded['values'] ?? null)
            ? array_values(array_map(static fn($value): string => trim((string)$value), $decoded['values']))
            : [];

        if (is_array($mappingEntry) && array_key_exists('index', $mappingEntry)) {
            $index = (int)$mappingEntry['index'];

            if (array_key_exists($index, $values) && trim($values[$index]) !== '') {
                return trim($values[$index]);
            }
        }

        foreach (self::HEADER_ALIASES[$fieldName] ?? [] as $alias) {
            foreach ($headers as $index => $header) {
                if (self::normaliseHeaderName($header) === self::normaliseHeaderName((string)$alias)) {
                    $value = trim((string)($values[$index] ?? ''));

                    return $value !== '' ? $value : null;
                }
            }
        }

        return null;
    }

    private function matchesTransferMarker(?string $txnType, ?string $marker): bool {
        $txnType = trim((string)$txnType);
        $marker = trim((string)$marker);

        return $txnType !== '' && $marker !== '' && strcasecmp($txnType, $marker) === 0;
    }

    private function fetchExistingTransactionHashes(int $companyId, array $rowHashes): array {
        $rowHashes = array_values(array_unique(array_filter(array_map('strval', $rowHashes))));

        if ($companyId <= 0 || $rowHashes === []) {
            return [];
        }

        $existing = [];

        foreach (array_chunk($rowHashes, 200) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
            $sql = 'SELECT dedupe_hash
                    FROM transactions
                    WHERE company_id = ?
                      AND dedupe_hash IN (' . $placeholders . ')';
            $stmt = InterfaceDB::prepare($sql);
            $stmt->execute(array_merge([$companyId], $chunk));

            foreach ($stmt->fetchAll() as $row) {
                $existing[(string)$row['dedupe_hash']] = true;
            }
        }

        return $existing;
    }

    private function findTransactionIdByDedupeHash(int $companyId, string $dedupeHash): ?int {
        $stmt = InterfaceDB::prepare(
            'SELECT id
             FROM transactions
             WHERE company_id = :company_id
               AND dedupe_hash = :dedupe_hash
             LIMIT 1'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'dedupe_hash' => $dedupeHash,
        ]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int)$id : null;
    }

    private function uploadCanResume(array $upload): bool {
        return in_array((string)($upload['workflow_status'] ?? ''), [
            self::WORKFLOW_UPLOADED,
            self::WORKFLOW_MAPPED,
            self::WORKFLOW_STAGED,
        ], true) && (int)($upload['rows_committed'] ?? 0) === 0;
    }

    private function readSourceHeaders(string $filename, array &$errors): array {
        $handle = fopen($filename, 'rb');

        if ($handle === false) {
            $errors[] = 'The uploaded CSV could not be opened.';
            return [];
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '\\');

            if (!is_array($header) || $header === []) {
                $errors[] = 'The uploaded CSV is empty.';
                return [];
            }

            $sourceHeaders = [];

            foreach ($header as $index => $value) {
                $headerValue = trim((string)$value);

                if ($index === 0) {
                    $headerValue = preg_replace('/^\xEF\xBB\xBF/', '', $headerValue) ?? $headerValue;
                }

                $sourceHeaders[] = $headerValue !== '' ? $headerValue : 'Column ' . ($index + 1);
            }

            return $sourceHeaders;
        } finally {
            fclose($handle);
        }
    }

    private function buildAutoMappingFromHeaders(array $sourceHeaders): array {
        return self::autoMapHeaders($sourceHeaders);
    }

    private function validateRequiredFieldMapping(array $mapping): array {
        $errors = [];

        if (($mapping['description'] ?? null) === null) {
            $errors[] = 'Description must be mapped before previewing rows.';
        }

        if (($mapping['amount'] ?? null) === null) {
            $errors[] = 'Amount must be mapped before previewing rows.';
        }

        if (($mapping['created'] ?? null) === null && ($mapping['processed'] ?? null) === null) {
            $errors[] = 'Map either Transaction Date or Processed Date so the import has a usable transaction date.';
        }

        return $errors;
    }

    private function resolveInitialMapping(int $companyId, int $accountId, array $sourceHeaders): array {
        $savedMapping = $this->fetchLatestAccountMapping($companyId, $accountId, self::SOURCE_TYPE);

        if ($savedMapping === null) {
            return $this->buildAutoMappingFromHeaders($sourceHeaders);
        }

        $resolved = [];

        foreach (array_keys(self::FIELD_DEFINITIONS) as $fieldName) {
            $mappingEntry = $savedMapping[$fieldName] ?? null;

            if (!is_array($mappingEntry)) {
                $resolved[$fieldName] = null;
                continue;
            }

            if (array_key_exists('default_value', $mappingEntry)) {
                $resolved[$fieldName] = [
                    'default_value' => (string)$mappingEntry['default_value'],
                    'label' => (string)($mappingEntry['label'] ?? $mappingEntry['default_value']),
                ];
                continue;
            }

            if (!isset($mappingEntry['header'])) {
                $resolved[$fieldName] = null;
                continue;
            }

            $matchedIndex = $this->findHeaderIndexByOriginalLabel($sourceHeaders, (string)$mappingEntry['header']);

            if ($matchedIndex === null) {
                $resolved[$fieldName] = null;
                continue;
            }

            $resolved[$fieldName] = [
                'header' => $sourceHeaders[$matchedIndex],
                'index' => $matchedIndex,
            ];
        }

        return $resolved;
    }

    private function upsertStatementImportMapping(int $uploadId, array $originalHeaders, array $mapping): void {
        $existing = $this->fetchUploadMapping($uploadId);

        if ($existing === null) {
            $stmt = InterfaceDB::prepare(
                'INSERT INTO statement_import_mappings (
                    upload_id,
                    source_type,
                    original_headers_json,
                    mapping_json
                ) VALUES (
                    :upload_id,
                    :source_type,
                    :original_headers_json,
                    :mapping_json
                )'
            );
            $stmt->execute([
                'upload_id' => $uploadId,
                'source_type' => self::SOURCE_TYPE,
                'original_headers_json' => $this->encodeJson($originalHeaders),
                'mapping_json' => $this->encodeJson($mapping),
            ]);

            return;
        }

        $stmt = InterfaceDB::prepare(
            'UPDATE statement_import_mappings
             SET source_type = :source_type,
                 original_headers_json = :original_headers_json,
                 mapping_json = :mapping_json
             WHERE upload_id = :upload_id'
        );
        $stmt->execute([
            'upload_id' => $uploadId,
            'source_type' => self::SOURCE_TYPE,
            'original_headers_json' => $this->encodeJson($originalHeaders),
            'mapping_json' => $this->encodeJson($mapping),
        ]);
    }

    private function updateUploadWorkflow(int $uploadId, string $workflowStatus): void {
        $stmt = InterfaceDB::prepare(
            'UPDATE statement_uploads
             SET workflow_status = :workflow_status
             WHERE id = :id'
        );
        $stmt->execute([
            'workflow_status' => $workflowStatus,
            'id' => $uploadId,
        ]);
    }

    private function updateUploadAccount(int $uploadId, int $accountId): void {
        $stmt = InterfaceDB::prepare(
            'UPDATE statement_uploads
             SET account_id = :account_id
             WHERE id = :id'
        );
        $stmt->execute([
            'account_id' => $accountId,
            'id' => $uploadId,
        ]);
    }

    private function requirePositiveInteger($value, string $fieldName, array &$errors): ?int {
        $value = is_string($value) ? trim($value) : $value;

        if (!is_scalar($value) || !preg_match('/^[1-9][0-9]*$/', (string)$value)) {
            $errors[] = sprintf('%s must be a positive integer.', $fieldName);
            return null;
        }

        return (int)$value;
    }

    private function fetchCompanyTaxYear(int $companyId, int $taxYearId): ?array {
        $stmt = InterfaceDB::prepare(
            'SELECT ty.id, ty.period_start, ty.period_end
             FROM tax_years ty
             INNER JOIN companies c ON c.id = ty.company_id
             WHERE ty.id = :tax_year_id
               AND ty.company_id = :company_id
             LIMIT 1'
        );
        $stmt->execute([
            'tax_year_id' => $taxYearId,
            'company_id' => $companyId,
        ]);

        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function fetchCompanyAccountingPeriods(int $companyId): array {
        if ($companyId <= 0) {
            return [];
        }

        $stmt = InterfaceDB::prepare(
            'SELECT id, label, period_start, period_end
             FROM tax_years
             WHERE company_id = :company_id
             ORDER BY period_start ASC, period_end ASC, id ASC'
        );
        $stmt->execute(['company_id' => $companyId]);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    private function resolveAccountingPeriodForDate(string $txnDate, array $companyTaxYears): ?array {
        $txnDate = trim($txnDate);

        if ($txnDate === '' || $companyTaxYears === []) {
            return null;
        }

        foreach ($companyTaxYears as $row) {
            $periodStart = trim((string)($row['period_start'] ?? ''));
            $periodEnd = trim((string)($row['period_end'] ?? ''));

            if ($periodStart === '' || $periodEnd === '') {
                continue;
            }

            if ($txnDate >= $periodStart && $txnDate <= $periodEnd) {
                return [
                    'id' => isset($row['id']) && $row['id'] !== null ? (int)$row['id'] : null,
                    'label' => (string)($row['label'] ?? ''),
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                ];
            }
        }

        return null;
    }

    private function fetchCompanyAccount(int $companyId, int $accountId): ?array {
        if ($companyId <= 0 || $accountId <= 0) {
            return null;
        }

        $stmt = InterfaceDB::prepare(
            'SELECT id,
                    company_id,
                    account_name,
                    account_type,
                    institution_name,
                    is_active
             FROM company_accounts
             WHERE company_id = :company_id
               AND id = :id
             LIMIT 1'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'id' => $accountId,
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function fetchLatestAccountMapping(int $companyId, int $accountId, string $sourceType): ?array {
        $mappingRow = $this->fetchLatestAccountMappingRow($companyId, $accountId, $sourceType);

        if ($mappingRow === null) {
            return null;
        }

        $mappingJson = (string)($mappingRow['mapping_json'] ?? '');

        if (trim($mappingJson) === '') {
            return null;
        }

        $decoded = $this->decodeJsonObject($mappingJson);

        return $decoded !== [] ? $decoded : null;
    }

    private function fetchLatestAccountMappingRow(int $companyId, int $accountId, string $sourceType, ?int $excludeUploadId = null): ?array {
        if ($companyId <= 0 || $accountId <= 0) {
            return null;
        }

        $sql = 'SELECT sim.upload_id,
                       sim.mapping_json,
                       sim.original_headers_json
                FROM statement_import_mappings sim
                INNER JOIN statement_uploads su ON su.id = sim.upload_id
                WHERE su.company_id = :company_id
                  AND su.account_id = :account_id
                  AND su.source_type = :source_type';
        $params = [
            'company_id' => $companyId,
            'account_id' => $accountId,
            'source_type' => $sourceType,
        ];

        if ($excludeUploadId !== null && $excludeUploadId > 0) {
            $sql .= ' AND su.id <> :exclude_upload_id';
            $params['exclude_upload_id'] = $excludeUploadId;
        }

        $sql .= ' ORDER BY su.uploaded_at DESC, su.id DESC
                  LIMIT 1';

            $stmt = InterfaceDB::prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function findLatestMappedUploadIdForAccount(int $companyId, int $accountId, string $sourceType, ?int $excludeUploadId = null): ?int {
        $mappingRow = $this->fetchLatestAccountMappingRow($companyId, $accountId, $sourceType, $excludeUploadId);

        if ($mappingRow === null) {
            return null;
        }

        return isset($mappingRow['upload_id']) ? (int)$mappingRow['upload_id'] : null;
    }

    private function sourceHeadersContainAdditionalColumns(array $currentHeaders, array $mappedHeaders): bool {
        return $this->findAdditionalSourceHeaders($currentHeaders, $mappedHeaders) !== [];
    }

    private function findAdditionalSourceHeaders(array $currentHeaders, array $mappedHeaders): array {
        $knownHeaders = [];
        $additionalHeaders = [];

        foreach ($mappedHeaders as $header) {
            $knownHeaders[self::normaliseHeaderName((string)$header)] = true;
        }

        foreach ($currentHeaders as $header) {
            $normalisedHeader = self::normaliseHeaderName((string)$header);

            if ($normalisedHeader === '') {
                continue;
            }

            if (!isset($knownHeaders[$normalisedHeader])) {
                $additionalHeaders[] = (string)$header;
            }
        }

        return array_values(array_unique($additionalHeaders));
    }

    private function uploadWorkflowHasConfirmedMapping(string $workflowStatus): bool {
        return in_array($workflowStatus, [
            self::WORKFLOW_MAPPED,
            self::WORKFLOW_STAGED,
            self::WORKFLOW_COMMITTED,
            self::WORKFLOW_COMPLETED,
        ], true);
    }

    private function validateUploadedFile(array $upload, array &$errors): ?array {
        $errorCode = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($errorCode !== UPLOAD_ERR_OK) {
            $errors[] = $this->uploadErrorMessage($errorCode);
            return null;
        }

        $tmpName = (string)($upload['tmp_name'] ?? '');
        $originalName = (string)($upload['name'] ?? '');

        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            $errors[] = 'The uploaded file was not received as a valid HTTP upload.';
            return null;
        }

        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'csv') {
            $errors[] = 'The uploaded file must use a .csv extension.';
            return null;
        }

        $mimeType = $this->detectMimeType($tmpName);
        $allowedMimeTypes = [
            'text/csv',
            'text/plain',
            'application/csv',
            'application/vnd.ms-excel',
            'application/octet-stream',
        ];

        if ($mimeType !== null && !in_array($mimeType, $allowedMimeTypes, true)) {
            $errors[] = sprintf('Unexpected uploaded file type: %s.', $mimeType);
            return null;
        }

        return [
            'tmp_name' => $tmpName,
            'original_name' => $this->sanitiseOriginalFilename($originalName),
        ];
    }

    private function extractUploadedFiles(array $files): array {
        $uploads = [];
        $rawUpload = $files['statement_files'] ?? $files['statement_file'] ?? $files['csv_file'] ?? null;

        if (!is_array($rawUpload)) {
            return [];
        }

        if (is_array($rawUpload['name'] ?? null)) {
            $names = $rawUpload['name'];
            $types = is_array($rawUpload['type'] ?? null) ? $rawUpload['type'] : [];
            $tmpNames = is_array($rawUpload['tmp_name'] ?? null) ? $rawUpload['tmp_name'] : [];
            $errors = is_array($rawUpload['error'] ?? null) ? $rawUpload['error'] : [];
            $sizes = is_array($rawUpload['size'] ?? null) ? $rawUpload['size'] : [];

            foreach ($names as $index => $name) {
                $uploads[] = [
                    'name' => (string)$name,
                    'type' => (string)($types[$index] ?? ''),
                    'tmp_name' => (string)($tmpNames[$index] ?? ''),
                    'error' => (int)($errors[$index] ?? UPLOAD_ERR_NO_FILE),
                    'size' => (int)($sizes[$index] ?? 0),
                ];
            }

            return $uploads;
        }

        return [$rawUpload];
    }

    private function detectMimeType(string $filename): ?string {
        if (!is_file($filename) || !function_exists('finfo_open')) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        $mimeType = finfo_file($finfo, $filename) ?: null;
        finfo_close($finfo);

        return is_string($mimeType) ? $mimeType : null;
    }

    private function sanitiseOriginalFilename(string $filename): string {
        $filename = trim($filename);
        $filename = preg_replace('/[^A-Za-z0-9._ -]/', '_', $filename) ?? 'statement.csv';
        $filename = trim($filename, '. ');

        return $filename !== '' ? $filename : 'statement.csv';
    }

    private function buildDuplicateFileWarning(array $existingUpload): string {
        $existingUploadId = (int)($existingUpload['id'] ?? 0);
        $rowsParsed = (int)($existingUpload['rows_parsed'] ?? 0);
        $rowsCommitted = (int)($existingUpload['rows_committed'] ?? 0);

        if ($rowsParsed === 0 && $rowsCommitted === 0) {
            return sprintf(
                'This exact file matches earlier upload #%d. That earlier upload also contained no transaction rows, so this usually means the CSV only has headers. A fresh upload record will still be created.',
                $existingUploadId
            );
        }

        return sprintf(
            'This exact file has already been uploaded before as upload #%d. A fresh upload record will be created, and duplicate transactions will still be filtered using row checksums when you preview or commit.',
            $existingUploadId
        );
    }

    private function findUploadByFileHash(int $companyId, string $fileSha256): ?array {
        $stmt = InterfaceDB::prepare(
            'SELECT id,
                    workflow_status,
                    rows_parsed,
                    rows_inserted,
                    rows_duplicate,
                    rows_committed,
                    source_headers_json,
                    account_id
             FROM statement_uploads
             WHERE company_id = :company_id
               AND file_sha256 = :file_sha256
             LIMIT 1'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'file_sha256' => $fileSha256,
        ]);

        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function resolveUploadDirectory(int $companyId): string {
        return HelperFramework::companyUploadSubdirectory($companyId, 'statements', $this->uploadBaseDirectory);
    }

    private function buildStoredFilename(): string {
        $guid = bin2hex(random_bytes(16));

        return sprintf(
            'statement_%s-%s-%s-%s-%s.csv',
            substr($guid, 0, 8),
            substr($guid, 8, 4),
            substr($guid, 12, 4),
            substr($guid, 16, 4),
            substr($guid, 20, 12)
        );
    }

    private function ensureUploadDirectoryExists(string $directory): void {
        if (is_dir($directory)) {
            if (!is_writable($directory)) {
                throw new RuntimeException('The upload directory is not writable: ' . $directory);
            }

            return;
        }

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('The upload directory could not be created: ' . $directory);
        }
    }

    private function insertStatementUpload(array $data): void {
        $stmt = InterfaceDB::prepare(
            'INSERT INTO statement_uploads (
                company_id,
                tax_year_id,
                account_id,
                source_type,
                workflow_status,
                statement_month,
                original_filename,
                stored_filename,
                file_sha256,
                source_headers_json,
                rows_parsed,
                rows_inserted,
                rows_duplicate,
                rows_valid,
                rows_invalid,
                rows_duplicate_within_upload,
                rows_duplicate_existing,
                rows_ready_to_import,
                rows_committed,
                upload_notes
            ) VALUES (
                :company_id,
                :tax_year_id,
                :account_id,
                :source_type,
                :workflow_status,
                :statement_month,
                :original_filename,
                :stored_filename,
                :file_sha256,
                :source_headers_json,
                :rows_parsed,
                :rows_inserted,
                :rows_duplicate,
                :rows_valid,
                :rows_invalid,
                :rows_duplicate_within_upload,
                :rows_duplicate_existing,
                :rows_ready_to_import,
                :rows_committed,
                :upload_notes
            )'
        );
        $stmt->execute($data);
    }

    private function findStatementUploadId(int $companyId, string $storedFilename): ?int {
        $stmt = InterfaceDB::prepare(
            'SELECT id
             FROM statement_uploads
             WHERE company_id = :company_id
               AND stored_filename = :stored_filename
             LIMIT 1'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'stored_filename' => $storedFilename,
        ]);

        $id = $stmt->fetchColumn();

        return $id !== false ? (int)$id : null;
    }

    private function storedFilePathForUpload(array $upload): string {
        $directory = $this->resolveUploadDirectory((int)$upload['company_id']);

        return $directory . DIRECTORY_SEPARATOR . (string)$upload['stored_filename'];
    }

    private function readUploadSourceSample(array $upload, int $limit = 2): array {
        $headers = $this->decodeJsonArray((string)($upload['source_headers_json'] ?? ''));
        $sample = [
            'headers' => $headers,
            'rows' => [],
        ];

        if ($headers === []) {
            return $sample;
        }

        $storedPath = $this->storedFilePathForUpload($upload);

        if (!is_file($storedPath)) {
            return $sample;
        }

        $handle = fopen($storedPath, 'rb');

        if (!is_resource($handle)) {
            return $sample;
        }

        try {
            $headerRow = fgetcsv($handle, 0, ',', '"', '\\');

            if (!is_array($headerRow)) {
                return $sample;
            }

            while (count($sample['rows']) < $limit) {
                $row = fgetcsv($handle, 0, ',', '"', '\\');

                if ($row === false) {
                    break;
                }

                if (!is_array($row) || $this->isBlankCsvRow($row)) {
                    continue;
                }

                $normalisedRow = array_map(
                    static fn(mixed $value): string => trim((string)$value),
                    array_slice(array_pad($row, count($headers), ''), 0, count($headers))
                );

                $sample['rows'][] = $normalisedRow;
            }
        } finally {
            fclose($handle);
        }

        return $sample;
    }

    private function isBlankCsvRow(array $row): bool {
        foreach ($row as $value) {
            if (trim((string)$value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function appendValidationNote(string $existing, string $note): string {
        $existing = trim($existing);
        $note = trim($note);

        if ($existing === '') {
            return $note;
        }

        if ($note === '') {
            return $existing;
        }

        return $existing . ' ' . $note;
    }

    private function findHeaderIndexByOriginalLabel(array $sourceHeaders, string $selectedHeader): ?int {
        foreach ($sourceHeaders as $index => $header) {
            if ((string)$header === $selectedHeader) {
                return (int)$index;
            }
        }

        return null;
    }

    private function decodeJsonArray(string $payload): array {
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function decodeJsonObject(string $payload): array {
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function encodeJson(array $payload): string {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($encoded)) {
            throw new RuntimeException('JSON encoding failed for staged import data.');
        }

        return $encoded;
    }

    private static function hashText(?string $value): string {
        $value = self::normaliseText($value);

        if ($value === null) {
            return '';
        }

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }

    private function failureResult(int $httpStatus, array $errors, array $warnings): array {
        return [
            'http_status' => $httpStatus,
            'success' => false,
            'already_uploaded' => false,
            'rows_parsed' => 0,
            'rows_inserted' => 0,
            'rows_duplicate' => 0,
            'statement_upload_id' => null,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    private function uploadErrorMessage(int $errorCode): string {
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'The uploaded file exceeds the allowed upload size.';
            case UPLOAD_ERR_PARTIAL:
                return 'The uploaded file was only partially received.';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'The server upload temporary directory is missing.';
            case UPLOAD_ERR_CANT_WRITE:
                return 'The uploaded file could not be written to disk.';
            case UPLOAD_ERR_EXTENSION:
                return 'A PHP extension stopped the file upload.';
            default:
                return 'The file upload failed.';
        }
    }
}



