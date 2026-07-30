<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\CompaniesHouseProtocolConversationService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\CompaniesHouseProtocolConversationService $service
    ): void {
        $harness->check(
            \eel_accounts\Service\CompaniesHouseProtocolConversationService::class,
            'requires all durable protocol state tables',
            static function () use ($harness, $service): void {
                $harness->assertSame(true, $service->schemaReady());
                foreach ([
                    'companies_house_company_auth_preflights',
                    'govtalk_protocol_exchanges',
                    'companies_house_accounts_status_cycles',
                ] as $table) {
                    $harness->assertSame(true, InterfaceDB::tableExists($table));
                }
            }
        );

        $harness->check(
            \eel_accounts\Service\CompaniesHouseProtocolConversationService::class,
            'creates stable environment-specific preflight binding facts outside api.keys',
            static function () use ($harness): void {
                $path = test_tmp_directory() . DIRECTORY_SEPARATOR . 'companies-house-preflight-' . bin2hex(random_bytes(4)) . '.keys';
                $configPath = AppConfigurationStore::configPath();
                $originalConfig = file_get_contents($configPath);
                if (!is_string($originalConfig)) {
                    throw new RuntimeException('Unable to snapshot test configuration.');
                }
                AppConfigurationStore::set('security_keys.path', $path);
                try {
                    $service = new \eel_accounts\Service\CompaniesHouseProtocolConversationService();
                    $method = new ReflectionMethod($service, 'hmacKey');
                    $method->setAccessible(true);
                    $testKey = (string)$method->invoke($service, 'TEST');
                    $harness->assertSame($testKey, (string)$method->invoke($service, 'TEST'));
                    $liveKey = (string)$method->invoke($service, 'LIVE');
                    $harness->assertSame(false, $testKey === $liveKey);
                    $harness->assertSame(64, strlen($testKey));
                    $harness->assertSame(64, strlen($liveKey));
                } finally {
                    test_write_file_contents_locked($configPath, $originalConfig);
                    AppConfigurationStore::config(true);
                    @unlink($path);
                }
            }
        );

        $harness->check(
            \eel_accounts\Service\CompaniesHouseProtocolConversationService::class,
            'records prepared, sent, and empty-response states at their actual boundaries',
            static function () use ($harness): void {
                $companyId = 98831;
                $periodId = 98832;
                $root = test_register_cleanup_path(
                    test_tmp_directory() . DIRECTORY_SEPARATOR . 'protocol-conversation-' . bin2hex(random_bytes(4))
                );
                try {
                    InterfaceDB::prepareExecute(
                        'INSERT INTO companies (id, company_name, company_number, is_active, created_at)
                         VALUES (:id, :name, :number, 1, :created_at)',
                        [
                            'id' => $companyId,
                            'name' => 'Protocol Conversation Test Limited',
                            'number' => '09883100',
                            'created_at' => '2026-07-30 10:00:00',
                        ]
                    );
                    InterfaceDB::prepareExecute(
                        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end, created_at)
                         VALUES (:id, :company_id, :label, :start, :end, :created_at)',
                        [
                            'id' => $periodId,
                            'company_id' => $companyId,
                            'label' => 'PROTOCOL-98832',
                            'start' => '2025-10-01',
                            'end' => '2026-09-30',
                            'created_at' => '2026-07-30 10:00:00',
                        ]
                    );
                    $archive = new \eel_accounts\Service\TransmissionArchiveService($root);
                    $conversation = new \eel_accounts\Service\CompaniesHouseProtocolConversationService(
                        $archive,
                        str_repeat('k', 64)
                    );
                    $submission = [
                        'id' => 0,
                        'company_id' => $companyId,
                        'accounting_period_id' => $periodId,
                        'company_number' => '09883100',
                    ];
                    $preflight = $conversation->beginPreflight(
                        $submission,
                        'TEST',
                        str_repeat('f', 64),
                        'ABC123',
                        'test',
                        false
                    );
                    $reference = (string)$preflight['archive_reference'];
                    $harness->assertSame(null, $preflight['submission_id']);
                    $harness->assertTrue(str_starts_with(
                        $reference,
                        'authentication-check-'
                    ));
                    $requestXml = '<CompanyDataRequest transaction="STATE1"/>';
                    $receipt = $conversation->captureRequest(
                        $submission,
                        'TEST',
                        $reference,
                        'company_data',
                        ['transaction_id' => 'STATE1', 'request_xml' => $requestXml],
                        (int)$preflight['id']
                    );
                    $row = InterfaceDB::fetchOne(
                        'SELECT exchange_state, sent_at, request_message_class
                         FROM govtalk_protocol_exchanges
                         WHERE transaction_id = :transaction_id',
                        ['transaction_id' => 'STATE1']
                    );
                    $harness->assertSame('prepared', (string)$row['exchange_state']);
                    $harness->assertSame(
                        'CompanyDataRequest',
                        (string)$row['request_message_class']
                    );
                    $harness->assertSame(null, $row['sent_at']);
                    $harness->assertSame(hash('sha256', $requestXml), $receipt['request_sha256']);
                    $harness->assertTrue(str_contains(
                        str_replace('\\', '/', (string)$receipt['path']),
                        '/companies_house/test/_authentication_checks/check-'
                    ));

                    $conversation->markSendStarted('TEST', 'STATE1');
                    $row = InterfaceDB::fetchOne(
                        'SELECT exchange_state, sent_at
                         FROM govtalk_protocol_exchanges
                         WHERE transaction_id = :transaction_id',
                        ['transaction_id' => 'STATE1']
                    );
                    $harness->assertSame('sent', (string)$row['exchange_state']);
                    $harness->assertTrue(trim((string)$row['sent_at']) !== '');

                    $headers = ['Content-Type' => 'application/xml', 'Set-Cookie' => 'private'];
                    $sanitizedHeadersJson = (new \eel_accounts\Service\CompaniesHouseProtocolMetadataService())
                        ->responseHeadersJson(['content-type' => 'application/xml']);
                    $responseReceipt = $conversation->captureResponse(
                        $submission,
                        'TEST',
                        $reference,
                        'company_data',
                        [
                            'transaction_id' => 'STATE1',
                            'response_xml' => '',
                            'status_code' => 204,
                            'response_headers' => $headers,
                            'response_headers_sha256' => hash(
                                'sha256',
                                $sanitizedHeadersJson
                            ),
                        ],
                        (int)$preflight['id']
                    );
                    $row = InterfaceDB::fetchOne(
                        'SELECT exchange_state, received_at, response_path,
                                response_status_code, response_headers_json,
                                response_headers_sha256, govtalk_errors_json
                         FROM govtalk_protocol_exchanges
                         WHERE transaction_id = :transaction_id',
                        ['transaction_id' => 'STATE1']
                    );
                    $harness->assertSame('received', (string)$row['exchange_state']);
                    $harness->assertTrue(trim((string)$row['received_at']) !== '');
                    $harness->assertSame(null, $row['response_path']);
                    $harness->assertSame(204, (int)$row['response_status_code']);
                    $harness->assertSame(
                        $sanitizedHeadersJson,
                        (string)$row['response_headers_json']
                    );
                    $harness->assertSame(
                        hash('sha256', $sanitizedHeadersJson),
                        (string)$row['response_headers_sha256']
                    );
                    $harness->assertSame('[]', (string)$row['govtalk_errors_json']);
                    $harness->assertSame(0, (int)$responseReceipt['response_bytes']);
                    $harness->assertSame(null, $responseReceipt['response_sha256']);
                    $harness->assertSame(
                        hash('sha256', $sanitizedHeadersJson),
                        $responseReceipt['response_headers_sha256']
                    );
                    $harness->assertFalse(is_file(
                        dirname((string)$receipt['path'])
                            . DIRECTORY_SEPARATOR . 'company-data-state1-response.xml'
                    ));
                    $historyService = new \eel_accounts\Service\CompaniesHouseAccountsSubmissionService(
                        archiveService: $archive,
                        conversationService: $conversation
                    );
                    $recordedExchange = InterfaceDB::fetchOne(
                        'SELECT e.submission_id, e.preflight_id,
                                p.company_id, p.accounting_period_id
                         FROM govtalk_protocol_exchanges e
                         LEFT JOIN companies_house_company_auth_preflights p
                           ON p.id = e.preflight_id
                         WHERE e.transaction_id = :transaction_id',
                        ['transaction_id' => 'STATE1']
                    );
                    $harness->assertSame(null, $recordedExchange['submission_id'] ?? null);
                    $harness->assertSame((int)$preflight['id'], (int)($recordedExchange['preflight_id'] ?? 0));
                    $harness->assertSame($companyId, (int)($recordedExchange['company_id'] ?? 0));
                    $harness->assertSame($periodId, (int)($recordedExchange['accounting_period_id'] ?? 0));
                    $directHistory = InterfaceDB::fetchAll(
                        'SELECT e.id
                         FROM govtalk_protocol_exchanges e
                         LEFT JOIN companies_house_accounts_submissions s ON s.id = e.submission_id
                         LEFT JOIN companies_house_company_auth_preflights p ON p.id = e.preflight_id
                         WHERE (
                             (s.company_id = :submission_company_id
                              AND s.accounting_period_id = :submission_accounting_period_id)
                             OR
                             (p.company_id = :preflight_company_id
                              AND p.accounting_period_id = :preflight_accounting_period_id)
                         )',
                        [
                            'submission_company_id' => $companyId,
                            'submission_accounting_period_id' => $periodId,
                            'preflight_company_id' => $companyId,
                            'preflight_accounting_period_id' => $periodId,
                        ]
                    );
                    $harness->assertCount(1, $directHistory);

                    $otherPeriodId = 98834;
                    InterfaceDB::prepareExecute(
                        'INSERT INTO accounting_periods (
                            id, company_id, label, period_start, period_end, created_at
                         ) VALUES (
                            :id, :company_id, :label, :start, :end, :created_at
                         )',
                        [
                            'id' => $otherPeriodId,
                            'company_id' => $companyId,
                            'label' => 'PROTOCOL-98834',
                            'start' => '2024-10-01',
                            'end' => '2025-09-30',
                            'created_at' => '2026-07-30 10:01:00',
                        ]
                    );
                    $otherContext = [
                        'id' => 0,
                        'company_id' => $companyId,
                        'accounting_period_id' => $otherPeriodId,
                        'company_number' => '09883100',
                    ];
                    $otherPreflight = $conversation->beginAuthenticationCheck(
                        $otherContext,
                        'TEST',
                        str_repeat('f', 64),
                        'ABC123',
                        'test',
                        false
                    );
                    $conversation->captureRequest(
                        $otherContext,
                        'TEST',
                        (string)$otherPreflight['archive_reference'],
                        'company_data',
                        [
                            'transaction_id' => 'STATE2',
                            'request_xml' => '<CompanyDataRequest transaction="STATE2"/>',
                        ],
                        (int)$otherPreflight['id']
                    );
                    $conversation->markSendStarted('TEST', 'STATE2');

                    $harness->assertSame(
                        [],
                        $historyService->submissionHistory($companyId, $periodId)
                    );
                    $exchangeHistory = $historyService->protocolExchangeHistory(
                        $companyId
                    );
                    $harness->assertCount(2, $exchangeHistory);
                    $transactions = array_column($exchangeHistory, 'transaction_id');
                    sort($transactions);
                    $harness->assertSame(['STATE1', 'STATE2'], $transactions);
                    $state1 = array_values(array_filter(
                        $exchangeHistory,
                        static fn (array $exchange): bool =>
                            (string)$exchange['transaction_id'] === 'STATE1'
                    ))[0];
                    $harness->assertSame(null, $state1['submission_id']);
                    $harness->assertSame(true, (bool)$state1['request_available']);
                    $harness->assertSame(false, (bool)$state1['response_available']);
                    $harness->assertSame('204 No Content', $state1['display_http_status']);

                    $failedCheck = $conversation->beginAuthenticationCheck(
                        $submission,
                        'TEST',
                        str_repeat('e', 64),
                        'ABC123',
                        'test',
                        false
                    );
                    $failedReference = (string)$failedCheck['archive_reference'];
                    $conversation->captureRequest(
                        $submission,
                        'TEST',
                        $failedReference,
                        'company_data',
                        [
                            'transaction_id' => 'STATE3',
                            'request_xml' => '<CompanyDataRequest transaction="STATE3"/>',
                        ],
                        (int)$failedCheck['id']
                    );
                    $conversation->markSendStarted('TEST', 'STATE3');
                    $errorXml = '<?xml version="1.0"?><GovTalkMessage '
                        . 'xmlns="http://www.govtalk.gov.uk/CM/envelope">'
                        . '<GovTalkDetails><GovTalkErrors><Error>'
                        . '<RaisedBy>CompanyDataRequest</RaisedBy><Number>502</Number>'
                        . '<Type>fatal</Type><Text>Authorisation Failure</Text>'
                        . '<Location/></Error></GovTalkErrors></GovTalkDetails>'
                        . '</GovTalkMessage>';
                    $emptyHeadersJson = (new \eel_accounts\Service\CompaniesHouseProtocolMetadataService())
                        ->responseHeadersJson([]);
                    $conversation->captureResponse(
                        $submission,
                        'TEST',
                        $failedReference,
                        'company_data',
                        [
                            'transaction_id' => 'STATE3',
                            'response_xml' => $errorXml,
                            'status_code' => 200,
                            'response_headers' => [],
                            'response_headers_sha256' => hash('sha256', $emptyHeadersJson),
                        ],
                        (int)$failedCheck['id']
                    );
                    $conversation->finishPreflight((int)$failedCheck['id'], [
                        'success' => false,
                        'authenticated' => false,
                        'environment' => 'TEST',
                        'transaction_id' => 'STATE3',
                        'gateway_errors' => [[
                            'number' => '502',
                            'type' => 'fatal',
                            'texts' => ['Authorisation Failure'],
                        ]],
                        'error' => 'Authorisation Failure',
                    ]);
                    $failedRow = InterfaceDB::fetchOne(
                        'SELECT p.outcome, e.exchange_state, e.govtalk_errors_json
                         FROM companies_house_company_auth_preflights p
                         JOIN govtalk_protocol_exchanges e ON e.preflight_id = p.id
                         WHERE p.id = :id',
                        ['id' => (int)$failedCheck['id']]
                    );
                    $harness->assertSame(
                        'presenter_authorisation_failed',
                        (string)$failedRow['outcome']
                    );
                    $harness->assertSame('failed', (string)$failedRow['exchange_state']);
                    $harness->assertTrue(str_contains(
                        (string)$failedRow['govtalk_errors_json'],
                        'Authorisation Failure'
                    ));
                    InterfaceDB::prepareExecute(
                        'UPDATE govtalk_protocol_exchanges
                         SET govtalk_errors_json = NULL
                         WHERE transaction_id = :transaction_id',
                        ['transaction_id' => 'STATE3']
                    );
                    $legacyHistory = $historyService->protocolExchangeHistory($companyId);
                    $legacyExchange = array_values(array_filter(
                        $legacyHistory,
                        static fn(array $exchange): bool =>
                            (string)$exchange['transaction_id'] === 'STATE3'
                    ))[0];
                    $harness->assertSame(
                        '502',
                        (string)$legacyExchange['govtalk_errors'][0]['number']
                    );
                    $harness->assertSame(
                        'Presenter authorisation failed',
                        (string)$legacyExchange['display_outcome']
                    );
                    $harness->assertSame(
                        null,
                        InterfaceDB::fetchColumn(
                            'SELECT govtalk_errors_json
                             FROM govtalk_protocol_exchanges
                             WHERE transaction_id = :transaction_id',
                            ['transaction_id' => 'STATE3']
                        )
                    );
                    $harness->assertSame(
                        'unknown',
                        $conversation->companyDataCapability('TEST', str_repeat('e', 64))
                    );

                    $successfulCheck = $conversation->beginAuthenticationCheck(
                        $submission,
                        'TEST',
                        str_repeat('e', 64),
                        'ABC123',
                        'test',
                        false
                    );
                    $conversation->finishPreflight((int)$successfulCheck['id'], [
                        'success' => true,
                        'authenticated' => true,
                        'environment' => 'TEST',
                        'transaction_id' => '',
                        'company_number' => '09883100',
                        'company_name' => 'Protocol Conversation Test Limited',
                    ]);
                    $harness->assertSame(
                        'available',
                        $conversation->companyDataCapability('TEST', str_repeat('e', 64))
                    );
                } finally {
                    InterfaceDB::prepareExecute('DELETE FROM companies WHERE id = :id', ['id' => $companyId]);
                }
            }
        );
    }
);
