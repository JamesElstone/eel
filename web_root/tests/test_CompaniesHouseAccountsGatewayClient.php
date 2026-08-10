<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

function companiesHouseGatewayTestConversation(
    callable $beforeSend,
    callable $afterReceive,
    string $environment = 'TEST'
): \eel_accounts\Client\GovTalkConversationContext {
    return \eel_accounts\Client\GovTalkConversationContext::fromCallbacks(
        'companies_house',
        $environment,
        $beforeSend,
        $afterReceive
    );
}

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Client\CompaniesHouseAccountsGatewayClient::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Client\CompaniesHouseAccountsGatewayClient $unusedClient
    ): void {
        unset($unusedClient);

        $credentials = static fn(string $environment): array => [
            'presenter_id' => $environment . '-PRESENTER',
            'presenter_code' => $environment . '-CODE',
            'package_reference' => $environment === 'TEST' ? '0012' : 'LIVE-PACKAGE',
        ];
        $transactionId = static fn(): string => 'ABCDEF123456';
        $config = [
            'minimum_interval_microseconds' => 0,
            'max_response_bytes' => 65536,
        ];
        $submissionPayload = static fn(): array => [
            'company_number' => '14337285',
            'company_name' => 'ELSTONE ELECTRICALS LIMITED',
            'company_authentication_code' => 'ABC123',
            'submission_number' => '000001',
            'date_signed' => '2026-07-17',
            'accounts_xml' => '<?xml version="1.0"?>'
                . "\n<html>revised accounts</html>",
            'filename' => 'AP79-revised.xml',
            'customer_reference' => 'AP79REVISION',
            'language' => 'EN',
        ];
        $acknowledgement = static function (string $presenterId, string $presenterCode): string {
            return '<?xml version="1.0" encoding="UTF-8"?>'
                . '<GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope">'
                . '<EnvelopeVersion>1.0</EnvelopeVersion><Header><MessageDetails>'
                . '<Class>Accounts</Class><Qualifier>acknowledgement</Qualifier>'
                . '<TransactionID>ABCDEF123456</TransactionID>'
                . '<GatewayTimestamp>2026-07-17T10:00:00Z</GatewayTimestamp>'
                . '</MessageDetails><SenderDetails><IDAuthentication>'
                . '<SenderID>' . $presenterId . '</SenderID><Authentication>'
                . '<Method>CHMD5</Method><Value>' . $presenterCode . '</Value>'
                . '</Authentication></IDAuthentication></SenderDetails></Header>'
                . '<GovTalkDetails><Keys/></GovTalkDetails><Body/></GovTalkMessage>';
        };
        $statusResponse = static function (string $submissionNumber, string $status, string $extra = ''): string {
            return '<?xml version="1.0" encoding="UTF-8"?>'
                . '<GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope">'
                . '<EnvelopeVersion>1.0</EnvelopeVersion><Header><MessageDetails>'
                . '<Class>GetSubmissionStatus</Class><Qualifier>response</Qualifier>'
                . '<TransactionID>ABCDEF123456</TransactionID>'
                . '<GatewayTimestamp>2026-07-17T10:05:00Z</GatewayTimestamp>'
                . '</MessageDetails></Header><GovTalkDetails><Keys/></GovTalkDetails><Body>'
                . '<SubmissionStatus xmlns="http://xmlgw.companieshouse.gov.uk"><Status>'
                . '<SubmissionNumber>' . $submissionNumber . '</SubmissionNumber>'
                . '<StatusCode>' . $status . '</StatusCode><CompanyNumber>14337285</CompanyNumber>'
                . '<CustomerReference>AP79REVISION</CustomerReference>' . $extra
                . '</Status></SubmissionStatus></Body></GovTalkMessage>';
        };
        $xmlText = static function (string $xml, string $localName): string {
            $document = new DOMDocument();
            if (!$document->loadXML($xml, LIBXML_NONET)) {
                throw new RuntimeException('Unable to parse captured test XML.');
            }

            $xpath = new DOMXPath($document);
            $nodes = $xpath->query('//*[local-name()="' . $localName . '"]');
            $node = $nodes === false ? null : $nodes->item(0);

            return $node instanceof DOMNode ? trim($node->textContent) : '';
        };
        $validator = static fn(string $xml, array $inventory): array => [
            'success' => true,
            'files' => $inventory['files'] ?? $inventory,
        ];
        $evidenceReceipt = static function (array $exchange): array {
            $direction = array_key_exists('request_xml', $exchange) ? 'request' : 'response';
            $xml = (string)($exchange[$direction . '_xml'] ?? '');
            $receipt = [
                'transaction_id' => (string)($exchange['transaction_id'] ?? ''),
                $direction . '_sha256' => $xml !== '' ? hash('sha256', $xml) : null,
                $direction . '_bytes' => strlen($xml),
            ];
            if ($direction === 'response') {
                $headers = (array)($exchange['response_headers'] ?? []);
                $headersJson = (new \eel_accounts\Service\CompaniesHouseProtocolMetadataService())
                    ->responseHeadersJson($headers);
                $receipt['response_headers_sha256'] = hash('sha256', $headersJson);
            }

            return $receipt;
        };

        $harness->check(
            \eel_accounts\Client\CompaniesHouseAccountsGatewayClient::class,
            'builds a TEST FormSubmission v2.11 envelope without exposing secrets',
            static function () use (
                $harness,
                $credentials,
                $transactionId,
                $config,
                $submissionPayload,
                $acknowledgement,
                $xmlText,
                $evidenceReceipt
            ): void {
                $captured = [];
                $transport = static function (array $request) use (&$captured, $acknowledgement): array {
                    $captured = $request;

                    return [
                        'status_code' => 200,
                        'headers' => [
                            'X-Request-ID' => ' gateway-123 ',
                            'Content-Type' => 'text/xml',
                            'Set-Cookie' => 'private',
                            'Authorization' => 'private',
                        ],
                        'body' => $acknowledgement(
                            md5('TEST-PRESENTER'),
                            md5('TEST-CODE')
                        ),
                    ];
                };
                $client = new \eel_accounts\Client\CompaniesHouseAccountsGatewayClient(
                    $transport,
                    $credentials,
                    $transactionId,
                    $config,
                    static fn(string $xml, array $inventory): array => [
                        'success' => true,
                        'files' => $inventory['files'] ?? $inventory,
                    ]
                );
                $payload = $submissionPayload();
                $prepared = $client->prepareAccounts($payload, 'TEST', ['files' => []]);
                $capturedResponse = [];
                $result = $client->sendPreparedAccounts(
                    $prepared,
                    companiesHouseGatewayTestConversation(
                        $evidenceReceipt,
                        static function (array $exchange) use (&$capturedResponse, $evidenceReceipt): array {
                            $capturedResponse = $exchange;
                            return $evidenceReceipt($exchange);
                        }
                    )
                );
                $requestXml = (string)$captured['body'];

                $harness->assertSame(true, $result['success']);
                $harness->assertSame(true, $result['acknowledged']);
                $harness->assertSame(false, $result['transport_unknown']);
                $harness->assertSame(
                    'https://xmlgw.companieshouse.gov.uk/v1-0/xmlgw/Gateway',
                    $captured['url']
                );
                $harness->assertSame('POST', $captured['method']);
                $harness->assertSame('text/xml; charset=UTF-8', $captured['headers']['Content-Type']);
                $harness->assertSame('Accounts', $xmlText($requestXml, 'Class'));
                $harness->assertSame('submit', $xmlText($requestXml, 'Function'));
                $harness->assertSame('1', $xmlText($requestXml, 'GatewayTest'));
                $harness->assertSame(md5('TEST-PRESENTER'), $xmlText($requestXml, 'SenderID'));
                $harness->assertSame(md5('TEST-CODE'), $xmlText($requestXml, 'Value'));
                $harness->assertFalse(
                    $xmlText($requestXml, 'SenderID') === md5('TEST-PRESENTER' . 'ABCDEF123456')
                );
                $harness->assertFalse(
                    $xmlText($requestXml, 'Value') === md5('TEST-CODE' . 'ABCDEF123456')
                );
                $harness->assertSame('Accounts', $xmlText($requestXml, 'FormIdentifier'));
                $harness->assertSame('000001', $xmlText($requestXml, 'SubmissionNumber'));
                $harness->assertSame('0012', $xmlText($requestXml, 'PackageReference'));
                $harness->assertSame('ABC123', $xmlText($requestXml, 'CompanyAuthenticationCode'));
                $harness->assertSame('application/xml', $xmlText($requestXml, 'ContentType'));
                $harness->assertSame('ACCOUNTS', $xmlText($requestXml, 'Category'));
                $harness->assertSame(base64_encode($payload['accounts_xml']), $xmlText($requestXml, 'Data'));
                $harness->assertSame(
                    $acknowledgement(md5('TEST-PRESENTER'), md5('TEST-CODE')),
                    (string)($capturedResponse['response_xml'] ?? '')
                );
                $harness->assertSame([
                    'content-type' => 'text/xml',
                    'x-request-id' => 'gateway-123',
                ], (array)($capturedResponse['response_headers'] ?? []));
                $headersJson = (new \eel_accounts\Service\CompaniesHouseProtocolMetadataService())
                    ->responseHeadersJson((array)$capturedResponse['response_headers']);
                $harness->assertSame(
                    hash('sha256', $headersJson),
                    (string)($capturedResponse['response_headers_sha256'] ?? '')
                );
                $harness->assertTrue(str_contains($requestXml, 'FormSubmission-v2-11.xsd'));
                $harness->assertFalse(str_contains($requestXml, 'COMPANY_LOOKUP'));
                foreach (
                    [
                        'TEST-PRESENTER',
                        'TEST-CODE',
                        '0012',
                        'ABC123',
                        base64_encode($payload['accounts_xml']),
                    ] as $secret
                ) {
                    $harness->assertFalse(str_contains((string)$result['request_xml'], $secret));
                    $harness->assertFalse(str_contains((string)$result['response_xml'], $secret));
                }
            }
        );

        $harness->check(
            \eel_accounts\Client\CompaniesHouseAccountsGatewayClient::class,
            'builds the optional CompanyData diagnostic using the shared presenter credentials',
            static function () use (
                $harness,
                $credentials,
                $transactionId,
                $config,
                $xmlText,
                $validator,
                $evidenceReceipt
            ): void {
                $captured = [];
                $response = '<?xml version="1.0"?><GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope">'
                    . '<EnvelopeVersion>1.0</EnvelopeVersion><Header><MessageDetails>'
                    . '<Class>CompanyDataRequest</Class><Qualifier>response</Qualifier>'
                    . '<TransactionID>ABCDEF123456</TransactionID></MessageDetails></Header>'
                    . '<GovTalkDetails><Keys/></GovTalkDetails><Body><CompanyData>'
                    . '<CompanyNumber>14337285</CompanyNumber>'
                    . '<CompanyName>ELSTONE ELECTRICALS LIMITED</CompanyName>'
                    . '</CompanyData></Body></GovTalkMessage>';
                $client = new \eel_accounts\Client\CompaniesHouseAccountsGatewayClient(
                    static function (array $request) use (&$captured, $response): array {
                        $captured = $request;
                        return ['status_code' => 200, 'headers' => [], 'body' => $response];
                    },
                    $credentials,
                    $transactionId,
                    $config,
                    $validator
                );
                $result = $client->checkCompanyAuthentication(
                    '14337285',
                    'ABC123',
                    'TEST',
                    ['files' => []],
                    companiesHouseGatewayTestConversation($evidenceReceipt, $evidenceReceipt)
                );
                $requestXml = (string)$captured['body'];

                $harness->assertSame(true, $result['success']);
                $harness->assertSame(true, $result['authenticated']);
                $harness->assertSame('CompanyDataRequest', $xmlText($requestXml, 'Class'));
                $harness->assertSame('14337285', $xmlText($requestXml, 'CompanyNumber'));
                $harness->assertSame('ABC123', $xmlText($requestXml, 'CompanyAuthenticationCode'));
                $harness->assertSame(md5('TEST-PRESENTER'), $xmlText($requestXml, 'SenderID'));
                $harness->assertTrue(str_contains($requestXml, '/schema/CompanyData-v3-6.xsd'));
                $harness->assertFalse(str_contains($requestXml, '<PackageReference>'));
                $harness->assertFalse(str_contains($requestXml, '0012'));
                $harness->assertFalse(str_contains((string)$result['request_xml'], 'ABC123'));
                $harness->assertFalse(str_contains((string)$result['request_xml'], 'TEST-CODE'));
            }
        );

        $harness->check(
            \eel_accounts\Client\CompaniesHouseAccountsGatewayClient::class,
            'accepts the CompanyData fixture only in TEST mode',
            static function () use (
                $harness,
                $credentials,
                $transactionId,
                $config,
                $validator,
                $evidenceReceipt
            ): void {
                $fixtureResponse = '<?xml version="1.0"?><GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope">'
                    . '<EnvelopeVersion>1.0</EnvelopeVersion><Header><MessageDetails>'
                    . '<Class>CompanyDataRequest</Class><Qualifier>response</Qualifier>'
                    . '<TransactionID>ABCDEF123456</TransactionID></MessageDetails></Header>'
                    . '<GovTalkDetails><Keys/></GovTalkDetails><Body><CompanyData>'
                    . '<CompanyNumber>03176906</CompanyNumber>'
                    . '<CompanyName>MILLENNIUM STADIUM PLC</CompanyName>'
                    . '</CompanyData></Body></GovTalkMessage>';
                $client = new \eel_accounts\Client\CompaniesHouseAccountsGatewayClient(
                    static fn(array $request): array => ['status_code' => 200, 'headers' => [], 'body' => $fixtureResponse],
                    $credentials,
                    $transactionId,
                    $config,
                    $validator
                );
                $testResult = $client->checkCompanyAuthentication(
                    '14337285',
                    'ABC123',
                    'TEST',
                    ['files' => []],
                    companiesHouseGatewayTestConversation($evidenceReceipt, $evidenceReceipt)
                );
                $harness->assertTrue(!empty($testResult['success']));
                $harness->assertTrue(!empty($testResult['authenticated']));
                $harness->assertTrue(!empty($testResult['test_fixture']));

                $liveResult = $client->checkCompanyAuthentication(
                    '14337285',
                    'ABC123',
                    'LIVE',
                    ['files' => []],
                    companiesHouseGatewayTestConversation($evidenceReceipt, $evidenceReceipt)
                );
                $harness->assertFalse(!empty($liveResult['success']));
                $harness->assertFalse(!empty($liveResult['authenticated']));
                $harness->assertSame(
                    'Companies House CompanyData did not return the requested company identity.',
                    (string)$liveResult['error']
                );
            }
        );

        $harness->check(
            \eel_accounts\Client\CompaniesHouseAccountsGatewayClient::class,
            'refuses transport without a matching durable request receipt',
            static function () use (
                $harness,
                $credentials,
                $transactionId,
                $config,
                $submissionPayload,
                $evidenceReceipt
            ): void {
                $transportCalled = false;
                $client = new \eel_accounts\Client\CompaniesHouseAccountsGatewayClient(
                    static function (array $request) use (&$transportCalled): array {
                        $transportCalled = true;
                        return ['status_code' => 200, 'headers' => [], 'body' => '<unexpected/>'];
                    },
                    $credentials,
                    $transactionId,
                    $config,
                    static fn(string $xml, array $inventory): array => ['success' => true]
                );
                $result = $client->sendPreparedAccounts(
                    $client->prepareAccounts($submissionPayload(), 'TEST', ['files' => []]),
                    companiesHouseGatewayTestConversation(
                        static fn(array $exchange): array => [
                            'transaction_id' => (string)$exchange['transaction_id'],
                            'request_sha256' => str_repeat('0', 64),
                            'request_bytes' => (int)$exchange['request_bytes'],
                        ],
                        $evidenceReceipt
                    )
                );

                $harness->assertSame(false, $transportCalled);
                $harness->assertSame(false, $result['success']);
                $harness->assertSame(true, $result['pre_send_failure']);
                $harness->assertSame(false, $result['transport_unknown']);
            }
        );

        $harness->check(
            \eel_accounts\Client\CompaniesHouseAccountsGatewayClient::class,
            'stops before parsing when an exact response cannot be archived',
            static function () use (
                $harness,
                $credentials,
                $transactionId,
                $config,
                $submissionPayload,
                $acknowledgement,
                $evidenceReceipt
            ): void {
                $client = new \eel_accounts\Client\CompaniesHouseAccountsGatewayClient(
                    static fn(array $request): array => [
                        'status_code' => 200,
                        'headers' => [],
                        'body' => $acknowledgement(
                            md5('TEST-PRESENTER'),
                            md5('TEST-CODE')
                        ),
                    ],
                    $credentials,
                    $transactionId,
                    $config,
                    static fn(string $xml, array $inventory): array => ['success' => true]
                );
                $result = $client->sendPreparedAccounts(
                    $client->prepareAccounts($submissionPayload(), 'TEST', ['files' => []]),
                    companiesHouseGatewayTestConversation(
                        $evidenceReceipt,
                        static function (array $exchange): array {
                            throw new RuntimeException('Archive unavailable.');
                        }
                    )
                );

                $harness->assertSame(false, $result['success']);
                $harness->assertSame(true, $result['transport_unknown']);
                $harness->assertSame(true, $result['evidence_incomplete']);
                $harness->assertTrue(str_contains($result['error'], 'private transmission archive'));
            }
        );

        $harness->check(
            \eel_accounts\Client\CompaniesHouseAccountsGatewayClient::class,
            'treats invalid response-header evidence as an incomplete exchange',
            static function () use (
                $harness,
                $credentials,
                $transactionId,
                $config,
                $submissionPayload,
                $acknowledgement,
                $evidenceReceipt
            ): void {
                $responseCaptureCalled = false;
                $client = new \eel_accounts\Client\CompaniesHouseAccountsGatewayClient(
                    static fn(array $request): array => [
                        'status_code' => 200,
                        'headers' => ['x-invalid' => "value\ninjected"],
                        'body' => $acknowledgement(
                            md5('TEST-PRESENTER'),
                            md5('TEST-CODE')
                        ),
                    ],
                    $credentials,
                    $transactionId,
                    $config,
                    static fn(string $xml, array $inventory): array => ['success' => true]
                );
                $result = $client->sendPreparedAccounts(
                    $client->prepareAccounts($submissionPayload(), 'TEST', ['files' => []]),
                    companiesHouseGatewayTestConversation(
                        $evidenceReceipt,
                        static function (array $exchange) use (&$responseCaptureCalled): array {
                            $responseCaptureCalled = true;
                            return [];
                        }
                    )
                );

                $harness->assertFalse($responseCaptureCalled);
                $harness->assertFalse((bool)$result['success']);
                $harness->assertTrue((bool)$result['transport_unknown']);
                $harness->assertTrue((bool)$result['evidence_incomplete']);
            }
        );

        $harness->check(
            \eel_accounts\Client\CompaniesHouseAccountsGatewayClient::class,
            'builds the empty StatusAck and decodes GetDocument PDF evidence',
            static function () use (
                $harness,
                $credentials,
                $transactionId,
                $config,
                $xmlText,
                $validator,
                $evidenceReceipt
            ): void {
                $requests = [];
                $pdf = "%PDF-1.4\nmock filing\n%%EOF";
                $transport = static function (array $request) use (&$requests, $pdf): array {
                    $requests[] = (string)$request['body'];
                    $document = new DOMDocument();
                    $document->loadXML((string)$request['body'], LIBXML_NONET);
                    $xpath = new DOMXPath($document);
                    $class = trim((string)$xpath->evaluate('string(//*[local-name()="Class"][1])'));
                    $body = $class === 'GetDocument'
                        ? '<Document><CompanyNumber>14337285</CompanyNumber><DocumentDate>2026-07-23</DocumentDate>'
                            . '<DocumentType>ACCOUNTS</DocumentType><DocumentID>DOC-1</DocumentID>'
                            . '<DocumentData>' . base64_encode($pdf) . '</DocumentData></Document>'
                        : '';
                    return [
                        'status_code' => 200,
                        'headers' => [],
                        'body' => '<?xml version="1.0"?><GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope">'
                            . '<EnvelopeVersion>1.0</EnvelopeVersion><Header><MessageDetails><Class>'
                            . $class . '</Class><Qualifier>acknowledgement</Qualifier>'
                            . '<TransactionID>ABCDEF123456</TransactionID></MessageDetails></Header>'
                            . '<GovTalkDetails><Keys/></GovTalkDetails><Body>' . $body
                            . '</Body></GovTalkMessage>',
                    ];
                };
                $client = new \eel_accounts\Client\CompaniesHouseAccountsGatewayClient(
                    $transport,
                    $credentials,
                    $transactionId,
                    $config,
                    $validator
                );
                $ack = $client->acknowledgeSubmissionStatus(
                    'TEST',
                    ['files' => []],
                    companiesHouseGatewayTestConversation($evidenceReceipt, $evidenceReceipt)
                );
                $document = $client->getDocument(
                    'DOC-KEY-1',
                    'TEST',
                    ['files' => []],
                    companiesHouseGatewayTestConversation($evidenceReceipt, $evidenceReceipt)
                );

                $harness->assertSame(true, $ack['success']);
                $harness->assertSame('StatusAck', $xmlText($requests[0], 'Class'));
                $harness->assertSame('', $xmlText($requests[0], 'StatusAck'));
                $harness->assertSame(true, $document['success']);
                $harness->assertSame($pdf, $document['document_data']);
                $harness->assertSame(hash('sha256', $pdf), $document['document_sha256']);
                $harness->assertSame('DOC-KEY-1', $xmlText($requests[1], 'DocRequestKey'));
            }
        );

        $harness->check(
            \eel_accounts\Client\CompaniesHouseAccountsGatewayClient::class,
            'uses LIVE only when explicitly selected and builds GetSubmissionStatus v2.9',
            static function () use (
                $harness,
                $credentials,
                $transactionId,
                $config,
                $statusResponse,
                $xmlText,
                $evidenceReceipt
            ): void {
                $captured = [];
                $transport = static function (array $request) use (&$captured, $statusResponse): array {
                    $captured = $request;

                    return [
                        'status_code' => 200,
                        'headers' => [],
                        'body' => $statusResponse('LIVE-PRESENTER-000001', 'PENDING'),
                    ];
                };
                $client = new \eel_accounts\Client\CompaniesHouseAccountsGatewayClient(
                    $transport,
                    $credentials,
                    $transactionId,
                    $config
                );
                $capturedRequest = [];
                $capturedExchange = [];
                $result = $client->getSubmissionStatus(
                    '000001',
                    'LIVE',
                    companiesHouseGatewayTestConversation(
                        static function (array $exchange) use (&$capturedRequest, $evidenceReceipt): array {
                            $capturedRequest = $exchange;
                            return $evidenceReceipt($exchange);
                        },
                        static function (array $exchange) use (&$capturedExchange, $evidenceReceipt): array {
                            $capturedExchange = $exchange;
                            return $evidenceReceipt($exchange);
                        },
                        'LIVE'
                    )
                );
                $requestXml = (string)$captured['body'];

                $harness->assertSame(true, $result['success']);
                $harness->assertSame('PENDING', $result['submission_status']);
                $harness->assertSame('pending', $result['normalized_status']);
                $harness->assertSame(
                    'LIVE-PRESENTER-000001',
                    $result['statuses'][0]['submission_number']
                );
                $harness->assertSame('GetSubmissionStatus', $xmlText($requestXml, 'Class'));
                $harness->assertSame('0', $xmlText($requestXml, 'GatewayTest'));
                $harness->assertSame('000001', $xmlText($requestXml, 'SubmissionNumber'));
                $harness->assertSame('LIVE-PRESENTER', $xmlText($requestXml, 'PresenterID'));
                $harness->assertTrue(str_contains($requestXml, 'GetSubmissionStatus-v2-9.xsd'));
                $harness->assertSame($requestXml, (string)($capturedRequest['request_xml'] ?? ''));
                $harness->assertSame(
                    $statusResponse('LIVE-PRESENTER-000001', 'PENDING'),
                    (string)($capturedExchange['response_xml'] ?? '')
                );
                $harness->assertFalse(str_contains((string)$result['request_xml'], 'LIVE-PRESENTER'));
                $harness->assertFalse(str_contains((string)$result['request_xml'], 'LIVE-CODE'));
                $harness->assertFalse(str_contains((string)$result['request_xml'], 'LIVE-PACKAGE'));
            }
        );

        $harness->check(
            \eel_accounts\Client\CompaniesHouseAccountsGatewayClient::class,
            'does not accept a status qualified by another presenter ID',
            static function () use (
                $harness,
                $credentials,
                $transactionId,
                $config,
                $statusResponse,
                $evidenceReceipt
            ): void {
                $client = new \eel_accounts\Client\CompaniesHouseAccountsGatewayClient(
                    static fn(array $request): array => [
                        'status_code' => 200,
                        'headers' => [],
                        'body' => $statusResponse('OTHER-PRESENTER-000001', 'ACCEPT'),
                    ],
                    $credentials,
                    $transactionId,
                    $config
                );
                $result = $client->getSubmissionStatus(
                    '000001',
                    'LIVE',
                    companiesHouseGatewayTestConversation(
                        $evidenceReceipt,
                        $evidenceReceipt,
                        'LIVE'
                    )
                );

                $harness->assertSame(false, $result['success']);
                $harness->assertSame(
                    'Companies House XML Gateway returned no status for submission 000001.',
                    $result['error']
                );
            }
        );

        $harness->check(
            \eel_accounts\Client\CompaniesHouseAccountsGatewayClient::class,
            'normalizes every documented submission status',
            static function () use (
                $harness,
                $credentials,
                $transactionId,
                $config,
                $statusResponse,
                $evidenceReceipt
            ): void {
                $expected = [
                    'ACCEPT' => 'accepted',
                    'REJECT' => 'rejected',
                    'PENDING' => 'pending',
                    'PARKED' => 'parked',
                    'INTERNAL_FAILURE' => 'internal_failure',
                ];

                foreach ($expected as $raw => $normalized) {
                    $transport = static fn(array $request): array => [
                        'status_code' => 200,
                        'headers' => [],
                        'body' => $statusResponse('000001', $raw),
                    ];
                    $client = new \eel_accounts\Client\CompaniesHouseAccountsGatewayClient(
                        $transport,
                        $credentials,
                        $transactionId,
                        $config
                    );
                    $result = $client->getSubmissionStatus(
                        '000001',
                        'TEST',
                        companiesHouseGatewayTestConversation($evidenceReceipt, $evidenceReceipt)
                    );

                    $harness->assertSame(true, $result['success']);
                    $harness->assertSame($raw, $result['submission_status']);
                    $harness->assertSame($normalized, $result['normalized_status']);
                    $harness->assertSame($raw === 'ACCEPT', $result['accepted']);
                }
            }
        );

        $harness->check(
            \eel_accounts\Client\CompaniesHouseAccountsGatewayClient::class,
            'parses rejection reasons and examiner comments for the requested submission only',
            static function () use (
                $harness,
                $credentials,
                $transactionId,
                $config,
                $evidenceReceipt
            ): void {
                $body = '<?xml version="1.0"?><GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope">'
                    . '<EnvelopeVersion>1.0</EnvelopeVersion><Header><MessageDetails>'
                    . '<Class>GetSubmissionStatus</Class><Qualifier>response</Qualifier>'
                    . '</MessageDetails></Header><GovTalkDetails><Keys/></GovTalkDetails><Body>'
                    . '<SubmissionStatus xmlns="http://xmlgw.companieshouse.gov.uk">'
                    . '<Status><SubmissionNumber>OTHER1</SubmissionNumber><StatusCode>ACCEPT</StatusCode></Status>'
                    . '<Status><SubmissionNumber>000001</SubmissionNumber><StatusCode>REJECT</StatusCode>'
                    . '<CompanyNumber>14337285</CompanyNumber><Rejections>'
                    . '<Reject><RejectCode>9999</RejectCode><Description>First failure</Description>'
                    . '<InstanceNumber>1</InstanceNumber></Reject>'
                    . '<Reject><RejectCode>8888</RejectCode><Description>Second failure</Description></Reject>'
                    . '</Rejections><Examiner><Telephone>0300 123 4500</Telephone>'
                    . '<Comment>Correct the revised accounts facts.</Comment></Examiner></Status>'
                    . '</SubmissionStatus></Body></GovTalkMessage>';
                $client = new \eel_accounts\Client\CompaniesHouseAccountsGatewayClient(
                    static fn(array $request): array => [
                        'status_code' => 200,
                        'headers' => [],
                        'body' => $body,
                    ],
                    $credentials,
                    $transactionId,
                    $config
                );
                $result = $client->getSubmissionStatus(
                    '000001',
                    'TEST',
                    companiesHouseGatewayTestConversation($evidenceReceipt, $evidenceReceipt)
                );

                $harness->assertSame(true, $result['success']);
                $harness->assertSame('REJECT', $result['submission_status']);
                $harness->assertCount(1, $result['statuses']);
                $harness->assertCount(2, $result['rejections']);
                $harness->assertSame('9999', $result['rejections'][0]['code']);
                $harness->assertSame('First failure', $result['rejections'][0]['description']);
                $harness->assertSame('1', $result['rejections'][0]['instance_number']);
                $harness->assertSame(
                    'Correct the revised accounts facts.',
                    $result['examiner']['comment']
                );
            }
        );

        $harness->check(
            \eel_accounts\Client\CompaniesHouseAccountsGatewayClient::class,
            'returns synchronous GovTalk errors as a known rejection',
            static function () use (
                $harness,
                $credentials,
                $transactionId,
                $config,
                $submissionPayload,
                $evidenceReceipt
            ): void {
                $body = '<?xml version="1.0"?><GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope">'
                    . '<EnvelopeVersion>1.0</EnvelopeVersion><Header><MessageDetails>'
                    . '<Class>Accounts</Class><Qualifier>error</Qualifier></MessageDetails></Header>'
                    . '<GovTalkDetails><Keys/><GovTalkErrors><Error><RaisedBy>Accounts</RaisedBy>'
                    . '<Number>502</Number><Type>fatal</Type><Text>Authorisation Failure</Text>'
                    . '<Location>Header</Location></Error></GovTalkErrors></GovTalkDetails><Body/>'
                    . '</GovTalkMessage>';
                $client = new \eel_accounts\Client\CompaniesHouseAccountsGatewayClient(
                    static fn(array $request): array => [
                        'status_code' => 200,
                        'headers' => [],
                        'body' => $body,
                    ],
                    $credentials,
                    $transactionId,
                    $config,
                    static fn(string $xml, array $inventory): array => [
                        'success' => true,
                        'files' => $inventory['files'] ?? $inventory,
                    ]
                );
                $result = $client->sendPreparedAccounts(
                    $client->prepareAccounts($submissionPayload(), 'TEST', ['files' => []]),
                    companiesHouseGatewayTestConversation($evidenceReceipt, $evidenceReceipt)
                );

                $harness->assertSame(false, $result['success']);
                $harness->assertSame(false, $result['transport_unknown']);
                $harness->assertSame('502', $result['gateway_errors'][0]['number']);
                $harness->assertSame(['Authorisation Failure'], $result['gateway_errors'][0]['texts']);
                $harness->assertTrue(str_contains($result['error'], 'Authorisation Failure'));
            }
        );

        $harness->check(
            \eel_accounts\Client\CompaniesHouseAccountsGatewayClient::class,
            'fails closed for an invalid environment before calling the transport',
            static function () use (
                $harness,
                $credentials,
                $transactionId,
                $config,
                $submissionPayload,
                $evidenceReceipt
            ): void {
                $called = false;
                $transport = static function (array $request) use (&$called): array {
                    $called = true;
                    throw new RuntimeException('Transport should not have been called.');
                };
                $client = new \eel_accounts\Client\CompaniesHouseAccountsGatewayClient(
                    $transport,
                    $credentials,
                    $transactionId,
                    $config,
                    static fn(string $xml, array $inventory): array => [
                        'success' => true,
                        'files' => $inventory['files'] ?? $inventory,
                    ]
                );
                try {
                    $client->prepareAccounts($submissionPayload(), 'DISABLED', ['files' => []]);
                    $harness->assertTrue(false, 'Invalid environment should throw before transport.');
                } catch (InvalidArgumentException $exception) {
                    $harness->assertTrue(str_contains($exception->getMessage(), 'TEST or LIVE'));
                }
                $harness->assertSame(false, $called);
            }
        );

        $harness->check(
            \eel_accounts\Client\CompaniesHouseAccountsGatewayClient::class,
            'rejects a legacy accounts declaration before schema validation or transport',
            static function () use (
                $harness,
                $credentials,
                $transactionId,
                $config,
                $submissionPayload,
                $evidenceReceipt
            ): void {
                $transportCalls = 0;
                $validatorCalls = 0;
                $client = new \eel_accounts\Client\CompaniesHouseAccountsGatewayClient(
                    static function (array $request) use (&$transportCalls): array {
                        $transportCalls++;
                        return ['status_code'=>500, 'headers'=>[], 'body'=>''];
                    },
                    $credentials,
                    $transactionId,
                    $config,
                    static function (string $xml, array $inventory) use (&$validatorCalls): array {
                        $validatorCalls++;
                        return ['success' => true, 'files' => $inventory['files'] ?? $inventory];
                    }
                );
                $payload = $submissionPayload();
                $payload['accounts_xml'] = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<html/>";
                try {
                    $client->prepareAccounts($payload, 'TEST', ['files' => []]);
                    $harness->assertTrue(false, 'Legacy declaration should be rejected.');
                } catch (InvalidArgumentException $exception) {
                    $harness->assertTrue(str_contains($exception->getMessage(), 'regenerate'));
                }
                $harness->assertSame(0, $validatorCalls);
                $harness->assertSame(0, $transportCalls);
            }
        );

        $harness->check(
            \eel_accounts\Client\CompaniesHouseAccountsGatewayClient::class,
            'marks ambiguous submission transport failures without leaking credentials',
            static function () use (
                $harness,
                $credentials,
                $transactionId,
                $config,
                $submissionPayload,
                $evidenceReceipt
            ): void {
                $client = new \eel_accounts\Client\CompaniesHouseAccountsGatewayClient(
                    static function (array $request): array {
                        throw new RuntimeException('Connection closed after sending TEST-CODE for ABC123.');
                    },
                    $credentials,
                    $transactionId,
                    $config,
                    static fn(string $xml, array $inventory): array => [
                        'success' => true,
                        'files' => $inventory['files'] ?? $inventory,
                    ]
                );
                $result = $client->sendPreparedAccounts(
                    $client->prepareAccounts($submissionPayload(), 'TEST', ['files' => []]),
                    companiesHouseGatewayTestConversation($evidenceReceipt, $evidenceReceipt)
                );

                $harness->assertSame(false, $result['success']);
                $harness->assertSame(true, $result['transport_unknown']);
                $harness->assertFalse(str_contains($result['error'], 'TEST-CODE'));
                $harness->assertFalse(str_contains($result['error'], 'ABC123'));
            }
        );

        $harness->check(
            \eel_accounts\Client\CompaniesHouseAccountsGatewayClient::class,
            'rejects malformed, prohibited, oversized, and unknown status responses',
            static function () use (
                $harness,
                $credentials,
                $transactionId,
                $statusResponse,
                $evidenceReceipt
            ): void {
                $responses = [
                    '<not-closed',
                    '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY secret SYSTEM "file:///etc/passwd">]><x/>',
                    str_repeat('x', 129),
                    $statusResponse('000001', 'NEW_STATUS'),
                ];
                $errors = ['malformed XML', 'prohibited document type', 'size limit', 'unsupported submission status'];

                foreach ($responses as $index => $body) {
                    $client = new \eel_accounts\Client\CompaniesHouseAccountsGatewayClient(
                        static fn(array $request): array => [
                            'status_code' => 200,
                            'headers' => [],
                            'body' => $body,
                        ],
                        $credentials,
                        $transactionId,
                        [
                            'minimum_interval_microseconds' => 0,
                            'max_response_bytes' => $index === 2 ? 128 : 65536,
                        ]
                    );
                    $result = $client->getSubmissionStatus(
                        '000001',
                        'TEST',
                        companiesHouseGatewayTestConversation($evidenceReceipt, $evidenceReceipt)
                    );

                    $harness->assertSame(false, $result['success']);
                    $harness->assertTrue(str_contains($result['error'], $errors[$index]));
                }
            }
        );
    }
);
