<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support'
    . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\CompaniesHouseProtocolMetadataService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\CompaniesHouseProtocolMetadataService $metadata
    ): void {
        $harness->check(
            \eel_accounts\Service\CompaniesHouseProtocolMetadataService::class,
            'extracts protocol class and every field from a GovTalk 502 error',
            static function () use ($harness, $metadata): void {
                $xml = '<?xml version="1.0" encoding="UTF-8"?>'
                    . '<GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope">'
                    . '<Header><MessageDetails><Class>CompanyDataRequest</Class>'
                    . '</MessageDetails></Header><GovTalkDetails><GovTalkErrors>'
                    . '<Error><RaisedBy>CompanyDataRequest</RaisedBy><Number>502</Number>'
                    . '<Type>fatal</Type><Text>Authorisation Failure</Text>'
                    . '<Text>Presenter rejected</Text><Location/>'
                    . '<Location>Header/SenderDetails</Location></Error>'
                    . '</GovTalkErrors></GovTalkDetails><Body/></GovTalkMessage>';
                $harness->assertSame(
                    'CompanyDataRequest',
                    $metadata->requestMessageClass($xml, 'company_data')
                );
                $errors = $metadata->govTalkErrors($xml);
                $harness->assertCount(1, $errors);
                $harness->assertSame('CompanyDataRequest', $errors[0]['raised_by']);
                $harness->assertSame('502', $errors[0]['number']);
                $harness->assertSame('fatal', $errors[0]['type']);
                $harness->assertSame(
                    ['Authorisation Failure', 'Presenter rejected'],
                    $errors[0]['texts']
                );
                $harness->assertSame(
                    ['Header/SenderDetails'],
                    $errors[0]['locations']
                );
            }
        );

        $harness->check(
            \eel_accounts\Service\CompaniesHouseProtocolMetadataService::class,
            'filters private headers and hashes a stable canonical representation',
            static function () use ($harness, $metadata): void {
                $headers = $metadata->sanitizeResponseHeaders([
                    'X-Request-ID' => ' abc ',
                    'Content-Type' => 'application/xml',
                    'Set-Cookie' => 'secret',
                    'Authorization' => 'private',
                ]);
                $harness->assertSame([
                    'content-type' => 'application/xml',
                    'x-request-id' => 'abc',
                ], $headers);
                $json = $metadata->responseHeadersJson(array_reverse($headers, true));
                $harness->assertSame($headers, json_decode($json, true, 8, JSON_THROW_ON_ERROR));
                $harness->assertSame($json, $metadata->responseHeadersJson($headers));
                $harness->assertSame('200 OK', $metadata->httpStatusLabel(200));
                $harness->assertSame('599', $metadata->httpStatusLabel(599));
                $harness->assertSame('', $metadata->httpStatusLabel(null));
            }
        );
    }
);
