<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

/** @return string */
function hmrc_irmark_test_envelope(): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope">'
        . '<EnvelopeVersion>2.0</EnvelopeVersion><Header><MessageDetails/></Header>'
        . '<GovTalkDetails><Keys/></GovTalkDetails><Body>'
        . '<IRenvelope xmlns="http://www.govtalk.gov.uk/taxation/CT/5">'
        . '<IRheader><Keys><Key Type="UTR">0123456789</Key></Keys><Sender>Company</Sender></IRheader>'
        . '<CompanyTaxReturn><CompanyInformation><CompanyName>Example Trading Limited</CompanyName>'
        . '</CompanyInformation></CompanyTaxReturn></IRenvelope>'
        . '</Body></GovTalkMessage>';
}

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\HmrcIrmarkService::class,
    static function (GeneratedServiceClassTestHarness $h, \eel_accounts\Service\HmrcIrmarkService $service): void {
        $h->check(
            \eel_accounts\Service\HmrcIrmarkService::class,
            'applies one deterministic generic IRmark and verifies the exact body',
            static function () use ($h, $service): void {
                $first = $service->apply(hmrc_irmark_test_envelope());
                $second = $service->apply(hmrc_irmark_test_envelope());

                $h->assertSame(true, (bool)$first['ok']);
                $h->assertSame(true, (bool)$first['verified']);
                $h->assertSame($first['base64'], $second['base64']);
                $h->assertSame($first['xml'], $second['xml']);
                $h->assertSame(28, strlen((string)$first['base64']));
                $h->assertSame(32, strlen((string)$first['base32']));

                $verified = $service->verify((string)$first['xml']);
                $h->assertSame(true, (bool)$verified['ok']);
                $h->assertSame(true, (bool)$verified['verified']);
                $h->assertSame($first['base64'], $verified['stored']);

                $document = new DOMDocument();
                $h->assertSame(true, $document->loadXML((string)$first['xml'], LIBXML_NONET | LIBXML_NOBLANKS));
                $xpath = new DOMXPath($document);
                $xpath->registerNamespace('ct', 'http://www.govtalk.gov.uk/taxation/CT/5');
                $headerElements = $xpath->query('//*[local-name()="IRheader"]/*');
                $names = [];
                foreach ($headerElements ?: [] as $element) {
                    if ($element instanceof DOMElement) {
                        $names[] = $element->localName;
                    }
                }
                $h->assertSame(['Keys', 'IRmark', 'Sender'], $names);
            }
        );

        $h->check(
            \eel_accounts\Service\HmrcIrmarkService::class,
            'detects any change to the marked GovTalk Body',
            static function () use ($h, $service): void {
                $marked = $service->apply(hmrc_irmark_test_envelope());
                $tampered = str_replace('Example Trading Limited', 'Example Trading Changed', (string)$marked['xml']);
                $verified = $service->verify($tampered);

                $h->assertSame(false, (bool)$verified['ok']);
                $h->assertSame(false, (bool)$verified['verified']);
                $h->assertTrue(str_contains(implode(' ', (array)$verified['errors']), 'does not match'));
            }
        );

        $h->check(
            \eel_accounts\Service\HmrcIrmarkService::class,
            'fails closed for malformed XML and for a body without one CT header',
            static function () use ($h, $service): void {
                $malformed = $service->apply('<GovTalkMessage>');
                $missingHeader = $service->apply(
                    '<GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope"><Body>'
                    . '<IRenvelope xmlns="http://www.govtalk.gov.uk/taxation/CT/5"/>'
                    . '</Body></GovTalkMessage>'
                );

                $h->assertSame(false, (bool)$malformed['ok']);
                $h->assertSame(false, (bool)$missingHeader['ok']);
                $h->assertTrue(str_contains(implode(' ', (array)$missingHeader['errors']), 'exactly one CT IRheader'));
            }
        );
    }
);
