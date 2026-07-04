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

$harness->run('eel_accounts\Service\FileCheckService', function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof \eel_accounts\Service\FileCheckService) {
        throw new RuntimeException('Unexpected FileCheckService instance.');
    }

    $uploads = [
        'upload_base_dir' => APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'uploads-root',
        'statement_relative_path' => './statements/',
        'expense_receipts_relative_path' => './expense_receipts/',
        'transaction_receipts_relative_path' => './transaction_receipts/',
        'show_base_path_details' => true,
    ];

    $companyNumbersById = [
        42 => '12345678',
        43 => '00001234',
        44 => 'ACME123',
    ];
    $service = new \eel_accounts\Service\FileCheckService(
        $uploads,
        null,
        static fn(int $companyId): string => (string)($companyNumbersById[$companyId] ?? '')
    );
    $testRoot = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'file-check-service';
    $existingDirectory = $testRoot . DIRECTORY_SEPARATOR . 'existing';
    $existingFile = $existingDirectory . DIRECTORY_SEPARATOR . 'probe.txt';
    $creatableDirectory = $testRoot . DIRECTORY_SEPARATOR . 'created-by-service';
    $missingDirectory = $testRoot . DIRECTORY_SEPARATOR . 'missing';

    ensureFileCheckDirectory($existingDirectory);
    ensureFileCheckDirectory($uploads['upload_base_dir']);
    file_put_contents($existingFile, 'probe', LOCK_EX);

    try {
        $harness->check('eel_accounts\Service\FileCheckService', 'test directory handler returns true for an existing directory', function () use ($harness, $existingDirectory): void {
            $harness->assertTrue(fileCheckTestDirectoryExists($existingDirectory));
        });

        $harness->check('eel_accounts\Service\FileCheckService', 'test directory handler returns false for a missing directory', function () use ($harness, $missingDirectory): void {
            $harness->assertSame(false, fileCheckTestDirectoryExists($missingDirectory));
        });

        $harness->check('eel_accounts\Service\FileCheckService', 'test file handler returns true for an existing file', function () use ($harness, $existingFile): void {
            $harness->assertTrue(fileCheckTestFileExists($existingFile));
        });

        $harness->check('eel_accounts\Service\FileCheckService', 'test file handler returns false for a missing file', function () use ($harness, $missingDirectory): void {
            $harness->assertSame(false, fileCheckTestFileExists($missingDirectory . DIRECTORY_SEPARATOR . 'missing.txt'));
        });

        $harness->check('eel_accounts\Service\FileCheckService', 'test read handler returns true for an existing readable directory', function () use ($harness, $existingDirectory): void {
            $harness->assertTrue(fileCheckTestCanRead($existingDirectory));
        });

        $harness->check('eel_accounts\Service\FileCheckService', 'test read handler returns false for a missing directory', function () use ($harness, $missingDirectory): void {
            $harness->assertSame(false, fileCheckTestCanRead($missingDirectory));
        });

        $harness->check('eel_accounts\Service\FileCheckService', 'test temporary file handler creates and removes a probe file', function () use ($harness, $existingDirectory): void {
            $before = glob($existingDirectory . DIRECTORY_SEPARATOR . '.eel_filecheck_*');
            $harness->assertTrue(fileCheckTestCanWriteTemporaryFile($existingDirectory));
            $after = glob($existingDirectory . DIRECTORY_SEPARATOR . '.eel_filecheck_*');
            $harness->assertCount(count(is_array($before) ? $before : []), is_array($after) ? $after : []);
        });

        $harness->check('eel_accounts\Service\FileCheckService', 'test temporary directory handler creates and removes a probe directory', function () use ($harness, $existingDirectory): void {
            $before = glob($existingDirectory . DIRECTORY_SEPARATOR . '.eel_filecheck_*');
            $harness->assertTrue(fileCheckTestCanWriteTemporaryDirectory($existingDirectory));
            $after = glob($existingDirectory . DIRECTORY_SEPARATOR . '.eel_filecheck_*');
            $harness->assertCount(count(is_array($before) ? $before : []), is_array($after) ? $after : []);
        });

        $harness->check('eel_accounts\Service\FileCheckService', 'test create directory handler creates a missing directory', function () use ($harness, $creatableDirectory): void {
            $harness->assertTrue(fileCheckTestCreateDirectory($creatableDirectory));
            $harness->assertTrue(is_dir($creatableDirectory));
        });

        $harness->check('eel_accounts\Service\FileCheckService', 'test create directory handler returns true for an existing directory', function () use ($harness, $existingDirectory): void {
            $harness->assertTrue(fileCheckTestCreateDirectory($existingDirectory));
        });

        $harness->check('eel_accounts\Service\FileCheckService', 'getUpload returns the configured upload base directory', function () use ($harness, $service, $uploads): void {
            $harness->assertSame($uploads['upload_base_dir'], $service->getUpload());
        });

        $harness->check('eel_accounts\Service\FileCheckService', 'getPathDebug returns the configured debug flag', function () use ($harness, $service): void {
            $harness->assertTrue($service->getPathDebug());
        });

        $harness->check('eel_accounts\Service\FileCheckService', 'inspectDirectory reports a missing directory correctly', function () use ($harness, $service, $missingDirectory): void {
            $inspection = $service->inspectDirectory($missingDirectory, 'missing test directory');

            $harness->assertSame(false, $inspection['state'] ?? true);
            $harness->assertSame(false, $inspection['exists'] ?? true);
            $harness->assertSame(false, $inspection['can_read'] ?? true);
            $harness->assertSame(false, $inspection['can_write'] ?? true);
            $harness->assertSame('The missing test directory path does not exist.', $inspection['detail'] ?? '');
        });

        $harness->check('eel_accounts\Service\FileCheckService', 'company and category path helpers build expected paths from company id', function () use ($harness, $service, $uploads): void {
            $companyId = 42;

            $harness->assertSame(
                $uploads['upload_base_dir'] . DIRECTORY_SEPARATOR . '12345678' . DIRECTORY_SEPARATOR,
                $service->getCompanyUpload($companyId)
            );
            $harness->assertSame(
                $uploads['upload_base_dir'] . DIRECTORY_SEPARATOR . '12345678' . DIRECTORY_SEPARATOR . 'statements' . DIRECTORY_SEPARATOR,
                $service->getStatementDirectory($companyId)
            );
            $harness->assertSame(
                $uploads['upload_base_dir'] . DIRECTORY_SEPARATOR . '12345678' . DIRECTORY_SEPARATOR . 'expense_receipts' . DIRECTORY_SEPARATOR,
                $service->getExpenseReceiptDirectory($companyId)
            );
            $harness->assertSame(
                $uploads['upload_base_dir'] . DIRECTORY_SEPARATOR . '12345678' . DIRECTORY_SEPARATOR . 'transaction_receipts' . DIRECTORY_SEPARATOR,
                $service->getTransactionReceiptDirectory($companyId)
            );
            $harness->assertSame(
                $uploads['upload_base_dir'] . DIRECTORY_SEPARATOR . '12345678' . DIRECTORY_SEPARATOR . 'manual_asset_evidence' . DIRECTORY_SEPARATOR,
                $service->getManualAssetEvidenceDirectory($companyId)
            );
            $harness->assertSame(
                $uploads['upload_base_dir'] . DIRECTORY_SEPARATOR . '12345678' . DIRECTORY_SEPARATOR . 'companies_house' . DIRECTORY_SEPARATOR,
                $service->getCompaniesHouseDirectory($companyId)
            );
            $harness->assertSame(
                '12345678/statements/statement.csv',
                $service->getStatementRelativePath($companyId, 'statement.csv')
            );
            $harness->assertSame(
                '12345678/manual_asset_evidence/photo.jpg',
                $service->getManualAssetEvidenceRelativePath($companyId, 'photo.jpg')
            );
        });

        $harness->check('eel_accounts\Service\FileCheckService', 'resolved numeric company numbers preserve leading zeros', function () use ($harness, $service, $uploads): void {
            $companyId = 43;

            $harness->assertSame(
                $uploads['upload_base_dir'] . DIRECTORY_SEPARATOR . '00001234' . DIRECTORY_SEPARATOR . 'statements' . DIRECTORY_SEPARATOR,
                $service->getStatementDirectory($companyId)
            );
            $harness->assertSame(
                '00001234/statements/statement.csv',
                $service->getStatementRelativePath($companyId, 'statement.csv')
            );
        });

        $harness->check('eel_accounts\Service\FileCheckService', 'ensureCompanyUploadDirectories creates all expected company upload folders', function () use ($harness, $service, $uploads): void {
            $harness->assertTrue($service->ensureCompanyUploadDirectories(44));
            $harness->assertTrue(is_dir($uploads['upload_base_dir'] . DIRECTORY_SEPARATOR . 'ACME123'));
            $harness->assertTrue(is_dir($uploads['upload_base_dir'] . DIRECTORY_SEPARATOR . 'ACME123' . DIRECTORY_SEPARATOR . 'statements'));
            $harness->assertTrue(is_dir($uploads['upload_base_dir'] . DIRECTORY_SEPARATOR . 'ACME123' . DIRECTORY_SEPARATOR . 'expense_receipts'));
            $harness->assertTrue(is_dir($uploads['upload_base_dir'] . DIRECTORY_SEPARATOR . 'ACME123' . DIRECTORY_SEPARATOR . 'transaction_receipts'));
            $harness->assertTrue(is_dir($uploads['upload_base_dir'] . DIRECTORY_SEPARATOR . 'ACME123' . DIRECTORY_SEPARATOR . 'manual_asset_evidence'));
            $harness->assertTrue(is_dir($uploads['upload_base_dir'] . DIRECTORY_SEPARATOR . 'ACME123' . DIRECTORY_SEPARATOR . 'companies_house'));
        });

        $harness->check('eel_accounts\Service\FileCheckService', 'ensureStatementDirectory creates the company statement directory within an existing base upload root', function () use ($harness, $service, $uploads): void {
            $directory = $uploads['upload_base_dir'] . DIRECTORY_SEPARATOR . '12345678' . DIRECTORY_SEPARATOR . 'statements';

            $resolved = $service->ensureStatementDirectory(42);

            $harness->assertSame($directory, $resolved);
            $harness->assertTrue(is_dir($directory));
        });

        $harness->check('eel_accounts\Service\FileCheckService', 'ensureCompaniesHouseDirectory creates the company Companies House directory within an existing base upload root', function () use ($harness, $service, $uploads): void {
            $directory = $uploads['upload_base_dir'] . DIRECTORY_SEPARATOR . '12345678' . DIRECTORY_SEPARATOR . 'companies_house';

            $resolved = $service->ensureCompaniesHouseDirectory(42);

            $harness->assertSame($directory, $resolved);
            $harness->assertTrue(is_dir($directory));
        });

        $harness->check('eel_accounts\Service\FileCheckService', 'ensureStatementDirectory rejects creation when the configured base upload root is missing', function () use ($harness, $uploads): void {
            $missingBase = dirname($uploads['upload_base_dir']) . DIRECTORY_SEPARATOR . 'missing-upload-root';
            $service = new \eel_accounts\Service\FileCheckService([
                'upload_base_dir' => $missingBase,
                'statement_relative_path' => './statements/',
            ], null, static fn(int $companyId): string => $companyId === 42 ? '12345678' : '');

            $thrown = false;

            try {
                $service->ensureStatementDirectory(42);
            } catch (RuntimeException) {
                $thrown = true;
            }

            $harness->assertTrue($thrown);
            $harness->assertSame(false, is_dir($missingBase));
        });
    } finally {
        removeFileCheckDirectory($testRoot);
        removeFileCheckDirectory($uploads['upload_base_dir']);
    }
});

function ensureFileCheckDirectory(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }

    if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create FileCheckService test directory.');
    }
}

function fileCheckTestDirectoryExists(string $directoryPath): bool
{
    return $directoryPath !== '' && is_dir($directoryPath);
}

function fileCheckTestFileExists(string $filePath): bool
{
    return $filePath !== '' && is_file($filePath);
}

function fileCheckTestCanRead(string $directoryPath): bool
{
    return fileCheckTestDirectoryExists($directoryPath) && is_readable($directoryPath);
}

function fileCheckTestCanWriteTemporaryFile(string $directoryPath): bool
{
    if (!fileCheckTestDirectoryExists($directoryPath)) {
        return false;
    }

    $tempFile = rtrim($directoryPath, '\\/') . DIRECTORY_SEPARATOR . '.eel_filecheck_' . uniqid('', true) . '.tmp';
    $result = @file_put_contents($tempFile, 'ok', LOCK_EX);

    if ($result === false) {
        return false;
    }

    @unlink($tempFile);

    return true;
}

function fileCheckTestCanWriteTemporaryDirectory(string $directoryPath): bool
{
    if (!fileCheckTestDirectoryExists($directoryPath)) {
        return false;
    }

    $tempDirectory = rtrim($directoryPath, '\\/') . DIRECTORY_SEPARATOR . '.eel_filecheck_' . uniqid('', true);

    if (!@mkdir($tempDirectory, 0755)) {
        return false;
    }

    @rmdir($tempDirectory);

    return true;
}

function fileCheckTestCreateDirectory(string $directoryPath): bool
{
    if ($directoryPath === '') {
        return false;
    }

    if (is_dir($directoryPath)) {
        return true;
    }

    return @mkdir($directoryPath, 0755, true) || is_dir($directoryPath);
}

function removeFileCheckDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $items = scandir($directory);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $item;

        if (is_dir($path)) {
            removeFileCheckDirectory($path);
            continue;
        }

        @unlink($path);
    }

    @rmdir($directory);
}
