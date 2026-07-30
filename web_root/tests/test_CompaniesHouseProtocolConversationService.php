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
                    'companies_house_protocol_exchanges',
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
                $submissionId = 98833;
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
                    InterfaceDB::prepareExecute(
                        'INSERT INTO companies_house_accounts_submissions (
                            id, company_id, accounting_period_id, environment, filing_type,
                            lifecycle, basis_hash, idempotency_key, prepared_by,
                            prepared_at, status_updated_at, created_at, updated_at
                         ) VALUES (
                            :id, :company_id, :period_id, :environment, :filing_type,
                            :lifecycle, :basis_hash, :idempotency_key, :prepared_by,
                            :prepared_at, :status_updated_at, :created_at, :updated_at
                         )',
                        [
                            'id' => $submissionId,
                            'company_id' => $companyId,
                            'period_id' => $periodId,
                            'environment' => 'TEST',
                            'filing_type' => 'original',
                            'lifecycle' => 'prepared',
                            'basis_hash' => str_repeat('d', 64),
                            'idempotency_key' => str_repeat('e', 64),
                            'prepared_by' => 'test',
                            'prepared_at' => '2026-07-30 10:00:00',
                            'status_updated_at' => '2026-07-30 10:00:00',
                            'created_at' => '2026-07-30 10:00:00',
                            'updated_at' => '2026-07-30 10:00:00',
                        ]
                    );
                    $archive = new \eel_accounts\Service\TransmissionArchiveService($root);
                    $conversation = new \eel_accounts\Service\CompaniesHouseProtocolConversationService(
                        $archive,
                        str_repeat('k', 64)
                    );
                    $submission = [
                        'id' => $submissionId,
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
                        'SELECT exchange_state, sent_at
                         FROM companies_house_protocol_exchanges
                         WHERE transaction_id = :transaction_id',
                        ['transaction_id' => 'STATE1']
                    );
                    $harness->assertSame('prepared', (string)$row['exchange_state']);
                    $harness->assertSame(null, $row['sent_at']);
                    $harness->assertSame(hash('sha256', $requestXml), $receipt['request_sha256']);

                    $conversation->markSendStarted('TEST', 'STATE1');
                    $row = InterfaceDB::fetchOne(
                        'SELECT exchange_state, sent_at
                         FROM companies_house_protocol_exchanges
                         WHERE transaction_id = :transaction_id',
                        ['transaction_id' => 'STATE1']
                    );
                    $harness->assertSame('sent', (string)$row['exchange_state']);
                    $harness->assertTrue(trim((string)$row['sent_at']) !== '');

                    $responseReceipt = $conversation->captureResponse(
                        $submission,
                        'TEST',
                        $reference,
                        'company_data',
                        [
                            'transaction_id' => 'STATE1',
                            'response_xml' => '',
                            'status_code' => 204,
                        ],
                        (int)$preflight['id']
                    );
                    $row = InterfaceDB::fetchOne(
                        'SELECT exchange_state, received_at, response_path, response_status_code
                         FROM companies_house_protocol_exchanges
                         WHERE transaction_id = :transaction_id',
                        ['transaction_id' => 'STATE1']
                    );
                    $harness->assertSame('received', (string)$row['exchange_state']);
                    $harness->assertTrue(trim((string)$row['received_at']) !== '');
                    $harness->assertSame(null, $row['response_path']);
                    $harness->assertSame(204, (int)$row['response_status_code']);
                    $harness->assertSame(0, (int)$responseReceipt['response_bytes']);
                    $harness->assertSame(null, $responseReceipt['response_sha256']);
                    $harness->assertFalse(is_file(
                        dirname((string)$receipt['path'])
                            . DIRECTORY_SEPARATOR . 'company-data-state1-response.xml'
                    ));
                    $historyService = new \eel_accounts\Service\CompaniesHouseAccountsSubmissionService(
                        archiveService: $archive,
                        conversationService: $conversation
                    );
                    $harness->assertSame(
                        [],
                        $historyService->submissionHistory($companyId, $periodId)
                    );
                    $exchangeHistory = $historyService->protocolExchangeHistory(
                        $companyId,
                        $periodId
                    );
                    $harness->assertCount(1, $exchangeHistory);
                    $harness->assertSame('STATE1', (string)$exchangeHistory[0]['transaction_id']);
                    $harness->assertSame(true, (bool)$exchangeHistory[0]['request_available']);
                    $harness->assertSame(false, (bool)$exchangeHistory[0]['response_available']);
                } finally {
                    InterfaceDB::prepareExecute('DELETE FROM companies WHERE id = :id', ['id' => $companyId]);
                }
            }
        );
    }
);
