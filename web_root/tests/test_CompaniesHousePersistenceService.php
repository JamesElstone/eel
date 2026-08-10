<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\CompaniesHousePersistenceService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\CompaniesHousePersistenceService $service
    ): void {
        $harness->check(
            $service::class,
            'rechecks inside the transaction and preserves a successful parse before a failed write',
            static function () use ($harness, $service): void {
                foreach ([
                    'companies',
                    'companies_house_documents',
                    'companies_house_document_contexts',
                    'companies_house_document_facts',
                    'companies_house_taxonomy_concepts',
                ] as $table) {
                    if (!InterfaceDB::tableExists($table)) {
                        $harness->skip($table . ' table is not available.');
                    }
                }

                $seed = random_int(830000000, 839999000);
                $companyNumber = 'PF' . substr((string)$seed, -6);
                $externalDocumentId = 'preserved-document-' . $seed;
                $conceptId = $seed + 3;
                try {
                    InterfaceDB::execute(
                        'INSERT INTO companies (id, company_name, company_number, is_active)
                         VALUES (:id, :name, :number, 1)',
                        ['id' => $seed, 'name' => 'Preserved Parse Fixture Limited', 'number' => $companyNumber]
                    );
                    InterfaceDB::execute(
                        'INSERT INTO companies_house_taxonomy_concepts (
                            id, concept_name, short_name, friendly_label, value_type
                         ) VALUES (:id, :name, :short_name, :label, :value_type)',
                        [
                            'id' => $conceptId,
                            'name' => 'fixture:PreservedCurrentAssets' . $seed,
                            'short_name' => 'CurrentAssets',
                            'label' => 'Current assets',
                            'value_type' => 'monetary',
                        ]
                    );
                    $successfulRowSnapshot = null;
                    $hookSawTransaction = false;
                    $raceService = new \eel_accounts\Service\CompaniesHousePersistenceService(
                        static function () use (
                            $seed,
                            $companyNumber,
                            $externalDocumentId,
                            $conceptId,
                            &$successfulRowSnapshot,
                            &$hookSawTransaction
                        ): void {
                            $hookSawTransaction = InterfaceDB::inTransaction();
                            InterfaceDB::execute(
                                'INSERT INTO companies_house_documents (
                                    id, company_id, company_number, transaction_id, filing_date, filing_type,
                                    filing_category, filing_description, document_id, metadata_url,
                                    classification, significant_date, raw_metadata_json, raw_content_hash,
                                    parse_status, parse_error
                                 ) VALUES (
                                    :id, :company_id, :company_number, :transaction_id, :filing_date, :filing_type,
                                    :filing_category, :filing_description, :document_id, :metadata_url,
                                    :classification, :significant_date, :raw_metadata_json, :raw_content_hash,
                                    :parse_status, NULL
                                 )',
                                [
                                    'id' => $seed + 1,
                                    'company_id' => $seed,
                                    'company_number' => $companyNumber,
                                    'transaction_id' => 'preserved-transaction-' . $seed,
                                    'filing_date' => '2025-06-01',
                                    'filing_type' => 'AA',
                                    'filing_category' => 'accounts',
                                    'filing_description' => 'accounts-with-accounts-type-micro-entity',
                                    'document_id' => $externalDocumentId,
                                    'metadata_url' => 'https://example.invalid/original-metadata',
                                    'classification' => 'digital_xhtml',
                                    'significant_date' => '2024-09-30',
                                    'raw_metadata_json' => '{"created_at":"2025-06-01T10:00:00Z"}',
                                    'raw_content_hash' => hash('sha256', 'successfully parsed content'),
                                    'parse_status' => 'parsed_latest_year',
                                ]
                            );
                            InterfaceDB::execute(
                                'INSERT INTO companies_house_document_contexts (
                                    id, document_fk, context_ref, instant_date, is_latest_year_context
                                 ) VALUES (:id, :document_id, :context_ref, :instant_date, 1)',
                                [
                                    'id' => $seed + 4,
                                    'document_id' => $seed + 1,
                                    'context_ref' => 'preserved-current',
                                    'instant_date' => '2024-09-30',
                                ]
                            );
                            InterfaceDB::execute(
                                'INSERT INTO companies_house_document_facts (
                                    id, document_fk, context_fk, concept_fk, fact_name, raw_value,
                                    normalised_numeric, is_numeric, is_latest_year_fact
                                 ) VALUES (
                                    :id, :document_id, :context_id, :concept_id, :fact_name, :raw_value,
                                    :normalised_numeric, 1, 1
                                 )',
                                [
                                    'id' => $seed + 5,
                                    'document_id' => $seed + 1,
                                    'context_id' => $seed + 4,
                                    'concept_id' => $conceptId,
                                    'fact_name' => 'Current assets',
                                    'raw_value' => '125.00',
                                    'normalised_numeric' => 125.00,
                                ]
                            );
                            $successfulRowSnapshot = InterfaceDB::fetchOne(
                                'SELECT metadata_url, raw_metadata_json, raw_content_hash, parse_status, parse_error
                                 FROM companies_house_documents WHERE id = :id',
                                ['id' => $seed + 1]
                            );
                        }
                    );
                    $harness->assertSame(false, InterfaceDB::fetchOne(
                        'SELECT id FROM companies_house_documents WHERE document_id = :document_id',
                        ['document_id' => $externalDocumentId]
                    ));
                    $result = $raceService->persistDocument([
                        'company_id' => $seed,
                        'company_number' => $companyNumber,
                        'transaction_id' => 'preserved-transaction-' . $seed,
                        'filing_date' => '2025-06-01',
                        'filing_type' => 'AA',
                        'filing_category' => 'accounts',
                        'filing_description' => 'accounts-with-accounts-type-micro-entity',
                        'document_id' => $externalDocumentId,
                        'metadata_url' => 'https://example.invalid/failed-refresh',
                        'significant_date' => '2024-09-30',
                        'raw_metadata_json' => '{"placeholder":true}',
                        'parse_status' => 'metadata_fetch_failed',
                        'parse_error' => 'Simulated transient metadata failure.',
                    ]);
                    $after = InterfaceDB::fetchOne(
                        'SELECT metadata_url, raw_metadata_json, raw_content_hash, parse_status, parse_error
                         FROM companies_house_documents WHERE id = :id',
                        ['id' => $seed + 1]
                    );
                    $factValue = InterfaceDB::fetchColumn(
                        'SELECT normalised_numeric FROM companies_house_document_facts
                         WHERE document_fk = :document_id AND id = :fact_id',
                        ['document_id' => $seed + 1, 'fact_id' => $seed + 5]
                    );

                    $harness->assertSame(true, $hookSawTransaction);
                    $harness->assertSame($successfulRowSnapshot, $after);
                    $harness->assertSame(125.0, (float)$factValue);
                    $harness->assertSame(true, !empty($result['preserved_existing_parse']));
                    $harness->assertSame(1, (int)($result['latest_year_context_count'] ?? 0));
                    $harness->assertSame(1, (int)($result['latest_year_fact_count'] ?? 0));

                    $incompleteResult = $service->persistDocument([
                        'company_id' => $seed,
                        'company_number' => $companyNumber,
                        'transaction_id' => 'preserved-transaction-' . $seed,
                        'filing_date' => '2025-06-01',
                        'filing_type' => 'AA',
                        'filing_category' => 'accounts',
                        'filing_description' => 'accounts-with-accounts-type-micro-entity',
                        'document_id' => $externalDocumentId,
                        'metadata_url' => 'https://example.invalid/incomplete-reparse',
                        'significant_date' => '2024-09-30',
                        'raw_metadata_json' => '{"incomplete_reparse":true}',
                        'parse_status' => 'parsed_no_latest_year_facts',
                        'parse_error' => 'No latest-year facts were parsed.',
                    ], [
                        'summary' => [
                            'latest_year_context_count' => 0,
                            'latest_year_fact_count' => 0,
                        ],
                        'contexts' => [],
                        'facts' => [],
                    ]);
                    $afterIncompleteReparse = InterfaceDB::fetchOne(
                        'SELECT metadata_url, raw_metadata_json, raw_content_hash, parse_status, parse_error
                         FROM companies_house_documents WHERE id = :id',
                        ['id' => $seed + 1]
                    );
                    $factAfterIncompleteReparse = InterfaceDB::fetchColumn(
                        'SELECT normalised_numeric FROM companies_house_document_facts
                         WHERE document_fk = :document_id AND id = :fact_id',
                        ['document_id' => $seed + 1, 'fact_id' => $seed + 5]
                    );
                    $harness->assertSame($successfulRowSnapshot, $afterIncompleteReparse);
                    $harness->assertSame(125.0, (float)$factAfterIncompleteReparse);
                    $harness->assertSame(true, !empty($incompleteResult['preserved_existing_parse']));
                    $harness->assertSame(1, (int)($incompleteResult['latest_year_fact_count'] ?? 0));
                } finally {
                    InterfaceDB::execute('DELETE FROM companies WHERE id = :id', ['id' => $seed]);
                    InterfaceDB::execute(
                        'DELETE FROM companies_house_taxonomy_concepts WHERE id = :id',
                        ['id' => $conceptId]
                    );
                }
            }
        );

        $harness->check(
            $service::class,
            'stores oversized raw fact values as stable collision-resistant keys',
            static function () use ($harness, $service): void {
                foreach ([
                    'companies',
                    'companies_house_documents',
                    'companies_house_document_contexts',
                    'companies_house_document_facts',
                    'companies_house_taxonomy_concepts',
                ] as $table) {
                    if (!InterfaceDB::tableExists($table)) {
                        $harness->skip($table . ' table is not available.');
                    }
                }

                $seed = random_int(840000000, 849999000);
                $companyNumber = 'LV' . substr((string)$seed, -6);
                $documentId = 'long-value-document-' . $seed;
                $conceptName = 'fixture:LongNarrative' . $seed;
                $sharedPrefix = 'Résumé narrative (£): ' . str_repeat('identical disclosure wording ', 16);
                $longValues = [
                    $sharedPrefix . 'first conclusion',
                    $sharedPrefix . 'second conclusion',
                    str_repeat('£', 130),
                ];
                $parsedDocument = [
                    'summary' => [
                        'latest_year_context_count' => 1,
                        'latest_year_fact_count' => 3,
                    ],
                    'contexts' => [[
                        'context_ref' => 'long-narrative-current',
                        'instant_date' => '2025-09-30',
                        'is_latest_year_context' => 1,
                    ]],
                    'facts' => array_map(
                        static fn(string $longValue): array => [
                            'context_ref' => 'long-narrative-current',
                            'concept_name' => $conceptName,
                            'short_name' => 'LongNarrative',
                            'friendly_label' => 'Long narrative',
                            'concept_friendly_label' => 'Long narrative',
                            'value_type' => 'text',
                            'fact_name' => 'Long narrative',
                            'raw_value' => $longValue,
                            'normalised_text' => $longValue,
                            'is_numeric' => 0,
                            'is_latest_year_fact' => 1,
                        ],
                        $longValues
                    ),
                ];
                $document = [
                    'company_id' => $seed,
                    'company_number' => $companyNumber,
                    'transaction_id' => 'long-value-transaction-' . $seed,
                    'filing_date' => '2026-08-10',
                    'filing_type' => 'AAMD',
                    'filing_category' => 'accounts',
                    'filing_description' => 'accounts-amended-with-accounts-type-micro-entity',
                    'document_id' => $documentId,
                    'metadata_url' => 'https://example.invalid/long-value-metadata',
                    'classification' => 'digital_xhtml',
                    'significant_date' => '2025-09-30',
                    'parse_status' => 'parsed_latest_year',
                ];

                try {
                    InterfaceDB::execute(
                        'INSERT INTO companies (id, company_name, company_number, is_active)
                         VALUES (:id, :name, :number, 1)',
                        ['id' => $seed, 'name' => 'Long Value Fixture Limited', 'number' => $companyNumber]
                    );

                    $result = $service->persistDocument($document, $parsedDocument);
                    $harness->assertSame(3, (int)($result['latest_year_fact_count'] ?? 0));
                    $firstRows = InterfaceDB::fetchAll(
                        'SELECT f.raw_value, f.normalised_text
                         FROM companies_house_document_facts f
                         INNER JOIN companies_house_documents d ON d.id = f.document_fk
                         WHERE d.document_id = :document_id
                         ORDER BY f.id',
                        ['document_id' => $documentId]
                    );
                    $harness->assertSame(3, count($firstRows));

                    $storedRawByFullText = [];
                    foreach ($firstRows as $row) {
                        $storedRawByFullText[(string)($row['normalised_text'] ?? '')]
                            = (string)($row['raw_value'] ?? '');
                    }
                    foreach ($longValues as $longValue) {
                        $expectedRaw = '[sha256=' . hash('sha256', $longValue) . ']';
                        $harness->assertSame(true, array_key_exists($longValue, $storedRawByFullText));
                        $harness->assertSame($expectedRaw, $storedRawByFullText[$longValue] ?? '');
                        $harness->assertSame(73, strlen($expectedRaw));
                        $harness->assertSame(
                            1,
                            preg_match('/^\[sha256=[0-9a-f]{64}\]$/D', $storedRawByFullText[$longValue] ?? '')
                        );
                    }
                    $harness->assertSame(
                        count($longValues),
                        count(array_unique(array_values($storedRawByFullText)))
                    );

                    $service->persistDocument($document, $parsedDocument);
                    $secondRows = InterfaceDB::fetchAll(
                        'SELECT f.raw_value, f.normalised_text
                         FROM companies_house_document_facts f
                         INNER JOIN companies_house_documents d ON d.id = f.document_fk
                         WHERE d.document_id = :document_id
                         ORDER BY f.id',
                        ['document_id' => $documentId]
                    );
                    $harness->assertSame(
                        array_column($firstRows, 'raw_value'),
                        array_column($secondRows, 'raw_value')
                    );
                    $harness->assertSame(
                        array_column($firstRows, 'normalised_text'),
                        array_column($secondRows, 'normalised_text')
                    );
                } finally {
                    InterfaceDB::execute('DELETE FROM companies WHERE id = :id', ['id' => $seed]);
                    InterfaceDB::execute(
                        'DELETE FROM companies_house_taxonomy_concepts WHERE concept_name = :concept_name',
                        ['concept_name' => $conceptName]
                    );
                }
            }
        );
    }
);
