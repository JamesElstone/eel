<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

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
            'submission_number' => 'AP7901',
            'date_signed' => '2026-07-17',
            'accounts_xml' => '<?xml version="1.0"?>' . "\n<html>revised accounts</html>",
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
                $xmlText
            ): void {
                $captured = [];
                $transport = static function (array $request) use (&$captured, $acknowledgement): array {
                    $captured = $request;

                    return [
                        'status_code' => 200,
                        'headers' => ['content-type' => 'text/xml'],
                        'body' => $acknowledgement(
                            'md5#' . md5('TEST-PRESENTER'),
                            'md5#' . md5('TEST-CODE')
                        ),
                    ];
                };
                $client = new \eel_accounts\Client\CompaniesHouseAccountsGatewayClient(
                    $transport,
                    $credentials,
                    $transactionId,
                    $config,
                    static fn(string $xml, string $manifest): array => ['success'=>true,'snapshot_id'=>7,'manifest_sha256'=>$manifest]
                );
                $payload = $submissionPayload();
                $prepared = $client->prepareAccounts($payload, 'TEST', str_repeat('a', 64));
                $result = $client->sendPreparedAccounts($prepared);
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
                $harness->assertSame('md5#' . md5('TEST-PRESENTER'), $xmlText($requestXml, 'SenderID'));
                $harness->assertSame('md5#' . md5('TEST-CODE'), $xmlText($requestXml, 'Value'));
                $harness->assertSame('Accounts', $xmlText($requestXml, 'FormIdentifier'));
                $harness->assertSame('AP7901', $xmlText($requestXml, 'SubmissionNumber'));
                $harness->assertSame('0012', $xmlText($requestXml, 'PackageReference'));
                $harness->assertSame('ABC123', $xmlText($requestXml, 'CompanyAuthenticationCode'));
                $harness->assertSame('application/xml', $xmlText($requestXml, 'ContentType'));
                $harness->assertSame('ACCOUNTS', $xmlText($requestXml, 'Category'));
                $harness->assertSame(base64_encode($payload['accounts_xml']), $xmlText($requestXml, 'Data'));
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
            'uses LIVE only when explicitly selected and builds GetSubmissionStatus v2.9',
            static function () use (
                $harness,
                $credentials,
                $transactionId,
                $config,
                $statusResponse,
                $xmlText
            ): void {
                $captured = [];
                $transport = static function (array $request) use (&$captured, $statusResponse): array {
                    $captured = $request;

                    return [
                        'status_code' => 200,
                        'headers' => [],
                        'body' => $statusResponse('AP7901', 'PENDING'),
                    ];
                };
                $client = new \eel_accounts\Client\CompaniesHouseAccountsGatewayClient(
                    $transport,
                    $credentials,
                    $transactionId,
                    $config
                );
                $result = $client->getSubmissionStatus('AP7901', 'LIVE');
                $requestXml = (string)$captured['body'];

                $harness->assertSame(true, $result['success']);
                $harness->assertSame('PENDING', $result['submission_status']);
                $harness->assertSame('pending', $result['normalized_status']);
                $harness->assertSame('GetSubmissionStatus', $xmlText($requestXml, 'Class'));
                $harness->assertSame('0', $xmlText($requestXml, 'GatewayTest'));
                $harness->assertSame('AP7901', $xmlText($requestXml, 'SubmissionNumber'));
                $harness->assertSame('LIVE-PRESENTER', $xmlText($requestXml, 'PresenterID'));
                $harness->assertTrue(str_contains($requestXml, 'GetSubmissionStatus-v2-9.xsd'));
                $harness->assertFalse(str_contains((string)$result['request_xml'], 'LIVE-PRESENTER'));
                $harness->assertFalse(str_contains((string)$result['request_xml'], 'LIVE-CODE'));
                $harness->assertFalse(str_contains((string)$result['request_xml'], 'LIVE-PACKAGE'));
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
                $statusResponse
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
                        'body' => $statusResponse('AP7901', $raw),
                    ];
                    $client = new \eel_accounts\Client\CompaniesHouseAccountsGatewayClient(
                        $transport,
                        $credentials,
                        $transactionId,
                        $config
                    );
                    $result = $client->getSubmissionStatus('AP7901', 'TEST');

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
            static function () use ($harness, $credentials, $transactionId, $config): void {
                $body = '<?xml version="1.0"?><GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope">'
                    . '<EnvelopeVersion>1.0</EnvelopeVersion><Header><MessageDetails>'
                    . '<Class>GetSubmissionStatus</Class><Qualifier>response</Qualifier>'
                    . '</MessageDetails></Header><GovTalkDetails><Keys/></GovTalkDetails><Body>'
                    . '<SubmissionStatus xmlns="http://xmlgw.companieshouse.gov.uk">'
                    . '<Status><SubmissionNumber>OTHER1</SubmissionNumber><StatusCode>ACCEPT</StatusCode></Status>'
                    . '<Status><SubmissionNumber>AP7901</SubmissionNumber><StatusCode>REJECT</StatusCode>'
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
                $result = $client->getSubmissionStatus('AP7901', 'TEST');

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
                $submissionPayload
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
                    static fn(string $xml, string $manifest): array => ['success'=>true,'snapshot_id'=>7,'manifest_sha256'=>$manifest]
                );
                $result = $client->sendPreparedAccounts($client->prepareAccounts($submissionPayload(), 'TEST', str_repeat('a', 64)));

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
                $submissionPayload
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
                    static fn(string $xml, string $manifest): array => ['success'=>true,'snapshot_id'=>7,'manifest_sha256'=>$manifest]
                );
                try {
                    $client->prepareAccounts($submissionPayload(), 'DISABLED', str_repeat('a', 64));
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
                $submissionPayload
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
                    static function (string $xml, string $manifest) use (&$validatorCalls): array {
                        $validatorCalls++;
                        return ['success'=>true,'snapshot_id'=>7,'manifest_sha256'=>$manifest];
                    }
                );
                $payload = $submissionPayload();
                $payload['accounts_xml'] = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<html/>";
                try {
                    $client->prepareAccounts($payload, 'TEST', str_repeat('a', 64));
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
                $submissionPayload
            ): void {
                $client = new \eel_accounts\Client\CompaniesHouseAccountsGatewayClient(
                    static function (array $request): array {
                        throw new RuntimeException('Connection closed after sending TEST-CODE for ABC123.');
                    },
                    $credentials,
                    $transactionId,
                    $config,
                    static fn(string $xml, string $manifest): array => ['success'=>true,'snapshot_id'=>7,'manifest_sha256'=>$manifest]
                );
                $result = $client->sendPreparedAccounts($client->prepareAccounts($submissionPayload(), 'TEST', str_repeat('a', 64)));

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
                $statusResponse
            ): void {
                $responses = [
                    '<not-closed',
                    '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY secret SYSTEM "file:///etc/passwd">]><x/>',
                    str_repeat('x', 129),
                    $statusResponse('AP7901', 'NEW_STATUS'),
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
                    $result = $client->getSubmissionStatus('AP7901', 'TEST');

                    $harness->assertSame(false, $result['success']);
                    $harness->assertTrue(str_contains($result['error'], $errors[$index]));
                }
            }
        );
    }
);
