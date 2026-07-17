<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

function test_hmrc_ct_credentials(string $environment): array
{
    return [
        'sender_id' => $environment === 'TEST' ? 'TEST-SENDER-123' : 'LIVE-SENDER-456',
        'password' => $environment === 'TEST' ? 'test-password-secret' : 'live-password-secret',
        'vendor_id' => '6000',
        'product' => 'EEL Accounts Test',
        'version' => '1.0',
        'email' => 'developer@example.test',
    ];
}

function test_hmrc_ct_body(): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<IRenvelope xmlns="http://www.govtalk.gov.uk/taxation/CT/5">'
        . '<IRheader><Keys><Key Type="UTR">0123456789</Key></Keys>'
        . '<IRmark Type="generic"></IRmark></IRheader>'
        . '<CompanyTaxReturn><Value>business-value-must-remain</Value></CompanyTaxReturn>'
        . '</IRenvelope>';
}

function test_hmrc_ct_response(
    string $class,
    string $qualifier,
    string $function,
    string $correlationId = '',
    string $body = '',
    string $responseEndpoint = '',
    int $pollInterval = 10,
    string $transactionId = 'ABCDEF0123456789',
    string $govTalkErrors = ''
): string {
    $endpoint = htmlspecialchars($responseEndpoint, ENT_XML1 | ENT_QUOTES, 'UTF-8');

    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope">'
        . '<EnvelopeVersion>2.0</EnvelopeVersion><Header><MessageDetails>'
        . '<Class>' . $class . '</Class><Qualifier>' . $qualifier . '</Qualifier>'
        . '<Function>' . $function . '</Function><TransactionID>' . $transactionId . '</TransactionID>'
        . '<CorrelationID>' . $correlationId . '</CorrelationID>'
        . '<ResponseEndPoint PollInterval="' . $pollInterval . '">' . $endpoint . '</ResponseEndPoint>'
        . '<Transformation>XML</Transformation><GatewayTimestamp>2026-07-17T10:00:00.000</GatewayTimestamp>'
        . '</MessageDetails><SenderDetails/></Header><GovTalkDetails><Keys/>'
        . $govTalkErrors . '</GovTalkDetails>'
        . '<Body>' . $body . '</Body></GovTalkMessage>';
}

function test_hmrc_ct_client(callable $transport, ?callable $credentialLoader = null): \eel_accounts\Client\HmrcCtGatewayClient
{
    return new \eel_accounts\Client\HmrcCtGatewayClient(
        $transport,
        $credentialLoader ?? static fn(string $environment): array => test_hmrc_ct_credentials($environment),
        static fn(): string => '0123456789ABCDEF0123456789ABCDEF',
        ['minimum_poll_interval' => 2]
    );
}

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Client\HmrcCtGatewayClient::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $harness->check(
            \eel_accounts\Client\HmrcCtGatewayClient::class,
            'uses a closed TEST TIL LIVE environment map without exposing credentials',
            static function () use ($harness): void {
                $loadedEnvironment = '';
                $client = test_hmrc_ct_client(
                    static fn(array $request): array => [],
                    static function (string $environment) use (&$loadedEnvironment): array {
                        $loadedEnvironment = $environment;

                        return test_hmrc_ct_credentials($environment);
                    }
                );
                $status = $client->configurationStatus('TIL');

                $harness->assertSame(true, $status['ready']);
                $harness->assertSame('LIVE', $loadedEnvironment);
                $harness->assertSame('HMRC-CT-CT600-TIL', $status['class']);
                $harness->assertSame('0', $status['gateway_test']);
                $harness->assertSame(false, $status['statutory']);
                $encoded = json_encode($status);
                $harness->assertTrue(is_string($encoded));
                $harness->assertFalse(str_contains((string)$encoded, 'live-password-secret'));

                $invalid = $client->configurationStatus('STAGING');
                $harness->assertSame(false, $invalid['ready']);
                $harness->assertTrue(str_contains($invalid['blockers'][0], 'TEST, TIL or LIVE'));
            }
        );

        $harness->check(
            \eel_accounts\Client\HmrcCtGatewayClient::class,
            'submits an IRmarked CT5 body and persists only a credential-redacted request',
            static function () use ($harness): void {
                $captured = [];
                $transport = static function (array $request) use (&$captured): array {
                    $captured = $request;

                    return [
                        'status_code' => 200,
                        'headers' => [
                            'content-type' => 'text/xml',
                            'set-cookie' => 'secret-cookie',
                        ],
                        'body' => test_hmrc_ct_response(
                            'HMRC-CT-CT600',
                            'acknowledgement',
                            'submit',
                            'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
                            '',
                            'https://test-transaction-engine.tax.service.gov.uk/poll',
                            1
                        ),
                    ];
                };
                $client = test_hmrc_ct_client($transport);
                $result = $client->submit(
                    test_hmrc_ct_body(),
                    '0123456789',
                    'TEST',
                    '11111111111111111111111111111111'
                );

                $harness->assertSame(true, $result['success']);
                $harness->assertSame('acknowledged', $result['protocol_state']);
                $harness->assertSame(null, $result['business_outcome']);
                $harness->assertSame(2, $result['poll_interval']);
                $harness->assertTrue(trim((string)$result['irmark']) !== '');
                $harness->assertTrue(str_contains($captured['url'], 'test-transaction-engine'));
                $harness->assertTrue(str_contains($captured['body'], '<Class>HMRC-CT-CT600</Class>'));
                $harness->assertTrue(str_contains($captured['body'], '<GatewayTest>1</GatewayTest>'));
                $harness->assertTrue(str_contains($captured['body'], '<Key Type="UTR">0123456789</Key>'));
                $harness->assertTrue(str_contains($captured['body'], '<URI>6000</URI>'));
                $harness->assertTrue(str_contains($captured['body'], 'test-password-secret'));
                $harness->assertFalse(str_contains($result['request_xml'], 'TEST-SENDER-123'));
                $harness->assertFalse(str_contains($result['request_xml'], 'test-password-secret'));
                $harness->assertTrue(str_contains($result['request_xml'], '[REDACTED]'));
                $harness->assertTrue(str_contains($result['request_xml'], 'business-value-must-remain'));
                $harness->assertFalse(array_key_exists('set-cookie', $result['headers']));
            }
        );

        $harness->check(
            \eel_accounts\Client\HmrcCtGatewayClient::class,
            'uses the TIL class with the live endpoint and never marks it statutory',
            static function () use ($harness): void {
                $captured = [];
                $client = test_hmrc_ct_client(
                    static function (array $request) use (&$captured): array {
                        $captured = $request;

                        return [
                            'status_code' => 200,
                            'headers' => [],
                            'body' => test_hmrc_ct_response(
                                'HMRC-CT-CT600-TIL',
                                'acknowledgement',
                                'submit',
                                'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB'
                            ),
                        ];
                    }
                );
                $result = $client->submit(test_hmrc_ct_body(), '0123456789', 'TIL');

                $harness->assertSame(true, $result['success']);
                $harness->assertSame('TIL', $result['environment']);
                $harness->assertSame('LIVE', $result['credential_environment']);
                $harness->assertSame(false, $result['statutory']);
                $harness->assertTrue(str_contains($captured['url'], 'transaction-engine.tax.service.gov.uk'));
                $harness->assertFalse(str_contains($captured['url'], 'test-transaction-engine'));
                $harness->assertTrue(str_contains($captured['body'], '<Class>HMRC-CT-CT600-TIL</Class>'));
                $harness->assertTrue(str_contains($captured['body'], '<GatewayTest>0</GatewayTest>'));
                $harness->assertTrue(str_contains($captured['body'], 'LIVE-SENDER-456'));
            }
        );

        $harness->check(
            \eel_accounts\Client\HmrcCtGatewayClient::class,
            'uses the production IRmark service only after the final GovTalk wrapper exists',
            static function () use ($harness): void {
                $captured = [];
                $client = new \eel_accounts\Client\HmrcCtGatewayClient(
                    static function (array $request) use (&$captured): array {
                        $captured = $request;

                        return [
                            'status_code' => 200,
                            'headers' => [],
                            'body' => test_hmrc_ct_response(
                                'HMRC-CT-CT600',
                                'acknowledgement',
                                'submit',
                                '12121212121212121212121212121212'
                            ),
                        ];
                    },
                    static fn(string $environment): array => test_hmrc_ct_credentials($environment),
                    static fn(): string => '0123456789ABCDEF0123456789ABCDEF'
                );
                $result = $client->submit(test_hmrc_ct_body(), '0123456789', 'TEST');
                $recalculated = (new \eel_accounts\Service\IrmarkService())
                    ->calculateFromGovTalkXml($captured['body']);

                $harness->assertSame(true, $result['success']);
                $harness->assertTrue(trim((string)$result['irmark']) !== '');
                $harness->assertSame($recalculated['irmark'], $result['irmark']);
                $harness->assertTrue(str_contains($captured['body'], '<GovTalkMessage'));
            }
        );

        $harness->check(
            \eel_accounts\Client\HmrcCtGatewayClient::class,
            'polls an acknowledged conversation and recognises only the final business response as accepted',
            static function () use ($harness): void {
                $captured = [];
                $correlationId = 'CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC';
                $client = test_hmrc_ct_client(
                    static function (array $request) use (&$captured, $correlationId): array {
                        $captured = $request;

                        return [
                            'status_code' => 200,
                            'headers' => [],
                            'body' => test_hmrc_ct_response(
                                'HMRC-CT-CT600',
                                'response',
                                'submit',
                                $correlationId,
                                '<SuccessResponse xmlns="http://www.inlandrevenue.gov.uk/SuccessResponse">'
                                    . '<Message>Return accepted</Message></SuccessResponse>',
                                'https://transaction-engine.tax.service.gov.uk/poll'
                            ),
                        ];
                    }
                );
                $result = $client->poll(
                    $correlationId,
                    'https://transaction-engine.tax.service.gov.uk/poll',
                    'LIVE'
                );

                $harness->assertSame(true, $result['success']);
                $harness->assertSame('final_response', $result['protocol_state']);
                $harness->assertSame('accepted', $result['business_outcome']);
                $harness->assertSame(true, $result['cleanup_required']);
                $harness->assertTrue(str_contains($result['body_xml'], 'Return accepted'));
                $harness->assertTrue(str_contains($captured['body'], '<Qualifier>poll</Qualifier>'));
                $harness->assertFalse(str_contains($captured['body'], '<IDAuthentication>'));
            }
        );

        $harness->check(
            \eel_accounts\Client\HmrcCtGatewayClient::class,
            'parses final business errors with numbers text and locations',
            static function () use ($harness): void {
                $correlationId = 'DDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDD';
                $body = '<ErrorResponse xmlns="http://www.govtalk.gov.uk/CM/errorresponse" SchemaVersion="2.0">'
                    . '<Application><MessageCount>1</MessageCount></Application><Error>'
                    . '<RaisedBy>System</RaisedBy><Number>5005</Number><Type>business</Type>'
                    . '<Text>Keys in the GovTalkDetails do not match those in the IRheader.</Text>'
                    . '<Location>/GovTalkMessage/Body/IRenvelope/IRheader/Keys/Key</Location>'
                    . '</Error></ErrorResponse>';
                $client = test_hmrc_ct_client(
                    static fn(array $request): array => [
                        'status_code' => 200,
                        'headers' => [],
                        'body' => test_hmrc_ct_response(
                            'HMRC-CT-CT600',
                            'error',
                            'submit',
                            $correlationId,
                            $body,
                            'https://test-transaction-engine.tax.service.gov.uk/poll'
                        ),
                    ]
                );
                $result = $client->poll(
                    $correlationId,
                    'https://test-transaction-engine.tax.service.gov.uk/poll',
                    'TEST'
                );

                $harness->assertSame(false, $result['success']);
                $harness->assertSame('final_response', $result['protocol_state']);
                $harness->assertSame('rejected', $result['business_outcome']);
                $harness->assertSame('5005', $result['errors'][0]['number']);
                $harness->assertTrue(str_contains($result['error'], 'GovTalkDetails'));
                $harness->assertSame(true, $result['cleanup_required']);
            }
        );

        $harness->check(
            \eel_accounts\Client\HmrcCtGatewayClient::class,
            'keeps a post-acknowledgement Gateway fatal error in the existing poll sequence',
            static function () use ($harness): void {
                $correlationId = 'ABABABABABABABABABABABABABABABAB';
                $gatewayErrors = '<GovTalkErrors><Error><RaisedBy>Gateway</RaisedBy>'
                    . '<Number>1000</Number><Type>fatal</Type>'
                    . '<Text>Temporary Transaction Engine failure.</Text><Location/></Error></GovTalkErrors>';
                $client = test_hmrc_ct_client(
                    static fn(array $request): array => [
                        'status_code' => 200,
                        'headers' => [],
                        'body' => test_hmrc_ct_response(
                            'HMRC-CT-CT600',
                            'error',
                            'submit',
                            $correlationId,
                            '',
                            'https://test-transaction-engine.tax.service.gov.uk/poll',
                            2,
                            '11111111111111111111111111111111',
                            $gatewayErrors
                        ),
                    ]
                );
                $result = $client->poll(
                    $correlationId,
                    'https://test-transaction-engine.tax.service.gov.uk/poll',
                    'TEST',
                    '11111111111111111111111111111111'
                );

                $harness->assertSame(false, $result['success']);
                $harness->assertSame('submission_error', $result['protocol_state']);
                $harness->assertSame(null, $result['business_outcome']);
                $harness->assertSame(false, $result['cleanup_required']);
            }
        );

        $harness->check(
            \eel_accounts\Client\HmrcCtGatewayClient::class,
            'does not promote an accepted-looking non-2xx response or backfill omitted identifiers',
            static function () use ($harness): void {
                $correlationId = 'CDCDCDCDCDCDCDCDCDCDCDCDCDCDCDCD';
                $client = test_hmrc_ct_client(
                    static fn(array $request): array => [
                        'status_code' => 500,
                        'headers' => [],
                        'body' => test_hmrc_ct_response(
                            'HMRC-CT-CT600',
                            'response',
                            'submit',
                            $correlationId,
                            '<SuccessResponse xmlns="http://www.inlandrevenue.gov.uk/SuccessResponse"/>',
                            'https://test-transaction-engine.tax.service.gov.uk/poll',
                            2,
                            ''
                        ),
                    ]
                );
                $result = $client->poll(
                    $correlationId,
                    'https://test-transaction-engine.tax.service.gov.uk/poll',
                    'TEST',
                    '22222222222222222222222222222222'
                );

                $harness->assertSame(false, $result['success']);
                $harness->assertSame('failed', $result['protocol_state']);
                $harness->assertSame(null, $result['business_outcome']);
                $harness->assertSame('', $result['transaction_id']);
                $harness->assertSame($correlationId, $result['correlation_id']);
            }
        );

        $harness->check(
            \eel_accounts\Client\HmrcCtGatewayClient::class,
            'treats delete error 2000 as already cleaned up',
            static function () use ($harness): void {
                $correlationId = 'EEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEE';
                $body = '<ErrorResponse xmlns="http://www.govtalk.gov.uk/CM/errorresponse">'
                    . '<Error><RaisedBy>Gateway</RaisedBy><Number>2000</Number><Type>fatal</Type>'
                    . '<Text>CorrelationID cannot be found.</Text></Error></ErrorResponse>';
                $client = test_hmrc_ct_client(
                    static fn(array $request): array => [
                        'status_code' => 200,
                        'headers' => [],
                        'body' => test_hmrc_ct_response(
                            'HMRC-CT-CT600',
                            'error',
                            'delete',
                            $correlationId,
                            $body
                        ),
                    ]
                );
                $result = $client->delete(
                    $correlationId,
                    'https://test-transaction-engine.tax.service.gov.uk/poll',
                    'TEST'
                );

                $harness->assertSame(true, $result['success']);
                $harness->assertSame(true, $result['delete_not_found']);
                $harness->assertSame('deleted', $result['protocol_state']);
            }
        );

        $harness->check(
            \eel_accounts\Client\HmrcCtGatewayClient::class,
            'issues an authenticated DATA_REQUEST and normalises reconciliation records',
            static function () use ($harness): void {
                $captured = [];
                $body = '<StatusReport xmlns="http://www.govtalk.gov.uk/CM/envelope">'
                    . '<StatusRecord><TimeStamp>2026-07-17T09:00:00</TimeStamp>'
                    . '<CorrelationID>FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFF</CorrelationID>'
                    . '<TransactionID>1234ABCD</TransactionID><Identifiers>'
                    . '<Identifier Type="UTR">0123456789</Identifier></Identifiers>'
                    . '<Status>SUBMISSION_RESPONSE</Status></StatusRecord></StatusReport>';
                $client = test_hmrc_ct_client(
                    static function (array $request) use (&$captured, $body): array {
                        $captured = $request;

                        return [
                            'status_code' => 200,
                            'headers' => [],
                            'body' => test_hmrc_ct_response(
                                'HMRC-CT-CT600',
                                'response',
                                'list',
                                '',
                                $body
                            ),
                        ];
                    }
                );
                $result = $client->requestData([
                    'start_at' => '2026-07-17 08:00:00',
                    'end_at' => '2026-07-17 10:00:00',
                    'include_identifiers' => true,
                ], 'TEST');

                $harness->assertSame(true, $result['success']);
                $harness->assertSame('data_response', $result['protocol_state']);
                $harness->assertSame('final_response', $result['status_records'][0]['normalised_status']);
                $harness->assertSame('UTR', $result['status_records'][0]['identifiers'][0]['name']);
                $harness->assertTrue(str_contains($captured['body'], '<Function>list</Function>'));
                $harness->assertTrue(str_contains($captured['body'], '<IncludeIdentifiers>1</IncludeIdentifiers>'));
                $harness->assertTrue(str_contains($captured['body'], '<StartDate>17/07/2026</StartDate>'));
                $harness->assertTrue(str_contains($captured['body'], 'test-password-secret'));
                $harness->assertFalse(str_contains($result['request_xml'], 'test-password-secret'));
            }
        );

        $harness->check(
            \eel_accounts\Client\HmrcCtGatewayClient::class,
            'blocks untrusted poll endpoints before transport',
            static function () use ($harness): void {
                $calls = 0;
                $client = test_hmrc_ct_client(
                    static function (array $request) use (&$calls): array {
                        $calls++;

                        return [];
                    }
                );
                $result = $client->poll(
                    'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
                    'https://attacker.example/poll',
                    'TEST'
                );

                $harness->assertSame(false, $result['success']);
                $harness->assertSame(0, $calls);
                $harness->assertTrue(str_contains($result['error'], 'outside the selected'));
            }
        );

        $harness->check(
            \eel_accounts\Client\HmrcCtGatewayClient::class,
            'marks an uncertain submit transport failure without leaking credentials',
            static function () use ($harness): void {
                $client = test_hmrc_ct_client(
                    static function (array $request): array {
                        throw new RuntimeException(
                            'Connection reset for TEST-SENDER-123 using test-password-secret'
                        );
                    }
                );
                $result = $client->submit(test_hmrc_ct_body(), '0123456789', 'TEST');

                $harness->assertSame(false, $result['success']);
                $harness->assertSame(true, $result['transport_unknown']);
                $encoded = json_encode($result);
                $harness->assertTrue(is_string($encoded));
                $harness->assertFalse(str_contains((string)$encoded, 'TEST-SENDER-123'));
                $harness->assertFalse(str_contains((string)$encoded, 'test-password-secret'));
                $harness->assertTrue(str_contains($result['error'], '[REDACTED]'));
            }
        );

        $harness->check(
            \eel_accounts\Client\HmrcCtGatewayClient::class,
            'fake gateway scripts lifecycle outcomes and records calls',
            static function () use ($harness): void {
                $fake = new \eel_accounts\Client\FakeHmrcCtGatewayClient([
                    'poll' => [[
                        'success' => false,
                        'protocol_state' => 'final_response',
                        'business_outcome' => 'rejected',
                        'error' => 'Synthetic rejection.',
                    ]],
                ]);
                $submit = $fake->submit(test_hmrc_ct_body(), '0123456789', 'TEST');
                $poll = $fake->poll(
                    (string)$submit['correlation_id'],
                    (string)$submit['response_endpoint'],
                    'TEST'
                );

                $harness->assertSame('acknowledged', $submit['protocol_state']);
                $harness->assertSame('rejected', $poll['business_outcome']);
                $harness->assertCount(2, $fake->calls());
            }
        );
    }
);
