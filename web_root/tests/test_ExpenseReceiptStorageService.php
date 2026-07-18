<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'UploadedFileTestFixture.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->run(\eel_accounts\Service\ExpenseReceiptStorageService::class, function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\ExpenseReceiptStorageService $service): void {
    $harness->check(\eel_accounts\Service\ExpenseReceiptStorageService::class, 'detects the real MIME type of uploaded receipt content', function () use ($harness, $service): void {
        $upload = UploadedFileTestFixture::jpegUpload('receipt-with-misleading-extension.txt');
        $upload['type'] = 'text/plain';

        $method = (new ReflectionClass($service))->getMethod('validateUploadedFile');
        $method->setAccessible(true);
        $result = $method->invoke($service, $upload);

        $harness->assertSame([], $result['errors'] ?? null);
        $harness->assertSame('image/jpeg', $result['content_type'] ?? null);
    });

    $harness->check(\eel_accounts\Service\ExpenseReceiptStorageService::class, 'receipt path helpers use the shared expense receipt helpers', function () use ($harness): void {
        $baseDirectory = test_tmp_directory() . DIRECTORY_SEPARATOR . 'expense-receipt-storage-service';
        $fileCheckService = new \eel_accounts\Service\FileCheckService([
            'upload_base_dir' => $baseDirectory,
            'expense_receipts_relative_path' => './expense_receipts/',
        ], null, static fn(int $companyId): string => $companyId === 42 ? '12345678' : '', static fn(int $companyId): string => $baseDirectory);
        $service = new \eel_accounts\Service\ExpenseReceiptStorageService($baseDirectory, fileCheckService: $fileCheckService);
        $reflection = new ReflectionClass($service);
        $directoryMethod = $reflection->getMethod('receiptDirectoryForCompany');
        $directoryMethod->setAccessible(true);
        $relativePathMethod = $reflection->getMethod('relativePathForCompany');
        $relativePathMethod->setAccessible(true);
        $absolutePathMethod = $reflection->getMethod('absolutePathFromStoredReference');
        $absolutePathMethod->setAccessible(true);

        $harness->assertSame(
            $baseDirectory . DIRECTORY_SEPARATOR . '12345678' . DIRECTORY_SEPARATOR . 'expense_receipts' . DIRECTORY_SEPARATOR,
            $directoryMethod->invoke($service, 42)
        );
        $harness->assertSame(
            '12345678/expense_receipts/receipt.pdf',
            $relativePathMethod->invoke($service, 42, 'receipt.pdf')
        );
        $harness->assertSame(null, $absolutePathMethod->invoke($service, 42, 'C:/outside/receipt.pdf'));
        $harness->assertSame(null, $absolutePathMethod->invoke($service, 42, '../outside/receipt.pdf'));
    });
});
