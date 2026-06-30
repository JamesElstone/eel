<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(\eel_accounts\Service\StatementUploadService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\StatementUploadService $service): void {
    $harness->check(\eel_accounts\Service\StatementUploadService::class, 'returns no month status without a selected company or period', static function () use ($harness, $service): void {
        $harness->assertSame([], $service->buildMonthStatus(0, 0));
    });

    $harness->check(\eel_accounts\Service\StatementUploadService::class, 'provides upload history filter labels', static function () use ($harness, $service): void {
        $options = $service->uploadsHistoryFilterOptions();

        $harness->assertSame('All uploads', $options['all'] ?? null);
        $harness->assertSame('Action required', $options['action_required'] ?? null);
        $harness->assertSame('Ready to import', $options['ready'] ?? null);
        $harness->assertSame('Imported', $options['imported'] ?? null);
        $harness->assertSame('Duplicate files', $options['duplicate_files'] ?? null);
        $harness->assertSame('Zero-row CSVs', $options['zero_row_csv'] ?? null);
    });

    $harness->check(\eel_accounts\Service\StatementUploadService::class, 'rejects more than thirteen CSV files in one batch', static function () use ($harness, $service): void {
        $fileNames = [];
        $fileTypes = [];
        $tmpNames = [];
        $errors = [];
        $sizes = [];

        for ($index = 1; $index <= 14; $index++) {
            $fileNames[] = 'statement-' . $index . '.csv';
            $fileTypes[] = 'text/csv';
            $tmpNames[] = 'statement-' . $index . '.csv';
            $errors[] = UPLOAD_ERR_OK;
            $sizes[] = 123;
        }

        $result = $service->importUploadedStatements([
            'company_id' => 1,
            'account_id' => 1,
            'accounting_period_id' => 1,
        ], [
            'statement_files' => [
                'name' => $fileNames,
                'type' => $fileTypes,
                'tmp_name' => $tmpNames,
                'error' => $errors,
                'size' => $sizes,
            ],
        ]);

        $harness->assertSame(false, $result['success'] ?? true);
        $harness->assertSame(400, $result['http_status'] ?? null);
        $harness->assertSame(
            ['You can upload at most 13 CSV files at once.'],
            $result['errors'] ?? null
        );
    });

    $harness->check(\eel_accounts\Service\StatementUploadService::class, 'treats missing accounting period uploads as action required', static function () use ($harness, $service): void {
        $method = new ReflectionMethod(\eel_accounts\Service\StatementUploadService::class, 'uploadMatchesHistoryFilter');
        $method->setAccessible(true);

        $harness->assertSame(true, $method->invoke(null, ['workflow_status' => 'uploaded'], 'action_required'));
        $harness->assertSame(true, $method->invoke(null, ['workflow_status' => 'needs_accounting_period'], 'action_required'));
        $harness->assertSame(false, $method->invoke(null, ['workflow_status' => 'staged'], 'action_required'));
        $harness->assertSame(true, $method->invoke(null, ['workflow_status' => 'staged'], 'ready'));
    });

    $harness->check(\eel_accounts\Service\StatementUploadService::class, 'matches zero-row uploads in the upload history filter', static function () use ($harness): void {
        $method = new ReflectionMethod(\eel_accounts\Service\StatementUploadService::class, 'uploadMatchesHistoryFilter');
        $method->setAccessible(true);

        $harness->assertSame(true, $method->invoke(null, ['rows_parsed' => 0], 'zero_row_csv'));
        $harness->assertSame(false, $method->invoke(null, ['rows_parsed' => 1], 'zero_row_csv'));
    });

    $harness->check(\eel_accounts\Service\StatementUploadService::class, 'can fetch upload history without selected accounting period filtering', static function () use ($harness): void {
        $method = new ReflectionMethod(\eel_accounts\Service\StatementUploadService::class, 'fetchUploadHistory');
        $parameters = $method->getParameters();

        $harness->assertSame('respectSelectedAccountingPeriod', $parameters[2]->getName() ?? null);
        $harness->assertSame(true, $parameters[2]->getDefaultValue() ?? null);
    });

    $harness->check(\eel_accounts\Service\StatementUploadService::class, 'marks duplicate file uploads by file hash', static function () use ($harness): void {
        $method = new ReflectionMethod(\eel_accounts\Service\StatementUploadService::class, 'annotateDuplicateFileUploads');
        $method->setAccessible(true);

        $rows = $method->invoke(null, [
            ['id' => 1, 'file_sha256' => 'same'],
            ['id' => 2, 'file_sha256' => 'unique'],
            ['id' => 3, 'file_sha256' => 'same'],
            ['id' => 4, 'file_sha256' => ''],
        ]);

        $harness->assertSame([true, false, true, false], array_map(static fn(array $row): bool => !empty($row['duplicate_file']), $rows));
    });

    $harness->check(\eel_accounts\Service\StatementUploadService::class, 'counts uploaded CSV data rows without requiring field mapping', static function () use ($harness, $service): void {
        $method = new ReflectionMethod(\eel_accounts\Service\StatementUploadService::class, 'countSourceDataRows');
        $method->setAccessible(true);
        $errors = [];

        $filename = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'example_data' . DIRECTORY_SEPARATOR . 'example_2026-02-ANNA_010226_280226.csv';
        $count = $method->invokeArgs($service, [$filename, &$errors]);

        $harness->assertSame([], $errors);
        $harness->assertSame(93, $count);
    });

    $harness->check(\eel_accounts\Service\StatementUploadService::class, 'counts only importable unimported CSVs as outstanding in period summary', static function () use ($harness, $service): void {
        if (
            !InterfaceDB::tableExists('companies')
            || !InterfaceDB::tableExists('accounting_periods')
            || !InterfaceDB::tableExists('statement_uploads')
        ) {
            $harness->skip('Required database tables are unavailable.');
        }

        $marker = 'outstanding-summary-' . bin2hex(random_bytes(4));

        InterfaceDB::beginTransaction();
        try {
            InterfaceDB::prepareExecute(
                'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
                [
                    'company_name' => 'Outstanding Summary Fixture',
                    'company_number' => $marker,
                ]
            );

            $companyId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM companies WHERE company_number = :company_number',
                ['company_number' => $marker]
            );

            InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (company_id, label, period_start, period_end) VALUES (:company_id, :label, :period_start, :period_end)',
                [
                    'company_id' => $companyId,
                    'label' => 'Outstanding Summary FY',
                    'period_start' => '2025-10-01',
                    'period_end' => '2026-09-30',
                ]
            );

            $periodId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label',
                [
                    'company_id' => $companyId,
                    'label' => 'Outstanding Summary FY',
                ]
            );

            $insertUpload = static function (
                ?int $accountingPeriodId,
                string $status,
                int $rowsParsed,
                int $rowsCommitted,
                string $fileHash,
                string $filename
            ) use ($companyId): void {
                InterfaceDB::prepareExecute(
                    'INSERT INTO statement_uploads (
                        company_id,
                        accounting_period_id,
                        workflow_status,
                        statement_month,
                        original_filename,
                        stored_filename,
                        file_sha256,
                        rows_parsed,
                        rows_committed,
                        uploaded_at
                    ) VALUES (
                        :company_id,
                        :accounting_period_id,
                        :workflow_status,
                        :statement_month,
                        :original_filename,
                        :stored_filename,
                        :file_sha256,
                        :rows_parsed,
                        :rows_committed,
                        :uploaded_at
                    )',
                    [
                        'company_id' => $companyId,
                        'accounting_period_id' => $accountingPeriodId,
                        'workflow_status' => $status,
                        'statement_month' => '2026-02-01',
                        'original_filename' => $filename,
                        'stored_filename' => $filename,
                        'file_sha256' => $fileHash,
                        'rows_parsed' => $rowsParsed,
                        'rows_committed' => $rowsCommitted,
                        'uploaded_at' => '2026-02-15 10:00:00',
                    ]
                );
            };

            $insertUpload($periodId, 'uploaded', 10, 0, hash('sha256', $marker . '-uploaded'), 'uploaded.csv');
            $insertUpload($periodId, 'mapped', 11, 0, hash('sha256', $marker . '-mapped'), 'mapped.csv');
            $insertUpload($periodId, 'staged', 12, 0, hash('sha256', $marker . '-staged'), 'staged.csv');
            $insertUpload(null, 'needs_accounting_period', 13, 0, hash('sha256', $marker . '-needs-period'), 'needs-period.csv');
            $insertUpload($periodId, 'committed', 14, 14, hash('sha256', $marker . '-committed'), 'committed.csv');
            $insertUpload($periodId, 'uploaded', 0, 0, hash('sha256', $marker . '-zero'), 'zero.csv');
            $insertUpload($periodId, 'uploaded', 9, 0, hash('sha256', $marker . '-duplicate'), 'duplicate-a.csv');
            $insertUpload($periodId, 'uploaded', 9, 0, hash('sha256', $marker . '-duplicate'), 'duplicate-b.csv');

            $summary = $service->fetchUploadSummaryByAccountingPeriod($companyId);

            $harness->assertSame(1, count($summary));
            $harness->assertSame(4, (int)($summary[0]['outstanding_upload_count'] ?? -1));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\StatementUploadService::class, 'auto mapping defaults currency to GBP when no currency header exists', static function () use ($harness): void {
        $mapping = \eel_accounts\Service\StatementUploadService::autoMapHeaders([
            'date',
            'description',
            'amount',
            'balance',
        ]);

        $harness->assertSame('GBP', $mapping['currency']['default_value'] ?? null);
        $harness->assertSame('£ GBP', $mapping['currency']['label'] ?? null);
    });

    $harness->check(\eel_accounts\Service\StatementUploadService::class, 'auto mapping recognises reference counterparty and card fields', static function () use ($harness): void {
        $mapping = \eel_accounts\Service\StatementUploadService::autoMapHeaders([
            'date',
            'type',
            'description',
            'reference',
            'amount',
            'name',
            'card',
        ]);

        $harness->assertSame('reference', $mapping['reference']['header'] ?? null);
        $harness->assertSame(3, $mapping['reference']['index'] ?? null);
        $harness->assertSame('name', $mapping['counterparty']['header'] ?? null);
        $harness->assertSame(5, $mapping['counterparty']['index'] ?? null);
        $harness->assertSame('card', $mapping['card']['header'] ?? null);
        $harness->assertSame(6, $mapping['card']['index'] ?? null);
    });
});
