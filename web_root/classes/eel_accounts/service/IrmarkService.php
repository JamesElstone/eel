<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Implements HMRC's generic IRmark algorithm over the final GovTalk Body. */
final class IrmarkService
{
    public const GOVTALK_NAMESPACE = 'http://www.govtalk.gov.uk/CM/envelope';
    public const CT_NAMESPACE = 'http://www.govtalk.gov.uk/taxation/CT/5';

    /**
     * @return array{
     *   xml: string,
     *   envelope_xml: string,
     *   ir_envelope_xml: string,
     *   irmark: string,
     *   irmark_display: string,
     *   canonical_body_sha256: string
     * }
     */
    public function applyToGovTalkXml(string $govTalkXml): array
    {
        $document = $this->parse($govTalkXml);
        $calculation = $this->calculateForDocument($document);
        $irMark = $this->singleElement(
            $document,
            '//*[local-name()="IRmark" and namespace-uri()="' . self::CT_NAMESPACE . '"]',
            'CT/5 IRmark'
        );

        while ($irMark->firstChild !== null) {
            $irMark->removeChild($irMark->firstChild);
        }
        $irMark->appendChild($document->createTextNode($calculation['irmark']));

        $xml = $document->saveXML();
        if (!is_string($xml) || $xml === '') {
            throw new \RuntimeException('Unable to serialise the IRmarked GovTalk request.');
        }
        $irEnvelope = $this->singleElement(
            $document,
            '//*[local-name()="IRenvelope" and namespace-uri()="' . self::CT_NAMESPACE . '"]',
            'CT/5 IRenvelope'
        );
        $irEnvelopeXml = $document->saveXML($irEnvelope);
        if (!is_string($irEnvelopeXml) || $irEnvelopeXml === '') {
            throw new \RuntimeException('Unable to serialise the finalized CT/5 body.');
        }

        return [
            'xml' => $xml,
            'envelope_xml' => $xml,
            'ir_envelope_xml' => $irEnvelopeXml,
            'irmark' => $calculation['irmark'],
            'irmark_display' => $calculation['irmark_display'],
            'canonical_body_sha256' => $calculation['canonical_body_sha256'],
        ];
    }

    /**
     * @return array{irmark: string, irmark_display: string, canonical_body_sha256: string}
     */
    public function calculateFromGovTalkXml(string $govTalkXml): array
    {
        return $this->calculateForDocument($this->parse($govTalkXml));
    }

    /**
     * @return array{irmark: string, irmark_display: string, canonical_body_sha256: string}
     */
    private function calculateForDocument(\DOMDocument $document): array
    {
        $digestDocument = $document->cloneNode(true);
        if (!$digestDocument instanceof \DOMDocument) {
            throw new \RuntimeException('Unable to clone the GovTalk XML for IRmark calculation.');
        }
        $body = $this->singleElement(
            $digestDocument,
            '/*[local-name()="GovTalkMessage" and namespace-uri()="' . self::GOVTALK_NAMESPACE . '"]'
                . '/*[local-name()="Body" and namespace-uri()="' . self::GOVTALK_NAMESPACE . '"]',
            'GovTalk Body'
        );
        $irMark = $this->singleElement(
            $digestDocument,
            '//*[local-name()="IRmark" and namespace-uri()="' . self::CT_NAMESPACE . '"]',
            'CT/5 IRmark'
        );
        if ($irMark->parentNode === null) {
            throw new \RuntimeException('CT/5 IRmark has no parent node.');
        }

        // HMRC's generic algorithm removes the complete IRmark element, while
        // preserving every other text/whitespace node, before inclusive C14N.
        $irMark->parentNode->removeChild($irMark);
        $canonicalBody = $body->C14N(false, false);
        if (!is_string($canonicalBody) || $canonicalBody === '') {
            throw new \RuntimeException('Unable to canonicalise the GovTalk Body for IRmark calculation.');
        }

        $binaryDigest = sha1($canonicalBody, true);

        return [
            'irmark' => base64_encode($binaryDigest),
            'irmark_display' => $this->base32($binaryDigest),
            'canonical_body_sha256' => hash('sha256', $canonicalBody),
        ];
    }

    private function parse(string $xml): \DOMDocument
    {
        if (trim($xml) === '') {
            throw new \InvalidArgumentException('GovTalk XML is required for IRmark calculation.');
        }

        $document = new \DOMDocument();
        $document->preserveWhiteSpace = true;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $loaded = $document->loadXML($xml, \LIBXML_NONET);
            $errors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            $message = isset($errors[0]) ? trim($errors[0]->message) : 'unknown XML parse error';
            throw new \InvalidArgumentException('GovTalk XML is not well formed: ' . $message);
        }

        return $document;
    }

    private function singleElement(\DOMDocument $document, string $query, string $label): \DOMElement
    {
        $nodes = (new \DOMXPath($document))->query($query);
        if ($nodes === false || $nodes->length !== 1 || !$nodes->item(0) instanceof \DOMElement) {
            throw new \InvalidArgumentException('Expected exactly one ' . $label . ' element.');
        }

        /** @var \DOMElement $element */
        $element = $nodes->item(0);
        return $element;
    }

    private function base32(string $binary): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0;
        $bits = 0;
        $encoded = '';

        foreach (unpack('C*', $binary) ?: [] as $byte) {
            $buffer = ($buffer << 8) | $byte;
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= $alphabet[($buffer >> $bits) & 31];
                $buffer &= (1 << $bits) - 1;
            }
        }
        if ($bits > 0) {
            $encoded .= $alphabet[($buffer << (5 - $bits)) & 31];
        }

        return $encoded;
    }
}
