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
$harness->run(StatementUploadService::class, function (GeneratedServiceClassTestHarness $harness, StatementUploadService $service): void {
    $harness->check(StatementUploadService::class, 'returns null for a missing file MIME detection', function () use ($harness, $service): void {
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('detectMimeType');
        $method->setAccessible(true);

        $harness->assertSame(null, $method->invoke($service, APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'missing-file.bin'));
    });

    $harness->check(StatementUploadService::class, 'resolveUploadDirectory uses the shared statement directory helper', function () use ($harness): void {
        $baseDirectory = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'statement-upload-service';
        $fileCheckService = new FileCheckService([
            'upload_base_dir' => $baseDirectory,
            'statement_relative_path' => './statements/',
        ], null, static fn(int $companyId): string => $companyId === 42 ? '12345678' : '');
        $service = new StatementUploadService($baseDirectory, null, null, $fileCheckService);
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('resolveUploadDirectory');
        $method->setAccessible(true);

        $harness->assertSame(
            $baseDirectory . DIRECTORY_SEPARATOR . '12345678' . DIRECTORY_SEPARATOR . 'statements' . DIRECTORY_SEPARATOR,
            $method->invoke($service, 42)
        );
    });

    $harness->check(StatementUploadService::class, 'running balance check accepts matching rows in upload order', function () use ($harness, $service): void {
        $method = (new ReflectionClass($service))->getMethod('firstRunningBalanceBreak');
        $method->setAccessible(true);

        $harness->assertSame(null, $method->invoke($service, [
            statement_upload_test_row(1, '100.00', '1000.00'),
            statement_upload_test_row(2, '-25.50', '974.50'),
            statement_upload_test_row(3, '10.00', '984.50'),
        ]));
    });

    $harness->check(StatementUploadService::class, 'running balance check accepts matching rows in reverse upload order', function () use ($harness, $service): void {
        $method = (new ReflectionClass($service))->getMethod('firstRunningBalanceBreak');
        $method->setAccessible(true);

        $harness->assertSame(null, $method->invoke($service, [
            statement_upload_test_row(3, '10.00', '984.50'),
            statement_upload_test_row(2, '-25.50', '974.50'),
            statement_upload_test_row(1, '100.00', '1000.00'),
        ]));
    });

    $harness->check(StatementUploadService::class, 'running balance break identifies only the trusted prefix', function () use ($harness, $service): void {
        $method = (new ReflectionClass($service))->getMethod('firstRunningBalanceBreak');
        $method->setAccessible(true);

        $break = $method->invoke($service, [
            statement_upload_test_row(1, '100.00', '1000.00'),
            statement_upload_test_row(2, '-25.50', '974.50'),
            statement_upload_test_row(3, '10.00', '990.00'),
            statement_upload_test_row(4, '-5.00', '985.00'),
        ]);

        $harness->assertSame(3, $break['break_row_number'] ?? null);
        $harness->assertSame([1, 2], $break['trusted_row_numbers'] ?? null);
    });

    $harness->check(StatementUploadService::class, 'duplicate file warning states no new upload record is created', function () use ($harness, $service): void {
        $method = (new ReflectionClass($service))->getMethod('buildDuplicateFileWarning');
        $method->setAccessible(true);

        $message = $method->invoke($service, [
            'id' => 42,
            'rows_parsed' => 93,
            'rows_committed' => 0,
        ]);

        $harness->assertTrue(str_contains($message, 'upload #42'));
        $harness->assertTrue(str_contains($message, 'no duplicate record was created'));
        $harness->assertSame(false, str_contains($message, 'A fresh upload record will be created'));
    });

    $harness->check(StatementUploadService::class, 'upload summary query deduplicates exact file hashes', function () use ($harness, $service): void {
        $method = (new ReflectionClass($service))->getMethod('uploadSummaryByTaxYearSql');
        $method->setAccessible(true);

        $sql = $method->invoke($service);

        $harness->assertTrue(str_contains($sql, 'unique_uploads'));
        $harness->assertTrue(str_contains($sql, 'su.file_sha256'));
        $harness->assertTrue(str_contains($sql, 'GROUP BY COALESCE(su.tax_year_id, ty.id),'));
        $harness->assertTrue(str_contains($sql, "COALESCE(NULLIF(su.file_sha256, ''), CONCAT('upload:', su.id))"));
    });

    $harness->check(StatementUploadService::class, 'upload history period filter constrains unassigned uploads by statement dates', function () use ($harness, $service): void {
        $method = (new ReflectionClass($service))->getMethod('uploadHistoryTaxYearFilterClause');
        $method->setAccessible(true);

        $sql = $method->invoke($service);

        $harness->assertTrue(str_contains($sql, 'su.tax_year_id = ?'));
        $harness->assertTrue(str_contains($sql, 'su.tax_year_id IS NULL'));
        $harness->assertTrue(str_contains($sql, 'COALESCE(su.date_range_start, su.statement_month) <= ?'));
        $harness->assertTrue(str_contains($sql, 'COALESCE(su.date_range_end, su.statement_month) >= ?'));
    });
});

function statement_upload_test_row(int $rowNumber, string $amount, string $balance): array
{
    return [
        'row_number' => $rowNumber,
        'normalised_amount' => $amount,
        'normalised_balance' => $balance,
    ];
}
