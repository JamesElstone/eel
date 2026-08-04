<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

function hmrcReceiptReferenceFixture(
    string $reference = '8596148860',
    string $successNamespace = 'http://www.inlandrevenue.gov.uk/SuccessResponse',
    string $successPrefix = ''
): string {
    $prefix = $successPrefix !== '' ? $successPrefix . ':' : '';
    $namespace = $successPrefix !== ''
        ? ' xmlns:' . $successPrefix . '="' . $successNamespace . '"'
        : ' xmlns="' . $successNamespace . '"';
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<gti:GovTalkMessage xmlns:gti="http://www.govtalk.gov.uk/CM/envelope">'
        . '<gti:Body><' . $prefix . 'SuccessResponse' . $namespace . '>'
        . '<' . $prefix . 'IRmarkReceipt>'
        . '<dsig:Signature xmlns:dsig="http://www.w3.org/2000/09/xmldsig#">'
        . '<dsig:SignedInfo><dsig:Reference><dsig:Transforms><dsig:Transform>'
        . '<dsig:XPath>(count(ancestor-or-self::node()|/gti:GovTalkMessage/gti:Body)='
        . 'count(ancestor-or-self::node()))</dsig:XPath>'
        . '</dsig:Transform></dsig:Transforms></dsig:Reference></dsig:SignedInfo>'
        . '</dsig:Signature>'
        . '<' . $prefix . 'Message code="0000">HMRC has received the HMRC-CT-CT600 document ref: '
        . $reference . ' at 07.35 on 04/08/2026. The associated IRmark was: TEST.</'
        . $prefix . 'Message>'
        . '</' . $prefix . 'IRmarkReceipt>'
        . '<' . $prefix . 'Message code="077001">Thank you for your submission</'
        . $prefix . 'Message>'
        . '</' . $prefix . 'SuccessResponse></gti:Body></gti:GovTalkMessage>';
}

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\HmrcReceiptReferenceService::class,
    static function (
        GeneratedServiceClassTestHarness $h,
        \eel_accounts\Service\HmrcReceiptReferenceService $service
    ): void {
        $h->check($service::class, 'extracts the document reference without reading signature XPath text', static function () use ($h, $service): void {
            $xml = hmrcReceiptReferenceFixture();
            $reference = $service->extract($xml);
            $h->assertSame('8596148860', $reference);
            $h->assertFalse(str_contains((string)$reference, 'ancestor-or-self'));
        });
        $h->check($service::class, 'resolves the success namespace independently of XML prefixes', static function () use ($h, $service): void {
            $h->assertSame('8596148860', $service->extract(hmrcReceiptReferenceFixture(
                '8596148860',
                'http://www.inlandrevenue.gov.uk/SuccessResponse',
                'success'
            )));
        });
        $h->check($service::class, 'accepts an unambiguous explicit leaf reference', static function () use ($h, $service): void {
            $xml = str_replace(
                '<IRmarkReceipt>',
                '<SubmissionReference>ABC-123</SubmissionReference><IRmarkReceipt>',
                hmrcReceiptReferenceFixture('ABC-123')
            );
            $h->assertSame('ABC-123', $service->extract($xml));
        });
        $h->check($service::class, 'fails closed for wrong namespaces ambiguity and malformed XML', static function () use ($h, $service): void {
            $h->assertSame(null, $service->extract(hmrcReceiptReferenceFixture(
                '8596148860',
                'https://example.test/wrong'
            )));
            $ambiguous = str_replace(
                '<IRmarkReceipt>',
                '<SubmissionReference>OTHER-REF</SubmissionReference><IRmarkReceipt>',
                hmrcReceiptReferenceFixture()
            );
            $h->assertSame(null, $service->extract($ambiguous));
            $h->assertSame(null, $service->extract('<GovTalkMessage>'));
        });
        $h->check($service::class, 'rejects blank XPath XML control and oversized stored values', static function () use ($h, $service): void {
            foreach ([
                '',
                '(count(ancestor-or-self::node()|/gti:GovTalkMessage/gti:Body)=1)',
                '<Reference>8596148860</Reference>',
                '/gti:GovTalkMessage/gti:Body',
                "ABC\n123",
                str_repeat('A', 129),
            ] as $invalid) {
                $h->assertSame(null, $service->normalise($invalid));
            }
            $h->assertSame('8596148860', $service->normalise(' 8596148860 '));
        });
    }
);
