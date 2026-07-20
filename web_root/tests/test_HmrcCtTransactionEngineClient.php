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
            'vendor_id' => '1234',
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

        $h->check(
            \eel_accounts\Client\HmrcCtTransactionEngineClient::class,
            'uses the live endpoint and TIL class, persists before send, and redacts credentials',
            static function () use ($h, $credentials, $transactionId, $body, $response): void {
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
                $result = $client->submit(
                    $body,
                    '0123456789',
                    'TIL',
                    null,
                    static function (array $request) use (&$order, &$preSend): void {
                        $order[] = 'persist';
                        $preSend = $request;
                    }
                );

                $h->assertSame(['persist', 'transport'], $order);
                $h->assertSame('https://transaction-engine.tax.service.gov.uk/submission', $captured['url']);
                $h->assertTrue(str_contains((string)$captured['body'], '<Class>HMRC-CT-CT600-TIL</Class>'));
                $h->assertTrue(str_contains((string)$captured['body'], '<GatewayTest>0</GatewayTest>'));
                $h->assertTrue(str_contains((string)$captured['body'], '<SenderID>LIVE-SENDER</SenderID>'));
                $h->assertTrue(str_contains((string)$captured['body'], '<Value>LIVE-PASSWORD</Value>'));
                $h->assertFalse(str_contains((string)$preSend['request_xml'], 'LIVE-SENDER'));
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
            static function () use ($h, $credentials, $transactionId, $response): void {
                $responses = [
                    $response(
                        'HMRC-CT-CT600-TIL',
                        'response',
                        'submit',
                        'CAFE1234',
                        '',
                        '<GovTalkDetails><Keys/></GovTalkDetails>',
                        '<Body><SubmissionReference xmlns="urn:test">HMRC-REF-1</SubmissionReference></Body>'
                    ),
                    $response('HMRC-CT-CT600-TIL', 'response', 'delete', 'CAFE1234'),
                ];
                $client = new \eel_accounts\Client\HmrcCtTransactionEngineClient(
                    static function (array $request) use (&$responses): array {
                        unset($request);
                        return ['status_code' => 200, 'headers' => [], 'body' => array_shift($responses)];
                    },
                    $credentials,
                    $transactionId
                );
                $poll = $client->poll(
                    'CAFE1234',
                    'https://transaction-engine.tax.service.gov.uk/poll',
                    'TIL'
                );
                $h->assertTrue((bool)$poll['success']);
                $h->assertSame('final_response', $poll['protocol_state']);
                $h->assertSame('accepted', $poll['business_outcome']);
                $h->assertTrue((bool)$poll['cleanup_required']);

                $delete = $client->delete(
                    'CAFE1234',
                    'https://transaction-engine.tax.service.gov.uk/poll',
                    'TIL'
                );
                $h->assertTrue((bool)$delete['success']);
                $h->assertSame('deleted', $delete['protocol_state']);
            }
        );

        $h->check(
            \eel_accounts\Client\HmrcCtTransactionEngineClient::class,
            'records an unknown submit outcome and never leaks credentials in the error',
            static function () use ($h, $credentials, $transactionId, $body): void {
                $client = new \eel_accounts\Client\HmrcCtTransactionEngineClient(
                    static function (array $request): array {
                        unset($request);
                        throw new RuntimeException('timeout mentioning LIVE-PASSWORD');
                    },
                    $credentials,
                    $transactionId
                );
                $result = $client->submit($body, '0123456789', 'LIVE');
                $h->assertFalse((bool)$result['success']);
                $h->assertTrue((bool)$result['transport_unknown']);
                $h->assertFalse(str_contains((string)$result['error'], 'LIVE-PASSWORD'));
            }
        );

        $h->check(
            \eel_accounts\Client\HmrcCtTransactionEngineClient::class,
            'recursively redacts credentials echoed by a parsed GovTalk error',
            static function () use ($h, $credentials, $transactionId, $body, $response): void {
                $govTalkDetails = '<GovTalkDetails><Keys/><GovTalkErrors><Error>'
                    . '<RaisedBy>Gateway</RaisedBy><Number>5000</Number><Type>fatal</Type>'
                    . '<Text>Rejected LIVE-SENDER using LIVE-PASSWORD.</Text>'
                    . '<Location>LIVE-PASSWORD</Location></Error></GovTalkErrors></GovTalkDetails>';
                $client = new \eel_accounts\Client\HmrcCtTransactionEngineClient(
                    static fn(array $request): array => [
                        'status_code' => 200,
                        'headers' => [],
                        'body' => $response(
                            'HMRC-CT-CT600',
                            'error',
                            'submit',
                            '',
                            '',
                            $govTalkDetails
                        ),
                    ],
                    $credentials,
                    $transactionId
                );

                $result = $client->submit($body, '0123456789', 'LIVE');
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
            static function () use ($h, $credentials, $transactionId, $response): void {
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
                            '',
                            $gatewayError
                        ),
                    ],
                    $credentials,
                    $transactionId
                );
                $result = $client->poll(
                    'CAFE1234',
                    'https://transaction-engine.tax.service.gov.uk/poll',
                    'TIL'
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
            static function () use ($h, $credentials, $transactionId, $response): void {
                $client = new \eel_accounts\Client\HmrcCtTransactionEngineClient(
                    static fn(array $request): array => [
                        'status_code' => 200,
                        'headers' => [],
                        'body' => $response(
                            'HMRC-CT-CT600-TIL',
                            'response',
                            'submit',
                            'DEADBEEF'
                        ),
                    ],
                    $credentials,
                    $transactionId
                );
                $result = $client->poll(
                    'CAFE1234',
                    'https://transaction-engine.tax.service.gov.uk/poll',
                    'TIL'
                );
                $h->assertFalse((bool)$result['success']);
                $h->assertSame('failed', $result['protocol_state']);
                $h->assertSame(null, $result['business_outcome']);
                $h->assertTrue(str_contains((string)$result['error'], 'correlation ID'));
            }
        );

        $h->check(
            \eel_accounts\Client\HmrcCtTransactionEngineClient::class,
            'rejects missing and mismatched GovTalk response transaction IDs',
            static function () use ($h, $credentials, $transactionId, $response): void {
                foreach (['', 'DEADBEEF'] as $responseTransactionId) {
                    $client = new \eel_accounts\Client\HmrcCtTransactionEngineClient(
                        static fn(array $request): array => [
                            'status_code' => 200,
                            'headers' => [],
                            'body' => $response(
                                'HMRC-CT-CT600-TIL',
                                'response',
                                'submit',
                                'CAFE1234',
                                '',
                                '<GovTalkDetails><Keys/></GovTalkDetails>',
                                '<Body/>',
                                $responseTransactionId
                            ),
                        ],
                        $credentials,
                        $transactionId
                    );
                    $result = $client->poll(
                        'CAFE1234',
                        'https://transaction-engine.tax.service.gov.uk/poll',
                        'TIL'
                    );
                    $h->assertFalse((bool)$result['success']);
                    $h->assertSame('failed', $result['protocol_state']);
                    $h->assertSame(null, $result['business_outcome']);
                    $h->assertTrue(str_contains(strtolower((string)$result['error']), 'transaction id'));
                }
            }
        );

        $h->check(
            \eel_accounts\Client\HmrcCtTransactionEngineClient::class,
            'rejects an HMRC-supplied poll endpoint outside the selected environment',
            static function () use ($h, $credentials, $transactionId): void {
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
                $result = $client->poll('CAFE1234', 'https://attacker.example/poll', 'LIVE');
                $h->assertFalse((bool)$result['success']);
                $h->assertTrue((bool)$result['pre_send_failure']);
                $h->assertFalse($called);
            }
        );
    }
);
