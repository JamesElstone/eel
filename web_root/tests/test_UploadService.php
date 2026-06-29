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
});
