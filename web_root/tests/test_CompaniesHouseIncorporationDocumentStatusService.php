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

$harness->run(\eel_accounts\Service\CompaniesHouseIncorporationDocumentStatusService::class, function (GeneratedServiceClassTestHarness $harness): void {
    $baseDirectory = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'companies-house-incorporation-status';
    companiesHouseIncorporationStatusRemoveDirectory($baseDirectory);
    companiesHouseIncorporationStatusEnsureDirectory($baseDirectory . DIRECTORY_SEPARATOR . '12344321' . DIRECTORY_SEPARATOR . 'companies_house');

    $fileCheckService = new \eel_accounts\Service\FileCheckService([
        'upload_base_dir' => $baseDirectory,
    ], null, static fn(int $companyId): string => $companyId === 7 ? '12344321' : '');
    $service = new \eel_accounts\Service\CompaniesHouseIncorporationDocumentStatusService($fileCheckService);

    try {
        $harness->check(\eel_accounts\Service\CompaniesHouseIncorporationDocumentStatusService::class, 'reports not downloaded when no incorporation PDF exists', function () use ($harness, $service): void {
            $status = $service->statusForCompany(7);

            $harness->assertSame(false, (bool)($status['downloaded'] ?? true));
            $harness->assertSame('', (string)($status['downloaded_at'] ?? 'unexpected'));
            $harness->assertSame('', (string)($status['filename'] ?? 'unexpected'));
        });

        $harness->check(\eel_accounts\Service\CompaniesHouseIncorporationDocumentStatusService::class, 'reports downloaded incorporation PDF filename and timestamp', function () use ($harness, $service, $baseDirectory): void {
            $filename = '12344321_newinc_2022-09-05.pdf';
            $path = $baseDirectory . DIRECTORY_SEPARATOR . '12344321' . DIRECTORY_SEPARATOR . 'companies_house' . DIRECTORY_SEPARATOR . $filename;
            $timestamp = strtotime('2026-07-04 12:34:56');

            file_put_contents($path, 'pdf');
            touch($path, $timestamp);

            $status = $service->statusForCompany(7);

            $harness->assertSame(true, (bool)($status['downloaded'] ?? false));
            $harness->assertSame(date('Y-m-d H:i:s', $timestamp), (string)($status['downloaded_at'] ?? ''));
            $harness->assertSame($filename, (string)($status['filename'] ?? ''));
        });
    } finally {
        companiesHouseIncorporationStatusRemoveDirectory($baseDirectory);
    }
});

function companiesHouseIncorporationStatusEnsureDirectory(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }

    if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create Companies House incorporation status test directory.');
    }
}

function companiesHouseIncorporationStatusRemoveDirectory(string $directory): void
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
            companiesHouseIncorporationStatusRemoveDirectory($path);
            continue;
        }

        @unlink($path);
    }

    @rmdir($directory);
}
