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

$harness->run(\eel_accounts\Service\CompaniesHousePdfDownloadService::class, function (GeneratedServiceClassTestHarness $harness): void {
    $baseDirectory = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'companies-house-pdf-download';
    companiesHousePdfDownloadRemoveDirectory($baseDirectory);
    companiesHousePdfDownloadEnsureDirectory($baseDirectory);

    $pdfBody = '%PDF-1.4 test body';
    $companyService = new \eel_accounts\Service\CompaniesHouseService('TEST', 20, static function (array $request): array {
        return [
            'status_code' => 200,
            'url' => 'https://api.company-information.service.gov.uk/company/12344321/filing-history',
            'body' => json_encode([
                'total_count' => 1,
                'items' => [[
                    'date' => '2022-09-05',
                    'type' => 'NEWINC',
                    'category' => 'incorporation',
                    'description' => 'incorporation-company',
                    'transaction_id' => 'transaction-1',
                    'pages' => 10,
                    'links' => [
                        'document_metadata' => 'https://document-api.company-information.service.gov.uk/document/doc-1',
                    ],
                ]],
            ], JSON_THROW_ON_ERROR),
        ];
    });
    $documentService = new \eel_accounts\Service\CompaniesHouseDocumentService('TEST', 20, static function (array $request) use ($pdfBody): array {
        $url = (string)($request['url'] ?? '');

        if (str_ends_with($url, '/content')) {
            return [
                'status_code' => 200,
                'url' => $url,
                'headers' => ['content-type' => 'application/pdf'],
                'body' => $pdfBody,
            ];
        }

        return [
            'status_code' => 200,
            'url' => $url,
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode([
                'company_number' => '12344321',
                'category' => 'new-companies',
                'pages' => 10,
                'filename' => '12344321_newinc_2022-09-05',
                'links' => [
                    'self' => 'https://document-api.company-information.service.gov.uk/document/doc-1',
                    'document' => 'https://document-api.company-information.service.gov.uk/document/doc-1/content',
                ],
                'resources' => [
                    'application/pdf' => [
                        'content_length' => strlen($pdfBody),
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ];
    });
    $fileCheckService = new \eel_accounts\Service\FileCheckService([
        'upload_base_dir' => $baseDirectory,
    ], null, static fn(int $companyId): string => $companyId === 7 ? '12344321' : '');
    $service = new \eel_accounts\Service\CompaniesHousePdfDownloadService(
        'TEST',
        20,
        $companyService,
        $documentService,
        $fileCheckService
    );

    try {
        $harness->check(\eel_accounts\Service\CompaniesHousePdfDownloadService::class, 'downloads filing PDF into the managed Companies House upload directory', function () use ($harness, $service, $baseDirectory, $pdfBody): void {
            $result = $service->downloadForCompany(7, '12344321');
            $path = $baseDirectory . DIRECTORY_SEPARATOR . '12344321' . DIRECTORY_SEPARATOR . 'companies_house' . DIRECTORY_SEPARATOR . '12344321_newinc_2022-09-05.pdf';

            $harness->assertSame(1, (int)($result['downloaded_count'] ?? 0));
            $harness->assertSame(0, (int)($result['failed_count'] ?? 1));
            $harness->assertTrue(is_file($path));
            $harness->assertSame($pdfBody, (string)file_get_contents($path));
        });

        $harness->check(\eel_accounts\Service\CompaniesHousePdfDownloadService::class, 'skips an existing PDF when the stored size still matches metadata', function () use ($harness, $service): void {
            $result = $service->downloadForCompany(7, '12344321');

            $harness->assertSame(0, (int)($result['downloaded_count'] ?? 1));
            $harness->assertSame(1, (int)($result['skipped_existing_count'] ?? 0));
            $harness->assertSame('already_present', (string)($result['documents'][0]['status'] ?? ''));
        });
    } finally {
        companiesHousePdfDownloadRemoveDirectory($baseDirectory);
    }
});

function companiesHousePdfDownloadEnsureDirectory(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }

    if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create Companies House PDF download test directory.');
    }
}

function companiesHousePdfDownloadRemoveDirectory(string $directory): void
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
            companiesHousePdfDownloadRemoveDirectory($path);
            continue;
        }

        @unlink($path);
    }

    @rmdir($directory);
}
