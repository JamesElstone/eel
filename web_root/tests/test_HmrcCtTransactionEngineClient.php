<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Client\HmrcCtTransactionEngineClient::class,
    static function (
        GeneratedServiceClassTestHarness $h,
        \eel_accounts\Client\HmrcCtTransactionEngineClient $unused
    ): void {
        unset($unused);
        $credentials = static fn(string $environment): array => [
            'sender_id' => $environment . '-SENDER',
            'password' => $environment . '-PASSWORD',
            'software_reference' => $environment === 'TEST' ? '1234' : '5678',
            'product' => 'EEL Accounts Tests',
            'version' => '1.0',
            'email' => 'tests@example.test',
        ];
        $transactionId = static fn(): string => 'ABCDEF1234567890';
        $unmarkedBody = '<IRenvelope xmlns="http://www.govtalk.gov.uk/taxation/CT/5">'
            . '<IRheader><Keys><Key Type="UTR">0123456789</Key></Keys><Sender>Company</Sender></IRheader>'
            . '<CompanyTaxReturn/></IRenvelope>';
        $marked = (new \eel_accounts\Service\HmrcIrmarkService())->apply(
            '<GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope"><EnvelopeVersion>2.0</EnvelopeVersion>'
            . '<Header><MessageDetails/></Header><GovTalkDetails><Keys/></GovTalkDetails><Body>'
            . $unmarkedBody . '</Body></GovTalkMessage>'
        );
        if (empty($marked['ok'])) {
            throw new RuntimeException('Unable to create the IRmarked transport fixture.');
        }
        $markedDocument = new DOMDocument();
        $markedDocument->loadXML((string)$marked['xml'], LIBXML_NONET | LIBXML_NOBLANKS);
        $markedXpath = new DOMXPath($markedDocument);
        $markedNodes = $markedXpath->query('/*[local-name()="GovTalkMessage"]/*[local-name()="Body"]/*');
        $markedNode = $markedNodes === false ? null : $markedNodes->item(0);
        $body = $markedNode instanceof DOMElement ? (string)$markedDocument->saveXML($markedNode) : '';
        if ($body === '') {
            throw new RuntimeException('Unable to extract the IRmarked transport fixture body.');
        }
        $response = static function (
            string $class,
            string $qualifier,
            string $function,
            string $correlationId = '',
            string $details = '',
            string $govTalkDetails = '<GovTalkDetails><Keys/></GovTalkDetails>',
            string $responseBody = '<Body/>',
            string $responseTransactionId = 'ABCDEF1234567890'
        ): string {
            return '<?xml version="1.0" encoding="UTF-8"?>'
                . '<GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope">'
                . '<EnvelopeVersion>2.0</EnvelopeVersion><Header><MessageDetails>'
                . '<Class>' . $class . '</Class><Qualifier>' . $qualifier . '</Qualifier>'
                . '<Function>' . $function . '</Function><TransactionID>' . $responseTransactionId . '</TransactionID>'
                . '<CorrelationID>' . $correlationId . '</CorrelationID><Transformation>XML</Transformation>'
                . $details . '</MessageDetails><SenderDetails/></Header>'
                . $govTalkDetails . $responseBody . '</GovTalkMessage>';
        };
        $recorders = static function (): array {
            $before = static fn(array $request): array => [
                'transaction_id' => (string)$request['transaction_id'],
                'request_sha256' => (string)$request['request_sha256'],
                'request_bytes' => (int)$request['request_bytes'],
            ];
            $after = static fn(array $response): array => [
                'transaction_id' => (string)$response['transaction_id'],
                'response_sha256' => $response['response_sha256'],
                'response_bytes' => (int)$response['response_bytes'],
                'response_headers_sha256' => (string)$response['response_headers_sha256'],
            ];

            return [$before, $after];
        };
        $conversation = static fn(
            string $environment,
            callable $before,
            callable $after
        ): \eel_accounts\Client\GovTalkConversationContext =>
            \eel_accounts\Client\GovTalkConversationContext::fromCallbacks(
                'hmrc',
                $environment,
                $before,
                $after
            );

        $h->check(
            \eel_accounts\Client\HmrcCtTransactionEngineClient::class,
            'prepares the exact GovTalk submission request without transport',
            static function () use ($h, $credentials, $transactionId, $body): void {
                $transportCalled = false;
                $client = new \eel_accounts\Client\HmrcCtTransactionEngineClient(
                    static function (array $request) use (&$transportCalled): array {
                        unset($request);
                        $transportCalled = true;
                        throw new RuntimeException('Transport must not run while generating a request file.');
                    },
                    $credentials,
                    $transactionId
                );
                $prepared = $client->prepareSubmissionRequest(
                    $body,
                    '0123456789',
                    'TEST'
                );

                $h->assertTrue((bool)$prepared['success']);
                $h->assertFalse($transportCalled);
                $h->assertSame('prepared', (string)$prepared['protocol_state']);
                $h->assertFalse((bool)$prepared['credentials_placeholder']);
                $h->assertSame('ABCDEF1234567890', (string)$prepared['transaction_id']);
                $h->assertTrue(str_contains(
                    (string)$prepared['request_xml'],
                    '<Class>HMRC-CT-CT600</Class>'
                ));
                $h->assertTrue(str_contains(
                    (string)$prepared['request_xml'],
                    '<GatewayTest>1</GatewayTest>'
                ));
                $h->assertTrue(str_contains(
                    (string)$prepared['request_xml'],
                    '<SenderID>TEST-SENDER</SenderID>'
                ));
                $h->assertTrue(str_contains(
                    (string)$prepared['request_xml'],
                    '<Value>TEST-PASSWORD</Value>'
                ));
                $h->assertTrue(str_contains(
                    (string)$prepared['request_xml'],
                    '<URI>1234</URI>'
                ));
                $h->assertSame(
                    hash('sha256', (string)$prepared['request_xml']),
                    (string)$prepared['request_sha256']
                );
                $h->assertSame(
                    strlen((string)$prepared['request_xml']),
                    (int)$prepared['request_bytes']
                );
            }
        );

        $h->check(
            \eel_accounts\Client\HmrcCtTransactionEngineClient::class,
            'requires a four-digit Software Reference for the selected credential environment',
            static function () use ($h, $transactionId): void {
                foreach (['', '12AB'] as $softwareReference) {
                    $client = new \eel_accounts\Client\HmrcCtTransactionEngineClient(
                        static fn(array $request): array => [],
                        static fn(string $environment): array => [
                            'sender_id' => $environment . '-SENDER',
                            'password' => $environment . '-PASSWORD',
                            'software_reference' => $softwareReference,
                            'product' => 'EEL Accounts Tests',
                            'version' => '1.0',
                            'email' => 'tests@example.test',
                        ],
                        $transactionId
                    );
                    $status = $client->configurationStatus('TEST');

                    $h->assertFalse((bool)$status['ready']);
                    $h->assertSame(
                        ['HMRC XML Vendor ID must contain exactly four digits.'],
                        (array)$status['blockers']
                    );
                }
            }
        );

        $h->check(
            \eel_accounts\Client\HmrcCtTransactionEngineClient::class,
            'uses explicit placeholders for an unsent request while real submission remains fail closed',
            static function () use ($h, $transactionId, $body, $recorders, $conversation): void {
                $transportCalled = false;
                $client = new \eel_accounts\Client\HmrcCtTransactionEngineClient(
                    static function (array $request) use (&$transportCalled): array {
                        unset($request);
                        $transportCalled = true;
                        throw new RuntimeException('Transport must remain blocked without credentials.');
                    },
                    static fn(string $environment): array => [],
                    $transactionId
                );

                $status = $client->configurationStatus('TEST');
                $prepared = $client->prepareSubmissionRequest($body, '0123456789', 'TEST');
                [$before, $after] = $recorders();
                $submitted = $client->submit(
                    $body,
                    '0123456789',
                    'TEST',
                    $conversation('TEST', $before, $after)
                );

                $h->assertFalse((bool)$status['ready']);
                $h->assertTrue((bool)$prepared['success']);
                $h->assertTrue((bool)$prepared['credentials_placeholder']);
                $h->assertTrue(str_contains(
                    (string)$prepared['request_xml'],
                    '<SenderID>DEVELOPER-SENDER-ID</SenderID>'
                ));
                $h->assertTrue(str_contains(
                    (string)$prepared['request_xml'],
                    '<Value>DEVELOPER-PASSWORD</Value>'
                ));
                $h->assertTrue(str_contains(
                    (string)$prepared['request_xml'],
                    '<URI>0000</URI>'
                ));
                $h->assertFalse((bool)$submitted['success']);
                $h->assertFalse($transportCalled);
            }
        );

        $h->check(
            \eel_accounts\Client\HmrcCtTransactionEngineClient::class,
            'uses the live endpoint and TIL class, persists before send, and redacts credentials',
            static function () use ($h, $credentials, $transactionId, $body, $response, $recorders, $conversation): void {
                $order = [];
                $captured = [];
                $transport = static function (array $request) use (&$order, &$captured, $response): array {
                    $order[] = 'transport';
                    $captured = $request;
                    return [
                        'status_code' => 200,
                        'headers' => ['content-type' => 'text/xml', 'authorization' => 'secret'],
                        'body' => $response(
                            'HMRC-CT-CT600-TIL',
                            'acknowledgement',
                            'submit',
                            'CAFE1234',
                            '<ResponseEndPoint PollInterval="7">https://transaction-engine.tax.service.gov.uk/poll</ResponseEndPoint>'
                        ),
                    ];
                };
                $client = new \eel_accounts\Client\HmrcCtTransactionEngineClient(
                    $transport,
                    $credentials,
                    $transactionId,
                    ['minimum_poll_interval' => 1]
                );
                $preSend = [];
                [, $afterReceive] = $recorders();
                $result = $client->submit(
                    $body,
                    '0123456789',
                    'TIL',
                    $conversation(
                        'TIL',
                        static function (array $request) use (&$order, &$preSend): array {
                            $order[] = 'persist';
                            $preSend = $request;
                            return [
                                'transaction_id' => (string)$request['transaction_id'],
                                'request_sha256' => (string)$request['request_sha256'],
                                'request_bytes' => (int)$request['request_bytes'],
                            ];
                        },
                        $afterReceive
                    )
                );

                $h->assertSame(['persist', 'transport'], $order);
                $h->assertSame('https://transaction-engine.tax.service.gov.uk/submission', $captured['url']);
                $h->assertTrue(str_contains((string)$captured['body'], '<Class>HMRC-CT-CT600-TIL</Class>'));
                $h->assertTrue(str_contains((string)$captured['body'], '<GatewayTest>0</GatewayTest>'));
                $h->assertTrue(str_contains((string)$captured['body'], '<SenderID>LIVE-SENDER</SenderID>'));
                $h->assertTrue(str_contains((string)$captured['body'], '<Value>LIVE-PASSWORD</Value>'));
                $h->assertTrue(str_contains((string)$captured['body'], '<URI>5678</URI>'));
                // The private evidence boundary receives the exact bytes. Only
                // application-facing result payloads are redacted.
                $h->assertTrue(str_contains((string)$preSend['request_xml'], 'LIVE-SENDER'));
                $h->assertFalse(str_contains((string)$result['request_xml'], 'LIVE-PASSWORD'));
                $h->assertSame(hash('sha256', (string)$captured['body']), $preSend['request_sha256']);
                $h->assertTrue((bool)$result['success']);
                $h->assertSame('acknowledged', $result['protocol_state']);
                $h->assertSame('CAFE1234', $result['correlation_id']);
                $h->assertSame(7, $result['poll_interval']);
                $h->assertFalse(array_key_exists('authorization', $result['headers']));
            }
        );

        $h->check(
            \eel_accounts\Client\HmrcCtTransactionEngineClient::class,
            'parses final poll acceptance and protocol cleanup',
            static function () use ($h, $credentials, $transactionId, $response, $recorders, $conversation): void {
                $responses = [
                    $response(
                        'HMRC-CT-CT600-TIL',
                        'response',
                        'submit',
                        'CAFE1234',
                        '<ResponseEndPoint PollInterval="10">https://transaction-engine.tax.service.gov.uk/submission</ResponseEndPoint>',
                        '<GovTalkDetails><Keys/></GovTalkDetails>',
                        '<Body><SubmissionReference xmlns="urn:test">HMRC-REF-1</SubmissionReference></Body>'
                    ),
                    $response(
                        'HMRC-CT-CT600-TIL',
                        'response',
                        'delete',
                        'CAFE1234',
                        '',
                        '<GovTalkDetails><Keys/></GovTalkDetails>',
                        '<Body/>',
                        ''
                    ),
                ];
                $capturedUrls = [];
                $client = new \eel_accounts\Client\HmrcCtTransactionEngineClient(
                    static function (array $request) use (&$responses, &$capturedUrls): array {
                        $capturedUrls[] = (string)$request['url'];
                        return ['status_code' => 200, 'headers' => [], 'body' => array_shift($responses)];
                    },
                    $credentials,
                    $transactionId
                );
                [$beforeSend, $afterReceive] = $recorders();
                $poll = $client->poll(
                    'CAFE1234',
                    'https://transaction-engine.tax.service.gov.uk/poll',
                    'TIL',
                    $conversation('TIL', $beforeSend, $afterReceive),
                    'ABCDEF1234567890'
                );
                $h->assertTrue((bool)$poll['success']);
                $h->assertSame('final_response', $poll['protocol_state']);
                $h->assertSame('accepted', $poll['business_outcome']);
                $h->assertTrue((bool)$poll['cleanup_required']);

                $delete = $client->delete(
                    'CAFE1234',
                    'https://transaction-engine.tax.service.gov.uk/submission',
                    'TIL',
                    $conversation('TIL', $beforeSend, $afterReceive),
                    'ABCDEF1234567890'
                );
                $h->assertTrue((bool)$delete['success']);
                $h->assertSame('deleted', $delete['protocol_state']);
                $h->assertSame([
                    'https://transaction-engine.tax.service.gov.uk/poll',
                    'https://transaction-engine.tax.service.gov.uk/submission',
                ], $capturedUrls);
            }
        );

        $h->check(
            \eel_accounts\Client\HmrcCtTransactionEngineClient::class,
            'accepts the archived HMRC acknowledgement with a blank response transaction ID',
            static function () use ($h, $credentials, $transactionId, $body, $recorders, $conversation): void {
                $fixture = '<?xml version="1.0" encoding="UTF-8"?>'
                    . '<GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope">'
                    . '<EnvelopeVersion>2.0</EnvelopeVersion><Header><MessageDetails>'
                    . '<Class>HMRC-CT-CT600</Class><Qualifier>acknowledgement</Qualifier><Function>submit</Function>'
                    . '<TransactionID></TransactionID><CorrelationID>D3C2B9E5F98449A19863D934273FA052</CorrelationID>'
                    . '<ResponseEndPoint PollInterval="10">https://test-transaction-engine.tax.service.gov.uk/poll</ResponseEndPoint>'
                    . '<GatewayTimestamp>2026-08-01T00:55:40.321</GatewayTimestamp>'
                    . '</MessageDetails><SenderDetails/></Header><GovTalkDetails><Keys/></GovTalkDetails><Body/></GovTalkMessage>';
                $client = new \eel_accounts\Client\HmrcCtTransactionEngineClient(
                    static fn(array $request): array => [
                        'status_code' => 200,
                        'headers' => ['Content-Type' => 'text/xml'],
                        'body' => $fixture,
                    ],
                    $credentials,
                    $transactionId
                );
                [$beforeSend, $afterReceive] = $recorders();
                $result = $client->submit(
                    $body,
                    '0123456789',
                    'TEST',
                    $conversation('TEST', $beforeSend, $afterReceive)
                );

                $h->assertTrue((bool)$result['success']);
                $h->assertFalse((bool)$result['transport_unknown']);
                $h->assertSame('acknowledged', $result['protocol_state']);
                $h->assertSame('ABCDEF1234567890', $result['transaction_id']);
                $h->assertSame('', $result['response_transaction_id']);
                $h->assertSame('D3C2B9E5F98449A19863D934273FA052', $result['correlation_id']);
                $h->assertSame('https://test-transaction-engine.tax.service.gov.uk/poll', $result['response_endpoint']);
                $h->assertSame(10, $result['poll_interval']);

                $reparsed = $client->parseArchivedResponse(
                    $fixture,
                    'submit',
                    'TEST',
                    '',
                    'ABCDEF1234567890',
                    'ABCDEF1234567890'
                );
                $h->assertTrue((bool)$reparsed['success']);
                $h->assertSame('acknowledged', $reparsed['protocol_state']);
                $h->assertSame('', $reparsed['response_transaction_id']);
            }
        );

        $h->check(
            \eel_accounts\Client\HmrcCtTransactionEngineClient::class,
            'reprocesses the exact HMRC 3001 poll shape as a final business rejection',
            static function () use ($h, $credentials, $transactionId): void {
                $originalTransactionId = 'AD907A5A3D1804FB27577E1CCD9C95C9';
                $pollTransactionId = '54B1C98A7BC69A5135435909056F65D1';
                $correlationId = 'D3C2B9E5F98449A19863D934273FA052';
                $accountsError = '<Error><RaisedBy>ChRIS</RaisedBy><Number>0</Number>'
                    . '<Type>xbrl.ixbrl.FormatUndefined</Type>'
                    . '<Text>Accounts transformation format is undefined.</Text>'
                    . '<Location>Accounts</Location></Error>';
                $computationsError = '<Error><RaisedBy>ChRIS</RaisedBy><Number>0</Number>'
                    . '<Type>xbrl.ixbrl.FormatUndefined</Type>'
                    . '<Text>Computations transformation format is undefined.</Text>'
                    . '<Location>Computations</Location></Error>';
                $fixture = '<?xml version="1.0" encoding="UTF-8"?>'
                    . '<GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope">'
                    . '<EnvelopeVersion>2.0</EnvelopeVersion><Header><MessageDetails>'
                    . '<Class>HMRC-CT-CT600</Class><Qualifier>error</Qualifier><Function>submit</Function>'
                    . '<TransactionID>' . $originalTransactionId . '</TransactionID>'
                    . '<CorrelationID>' . $correlationId . '</CorrelationID>'
                    . '<ResponseEndPoint PollInterval="10">https://test-transaction-engine.tax.service.gov.uk/submission</ResponseEndPoint>'
                    . '<Transformation>XML</Transformation></MessageDetails><SenderDetails/></Header>'
                    . '<GovTalkDetails><Keys/><GovTalkErrors><Error>'
                    . '<RaisedBy>Department</RaisedBy><Number>3001</Number><Type>business</Type>'
                    . '<Text>The submission failed departmental business logic.</Text><Location/>'
                    . '</Error></GovTalkErrors></GovTalkDetails><Body>'
                    . '<ErrorResponse xmlns="http://www.govtalk.gov.uk/CM/errorresponse" SchemaVersion="2.0">'
                    . '<Application><MessageCount>69</MessageCount></Application>'
                    . str_repeat($accountsError, 68) . $computationsError
                    . '</ErrorResponse></Body></GovTalkMessage>';
                $client = new \eel_accounts\Client\HmrcCtTransactionEngineClient(
                    static function (array $request): array {
                        unset($request);
                        throw new RuntimeException('Archived parsing must not contact HMRC.');
                    },
                    $credentials,
                    $transactionId
                );

                $result = $client->parseArchivedResponse(
                    $fixture,
                    'poll',
                    'TEST',
                    $correlationId,
                    $originalTransactionId,
                    $pollTransactionId
                );

                $h->assertFalse((bool)$result['success']);
                $h->assertSame('final_response', $result['protocol_state']);
                $h->assertSame('rejected', $result['business_outcome']);
                $h->assertTrue((bool)$result['cleanup_required']);
                $h->assertSame($pollTransactionId, $result['transaction_id']);
                $h->assertSame($originalTransactionId, $result['response_transaction_id']);
                $h->assertSame($correlationId, $result['correlation_id']);
                $h->assertSame(
                    'https://test-transaction-engine.tax.service.gov.uk/submission',
                    $result['response_endpoint']
                );
                $h->assertSame(10, $result['poll_interval']);
                $h->assertSame(69, $result['departmental_error_count']);
                $h->assertSame(70, count((array)$result['errors']));
                $h->assertSame('govtalk', $result['errors'][0]['source']);
                $h->assertSame([], $result['errors'][0]['locations']);
                $h->assertSame('department', $result['errors'][1]['source']);
                $h->assertSame('xbrl.ixbrl.FormatUndefined', $result['errors'][1]['type']);
                $h->assertSame(['Accounts'], $result['errors'][1]['locations']);
                $h->assertSame(['Computations'], $result['errors'][69]['locations']);
                $h->assertTrue(str_contains((string)$result['error'], '3001:'));
                $h->assertTrue(str_contains((string)$result['error'], '69 departmental validation errors'));
                $h->assertFalse(str_contains((string)$result['error'], 'transformation format'));

                $wrongOriginal = $client->parseArchivedResponse(
                    $fixture,
                    'poll',
                    'TEST',
                    $correlationId,
                    'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
                    $pollTransactionId
                );
                $h->assertFalse((bool)$wrongOriginal['success']);
                $h->assertSame('failed', $wrongOriginal['protocol_state']);
                $h->assertTrue(str_contains(
                    strtolower((string)$wrongOriginal['error']),
                    'transaction id'
                ));
            }
        );

        $h->check(
            \eel_accounts\Client\HmrcCtTransactionEngineClient::class,
            'applies current and original transaction IDs by response type and handles delete 2000',
            static function () use ($h, $credentials, $transactionId, $response): void {
                $original = 'AD907A5A3D1804FB27577E1CCD9C95C9';
                $current = '54B1C98A7BC69A5135435909056F65D1';
                $correlation = 'D3C2B9E5F98449A19863D934273FA052';
                $client = new \eel_accounts\Client\HmrcCtTransactionEngineClient(
                    static fn(array $request): array => [],
                    $credentials,
                    $transactionId
                );
                $acknowledgement = static function (string $responseTransactionId) use (
                    $response,
                    $correlation
                ): string {
                    return $response(
                        'HMRC-CT-CT600',
                        'acknowledgement',
                        'submit',
                        $correlation,
                        '<ResponseEndPoint PollInterval="10">https://test-transaction-engine.tax.service.gov.uk/poll</ResponseEndPoint>',
                        '<GovTalkDetails><Keys/></GovTalkDetails>',
                        '<Body/>',
                        $responseTransactionId
                    );
                };
                $validAcknowledgement = $client->parseArchivedResponse(
                    $acknowledgement($current),
                    'poll',
                    'TEST',
                    $correlation,
                    $original,
                    $current
                );
                $h->assertTrue((bool)$validAcknowledgement['success']);
                $wrongAcknowledgement = $client->parseArchivedResponse(
                    $acknowledgement($original),
                    'poll',
                    'TEST',
                    $correlation,
                    $original,
                    $current
                );
                $h->assertSame('failed', $wrongAcknowledgement['protocol_state']);

                $deleteResponse = $response(
                    'HMRC-CT-CT600',
                    'response',
                    'delete',
                    $correlation,
                    '<ResponseEndPoint PollInterval="not-used">https://attacker.example/delete</ResponseEndPoint>',
                    '<GovTalkDetails><Keys/></GovTalkDetails>',
                    '<Body/>',
                    $current
                );
                $deleted = $client->parseArchivedResponse(
                    $deleteResponse,
                    'delete',
                    'TEST',
                    $correlation,
                    $original,
                    $current
                );
                $h->assertTrue((bool)$deleted['success']);
                $h->assertSame('deleted', $deleted['protocol_state']);
                $h->assertSame('', $deleted['response_endpoint']);
                $h->assertSame(null, $deleted['poll_interval']);

                $gateway2000 = '<GovTalkDetails><Keys/><GovTalkErrors><Error>'
                    . '<RaisedBy>Gateway</RaisedBy><Number>2000</Number><Type>fatal</Type>'
                    . '<Text>The correlation ID is no longer known.</Text><Location/>'
                    . '</Error></GovTalkErrors></GovTalkDetails>';
                $deleteNotFound = $client->parseArchivedResponse(
                    $response(
                        'HMRC-CT-CT600',
                        'error',
                        'delete',
                        $correlation,
                        '',
                        $gateway2000,
                        '<Body/>',
                        $original
                    ),
                    'delete',
                    'TEST',
                    $correlation,
                    $original,
                    $current
                );
                $h->assertTrue((bool)$deleteNotFound['success']);
                $h->assertTrue((bool)$deleteNotFound['delete_not_found']);
                $h->assertSame('deleted', $deleteNotFound['protocol_state']);
                $h->assertSame($current, $deleteNotFound['transaction_id']);
                $h->assertSame($original, $deleteNotFound['response_transaction_id']);
                $h->assertSame('', $deleteNotFound['response_endpoint']);
                $h->assertSame(null, $deleteNotFound['poll_interval']);

                $retryableDeleteError = '<GovTalkDetails><Keys/><GovTalkErrors><Error>'
                    . '<RaisedBy>Gateway</RaisedBy><Number>5000</Number><Type>fatal</Type>'
                    . '<Text>The delete request may be retried.</Text><Location/>'
                    . '</Error></GovTalkErrors></GovTalkDetails>';
                $deleteRetry = $client->parseArchivedResponse(
                    $response(
                        'HMRC-CT-CT600',
                        'error',
                        'delete',
                        $correlation,
                        '<ResponseEndPoint PollInterval="10">https://test-transaction-engine.tax.service.gov.uk/submission</ResponseEndPoint>',
                        $retryableDeleteError,
                        '<Body/>',
                        $current
                    ),
                    'delete',
                    'TEST',
                    $correlation,
                    $original,
                    $current
                );
                $h->assertFalse((bool)$deleteRetry['success']);
                $h->assertSame('submission_error', $deleteRetry['protocol_state']);
                $h->assertTrue((bool)$deleteRetry['cleanup_required']);
                $h->assertSame(
                    'https://test-transaction-engine.tax.service.gov.uk/submission',
                    $deleteRetry['response_endpoint']
                );
                $h->assertSame(10, $deleteRetry['poll_interval']);

                $previousPoll = 'CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC';
                $retryableGatewayError = '<GovTalkDetails><Keys/><GovTalkErrors><Error>'
                    . '<RaisedBy>Gateway</RaisedBy><Number>5000</Number><Type>fatal</Type>'
                    . '<Text>The follow-on request may be retried.</Text><Location/>'
                    . '</Error></GovTalkErrors></GovTalkDetails>';
                $boundGatewayResponse = static function (string $endpoint) use (
                    $response,
                    $correlation,
                    $retryableGatewayError,
                    $previousPoll
                ): string {
                    return $response(
                        'HMRC-CT-CT600',
                        'error',
                        'submit',
                        $correlation,
                        '<ResponseEndPoint PollInterval="10">' . $endpoint . '</ResponseEndPoint>',
                        $retryableGatewayError,
                        '<Body/>',
                        $previousPoll
                    );
                };
                $boundGateway = $client->parseArchivedResponse(
                    $boundGatewayResponse('https://test-transaction-engine.tax.service.gov.uk/poll'),
                    'poll',
                    'TEST',
                    $correlation,
                    $original,
                    $current,
                    [$previousPoll]
                );
                $h->assertFalse((bool)$boundGateway['success']);
                $h->assertSame('submission_error', $boundGateway['protocol_state']);
                $h->assertSame($current, $boundGateway['transaction_id']);
                $h->assertSame($previousPoll, $boundGateway['response_transaction_id']);
                $h->assertSame(
                    'https://test-transaction-engine.tax.service.gov.uk/poll',
                    $boundGateway['response_endpoint']
                );
                $unboundGateway = $client->parseArchivedResponse(
                    $boundGatewayResponse('https://test-transaction-engine.tax.service.gov.uk/poll'),
                    'poll',
                    'TEST',
                    $correlation,
                    $original,
                    $current
                );
                $h->assertSame('failed', $unboundGateway['protocol_state']);
                $h->assertThrows(
                    static fn(): array => $client->parseArchivedResponse(
                        $boundGatewayResponse('https://test-transaction-engine.tax.service.gov.uk/submission'),
                        'poll',
                        'TEST',
                        $correlation,
                        $original,
                        $current,
                        [$previousPoll]
                    ),
                    InvalidArgumentException::class
                );

                $wrongDeleteTransaction = $client->parseArchivedResponse(
                    str_replace(
                        '<TransactionID>' . $current . '</TransactionID>',
                        '<TransactionID>' . $original . '</TransactionID>',
                        $deleteResponse
                    ),
                    'delete',
                    'TEST',
                    $correlation,
                    $original,
                    $current
                );
                $h->assertSame('failed', $wrongDeleteTransaction['protocol_state']);
            }
        );

        $h->check(
            \eel_accounts\Client\HmrcCtTransactionEngineClient::class,
            'records an unknown submit outcome and never leaks credentials in the error',
            static function () use ($h, $credentials, $transactionId, $body, $recorders, $conversation): void {
                $client = new \eel_accounts\Client\HmrcCtTransactionEngineClient(
                    static function (array $request): array {
                        unset($request);
                        throw new RuntimeException('timeout mentioning LIVE-PASSWORD');
                    },
                    $credentials,
                    $transactionId
                );
                [$beforeSend, $afterReceive] = $recorders();
                $result = $client->submit(
                    $body,
                    '0123456789',
                    'LIVE',
                    $conversation('LIVE', $beforeSend, $afterReceive)
                );
                $h->assertFalse((bool)$result['success']);
                $h->assertTrue((bool)$result['transport_unknown']);
                $h->assertFalse(str_contains((string)$result['error'], 'LIVE-PASSWORD'));
            }
        );

        $h->check(
            \eel_accounts\Client\HmrcCtTransactionEngineClient::class,
            'reprocesses immutable archived responses without requiring current credentials',
            static function () use ($h, $transactionId, $response): void {
                $client = new \eel_accounts\Client\HmrcCtTransactionEngineClient(
                    static fn(array $request): array => [],
                    static function (string $environment): array {
                        unset($environment);
                        throw new RuntimeException('Credentials are no longer available.');
                    },
                    $transactionId
                );
                $archived = $response(
                    'HMRC-CT-CT600',
                    'acknowledgement',
                    'submit',
                    'D3C2B9E5F98449A19863D934273FA052',
                    '<ResponseEndPoint PollInterval="10">https://test-transaction-engine.tax.service.gov.uk/poll</ResponseEndPoint>',
                    '<GovTalkDetails><Keys/></GovTalkDetails>',
                    '<Body/>',
                    ''
                );
                $parsed = $client->parseArchivedResponse(
                    $archived,
                    'submit',
                    'TEST',
                    '',
                    'ABCDEF1234567890',
                    'ABCDEF1234567890'
                );
                $h->assertTrue((bool)$parsed['success']);
                $h->assertSame('acknowledged', $parsed['protocol_state']);
            }
        );

        $h->check(
            \eel_accounts\Client\HmrcCtTransactionEngineClient::class,
            'treats the HMRC 1046 pre-conversation response as a definitive Gateway rejection',
            static function () use ($h, $credentials, $transactionId, $body, $recorders, $conversation): void {
                $fixture = '<?xml version="1.0" encoding="UTF-8"?>'
                    . '<GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope">'
                    . '<EnvelopeVersion>2.0</EnvelopeVersion><Header><MessageDetails>'
                    . '<Class>UndefinedClass</Class><Qualifier>error</Qualifier><Function>submit</Function>'
                    . '<TransactionID></TransactionID><CorrelationID></CorrelationID>'
                    . '<ResponseEndPoint PollInterval="10">https://test-transaction-engine.tax.service.gov.uk/submission</ResponseEndPoint>'
                    . '<GatewayTimestamp>2026-07-31T22:18:53.498</GatewayTimestamp>'
                    . '</MessageDetails><SenderDetails/></Header><GovTalkDetails><Keys/><GovTalkErrors><Error>'
                    . '<RaisedBy>Gateway</RaisedBy><Number>1046</Number><Type>fatal</Type>'
                    . '<Text>Authentication Failure. The supplied user credentials failed validation for the requested service.</Text>'
                    . '<Location/></Error></GovTalkErrors></GovTalkDetails><Body/></GovTalkMessage>';
                $client = new \eel_accounts\Client\HmrcCtTransactionEngineClient(
                    static fn(array $request): array => [
                        'status_code' => 200,
                        'headers' => ['Content-Type' => 'text/xml'],
                        'body' => $fixture,
                    ],
                    $credentials,
                    $transactionId
                );
                [$beforeSend, $afterReceive] = $recorders();
                $result = $client->submit(
                    $body,
                    '0123456789',
                    'TEST',
                    $conversation('TEST', $beforeSend, $afterReceive)
                );

                $h->assertFalse((bool)$result['success']);
                $h->assertSame('gateway_rejected', $result['protocol_state']);
                $h->assertFalse((bool)$result['transport_unknown']);
                $h->assertSame('ABCDEF1234567890', $result['transaction_id']);
                $h->assertSame('', $result['correlation_id']);
                $h->assertFalse((bool)$result['cleanup_required']);
                $error = (array)($result['errors'][0] ?? []);
                $h->assertSame('Gateway', $error['raised_by']);
                $h->assertSame('1046', $error['number']);
                $h->assertSame('fatal', $error['type']);
                $h->assertSame(
                    ['Authentication Failure. The supplied user credentials failed validation for the requested service.'],
                    $error['texts']
                );
                $h->assertSame([], $error['locations']);
            }
        );

        $h->check(
            \eel_accounts\Client\HmrcCtTransactionEngineClient::class,
            'recursively redacts credentials echoed by a parsed GovTalk error',
            static function () use ($h, $credentials, $transactionId, $body, $response, $recorders, $conversation): void {
                $govTalkDetails = '<GovTalkDetails><Keys/><GovTalkErrors><Error>'
                    . '<RaisedBy>Gateway</RaisedBy><Number>5000</Number><Type>fatal</Type>'
                    . '<Text>Rejected LIVE-SENDER using LIVE-PASSWORD.</Text>'
                    . '<Location>LIVE-PASSWORD</Location></Error></GovTalkErrors></GovTalkDetails>';
                $client = new \eel_accounts\Client\HmrcCtTransactionEngineClient(
                    static fn(array $request): array => [
                        'status_code' => 200,
                        'headers' => [],
                        'body' => $response(
                            'UndefinedClass',
                            'error',
                            'submit',
                            '',
                            '<ResponseEndPoint>https://transaction-engine.tax.service.gov.uk/submission</ResponseEndPoint>',
                            $govTalkDetails,
                            '<Body/>',
                            ''
                        ),
                    ],
                    $credentials,
                    $transactionId
                );

                [$beforeSend, $afterReceive] = $recorders();
                $result = $client->submit(
                    $body,
                    '0123456789',
                    'LIVE',
                    $conversation('LIVE', $beforeSend, $afterReceive)
                );
                $h->assertSame('gateway_rejected', $result['protocol_state']);
                $h->assertFalse((bool)$result['transport_unknown']);
                $encodedErrors = json_encode((array)$result['errors'], JSON_THROW_ON_ERROR);
                $h->assertFalse(str_contains((string)$result['error'], 'LIVE-SENDER'));
                $h->assertFalse(str_contains((string)$result['error'], 'LIVE-PASSWORD'));
                $h->assertFalse(str_contains($encodedErrors, 'LIVE-SENDER'));
                $h->assertFalse(str_contains($encodedErrors, 'LIVE-PASSWORD'));
                $h->assertTrue(str_contains($encodedErrors, '[REDACTED]'));
            }
        );

        $h->check(
            \eel_accounts\Client\HmrcCtTransactionEngineClient::class,
            'distinguishes a gateway poll error from a final business rejection',
            static function () use ($h, $credentials, $transactionId, $response, $recorders, $conversation): void {
                $gatewayError = '<GovTalkDetails><Keys/><GovTalkErrors><Error>'
                    . '<RaisedBy>Gateway</RaisedBy><Number>5000</Number><Type>fatal</Type>'
                    . '<Text>Temporary gateway failure.</Text></Error></GovTalkErrors></GovTalkDetails>';
                $client = new \eel_accounts\Client\HmrcCtTransactionEngineClient(
                    static fn(array $request): array => [
                        'status_code' => 200,
                        'headers' => [],
                        'body' => $response(
                            'HMRC-CT-CT600-TIL',
                            'error',
                            'submit',
                            'CAFE1234',
                            '<ResponseEndPoint PollInterval="10">https://transaction-engine.tax.service.gov.uk/poll</ResponseEndPoint>',
                            $gatewayError,
                            '<Body/>',
                            ''
                        ),
                    ],
                    $credentials,
                    $transactionId
                );
                [$beforeSend, $afterReceive] = $recorders();
                $result = $client->poll(
                    'CAFE1234',
                    'https://transaction-engine.tax.service.gov.uk/poll',
                    'TIL',
                    $conversation('TIL', $beforeSend, $afterReceive),
                    'ABCDEF1234567890'
                );
                $h->assertFalse((bool)$result['success']);
                $h->assertSame('submission_error', $result['protocol_state']);
                $h->assertSame(null, $result['business_outcome']);
                $h->assertFalse((bool)$result['cleanup_required']);
            }
        );

        $h->check(
            \eel_accounts\Client\HmrcCtTransactionEngineClient::class,
            'rejects a response from a different GovTalk conversation',
            static function () use ($h, $credentials, $transactionId, $response, $recorders, $conversation): void {
                $client = new \eel_accounts\Client\HmrcCtTransactionEngineClient(
                    static fn(array $request): array => [
                        'status_code' => 200,
                        'headers' => [],
                        'body' => $response(
                            'HMRC-CT-CT600-TIL',
                            'response',
                            'submit',
                            'DEADBEEF',
                            '<ResponseEndPoint PollInterval="10">https://transaction-engine.tax.service.gov.uk/submission</ResponseEndPoint>'
                        ),
                    ],
                    $credentials,
                    $transactionId
                );
                [$beforeSend, $afterReceive] = $recorders();
                $result = $client->poll(
                    'CAFE1234',
                    'https://transaction-engine.tax.service.gov.uk/poll',
                    'TIL',
                    $conversation('TIL', $beforeSend, $afterReceive),
                    'ABCDEF1234567890'
                );
                $h->assertFalse((bool)$result['success']);
                $h->assertSame('failed', $result['protocol_state']);
                $h->assertSame(null, $result['business_outcome']);
                $h->assertTrue(str_contains((string)$result['error'], 'correlation ID'));
            }
        );

        $h->check(
            \eel_accounts\Client\HmrcCtTransactionEngineClient::class,
            'accepts a missing GovTalk response transaction ID but rejects a non-empty mismatch',
            static function () use ($h, $credentials, $transactionId, $response, $recorders, $conversation): void {
                [$beforeSend, $afterReceive] = $recorders();
                $client = new \eel_accounts\Client\HmrcCtTransactionEngineClient(
                    static fn(array $request): array => [
                        'status_code' => 200,
                        'headers' => [],
                        'body' => $response(
                            'HMRC-CT-CT600-TIL',
                            'response',
                            'submit',
                            'CAFE1234',
                            '<ResponseEndPoint PollInterval="10">https://transaction-engine.tax.service.gov.uk/submission</ResponseEndPoint>',
                            '<GovTalkDetails><Keys/></GovTalkDetails>',
                            '<Body/>',
                            ''
                        ),
                    ],
                    $credentials,
                    $transactionId
                );
                $accepted = $client->poll(
                    'CAFE1234',
                    'https://transaction-engine.tax.service.gov.uk/poll',
                    'TIL',
                    $conversation('TIL', $beforeSend, $afterReceive),
                    'ABCDEF1234567890'
                );
                $h->assertTrue((bool)$accepted['success']);
                $h->assertSame('final_response', $accepted['protocol_state']);
                $h->assertSame('ABCDEF1234567890', $accepted['transaction_id']);
                $h->assertSame('', $accepted['response_transaction_id']);

                $mismatchedClient = new \eel_accounts\Client\HmrcCtTransactionEngineClient(
                    static fn(array $request): array => [
                        'status_code' => 200,
                        'headers' => [],
                        'body' => $response(
                            'HMRC-CT-CT600-TIL',
                            'response',
                            'submit',
                            'CAFE1234',
                            '<ResponseEndPoint PollInterval="10">https://transaction-engine.tax.service.gov.uk/submission</ResponseEndPoint>',
                            '<GovTalkDetails><Keys/></GovTalkDetails>',
                            '<Body/>',
                            'DEADBEEF'
                        ),
                    ],
                    $credentials,
                    $transactionId
                );
                $mismatched = $mismatchedClient->poll(
                    'CAFE1234',
                    'https://transaction-engine.tax.service.gov.uk/poll',
                    'TIL',
                    $conversation('TIL', $beforeSend, $afterReceive),
                    'ABCDEF1234567890'
                );
                $h->assertFalse((bool)$mismatched['success']);
                $h->assertSame('failed', $mismatched['protocol_state']);
                $h->assertSame(null, $mismatched['business_outcome']);
                $h->assertTrue(str_contains(strtolower((string)$mismatched['error']), 'transaction id'));
            }
        );

        $h->check(
            \eel_accounts\Client\HmrcCtTransactionEngineClient::class,
            'rejects an HMRC-supplied poll endpoint outside the selected environment',
            static function () use ($h, $credentials, $transactionId, $recorders, $conversation): void {
                $called = false;
                $client = new \eel_accounts\Client\HmrcCtTransactionEngineClient(
                    static function (array $request) use (&$called): array {
                        $called = true;
                        unset($request);
                        return [];
                    },
                    $credentials,
                    $transactionId
                );
                [$beforeSend, $afterReceive] = $recorders();
                $result = $client->poll(
                    'CAFE1234',
                    'https://attacker.example/poll',
                    'LIVE',
                    $conversation('LIVE', $beforeSend, $afterReceive),
                    'ABCDEF1234567890'
                );
                $h->assertFalse((bool)$result['success']);
                $h->assertTrue((bool)$result['pre_send_failure']);
                $h->assertFalse($called);
            }
        );

        $h->check(
            \eel_accounts\Client\HmrcCtTransactionEngineClient::class,
            'requires complete environment-bound acknowledgement and final-response instructions',
            static function () use ($h, $credentials, $transactionId, $response): void {
                $client = new \eel_accounts\Client\HmrcCtTransactionEngineClient(
                    static fn(array $request): array => [],
                    $credentials,
                    $transactionId
                );
                $acknowledgement = static function (string $details, string $correlationId = 'CAFE1234') use ($response): string {
                    return $response(
                        'HMRC-CT-CT600',
                        'acknowledgement',
                        'submit',
                        $correlationId,
                        $details,
                        '<GovTalkDetails><Keys/></GovTalkDetails>',
                        '<Body/>',
                        ''
                    );
                };

                $missingEndpoint = $client->parseArchivedResponse(
                    $acknowledgement(''),
                    'submit',
                    'TEST',
                    '',
                    'ABCDEF1234567890',
                    'ABCDEF1234567890'
                );
                $h->assertFalse((bool)$missingEndpoint['success']);
                $h->assertTrue(str_contains(
                    strtolower((string)$missingEndpoint['error']),
                    'polling endpoint'
                ));

                $missingCorrelation = $client->parseArchivedResponse(
                    $acknowledgement(
                        '<ResponseEndPoint PollInterval="10">https://test-transaction-engine.tax.service.gov.uk/poll</ResponseEndPoint>',
                        ''
                    ),
                    'submit',
                    'TEST',
                    '',
                    'ABCDEF1234567890',
                    'ABCDEF1234567890'
                );
                $h->assertFalse((bool)$missingCorrelation['success']);
                $h->assertTrue(str_contains(
                    strtolower((string)$missingCorrelation['error']),
                    'correlation id'
                ));

                $h->assertThrows(
                    static fn(): array => $client->parseArchivedResponse(
                        $acknowledgement(
                            '<ResponseEndPoint PollInterval="ten">https://test-transaction-engine.tax.service.gov.uk/poll</ResponseEndPoint>'
                        ),
                        'submit',
                        'TEST',
                        '',
                        'ABCDEF1234567890',
                        'ABCDEF1234567890'
                    ),
                    RuntimeException::class
                );
                $h->assertThrows(
                    static fn(): array => $client->parseArchivedResponse(
                        $acknowledgement(
                            '<ResponseEndPoint PollInterval="10">https://transaction-engine.tax.service.gov.uk/poll</ResponseEndPoint>'
                        ),
                        'submit',
                        'TEST',
                        '',
                        'ABCDEF1234567890',
                        'ABCDEF1234567890'
                    ),
                    InvalidArgumentException::class
                );

                $departmentError = '<GovTalkDetails><Keys/><GovTalkErrors><Error>'
                    . '<RaisedBy>Department</RaisedBy><Number>3000</Number><Type>business</Type>'
                    . '<Text>Department validation failed.</Text>'
                    . '</Error></GovTalkErrors></GovTalkDetails>';
                $finalError = static function (string $details) use (
                    $response,
                    $departmentError
                ): string {
                    return $response(
                        'HMRC-CT-CT600',
                        'error',
                        'submit',
                        'CAFE1234',
                        $details,
                        $departmentError,
                        '<Body/>',
                        'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'
                    );
                };
                $missingFollowUp = $client->parseArchivedResponse(
                    $finalError(''),
                    'poll',
                    'TEST',
                    'CAFE1234',
                    'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
                    'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB'
                );
                $h->assertSame('failed', $missingFollowUp['protocol_state']);
                $h->assertTrue(str_contains(
                    strtolower((string)$missingFollowUp['error']),
                    'follow-up endpoint'
                ));
                $missingFollowUpInterval = $client->parseArchivedResponse(
                    $finalError(
                        '<ResponseEndPoint>https://test-transaction-engine.tax.service.gov.uk/submission</ResponseEndPoint>'
                    ),
                    'poll',
                    'TEST',
                    'CAFE1234',
                    'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
                    'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB'
                );
                $h->assertSame('failed', $missingFollowUpInterval['protocol_state']);
                $h->assertTrue(str_contains(
                    strtolower((string)$missingFollowUpInterval['error']),
                    'follow-up interval'
                ));
                $h->assertThrows(
                    static fn(): array => $client->parseArchivedResponse(
                        $finalError(
                            '<ResponseEndPoint PollInterval="10">https://transaction-engine.tax.service.gov.uk/submission</ResponseEndPoint>'
                        ),
                        'poll',
                        'TEST',
                        'CAFE1234',
                        'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
                        'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB'
                    ),
                    InvalidArgumentException::class
                );
            }
        );
    }
);
