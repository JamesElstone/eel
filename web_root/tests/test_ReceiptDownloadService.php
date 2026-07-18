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

$harness->run(\eel_accounts\Service\ReceiptDownloadService::class, function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\ReceiptDownloadService $service): void {
    $harness->check(\eel_accounts\Service\ReceiptDownloadService::class, 'receipt path helpers use the shared transaction receipt helpers', function () use ($harness): void {
        $baseDirectory = test_tmp_directory() . DIRECTORY_SEPARATOR . 'receipt-download-service';
        $fileCheckService = new \eel_accounts\Service\FileCheckService([
            'upload_base_dir' => $baseDirectory,
            'transaction_receipts_relative_path' => './transaction_receipts/',
        ], null, static fn(int $companyId): string => $companyId === 42 ? '12345678' : '', static fn(int $companyId): string => $baseDirectory);
        $service = new \eel_accounts\Service\ReceiptDownloadService($baseDirectory, fileCheckService: $fileCheckService);
        $reflection = new ReflectionClass($service);
        $directoryMethod = $reflection->getMethod('receiptDirectoryForCompany');
        $directoryMethod->setAccessible(true);
        $relativePathMethod = $reflection->getMethod('relativeReceiptPath');
        $relativePathMethod->setAccessible(true);
        $absolutePathMethod = $reflection->getMethod('absolutePathFromRelative');
        $absolutePathMethod->setAccessible(true);

        $harness->assertSame(
            $baseDirectory . DIRECTORY_SEPARATOR . '12345678' . DIRECTORY_SEPARATOR . 'transaction_receipts' . DIRECTORY_SEPARATOR,
            $directoryMethod->invoke($service, 42)
        );
        $harness->assertSame(
            '12345678/transaction_receipts/receipt.pdf',
            $relativePathMethod->invoke($service, 42, 'receipt.pdf')
        );
        $harness->assertSame(null, $absolutePathMethod->invoke($service, 42, 'C:/outside/receipt.pdf'));
        $harness->assertSame(null, $absolutePathMethod->invoke($service, 42, '../outside/receipt.pdf'));
    });
});
