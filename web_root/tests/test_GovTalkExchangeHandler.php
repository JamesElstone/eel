<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support'
    . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Client\GovTalkExchangeHandler::class,
    static function (GeneratedServiceClassTestHarness $h): void {
        $h->check(
            \eel_accounts\Client\GovTalkExchangeHandler::class,
            'archives and verifies both directions before transport and parsing',
            static function () use ($h): void {
                $order = [];
                $requestXml = '<GovTalkMessage><Header><MessageDetails>'
                    . '<Class>Accounts</Class><Qualifier>request</Qualifier>'
                    . '<TransactionID>ABC123</TransactionID>'
                    . '</MessageDetails></Header><Body/></GovTalkMessage>';
                $responseXml = '<GovTalkMessage><Header><MessageDetails>'
                    . '<Class>Accounts</Class><Qualifier>response</Qualifier>'
                    . '<TransactionID>ABC123</TransactionID>'
                    . '</MessageDetails></Header><GovTalkDetails><Keys/>'
                    . '</GovTalkDetails><Body/></GovTalkMessage>';
                $context = new \eel_accounts\Client\GovTalkConversationContext(
                    'companies_house',
                    49,
                    79,
                    'TEST',
                    '000001',
                    static function (array $request) use (&$order): array {
                        $order[] = 'request';
                        return [
                            'transaction_id' => $request['transaction_id'],
                            'request_sha256' => $request['request_sha256'],
                            'request_bytes' => $request['request_bytes'],
                        ];
                    },
                    static function (array $request) use (&$order): void {
                        unset($request);
                        $order[] = 'sent';
                    },
                    static function (array $response) use (&$order): array {
                        $order[] = 'response';
                        return [
                            'transaction_id' => $response['transaction_id'],
                            'response_sha256' => $response['response_sha256'],
                            'response_bytes' => $response['response_bytes'],
                            'response_headers_sha256' =>
                                $response['response_headers_sha256'],
                        ];
                    },
                    static function (
                        string $state,
                        string $error,
                        array $result
                    ) use (&$order, $h): void {
                        unset($error, $result);
                        $order[] = 'complete';
                        $h->assertSame('succeeded', $state);
                    },
                    static function (
                        string $transactionId,
                        string $error
                    ): void {
                        unset($transactionId, $error);
                        throw new RuntimeException('Evidence should be complete.');
                    }
                );
                $prepared = new \eel_accounts\Client\GovTalkPreparedRequest(
                    'companies_house',
                    'accounts',
                    'TEST',
                    'https://xmlgw.example.test',
                    'ABC123',
                    '',
                    $requestXml,
                    ['body' => $requestXml]
                );
                $handler = new \eel_accounts\Client\GovTalkExchangeHandler(
                    static function (array $request) use (
                        &$order,
                        $responseXml,
                        $h
                    ): array {
                        $h->assertSame(['request', 'sent'], $order);
                        $order[] = 'transport';
                        return [
                            'status_code' => 200,
                            'headers' => [
                                'Content-Type' => 'text/xml',
                                'Set-Cookie' => 'excluded',
                            ],
                            'body' => $responseXml,
                        ];
                    }
                );
                $result = $handler->execute(
                    $prepared,
                    $context,
                    static function (
                        \eel_accounts\Client\GovTalkPreparedRequest $request,
                        \eel_accounts\Client\GovTalkRawResponse $response
                    ) use (&$order, $h): array {
                        $h->assertSame(
                            ['request', 'sent', 'transport', 'response'],
                            $order
                        );
                        $h->assertSame('ABC123', $request->transactionId);
                        $h->assertFalse(array_key_exists(
                            'set-cookie',
                            $response->headers
                        ));
                        $order[] = 'parse';
                        return ['success' => true, 'outcome' => 'accepted'];
                    }
                );
                $h->assertTrue((bool)$result->payload['success']);
                $h->assertSame(
                    ['request', 'sent', 'transport', 'response', 'parse', 'complete'],
                    $order
                );
            }
        );

        $h->check(
            \eel_accounts\Client\GovTalkExchangeHandler::class,
            'does not transport when the request receipt is incomplete',
            static function () use ($h): void {
                $transported = false;
                $context = new \eel_accounts\Client\GovTalkConversationContext(
                    'hmrc',
                    49,
                    79,
                    'TEST',
                    'submission-000001',
                    static fn(array $request): array => [
                        'transaction_id' => $request['transaction_id'],
                    ],
                    static function (array $request): void {
                        unset($request);
                    },
                    static fn(array $response): array => $response,
                    static function (
                        string $state,
                        string $error,
                        array $result
                    ): void {
                        unset($state, $error, $result);
                    },
                    static function (
                        string $transactionId,
                        string $error
                    ): void {
                        unset($transactionId, $error);
                    }
                );
                $xml = '<GovTalkMessage><Body/></GovTalkMessage>';
                $handler = new \eel_accounts\Client\GovTalkExchangeHandler(
                    static function (array $request) use (&$transported): array {
                        unset($request);
                        $transported = true;
                        return [];
                    }
                );
                $result = $handler->execute(
                    new \eel_accounts\Client\GovTalkPreparedRequest(
                        'hmrc',
                        'submit',
                        'TEST',
                        'https://hmrc.example.test',
                        'ABC123',
                        '',
                        $xml,
                        ['body' => $xml]
                    ),
                    $context,
                    static fn(
                        \eel_accounts\Client\GovTalkPreparedRequest $request,
                        \eel_accounts\Client\GovTalkRawResponse $response
                    ): array => ['success' => true]
                );
                $h->assertFalse($transported);
                $h->assertTrue((bool)$result->payload['pre_send_failure']);
            }
        );
    }
);
