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

$harness->run(\eel_accounts\Service\CompaniesHouseInitialShareholdingExtractionService::class, function (GeneratedServiceClassTestHarness $harness): void {
    $baseDirectory = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'companies-house-initial-shareholdings';
    companiesHouseInitialShareholdingRemoveDirectory($baseDirectory);
    companiesHouseInitialShareholdingEnsureDirectory($baseDirectory . DIRECTORY_SEPARATOR . '12344321' . DIRECTORY_SEPARATOR . 'companies_house');

    $filename = '12344321_newinc_2022-09-05.pdf';
    $pdfPath = $baseDirectory . DIRECTORY_SEPARATOR . '12344321' . DIRECTORY_SEPARATOR . 'companies_house' . DIRECTORY_SEPARATOR . $filename;
    file_put_contents($pdfPath, '%PDF test');

    $fileCheckService = new \eel_accounts\Service\FileCheckService([
        'upload_base_dir' => $baseDirectory,
    ], null, static fn(int $companyId): string => $companyId === 7 ? '12344321' : '');
    $sampleText = <<<TEXT
         Initial Shareholdings

Name:    ALEX EXAMPLE                             Class of Shares:       ORDINARY
Address
         SILVEROAKS OAKFIELD
         GOLDSWORTH PARK                           Number of shares:      100
         WOKING                                    Currency:              GBP
         GU21 3QS                                  Nominal value of each  5
         UNITED KINGDOM                            share:
         GU21 3QS                                  Amount unpaid:         0
                                                   Amount paid:           5

Electronically filed document for Company Number:                         12344321
\f                   Persons with Significant Control (PSC)
TEXT;
    $service = new \eel_accounts\Service\CompaniesHouseInitialShareholdingExtractionService(
        $fileCheckService,
        static fn(string $path): string => $sampleText
    );

    try {
        $harness->check(\eel_accounts\Service\CompaniesHouseInitialShareholdingExtractionService::class, 'extracts Initial Shareholdings values into a draft share class', function () use ($harness, $service, $filename): void {
            $result = $service->draftForCompany(7);
            $draft = (array)($result['draft'] ?? []);

            $harness->assertSame(true, !empty($result['success']));
            $harness->assertSame('ORDINARY', (string)($draft['share_class'] ?? ''));
            $harness->assertSame('100', (string)($draft['quantity'] ?? ''));
            $harness->assertSame('GBP', (string)($draft['currency'] ?? ''));
            $harness->assertSame('500', (string)($draft['aggregate_nominal_value'] ?? ''));
            $harness->assertSame('0', (string)($draft['total_aggregate_unpaid'] ?? ''));
            $harness->assertSame($filename, (string)($draft['document_reference'] ?? ''));
            $harness->assertSame('5', (string)($draft['source_values']['paid_value_per_share'] ?? ''));
        });

        $harness->check(\eel_accounts\Service\CompaniesHouseInitialShareholdingExtractionService::class, 'parses Initial Shareholdings text directly', function () use ($harness, $service, $sampleText): void {
            $values = $service->parseInitialShareholdings($sampleText);

            $harness->assertSame('ORDINARY', (string)($values['share_class'] ?? ''));
            $harness->assertSame(100, (int)($values['quantity'] ?? 0));
            $harness->assertSame('GBP', (string)($values['currency'] ?? ''));
            $harness->assertSame('5', (string)($values['nominal_value_per_share'] ?? ''));
            $harness->assertSame('0', (string)($values['unpaid_value_per_share'] ?? ''));
            $harness->assertSame('5', (string)($values['paid_value_per_share'] ?? ''));
        });
    } finally {
        companiesHouseInitialShareholdingRemoveDirectory($baseDirectory);
    }
});

function companiesHouseInitialShareholdingEnsureDirectory(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }

    if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create Initial Shareholdings test directory.');
    }
}

function companiesHouseInitialShareholdingRemoveDirectory(string $directory): void
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
            companiesHouseInitialShareholdingRemoveDirectory($path);
            continue;
        }

        @unlink($path);
    }

    @rmdir($directory);
}
