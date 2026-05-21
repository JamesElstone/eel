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

$harness->run(ExpenseReceiptStorageService::class, function (GeneratedServiceClassTestHarness $harness, ExpenseReceiptStorageService $service): void {
    $harness->check(ExpenseReceiptStorageService::class, 'receipt path helpers use the shared expense receipt helpers', function () use ($harness): void {
        $baseDirectory = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'expense-receipt-storage-service';
        $fileCheckService = new FileCheckService([
            'upload_base_dir' => $baseDirectory,
            'expense_receipts_relative_path' => './expense_receipts/',
        ], null, static fn(int $companyId): string => $companyId === 42 ? '12345678' : '');
        $service = new ExpenseReceiptStorageService($baseDirectory, fileCheckService: $fileCheckService);
        $reflection = new ReflectionClass($service);
        $directoryMethod = $reflection->getMethod('receiptDirectoryForCompany');
        $directoryMethod->setAccessible(true);
        $relativePathMethod = $reflection->getMethod('relativePathForCompany');
        $relativePathMethod->setAccessible(true);

        $harness->assertSame(
            $baseDirectory . DIRECTORY_SEPARATOR . '12345678' . DIRECTORY_SEPARATOR . 'expense_receipts' . DIRECTORY_SEPARATOR,
            $directoryMethod->invoke($service, 42)
        );
        $harness->assertSame(
            '12345678/expense_receipts/receipt.pdf',
            $relativePathMethod->invoke($service, 42, 'receipt.pdf')
        );
    });
});
