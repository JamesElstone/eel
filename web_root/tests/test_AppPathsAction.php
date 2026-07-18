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

$harness->run(AppPathsAction::class, function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof AppPathsAction) {
        throw new RuntimeException('Unexpected AppPathsAction instance.');
    }

    $testRoot = test_tmp_directory() . DIRECTORY_SEPARATOR . 'app-paths-action';
    $baseDirectory = $testRoot . DIRECTORY_SEPARATOR . 'files';
    $companyId = 12345678;
    $companyDirectory = $baseDirectory . DIRECTORY_SEPARATOR . (string)$companyId;
    $statementDirectory = $companyDirectory . DIRECTORY_SEPARATOR . 'statements';
    $expenseDirectory = $companyDirectory . DIRECTORY_SEPARATOR . 'expense_receipts';
    $receiptDirectory = $companyDirectory . DIRECTORY_SEPARATOR . 'transaction_receipts';

    ensureAppPathsDirectory($statementDirectory);
    ensureAppPathsDirectory($expenseDirectory);
    ensureAppPathsDirectory($receiptDirectory);

    $companyNumberResolver = static fn(int $resolvedCompanyId): string => (string)$resolvedCompanyId;
    $fileCheckService = new \eel_accounts\Service\FileCheckService([
        'upload_base_dir' => $baseDirectory,
        'statement_relative_path' => './statements/',
        'expense_receipts_relative_path' => './expense_receipts/',
        'transaction_receipts_relative_path' => './transaction_receipts/',
        'show_base_path_details' => true,
    ], null, $companyNumberResolver, static fn(int $companyId): string => $baseDirectory);

    try {
        $calculatePathItems = new ReflectionMethod(AppPathsAction::class, 'CalculatePathItems');
        $calculatePathItems->setAccessible(true);
        $buildPathStatus = new ReflectionMethod(AppPathsAction::class, 'buildPathStatus');
        $buildPathStatus->setAccessible(true);

        $harness->check('AppPathsAction', 'CalculatePathItems returns all expected path checks for a selected company', function () use (
            $harness,
            $instance,
            $calculatePathItems,
            $fileCheckService,
            $companyId,
            $baseDirectory
        ): void {
            $items = $calculatePathItems->invoke($instance, $fileCheckService, $companyId);

            $harness->assertCount(5, $items);
            $harness->assertSame('Upload base directory', $items[0]['title'] ?? '');
            $harness->assertSame(true, $items[0]['state'] ?? false);
            $harness->assertSame($baseDirectory, $items[0]['path'] ?? '');
            $harness->assertSame(true, str_contains((string)($items[0]['detail'] ?? ''), 'exists and read / write access confirmed OK'));
        });

        $harness->check('AppPathsAction', 'CalculatePathItems reports missing directories as not existing', function () use (
            $harness,
            $instance,
            $calculatePathItems,
            $companyId,
            $companyNumberResolver
        ): void {
            $missingRoot = test_tmp_directory() . DIRECTORY_SEPARATOR . 'app-paths-action-missing';
            $missingBaseDirectory = $missingRoot . DIRECTORY_SEPARATOR . 'files';
            ensureAppPathsDirectory($missingBaseDirectory);

            try {
                $service = new \eel_accounts\Service\FileCheckService([
                    'upload_base_dir' => $missingBaseDirectory,
                    'statement_relative_path' => './statements/',
                    'expense_receipts_relative_path' => './expense_receipts/',
                    'transaction_receipts_relative_path' => './transaction_receipts/',
                    'show_base_path_details' => true,
                ], null, $companyNumberResolver, static fn(int $resolvedCompanyId): string => $missingBaseDirectory);

                $items = $calculatePathItems->invoke($instance, $service, $companyId);

                $harness->assertSame(false, $items[1]['state'] ?? true);
                $harness->assertSame(
                    'The company upload directory path does not exist.',
                    $items[1]['detail'] ?? ''
                );
                $harness->assertSame(
                    'The transaction receipt directory path does not exist.',
                    $items[4]['detail'] ?? ''
                );
            } finally {
                removeAppPathsDirectory($missingRoot);
            }
        });

        $harness->check('AppPathsAction', 'CalculatePathItems can create missing company upload directories when requested', function () use (
            $harness,
            $instance,
            $calculatePathItems,
            $companyId,
            $companyNumberResolver
        ): void {
            $createRoot = test_tmp_directory() . DIRECTORY_SEPARATOR . 'app-paths-action-create';
            $createBaseDirectory = $createRoot . DIRECTORY_SEPARATOR . 'files';
            ensureAppPathsDirectory($createBaseDirectory);

            try {
                $service = new \eel_accounts\Service\FileCheckService([
                    'upload_base_dir' => $createBaseDirectory,
                    'statement_relative_path' => './statements/',
                    'expense_receipts_relative_path' => './expense_receipts/',
                    'transaction_receipts_relative_path' => './transaction_receipts/',
                    'show_base_path_details' => true,
                ], null, $companyNumberResolver, static fn(int $resolvedCompanyId): string => $createBaseDirectory);

                $items = $calculatePathItems->invoke($instance, $service, $companyId, true);

                $harness->assertSame(true, is_dir($createBaseDirectory . DIRECTORY_SEPARATOR . '12345678'));
                $harness->assertSame(true, is_dir($createBaseDirectory . DIRECTORY_SEPARATOR . '12345678' . DIRECTORY_SEPARATOR . 'statements'));
                $harness->assertSame(true, is_dir($createBaseDirectory . DIRECTORY_SEPARATOR . '12345678' . DIRECTORY_SEPARATOR . 'expense_receipts'));
                $harness->assertSame(true, is_dir($createBaseDirectory . DIRECTORY_SEPARATOR . '12345678' . DIRECTORY_SEPARATOR . 'transaction_receipts'));
                $harness->assertSame(true, $items[1]['state'] ?? false);
                $harness->assertSame(true, $items[4]['state'] ?? false);
            } finally {
                removeAppPathsDirectory($createRoot);
            }
        });

        $harness->check('AppPathsAction', 'CalculatePathItems returns a warning item when no company is selected', function () use (
            $harness,
            $instance,
            $calculatePathItems,
            $fileCheckService
        ): void {
            $items = $calculatePathItems->invoke($instance, $fileCheckService, 0);

            $harness->assertCount(2, $items);
            $harness->assertSame('warn', $items[1]['state'] ?? '');
            $harness->assertSame('Company upload directory', $items[1]['title'] ?? '');
        });

        $harness->check('AppPathsAction', 'buildPathStatus reports create-mode warning when no company is selected', function () use (
            $harness,
            $instance,
            $buildPathStatus
        ): void {
            $status = $buildPathStatus->invoke($instance, 0, true);

            $harness->assertSame('warn', $status['state'] ?? '');
            $harness->assertSame('Select a company before creating company upload paths.', $status['message'] ?? '');
        });

        $harness->check('AppPathsAction', 'check file paths card renders path details from page context', function () use (
            $harness,
            $baseDirectory
        ): void {
            $card = new _check_file_pathsCard();
            $html = $card->render([
                'path_status' => [
                    'state' => 'ok',
                    'message' => 'All tested paths are ready.',
                    'debug' => true,
                    'items' => [
                        [
                            'title' => 'Upload base directory',
                            'state' => true,
                            'path' => $baseDirectory,
                            'detail' => 'The upload base directory path exists and read and write access confirmed.',
                        ],
                    ],
                ],
            ]);

            $harness->assertSame(true, str_contains($html, 'Upload base directory'));
            $harness->assertSame(true, str_contains($html, $baseDirectory));
            $harness->assertSame(true, str_contains($html, 'All tested paths are ready.'));
            $harness->assertSame(true, str_contains($html, 'Create Paths'));
        });
    } finally {
        removeAppPathsDirectory($testRoot);
    }
});

function ensureAppPathsDirectory(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }

    if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create AppPathsAction test directory.');
    }
}

function removeAppPathsDirectory(string $directory): void
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
            removeAppPathsDirectory($path);
            continue;
        }

        @unlink($path);
    }

    @rmdir($directory);
}
