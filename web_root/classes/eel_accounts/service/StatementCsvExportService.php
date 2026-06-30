<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class StatementCsvExportService
{
    private \eel_accounts\Service\FileCheckService $fileCheckService;

    public function __construct(
        string $defaultUploadDirectory = '',
        ?\eel_accounts\Service\FileCheckService $fileCheckService = null
    ) {
        $this->fileCheckService = $fileCheckService ?? new \eel_accounts\Service\FileCheckService($this->uploadsConfig($defaultUploadDirectory));
    }

    public function fetchExportMonths(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [];
        }

        $accountingPeriod = (new \eel_accounts\Repository\AccountingPeriodRepository())->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null) {
            return [];
        }

        $uploads = \InterfaceDB::fetchAll(
            'SELECT su.id,
                    su.original_filename,
                    su.stored_filename,
                    su.statement_month,
                    su.rows_parsed,
                    su.rows_ready_to_import,
                    su.rows_committed,
                    su.workflow_status,
                    COALESCE(ca.account_name, \'\') AS account_name
             FROM statement_uploads su
             LEFT JOIN company_accounts ca
                ON ca.id = su.account_id
               AND ca.company_id = su.company_id
             WHERE su.company_id = :company_id
               AND (
                    su.accounting_period_id = :accounting_period_id
                    OR (
                        su.accounting_period_id IS NULL
                        AND su.statement_month BETWEEN :period_start AND :period_end
                    )
               )
             ORDER BY su.statement_month ASC, su.uploaded_at ASC, su.id ASC',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => (string)($accountingPeriod['period_start'] ?? ''),
                'period_end' => (string)($accountingPeriod['period_end'] ?? ''),
            ]
        );

        $uploadsByMonth = [];
        foreach ($uploads as $upload) {
            foreach ($this->splitUploadByExportMonth($companyId, $upload) as $monthKey => $monthUpload) {
                if (!preg_match('/^[0-9]{4}-[0-9]{2}-01$/', (string)$monthKey)) {
                    continue;
                }

                $uploadsByMonth[(string)$monthKey][] = $monthUpload;
            }
        }

        $months = [];
        $cursor = new \DateTimeImmutable((string)$accountingPeriod['period_start']);
        $end = new \DateTimeImmutable((string)$accountingPeriod['period_end']);
        $cursor = $cursor->modify('first day of this month');
        $end = $end->modify('first day of this month');

        while ($cursor <= $end) {
            $monthKey = $cursor->format('Y-m-01');
            $months[] = [
                'month_key' => $monthKey,
                'label' => \HelperFramework::displayMonthYear($cursor),
                'uploads' => array_values((array)($uploadsByMonth[$monthKey] ?? [])),
            ];
            $cursor = $cursor->modify('+1 month');
        }

        return $months;
    }

    public function buildExport(int $companyId, int $uploadId, string $monthKey = ''): array
    {
        $upload = $this->fetchUpload($companyId, $uploadId);
        if ($upload === null) {
            return [
                'success' => false,
                'errors' => ['The selected upload could not be found.'],
            ];
        }

        $path = $this->statementPath($companyId, (string)$upload['stored_filename']);
        if (!is_file($path) || !is_readable($path)) {
            return [
                'success' => false,
                'errors' => ['The stored CSV file could not be read.'],
            ];
        }

        $sourceHeaders = $this->decodeJsonArray((string)($upload['source_headers_json'] ?? ''));
        $mapping = $this->fetchMapping((int)$upload['id']);
        if ($mapping === []) {
            $mapping = \eel_accounts\Service\StatementUploadService::autoMapHeaders($sourceHeaders);
        }

        $monthKey = $this->normaliseMonthKey($monthKey);
        if ($monthKey === '') {
            return [
                'success' => false,
                'errors' => ['Select a transaction month before exporting the CSV.'],
            ];
        }

        $csv = $this->buildMappedCsv($path, $mapping, $this->fetchCommittedExportRows((int)$upload['id']), $monthKey);
        $upload['export_month'] = $monthKey;

        return [
            'success' => true,
            'errors' => [],
            'filename' => $this->downloadFilename($upload),
            'csv' => $csv,
        ];
    }

    public function buildXlsxExport(int $companyId, int $uploadId, string $monthKey = ''): array
    {
        $upload = $this->fetchUpload($companyId, $uploadId);
        if ($upload === null) {
            return [
                'success' => false,
                'errors' => ['The selected upload could not be found.'],
            ];
        }

        $path = $this->statementPath($companyId, (string)$upload['stored_filename']);
        if (!is_file($path) || !is_readable($path)) {
            return [
                'success' => false,
                'errors' => ['The stored CSV file could not be read.'],
            ];
        }

        $monthKey = $this->normaliseMonthKey($monthKey);
        if ($monthKey === '') {
            return [
                'success' => false,
                'errors' => ['Select a transaction month before exporting the XLSX file.'],
            ];
        }

        $sourceHeaders = $this->decodeJsonArray((string)($upload['source_headers_json'] ?? ''));
        $mapping = $this->fetchMapping((int)$upload['id']);
        if ($mapping === []) {
            $mapping = \eel_accounts\Service\StatementUploadService::autoMapHeaders($sourceHeaders);
        }

        $fields = array_keys(\eel_accounts\Service\StatementUploadService::fieldDefinitions());
        $rows = $this->buildExportRows($path, $mapping, $this->fetchCommittedExportRows((int)$upload['id']), $monthKey);
        $upload['export_month'] = $monthKey;

        return [
            'success' => true,
            'errors' => [],
            'filename' => preg_replace('/\.csv$/i', '.xlsx', $this->downloadFilename($upload)) ?? 'statement-export.xlsx',
            'xlsx' => $this->buildXlsx($companyId, (int)$upload['id'], $fields, $rows, $this->fetchCategoryOptions()),
        ];
    }

    private function fetchUpload(int $companyId, int $uploadId): ?array
    {
        if ($companyId <= 0 || $uploadId <= 0) {
            return null;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT su.*,
                    COALESCE(ca.account_name, \'\') AS account_name
             FROM statement_uploads su
             LEFT JOIN company_accounts ca
                ON ca.id = su.account_id
               AND ca.company_id = su.company_id
             WHERE su.company_id = :company_id
               AND su.id = :id
             LIMIT 1',
            [
                'company_id' => $companyId,
                'id' => $uploadId,
            ]
        );

        return is_array($row) ? $row : null;
    }

    private function fetchMapping(int $uploadId): array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT mapping_json
             FROM statement_import_mappings
             WHERE upload_id = :upload_id
             LIMIT 1',
            ['upload_id' => $uploadId]
        );

        if (!is_array($row)) {
            return [];
        }

        return $this->decodeJsonObject((string)($row['mapping_json'] ?? ''));
    }

    private function fetchCommittedExportRows(int $uploadId): array
    {
        if ($uploadId <= 0) {
            return [];
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT sir.row_number,
                    t.txn_date,
                    t.txn_type,
                    t.description,
                    t.reference,
                    t.amount,
                    t.currency,
                    t.balance,
                    t.counterparty_name,
                    t.card,
                    t.source_account_label,
                    t.source_created_at,
                    t.source_processed_at,
                    t.source_category,
                    t.source_document_url,
                    t.category_status,
                    COALESCE(na.code, \'\') AS nominal_code,
                    COALESCE(na.name, \'\') AS nominal_name
             FROM statement_import_rows sir
             INNER JOIN transactions t
                ON t.id = sir.committed_transaction_id
             LEFT JOIN nominal_accounts na
                ON na.id = t.nominal_account_id
             WHERE sir.upload_id = :upload_id
             ORDER BY sir.row_number ASC',
            ['upload_id' => $uploadId]
        );

        $exportRows = [];
        foreach ($rows as $row) {
            $rowNumber = (int)($row['row_number'] ?? 0);
            if ($rowNumber <= 0) {
                continue;
            }

            $exportRows[$rowNumber] = [
                'account' => (string)($row['source_account_label'] ?? ''),
                'created' => $this->dateValue((string)($row['source_created_at'] ?? ''), (string)($row['txn_date'] ?? '')),
                'processed' => $this->dateValue((string)($row['source_processed_at'] ?? ''), ''),
                'type' => (string)($row['txn_type'] ?? ''),
                'description' => (string)($row['description'] ?? ''),
                'reference' => (string)($row['reference'] ?? ''),
                'counterparty' => (string)($row['counterparty_name'] ?? ''),
                'card' => (string)($row['card'] ?? ''),
                'amount' => $this->decimalValue($row['amount'] ?? null),
                'balance' => $this->decimalValue($row['balance'] ?? null),
                'currency' => (string)($row['currency'] ?? ''),
                'category' => $this->categoryExportValue($row),
                'document' => (string)($row['source_document_url'] ?? ''),
            ];
        }

        return $exportRows;
    }

    private function splitUploadByExportMonth(int $companyId, array $upload): array
    {
        $path = $this->statementPath($companyId, (string)($upload['stored_filename'] ?? ''));

        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $sourceHeaders = $this->decodeJsonArray((string)($upload['source_headers_json'] ?? ''));
        $mapping = $this->fetchMapping((int)($upload['id'] ?? 0));
        if ($mapping === []) {
            $mapping = \eel_accounts\Service\StatementUploadService::autoMapHeaders($sourceHeaders);
        }

        $monthCounts = $this->scanExportMonthCounts($path, $mapping, $this->fetchCommittedExportRows((int)($upload['id'] ?? 0)));
        if ($monthCounts === []) {
            return [];
        }

        $uploads = [];
        foreach ($monthCounts as $monthKey => $rowCount) {
            $uploads[(string)$monthKey] = $this->monthUpload($upload, (string)$monthKey, (int)$rowCount);
        }

        return $uploads;
    }

    private function scanExportMonthCounts(string $path, array $mapping, array $committedRows = []): array
    {
        $input = fopen($path, 'rb');
        if ($input === false) {
            return [];
        }

        $monthCounts = [];

        try {
            $header = fgetcsv($input, 0, ',', '"', '\\');
            if (!is_array($header)) {
                return [];
            }

            $rowNumber = 1;
            while (($row = fgetcsv($input, 0, ',', '"', '\\')) !== false) {
                $rowNumber++;
                if (!is_array($row) || $this->isBlankCsvRow($row)) {
                    continue;
                }

                $monthKey = $this->rowMonthKey($row, $mapping, is_array($committedRows[$rowNumber] ?? null) ? $committedRows[$rowNumber] : []);
                if ($monthKey === '') {
                    continue;
                }

                $monthCounts[$monthKey] = (int)($monthCounts[$monthKey] ?? 0) + 1;
            }
        } finally {
            fclose($input);
        }

        ksort($monthCounts);

        return $monthCounts;
    }

    private function monthUpload(array $upload, string $monthKey, int $rowCount): array
    {
        $upload['export_month'] = $monthKey;
        $upload['export_rows'] = $rowCount;

        return $upload;
    }

    private function buildMappedCsv(string $path, array $mapping, array $committedRows = [], string $monthKey = ''): string
    {
        $output = fopen('php://temp', 'w+b');
        if ($output === false) {
            throw new \RuntimeException('A temporary CSV export stream could not be opened.');
        }

        $fields = array_keys(\eel_accounts\Service\StatementUploadService::fieldDefinitions());
        fputcsv($output, $fields, ',', '"', '\\');

        try {
            foreach ($this->buildExportRows($path, $mapping, $committedRows, $monthKey) as $row) {
                fputcsv($output, array_map(static fn(string $field): string => (string)($row[$field] ?? ''), $fields), ',', '"', '\\');
            }

            rewind($output);
            return (string)stream_get_contents($output);
        } finally {
            fclose($output);
        }
    }

    private function buildExportRows(string $path, array $mapping, array $committedRows = [], string $monthKey = ''): array
    {
        $input = fopen($path, 'rb');
        if ($input === false) {
            throw new \RuntimeException('The stored CSV file could not be opened.');
        }

        $fields = array_keys(\eel_accounts\Service\StatementUploadService::fieldDefinitions());
        $exportRows = [];

        try {
            $header = fgetcsv($input, 0, ',', '"', '\\');
            if (!is_array($header)) {
                return [];
            }

            $rowNumber = 1;

            while (($row = fgetcsv($input, 0, ',', '"', '\\')) !== false) {
                $rowNumber++;

                if (!is_array($row) || $this->isBlankCsvRow($row)) {
                    continue;
                }

                $committedRow = is_array($committedRows[$rowNumber] ?? null) ? $committedRows[$rowNumber] : [];
                if ($monthKey !== '' && $this->rowMonthKey($row, $mapping, $committedRow) !== $monthKey) {
                    continue;
                }

                $exportRow = [];
                foreach ($fields as $fieldName) {
                    $committedValue = $committedRow[$fieldName] ?? null;
                    $exportRow[$fieldName] = $committedValue !== null && trim((string)$committedValue) !== ''
                        ? trim((string)$committedValue)
                        : $this->mappedValue($row, $mapping[$fieldName] ?? null);
                }

                $exportRow['_row_number'] = (string)$rowNumber;
                $exportRows[] = $exportRow;
            }

            return $exportRows;
        } finally {
            fclose($input);
        }
    }

    private function buildXlsx(int $companyId, int $uploadId, array $fields, array $rows, array $categoryOptions): string
    {
        $headers = array_merge(['eel_row_key'], $fields);
        $dataRows = [];
        foreach ($rows as $row) {
            $rowNumber = (int)($row['_row_number'] ?? 0);
            $dataRows[] = array_merge(
                [$this->rowExportKey($companyId, $uploadId, $rowNumber, $row)],
                array_map(static fn(string $field): string => (string)($row[$field] ?? ''), $fields)
            );
        }

        $categoryColumn = array_search('category', $headers, true);
        $categoryColumnLetter = $categoryColumn === false ? '' : $this->columnLetter((int)$categoryColumn + 1);
        $lastDataRow = max(2, count($dataRows) + 1);

        $files = [
            '[Content_Types].xml' => $this->xlsxContentTypesXml(),
            '_rels/.rels' => $this->xlsxRootRelationshipsXml(),
            'docProps/app.xml' => $this->xlsxAppPropertiesXml(),
            'docProps/core.xml' => $this->xlsxCorePropertiesXml(),
            'xl/workbook.xml' => $this->xlsxWorkbookXml(),
            'xl/_rels/workbook.xml.rels' => $this->xlsxWorkbookRelationshipsXml(),
            'xl/styles.xml' => $this->xlsxStylesXml(),
            'xl/worksheets/sheet1.xml' => $this->xlsxTransactionsSheetXml($headers, $dataRows, $categoryColumnLetter, $lastDataRow, count($categoryOptions)),
            'xl/worksheets/sheet2.xml' => $this->xlsxCategoriesSheetXml($categoryOptions),
        ];

        return $this->buildZipArchive($files);
    }

    private function fetchCategoryOptions(): array
    {
        $options = [];
        foreach ((new \eel_accounts\Repository\NominalAccountRepository())->fetchNominalAccounts() as $row) {
            $code = trim((string)($row['code'] ?? ''));
            $name = trim((string)($row['name'] ?? ''));
            if ($code === '' && $name === '') {
                continue;
            }

            $options[] = trim($code . ($code !== '' && $name !== '' ? ' - ' : '') . $name);
        }

        return array_values(array_unique($options));
    }

    private function rowExportKey(int $companyId, int $uploadId, int $rowNumber, array $row): string
    {
        return self::exportRowKey($companyId, $uploadId, $rowNumber, $row);
    }

    public static function exportRowKey(int $companyId, int $uploadId, int $rowNumber, array $row): string
    {
        $secret = \AppConfigurationStore::ensureUploadExportKey(32);
        $payload = implode('|', [
            'company:' . $companyId,
            'upload:' . $uploadId,
            'row:' . $rowNumber,
        ]);

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $secret, true)), '+/', '-_'), '=');
    }

    public static function legacyExportRowKey(int $companyId, int $uploadId, int $rowNumber, array $row): string
    {
        $secret = \AppConfigurationStore::ensureUploadExportKey(32);
        $payload = implode('|', [
            'company:' . $companyId,
            'upload:' . $uploadId,
            'row:' . $rowNumber,
            'created:' . (string)($row['created'] ?? ''),
            'processed:' . (string)($row['processed'] ?? ''),
            'amount:' . (string)($row['amount'] ?? ''),
            'description:' . (string)($row['description'] ?? ''),
        ]);

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $secret, true)), '+/', '-_'), '=');
    }

    private function xlsxTransactionsSheetXml(array $headers, array $rows, string $categoryColumn, int $lastDataRow, int $categoryCount): string
    {
        $sheetRows = [$this->xlsxRowXml(1, $headers)];
        foreach ($rows as $index => $row) {
            $sheetRows[] = $this->xlsxRowXml($index + 2, $row);
        }

        $validation = '';
        if ($categoryColumn !== '' && $categoryCount > 0) {
            $validation = '<dataValidations count="1"><dataValidation type="list" allowBlank="1" showErrorMessage="1" sqref="'
                . $categoryColumn . '2:' . $categoryColumn . $lastDataRow
                . '"><formula1>Categories!$A$1:$A$' . $categoryCount . '</formula1></dataValidation></dataValidations>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . $this->xlsxColumnWidthsXml($headers)
            . '<sheetData>' . implode('', $sheetRows) . '</sheetData>'
            . $validation
            . '</worksheet>';
    }

    private function xlsxColumnWidthsXml(array $headers): string
    {
        $widths = [
            'eel_row_key' => ['width' => 8, 'hidden' => true],
            'account' => ['width' => 30, 'hidden' => false],
            'created' => ['width' => 11, 'hidden' => false],
            'processed' => ['width' => 11, 'hidden' => false],
            'type' => ['width' => 5, 'hidden' => false],
            'description' => ['width' => 40, 'hidden' => false],
            'amount' => ['width' => 10, 'hidden' => false],
            'balance' => ['width' => 10, 'hidden' => false],
            'currency' => ['width' => 9, 'hidden' => false],
            'category' => ['width' => 40, 'hidden' => false],
            'document' => ['width' => 10, 'hidden' => false],
        ];

        $columns = [];
        foreach (array_values($headers) as $index => $header) {
            $definition = $widths[(string)$header] ?? null;
            if (!is_array($definition)) {
                continue;
            }

            $column = $index + 1;
            $columns[] = '<col min="' . $column . '" max="' . $column . '" width="' . (float)$definition['width'] . '"'
                . (!empty($definition['hidden']) ? ' hidden="1"' : '')
                . ' customWidth="1"/>';
        }

        return $columns === [] ? '' : '<cols>' . implode('', $columns) . '</cols>';
    }

    private function xlsxCategoriesSheetXml(array $categoryOptions): string
    {
        $rows = [];
        foreach (array_values($categoryOptions) as $index => $category) {
            $rows[] = $this->xlsxRowXml($index + 1, [(string)$category]);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . implode('', $rows) . '</sheetData>'
            . '</worksheet>';
    }

    private function xlsxRowXml(int $rowNumber, array $values): string
    {
        $cells = [];
        foreach (array_values($values) as $index => $value) {
            $ref = $this->columnLetter($index + 1) . $rowNumber;
            $cells[] = '<c r="' . $ref . '" t="inlineStr"><is><t>' . $this->xmlText((string)$value) . '</t></is></c>';
        }

        return '<row r="' . $rowNumber . '">' . implode('', $cells) . '</row>';
    }

    private function xlsxContentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private function xlsxRootRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function xlsxWorkbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>'
            . '<sheet name="Transactions" sheetId="1" r:id="rId1"/>'
            . '<sheet name="Categories" sheetId="2" state="hidden" r:id="rId2"/>'
            . '</sheets>'
            . '</workbook>';
    }

    private function xlsxWorkbookRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function xlsxStylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
            . '</styleSheet>';
    }

    private function xlsxAppPropertiesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>EEL Accounts</Application>'
            . '</Properties>';
    }

    private function xlsxCorePropertiesXml(): string
    {
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d\TH:i:s\Z');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>EEL Accounts</dc:creator>'
            . '<cp:lastModifiedBy>EEL Accounts</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private function buildZipArchive(array $files): string
    {
        $local = '';
        $central = '';
        $offset = 0;
        [$dosTime, $dosDate] = $this->zipDosDateTime();

        foreach ($files as $name => $content) {
            $name = str_replace('\\', '/', (string)$name);
            $content = (string)$content;
            $crc = (int)sprintf('%u', crc32($content));
            $size = strlen($content);
            $nameLength = strlen($name);

            $localHeader = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, $nameLength, 0) . $name;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, $nameLength, 0, 0, 0, 0, 0, $offset) . $name;
            $local .= $localHeader . $content;
            $offset += strlen($localHeader) + $size;
        }

        return $local
            . $central
            . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), strlen($local), 0);
    }

    private function zipDosDateTime(): array
    {
        $parts = getdate();
        $time = ((int)$parts['hours'] << 11) | ((int)$parts['minutes'] << 5) | ((int)($parts['seconds'] / 2));
        $date = (((int)$parts['year'] - 1980) << 9) | ((int)$parts['mon'] << 5) | (int)$parts['mday'];

        return [$time, $date];
    }

    private function columnLetter(int $column): string
    {
        $label = '';
        while ($column > 0) {
            $column--;
            $label = chr(65 + ($column % 26)) . $label;
            $column = intdiv($column, 26);
        }

        return $label;
    }

    private function xmlText(string $value): string
    {
        return htmlspecialchars($value, \ENT_XML1 | \ENT_COMPAT, 'UTF-8');
    }

    private function rowMonthKey(array $row, array $mapping, array $committedRow = []): string
    {
        $processed = trim((string)($committedRow['processed'] ?? ''));
        $created = trim((string)($committedRow['created'] ?? ''));

        if ($processed === '') {
            $processed = $this->mappedValue($row, $mapping['processed'] ?? null);
        }

        if ($created === '') {
            $created = $this->mappedValue($row, $mapping['created'] ?? null);
        }

        $processedDate = \eel_accounts\Service\StatementUploadService::parseDateTimeValue($processed);
        $createdDate = \eel_accounts\Service\StatementUploadService::parseDateTimeValue($created);
        $date = $processedDate instanceof \DateTimeImmutable ? $processedDate : $createdDate;

        return $date instanceof \DateTimeImmutable ? $date->format('Y-m-01') : '';
    }

    private function mappedValue(array $row, mixed $mappingEntry): string
    {
        if (!is_array($mappingEntry)) {
            return '';
        }

        if (array_key_exists('default_value', $mappingEntry)) {
            return trim((string)$mappingEntry['default_value']);
        }

        if (!array_key_exists('index', $mappingEntry)) {
            return '';
        }

        $index = (int)$mappingEntry['index'];
        return trim((string)($row[$index] ?? ''));
    }

    private function downloadFilename(array $upload): string
    {
        $statementMonth = $this->normaliseMonthKey((string)($upload['export_month'] ?? ''));
        if ($statementMonth === '') {
            $statementMonth = trim((string)($upload['statement_month'] ?? ''));
        }
        $year = preg_match('/^[0-9]{4}/', $statementMonth) ? substr($statementMonth, 0, 4) : 'unknown-year';
        $month = preg_match('/^[0-9]{4}-[0-9]{2}/', $statementMonth) ? substr($statementMonth, 5, 2) : 'unknown-month';
        $accountName = $this->filenamePart((string)($upload['account_name'] ?? 'account'));
        $storedFilename = preg_replace('/\.csv$/i', '', (string)($upload['stored_filename'] ?? 'statement')) ?? 'statement';

        return $accountName . '_' . $year . '_' . $month . '_' . $this->filenamePart($storedFilename) . '.csv';
    }

    private function normaliseMonthKey(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^[0-9]{4}-[0-9]{2}/', $value) !== 1) {
            return '';
        }

        return substr($value, 0, 7) . '-01';
    }

    private function categoryExportValue(array $row): string
    {
        $nominalCode = trim((string)($row['nominal_code'] ?? ''));
        $nominalName = trim((string)($row['nominal_name'] ?? ''));
        $categoryStatus = trim((string)($row['category_status'] ?? ''));

        if (in_array($categoryStatus, ['auto', 'manual'], true) && ($nominalCode !== '' || $nominalName !== '')) {
            return trim($nominalCode . ($nominalCode !== '' && $nominalName !== '' ? ' - ' : '') . $nominalName);
        }

        return trim((string)($row['source_category'] ?? ''));
    }

    private function dateValue(string $dateTimeValue, string $fallbackDate): string
    {
        $dateTimeValue = trim($dateTimeValue);
        if ($dateTimeValue !== '') {
            return substr($dateTimeValue, 0, 10);
        }

        return substr(trim($fallbackDate), 0, 10);
    }

    private function decimalValue(mixed $value): string
    {
        if ($value === null || trim((string)$value) === '') {
            return '';
        }

        return number_format((float)$value, 2, '.', '');
    }

    private function statementPath(int $companyId, string $storedFilename): string
    {
        return $this->fileCheckService->getStatementDirectory($companyId) . DIRECTORY_SEPARATOR . basename($storedFilename);
    }

    private function filenamePart(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[^A-Za-z0-9._-]+/', '_', $value) ?? '';
        $value = trim($value, '._-');

        return $value !== '' ? $value : 'export';
    }

    private function isBlankCsvRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string)$value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function decodeJsonArray(string $payload): array
    {
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function decodeJsonObject(string $payload): array
    {
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function uploadsConfig(string $defaultUploadDirectory): array
    {
        $config = \AppConfigurationStore::config();
        $uploads = is_array($config['uploads'] ?? null) ? $config['uploads'] : [];
        if (trim($defaultUploadDirectory) !== '') {
            $uploads['upload_base_dir'] = $defaultUploadDirectory;
        }

        return $uploads;
    }
}
