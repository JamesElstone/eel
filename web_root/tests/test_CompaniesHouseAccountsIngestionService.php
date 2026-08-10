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
$harness->run(
    \eel_accounts\Service\CompaniesHouseAccountsIngestionService::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $harness->check(
            \eel_accounts\Service\CompaniesHouseAccountsIngestionService::class,
            'persists a made-up-date placeholder when newest AAMD metadata cannot be fetched',
            static function () use ($harness): void {
                foreach ([
                    'companies',
                    'accounting_periods',
                    'companies_house_documents',
                ] as $table) {
                    if (!InterfaceDB::tableExists($table)) {
                        $harness->skip($table . ' table is not available.');
                    }
                }

                $seed = random_int(820000000, 829999000);
                $companyNumber = 'MF' . substr((string)$seed, -6);
                $failedExternalId = 'metadata-failed-' . $seed;
                $parsedExternalId = 'metadata-parsed-' . $seed;
                try {
                    InterfaceDB::execute(
                        'INSERT INTO companies (id, company_name, company_number, is_active)
                         VALUES (:id, :name, :number, 1)',
                        ['id' => $seed, 'name' => 'Metadata Failure Fixture Limited', 'number' => $companyNumber]
                    );
                    InterfaceDB::execute(
                        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
                         VALUES (:id, :company_id, :label, :period_start, :period_end)',
                        [
                            'id' => $seed + 1,
                            'company_id' => $seed,
                            'label' => 'Metadata failure fixture',
                            'period_start' => '2023-10-01',
                            'period_end' => '2024-09-30',
                        ]
                    );
                    InterfaceDB::execute(
                        'INSERT INTO companies_house_documents (
                            company_id, company_number, transaction_id, filing_date, filing_type,
                            filing_category, filing_description, document_id, metadata_url,
                            significant_date, significant_date_type, parse_status
                         ) VALUES (
                            :company_id, :company_number, :transaction_id, :filing_date, :filing_type,
                            :filing_category, :filing_description, :document_id, :metadata_url,
                            :significant_date, :significant_date_type, :parse_status
                         )',
                        [
                            'company_id' => $seed,
                            'company_number' => $companyNumber,
                            'transaction_id' => 'original-' . $seed,
                            'filing_date' => '2025-06-01',
                            'filing_type' => 'AA',
                            'filing_category' => 'accounts',
                            'filing_description' => 'accounts-with-accounts-type-micro-entity',
                            'document_id' => 'original-document-' . $seed,
                            'metadata_url' => 'https://example.invalid/original-' . $seed,
                            'significant_date' => '2024-09-30',
                            'significant_date_type' => 'made-up-date',
                            'parse_status' => 'parsed_latest_year',
                        ]
                    );

                    $historyResponse = [
                        'total_count' => 2,
                        'items' => [[
                            'transaction_id' => 'a-newest-amended-' . $seed,
                            'type' => 'AAMD',
                            'date' => '2026-08-11',
                            'category' => 'accounts',
                            'description' => 'accounts-amended-with-accounts-type-micro-entity',
                            'description_values' => ['made_up_date' => '2024-09-30'],
                            'action_date' => '2024-09-30',
                            'links' => [
                                'document_metadata' => 'https://document-api.company-information.service.gov.uk/document/' . $failedExternalId,
                            ],
                        ], [
                            'transaction_id' => 'z-older-amended-' . $seed,
                            'type' => 'AAMD',
                            'date' => '2026-08-11',
                            'category' => 'accounts',
                            'description' => 'accounts-amended-with-accounts-type-micro-entity',
                            'description_values' => ['made_up_date' => '2024-09-30'],
                            'action_date' => '2024-09-30',
                            'links' => [
                                'document_metadata' => 'https://document-api.company-information.service.gov.uk/document/' . $parsedExternalId,
                            ],
                        ]],
                    ];
                    $companiesHouse = new \eel_accounts\Service\CompaniesHouseService(
                        'TEST',
                        20,
                        static fn(array $request): array => [
                            'status_code' => 200,
                            'url' => 'https://api.company-information.service.gov.uk'
                                . (string)($request['path'] ?? ''),
                            'body' => json_encode($historyResponse, JSON_UNESCAPED_SLASHES),
                            'headers' => ['content-type' => 'application/json'],
                        ]
                    );
                    $filings = new \eel_accounts\Service\CompaniesHouseFilingService($companiesHouse);
                    $parsedXhtml = '<?xml version="1.0" encoding="UTF-8"?>'
                        . '<html xmlns="http://www.w3.org/1999/xhtml"'
                        . ' xmlns:ix="http://www.xbrl.org/2013/inlineXBRL"'
                        . ' xmlns:xbrli="http://www.xbrl.org/2003/instance"'
                        . ' xmlns:core="https://example.test/core"'
                        . ' xmlns:iso4217="http://www.xbrl.org/2003/iso4217">'
                        . '<body><ix:header><ix:resources>'
                        . '<xbrli:context id="current"><xbrli:entity><xbrli:identifier scheme="https://example.test">123</xbrli:identifier></xbrli:entity>'
                        . '<xbrli:period><xbrli:instant>2024-09-30</xbrli:instant></xbrli:period></xbrli:context>'
                        . '<xbrli:unit id="GBP"><xbrli:measure>iso4217:GBP</xbrli:measure></xbrli:unit>'
                        . '</ix:resources></ix:header>'
                        . '<ix:nonFraction name="core:CurrentAssets" contextRef="current" unitRef="GBP">1.00</ix:nonFraction>'
                        . '</body></html>';
                    $documents = new \eel_accounts\Service\CompaniesHouseDocumentService(
                        'TEST',
                        20,
                        static function (array $request) use (
                            $failedExternalId,
                            $parsedExternalId,
                            $parsedXhtml
                        ): array {
                            $url = (string)($request['url'] ?? '');
                            if (str_contains($url, $failedExternalId)) {
                                throw new RuntimeException('Simulated Document API outage.');
                            }
                            if (str_ends_with($url, '/content')) {
                                return [
                                    'status_code' => 200,
                                    'url' => $url,
                                    'headers' => ['content-type' => 'application/xhtml+xml'],
                                    'body' => $parsedXhtml,
                                ];
                            }
                            return [
                                'status_code' => 200,
                                'url' => $url,
                                'headers' => ['content-type' => 'application/json'],
                                'body' => json_encode([
                                    'id' => $parsedExternalId,
                                    'created_at' => '2026-08-11T09:00:00Z',
                                    'significant_date' => '2024-09-30',
                                    'significant_date_type' => 'made-up-date',
                                    'links' => [
                                        'document' => 'https://document-api.company-information.service.gov.uk/document/'
                                            . $parsedExternalId . '/content',
                                    ],
                                    'resources' => ['application/xhtml+xml' => []],
                                ], JSON_UNESCAPED_SLASHES),
                            ];
                        }
                    );
                    $service = new \eel_accounts\Service\CompaniesHouseAccountsIngestionService(
                        environment: 'TEST',
                        timeoutSeconds: 20,
                        filingService: $filings,
                        documentService: $documents
                    );

                    $result = $service->ingestForCompany($seed, $companyNumber);
                    $harness->assertSame(2, (int)($result['stored_document_count'] ?? 0));
                    $harness->assertSame(1, (int)($result['parsed_document_count'] ?? 0));
                    $harness->assertSame(1, (int)($result['failed_document_count'] ?? 0));
                    $stored = InterfaceDB::fetchOne(
                        'SELECT id, significant_date, significant_date_type, parse_status,
                                parse_error, raw_metadata_json
                         FROM companies_house_documents
                         WHERE document_id = :document_id',
                        ['document_id' => $failedExternalId]
                    );
                    $harness->assertSame('2024-09-30', (string)($stored['significant_date'] ?? ''));
                    $harness->assertSame('made-up-date', (string)($stored['significant_date_type'] ?? ''));
                    $harness->assertSame('metadata_fetch_failed', (string)($stored['parse_status'] ?? ''));
                    $harness->assertTrue(str_contains(
                        (string)($stored['parse_error'] ?? ''),
                        'Simulated Document API outage.'
                    ));
                    $placeholder = json_decode((string)($stored['raw_metadata_json'] ?? ''), true);
                    $harness->assertSame(true, !empty($placeholder['placeholder']));
                    $harness->assertSame('2024-09-30', (string)($placeholder['significant_date'] ?? ''));
                    $harness->assertSame(0, (int)($placeholder['_eel_filing_history_order'] ?? -1));
                    $parsedStored = InterfaceDB::fetchOne(
                        'SELECT id, parse_status, raw_metadata_json
                         FROM companies_house_documents WHERE document_id = :document_id',
                        ['document_id' => $parsedExternalId]
                    );
                    $harness->assertSame('parsed_latest_year', (string)($parsedStored['parse_status'] ?? ''));
                    $harness->assertTrue((int)($stored['id'] ?? 0) < (int)($parsedStored['id'] ?? 0));
                    $parsedMetadata = json_decode((string)($parsedStored['raw_metadata_json'] ?? ''), true);
                    $harness->assertSame(1, (int)($parsedMetadata['_eel_filing_history_order'] ?? -1));

                    $resolution = (new \eel_accounts\Service\CompaniesHousePeriodFilingResolverService())
                        ->resolve($seed, $seed + 1, $companyNumber, '2024-09-30');
                    $harness->assertSame((int)($stored['id'] ?? 0), (int)($resolution['latest_revision']['id'] ?? 0));
                    $harness->assertSame(
                        'metadata_fetch_failed',
                        (string)($resolution['latest_revision']['parse_status'] ?? '')
                    );

                    $observation = (new \eel_accounts\Service\YearEndCompaniesHouseComparisonService())
                        ->fetchRevisedObservation(
                            $seed,
                            $seed + 1,
                            [
                                'id' => $seed + 1,
                                'period_start' => '2023-10-01',
                                'period_end' => '2024-09-30',
                            ],
                            ['current_assets' => 1.00, 'reliable_closing_balance' => true]
                        );
                    $harness->assertSame('unverifiable', (string)($observation['reconciliation_state'] ?? ''));
                    $harness->assertSame(true, !empty($observation['filing_outstanding']));
                    $harness->assertSame((int)($stored['id'] ?? 0), (int)($observation['filing']['id'] ?? 0));
                } finally {
                    InterfaceDB::execute(
                        'DELETE FROM companies WHERE id = :id',
                        ['id' => $seed]
                    );
                }
            }
        );
    }
);
