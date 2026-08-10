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
    $baseDirectory = test_tmp_directory() . DIRECTORY_SEPARATOR . 'companies-house-pdf-download';
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
    ], null, static fn(int $companyId): string => $companyId === 7 ? '12344321' : '', static fn(int $companyId): string => $baseDirectory);
    $service = new \eel_accounts\Service\CompaniesHousePdfDownloadService(
        'TEST',
        20,
        $companyService,
        $documentService,
        $fileCheckService
    );

    try {
        $harness->check(\eel_accounts\Service\CompaniesHousePdfDownloadService::class, 'downloads filing PDF into the managed Companies House PDF directory', function () use ($harness, $service, $baseDirectory, $pdfBody): void {
            $result = $service->downloadForCompany(7, '12344321');
            $path = $baseDirectory . DIRECTORY_SEPARATOR . '12344321' . DIRECTORY_SEPARATOR
                . 'companies_house' . DIRECTORY_SEPARATOR . 'pdfs' . DIRECTORY_SEPARATOR
                . '12344321_newinc_2022-09-05.pdf';

            $harness->assertSame(1, (int)($result['downloaded_count'] ?? 0));
            $harness->assertSame(0, (int)($result['failed_count'] ?? 1));
            $harness->assertSame(dirname($path), (string)($result['directory'] ?? ''));
            $harness->assertTrue(is_file($path));
            $harness->assertSame($pdfBody, (string)file_get_contents($path));
        });

        $harness->check(\eel_accounts\Service\CompaniesHousePdfDownloadService::class, 'skips an existing PDF when the stored size still matches metadata', function () use ($harness, $service): void {
            $result = $service->downloadForCompany(7, '12344321');

            $harness->assertSame(0, (int)($result['downloaded_count'] ?? 1));
            $harness->assertSame(1, (int)($result['skipped_existing_count'] ?? 0));
            $harness->assertSame('already_present', (string)($result['documents'][0]['status'] ?? ''));
        });

        $harness->check(\eel_accounts\Service\CompaniesHousePdfDownloadService::class, 'suffixes colliding sanitized filenames with full document ids without deleting the legacy file', function () use ($harness, $baseDirectory): void {
            $firstBody = '%PDF-1.4 original filing';
            $secondBody = '%PDF-1.4 revised filing';
            $firstDocumentId = 'document-original-full-identifier-0123456789';
            $secondDocumentId = 'document-revised-full-identifier-9876543210';
            $companyService = new \eel_accounts\Service\CompaniesHouseService('TEST', 20, static function (array $request) use ($firstDocumentId, $secondDocumentId): array {
                return [
                    'status_code' => 200,
                    'url' => 'https://api.company-information.service.gov.uk/company/87654321/filing-history',
                    'body' => json_encode([
                        'total_count' => 2,
                        'items' => [
                            [
                                'date' => '2025-01-01',
                                'type' => 'AA',
                                'transaction_id' => 'transaction-original',
                                'links' => [
                                    'document_metadata' => 'https://document-api.company-information.service.gov.uk/document/' . $firstDocumentId,
                                ],
                            ],
                            [
                                'date' => '2025-01-15',
                                'type' => 'AAMD',
                                'transaction_id' => 'transaction-revised',
                                'links' => [
                                    'document_metadata' => 'https://document-api.company-information.service.gov.uk/document/' . $secondDocumentId,
                                ],
                            ],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ];
            });
            $documentService = new \eel_accounts\Service\CompaniesHouseDocumentService('TEST', 20, static function (array $request) use ($firstBody, $secondBody, $firstDocumentId, $secondDocumentId): array {
                $url = (string)($request['url'] ?? '');
                $isFirst = str_contains($url, $firstDocumentId);
                $documentId = $isFirst ? $firstDocumentId : $secondDocumentId;
                $body = $isFirst ? $firstBody : $secondBody;

                if (str_ends_with($url, '/content')) {
                    return [
                        'status_code' => 200,
                        'url' => $url,
                        'headers' => ['content-type' => 'application/pdf'],
                        'body' => $body,
                    ];
                }

                return [
                    'status_code' => 200,
                    'url' => $url,
                    'headers' => ['content-type' => 'application/json'],
                    'body' => json_encode([
                        'id' => $documentId,
                        'filename' => $isFirst ? 'accounts:2024.pdf' : 'accounts/2024.pdf',
                        'links' => [
                            'document' => 'https://document-api.company-information.service.gov.uk/document/' . $documentId . '/content',
                        ],
                        'resources' => [
                            'application/pdf' => [
                                'content_length' => strlen($body),
                            ],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ];
            });
            $fileCheckService = new \eel_accounts\Service\FileCheckService([
                'upload_base_dir' => $baseDirectory,
            ], null, static fn(int $companyId): string => $companyId === 8 ? '87654321' : '', static fn(int $companyId): string => $baseDirectory);
            $service = new \eel_accounts\Service\CompaniesHousePdfDownloadService(
                'TEST',
                20,
                $companyService,
                $documentService,
                $fileCheckService
            );
            $directory = $fileCheckService->ensureCompaniesHousePdfDirectory(8);
            $legacyPath = $directory . DIRECTORY_SEPARATOR . 'accounts_2024.pdf';
            // Match the revised PDF byte length so size alone cannot establish ownership.
            $legacyBody = '%PDF-1.4 legacy! filing';
            file_put_contents($legacyPath, $legacyBody);

            $result = $service->downloadForCompany(8, '87654321');
            $firstPath = $directory . DIRECTORY_SEPARATOR . 'accounts_2024_' . $firstDocumentId . '.pdf';
            $secondPath = $directory . DIRECTORY_SEPARATOR . 'accounts_2024_' . $secondDocumentId . '.pdf';

            $harness->assertSame(2, (int)($result['downloaded_count'] ?? 0));
            $harness->assertSame(0, (int)($result['failed_count'] ?? 1));
            $harness->assertSame($firstBody, (string)file_get_contents($firstPath));
            $harness->assertSame($secondBody, (string)file_get_contents($secondPath));
            $harness->assertSame($legacyBody, (string)file_get_contents($legacyPath));
            $harness->assertSame(basename($firstPath), (string)($result['documents'][0]['filename'] ?? ''));
            $harness->assertSame(basename($secondPath), (string)($result['documents'][1]['filename'] ?? ''));

            $repeat = $service->downloadForCompany(8, '87654321');
            $harness->assertSame(0, (int)($repeat['downloaded_count'] ?? 1));
            $harness->assertSame(2, (int)($repeat['skipped_existing_count'] ?? 0));
            $harness->assertSame($legacyBody, (string)file_get_contents($legacyPath));

            $wrongFirstBody = str_repeat('X', strlen($firstBody));
            file_put_contents($firstPath, $wrongFirstBody);
            $corruptExisting = $service->downloadForCompany(8, '87654321');
            $harness->assertSame(0, (int)($corruptExisting['downloaded_count'] ?? 1));
            $harness->assertSame(1, (int)($corruptExisting['skipped_existing_count'] ?? 0));
            $harness->assertSame(1, (int)($corruptExisting['failed_count'] ?? 0));
            $harness->assertSame('failed', (string)($corruptExisting['documents'][0]['status'] ?? ''));
            $harness->assertSame($wrongFirstBody, (string)file_get_contents($firstPath));
            $harness->assertSame($secondBody, (string)file_get_contents($secondPath));
            $harness->assertSame($legacyBody, (string)file_get_contents($legacyPath));
            file_put_contents($firstPath, $firstBody);

            $contentRequests = 0;
            $degradedDocumentService = new \eel_accounts\Service\CompaniesHouseDocumentService('TEST', 20, static function (array $request) use ($secondBody, $firstDocumentId, $secondDocumentId, &$contentRequests): array {
                $url = (string)($request['url'] ?? '');

                if (str_contains($url, $firstDocumentId)) {
                    throw new RuntimeException('Simulated original-filing metadata failure.');
                }

                if (str_ends_with($url, '/content')) {
                    $contentRequests++;

                    return [
                        'status_code' => 200,
                        'url' => $url,
                        'headers' => ['content-type' => 'application/pdf'],
                        'body' => $secondBody,
                    ];
                }

                return [
                    'status_code' => 200,
                    'url' => $url,
                    'headers' => ['content-type' => 'application/json'],
                    'body' => json_encode([
                        'id' => $secondDocumentId,
                        'filename' => 'accounts/2024.pdf',
                        'links' => [
                            'document' => 'https://document-api.company-information.service.gov.uk/document/' . $secondDocumentId . '/content',
                        ],
                        'resources' => [
                            'application/pdf' => [
                                'content_length' => strlen($secondBody),
                            ],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ];
            });
            $degradedService = new \eel_accounts\Service\CompaniesHousePdfDownloadService(
                'TEST',
                20,
                $companyService,
                $degradedDocumentService,
                $fileCheckService
            );

            $degraded = $degradedService->downloadForCompany(8, '87654321');

            $harness->assertSame(0, (int)($degraded['downloaded_count'] ?? 1));
            $harness->assertSame(1, (int)($degraded['skipped_existing_count'] ?? 0));
            $harness->assertSame(1, (int)($degraded['failed_count'] ?? 0));
            $harness->assertSame(1, $contentRequests);
            $harness->assertSame('failed', (string)($degraded['documents'][0]['status'] ?? ''));
            $harness->assertSame('already_present', (string)($degraded['documents'][1]['status'] ?? ''));
            $harness->assertSame(basename($secondPath), (string)($degraded['documents'][1]['filename'] ?? ''));
            $harness->assertSame($secondPath, (string)($degraded['documents'][1]['path'] ?? ''));
            $harness->assertSame($legacyBody, (string)file_get_contents($legacyPath));
            $harness->assertSame($firstBody, (string)file_get_contents($firstPath));
            $harness->assertSame($secondBody, (string)file_get_contents($secondPath));
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
