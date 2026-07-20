<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** HMRC generic IRmark: C14N GovTalk Body (without IRmark), SHA-1, Base64. */
final class HmrcIrmarkService
{
    public const VERSION = 'hmrc-generic-irmark-v1';
    private const ENVELOPE_NAMESPACE = 'http://www.govtalk.gov.uk/CM/envelope';

    /** @return array<string,mixed> */
    public function calculate(string $govTalkXml): array
    {
        $document = $this->document($govTalkXml);
        if (!$document instanceof \DOMDocument) {
            return $this->failure('The GovTalk XML could not be parsed for IRmark calculation.');
        }
        return $this->calculateDocument($document);
    }

    /** @return array<string,mixed> */
    public function apply(string $govTalkXml): array
    {
        $document = $this->document($govTalkXml);
        if (!$document instanceof \DOMDocument) {
            return $this->failure('The GovTalk XML could not be parsed before applying the IRmark.');
        }
        $xpath = $this->xpath($document);
        $headers = $xpath->query('/hd:GovTalkMessage/hd:Body/ct:IRenvelope/ct:IRheader');
        if (!$headers instanceof \DOMNodeList || $headers->length !== 1 || !$headers->item(0) instanceof \DOMElement) {
            return $this->failure('The GovTalk Body must contain exactly one CT IRheader.');
        }
        foreach ($xpath->query('/hd:GovTalkMessage/hd:Body/ct:IRenvelope/ct:IRheader/ct:IRmark') ?: [] as $existing) {
            $existing->parentNode?->removeChild($existing);
        }
        $calculation = $this->calculateDocument($document);
        if (empty($calculation['ok'])) {
            return $calculation;
        }
        /** @var \DOMElement $header */
        $header = $headers->item(0);
        $mark = $document->createElementNS(Ct600BuilderService::CT_NAMESPACE, 'IRmark');
        $mark->setAttribute('Type', 'generic');
        $mark->appendChild($document->createTextNode((string)$calculation['base64']));
        $sender = null;
        foreach ($header->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'Sender'
                && $child->namespaceURI === Ct600BuilderService::CT_NAMESPACE) {
                $sender = $child;
                break;
            }
        }
        if (!$sender instanceof \DOMElement) {
            return $this->failure('The CT IRheader has no Sender element after which ordering can be verified.');
        }
        $header->insertBefore($mark, $sender);
        $xml = $document->saveXML();
        if (!is_string($xml) || $xml === '') {
            return $this->failure('The final IRmarked GovTalk XML could not be serialized.');
        }
        $verified = $this->verify($xml);
        if (empty($verified['ok'])) {
            return $this->failure('The applied IRmark did not verify against the exact final GovTalk Body.', (array)($verified['errors'] ?? []));
        }
        return $calculation + [
            'xml' => $xml,
            'document_sha256' => hash('sha256', $xml),
            'verified' => true,
        ];
    }

    /** @return array<string,mixed> */
    public function verify(string $govTalkXml): array
    {
        $document = $this->document($govTalkXml);
        if (!$document instanceof \DOMDocument) {
            return $this->failure('The GovTalk XML could not be parsed for IRmark verification.');
        }
        $xpath = $this->xpath($document);
        $marks = $xpath->query('/hd:GovTalkMessage/hd:Body/ct:IRenvelope/ct:IRheader/ct:IRmark[@Type="generic"]');
        if (!$marks instanceof \DOMNodeList || $marks->length !== 1 || !$marks->item(0) instanceof \DOMElement) {
            return $this->failure('The final CT filing body must contain exactly one generic IRmark.');
        }
        $stored = trim((string)$marks->item(0)->textContent);
        if (preg_match('/^[A-Za-z0-9+\/]{27}=$/', $stored) !== 1) {
            return $this->failure('The stored generic IRmark is not a 28-character Base64 SHA-1 digest.');
        }
        $calculated = $this->calculateDocument($document);
        if (empty($calculated['ok']) || !hash_equals((string)$calculated['base64'], $stored)) {
            return $this->failure('The stored generic IRmark does not match the exact GovTalk Body.');
        }
        return $calculated + ['stored' => $stored, 'verified' => true];
    }

    /** @return array<string,mixed> */
    private function calculateDocument(\DOMDocument $document): array
    {
        $clone = $document->cloneNode(true);
        if (!$clone instanceof \DOMDocument) {
            return $this->failure('The GovTalk document could not be cloned for IRmark calculation.');
        }
        $xpath = $this->xpath($clone);
        $bodies = $xpath->query('/hd:GovTalkMessage/hd:Body');
        if (!$bodies instanceof \DOMNodeList || $bodies->length !== 1 || !$bodies->item(0) instanceof \DOMElement) {
            return $this->failure('The GovTalk message must contain exactly one Body for IRmark calculation.');
        }
        foreach ($xpath->query('/hd:GovTalkMessage/hd:Body//ct:IRmark') ?: [] as $mark) {
            $mark->parentNode?->removeChild($mark);
        }
        /** @var \DOMElement $body */
        $body = $bodies->item(0);
        $canonical = $body->C14N(false, false);
        if (!is_string($canonical) || $canonical === '') {
            return $this->failure('The GovTalk Body could not be canonicalised for IRmark calculation.');
        }
        $binary = hash('sha1', $canonical, true);
        return [
            'ok' => true,
            'errors' => [],
            'warnings' => [],
            'version' => self::VERSION,
            'algorithm' => 'sha1',
            'canonicalization' => 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315',
            'canonical_sha256' => hash('sha256', $canonical),
            'base64' => base64_encode($binary),
            'base32' => $this->base32($binary),
        ];
    }

    private function document(string $xml): ?\DOMDocument
    {
        if (trim($xml) === '') {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $document = new \DOMDocument();
        $document->preserveWhiteSpace = false;
        $document->formatOutput = false;
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $loaded ? $document : null;
    }

    private function xpath(\DOMDocument $document): \DOMXPath
    {
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('hd', self::ENVELOPE_NAMESPACE);
        $xpath->registerNamespace('ct', Ct600BuilderService::CT_NAMESPACE);
        return $xpath;
    }

    private function base32(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $result = '';
        foreach (str_split($bits, 5) as $chunk) {
            $result .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function failure(string $message, array $details = []): array
    {
        return [
            'ok' => false,
            'verified' => false,
            'warnings' => [],
            'errors' => array_values(array_unique(array_filter(array_map(
                'strval',
                array_merge([$message], $details)
            ), static fn(string $item): bool => trim($item) !== ''))),
        ];
    }
}
