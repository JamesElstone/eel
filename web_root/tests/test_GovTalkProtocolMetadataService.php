<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support'
    . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(
    \eel_accounts\Service\GovTalkProtocolMetadataService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\GovTalkProtocolMetadataService $metadata
    ): void {
        $harness->check(
            \eel_accounts\Service\GovTalkProtocolMetadataService::class,
            'indexes authority-neutral GovTalk message details and errors',
            static function () use ($harness, $metadata): void {
                $xml = '<?xml version="1.0"?>'
                    . '<GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope">'
                    . '<Header><MessageDetails><Class>HMRC-CT-CT600-TIL</Class>'
                    . '<Qualifier>request</Qualifier><Function>submit</Function>'
                    . '<TransactionID>TX1</TransactionID>'
                    . '<CorrelationID>CORR1</CorrelationID></MessageDetails></Header>'
                    . '<GovTalkDetails><GovTalkErrors><Error><RaisedBy>Gateway</RaisedBy>'
                    . '<Number>5000</Number><Type>fatal</Type><Text>Unavailable</Text>'
                    . '<Text>Try later</Text><Location>/GovTalkMessage/Header</Location><Location/>'
                    . '</Error></GovTalkErrors></GovTalkDetails><Body/></GovTalkMessage>';
                $details = $metadata->requestMessageDetails($xml);
                $errors = $metadata->govTalkErrors($xml);

                $harness->assertSame('HMRC-CT-CT600-TIL', $details['class']);
                $harness->assertSame('request', $details['qualifier']);
                $harness->assertSame('submit', $details['function']);
                $harness->assertSame('TX1', $details['transaction_id']);
                $harness->assertSame('CORR1', $details['correlation_id']);
                $harness->assertSame('Gateway', $errors[0]['raised_by']);
                $harness->assertSame('5000', $errors[0]['number']);
                $harness->assertSame('fatal', $errors[0]['type']);
                $harness->assertSame(['Unavailable', 'Try later'], $errors[0]['texts']);
                $harness->assertSame(['/GovTalkMessage/Header'], $errors[0]['locations']);
            }
        );
    }
);
