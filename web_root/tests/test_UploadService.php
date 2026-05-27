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
$harness->run(StatementUploadService::class, static function (GeneratedServiceClassTestHarness $harness, StatementUploadService $service): void {
    $harness->check(StatementUploadService::class, 'returns no month status without a selected company or period', static function () use ($harness, $service): void {
        $harness->assertSame([], $service->buildMonthStatus(0, 0));
    });

    $harness->check(StatementUploadService::class, 'provides upload history filter labels', static function () use ($harness, $service): void {
        $options = $service->uploadsHistoryFilterOptions();

        $harness->assertSame('All uploads', $options['all'] ?? null);
        $harness->assertSame('Action required', $options['action_required'] ?? null);
        $harness->assertSame('Ready to import', $options['ready'] ?? null);
        $harness->assertSame('Imported', $options['imported'] ?? null);
        $harness->assertSame('Duplicate files', $options['duplicate_files'] ?? null);
        $harness->assertSame('Zero-row CSVs', $options['zero_row_csv'] ?? null);
    });

    $harness->check(StatementUploadService::class, 'treats missing accounting period uploads as action required', static function () use ($harness, $service): void {
        $method = new ReflectionMethod(StatementUploadService::class, 'uploadMatchesHistoryFilter');
        $method->setAccessible(true);

        $harness->assertSame(true, $method->invoke(null, ['workflow_status' => 'uploaded'], 'action_required'));
        $harness->assertSame(true, $method->invoke(null, ['workflow_status' => 'needs_accounting_period'], 'action_required'));
        $harness->assertSame(false, $method->invoke(null, ['workflow_status' => 'staged'], 'action_required'));
        $harness->assertSame(true, $method->invoke(null, ['workflow_status' => 'staged'], 'ready'));
    });

    $harness->check(StatementUploadService::class, 'matches zero-row uploads in the upload history filter', static function () use ($harness): void {
        $method = new ReflectionMethod(StatementUploadService::class, 'uploadMatchesHistoryFilter');
        $method->setAccessible(true);

        $harness->assertSame(true, $method->invoke(null, ['rows_parsed' => 0], 'zero_row_csv'));
        $harness->assertSame(false, $method->invoke(null, ['rows_parsed' => 1], 'zero_row_csv'));
    });

    $harness->check(StatementUploadService::class, 'can fetch upload history without selected accounting period filtering', static function () use ($harness): void {
        $method = new ReflectionMethod(StatementUploadService::class, 'fetchUploadHistory');
        $parameters = $method->getParameters();

        $harness->assertSame('respectSelectedAccountingPeriod', $parameters[2]->getName() ?? null);
        $harness->assertSame(true, $parameters[2]->getDefaultValue() ?? null);
    });

    $harness->check(StatementUploadService::class, 'marks duplicate file uploads by file hash', static function () use ($harness): void {
        $method = new ReflectionMethod(StatementUploadService::class, 'annotateDuplicateFileUploads');
        $method->setAccessible(true);

        $rows = $method->invoke(null, [
            ['id' => 1, 'file_sha256' => 'same'],
            ['id' => 2, 'file_sha256' => 'unique'],
            ['id' => 3, 'file_sha256' => 'same'],
            ['id' => 4, 'file_sha256' => ''],
        ]);

        $harness->assertSame([true, false, true, false], array_map(static fn(array $row): bool => !empty($row['duplicate_file']), $rows));
    });

    $harness->check(StatementUploadService::class, 'counts uploaded CSV data rows without requiring field mapping', static function () use ($harness, $service): void {
        $method = new ReflectionMethod(StatementUploadService::class, 'countSourceDataRows');
        $method->setAccessible(true);
        $errors = [];

        $filename = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'example_data' . DIRECTORY_SEPARATOR . 'example_2026-02-BANK_010226_280226.csv';
        $count = $method->invokeArgs($service, [$filename, &$errors]);

        $harness->assertSame([], $errors);
        $harness->assertSame(93, $count);
    });

    $harness->check(StatementUploadService::class, 'auto mapping defaults currency to GBP when no currency header exists', static function () use ($harness): void {
        $mapping = StatementUploadService::autoMapHeaders([
            'date',
            'description',
            'amount',
            'balance',
        ]);

        $harness->assertSame('GBP', $mapping['currency']['default_value'] ?? null);
        $harness->assertSame('£ GBP', $mapping['currency']['label'] ?? null);
    });
});
