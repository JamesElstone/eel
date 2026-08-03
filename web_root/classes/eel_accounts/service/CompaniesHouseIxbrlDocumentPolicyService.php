<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Enforces the deterministic XML envelope used by generated Companies House iXBRL. */
final class CompaniesHouseIxbrlDocumentPolicyService
{
    public const XML_DECLARATION = '<?xml version="1.0"?>';
    public const DOCUMENT_PREFIX = self::XML_DECLARATION . "\n";

    public function canonicaliseGeneratedDocument(string $xml): string
    {
        $this->assertUtf8WithoutLeadingBytes($xml);
        if (preg_match('/\A<\?xml\s+version=(["\'])1\.0\1(?:\s+encoding=(["\'])UTF-8\2)?(?:\s+standalone=(["\'])no\3)?\s*\?>/i', $xml, $match) !== 1) {
            throw new \InvalidArgumentException(
                'Generated Companies House iXBRL has an unsupported XML declaration.'
            );
        }
        $body = substr($xml, strlen((string)$match[0]));
        if (str_starts_with($body, "\r\n")) {
            $body = substr($body, 2);
        } elseif (str_starts_with($body, "\n") || str_starts_with($body, "\r")) {
            $body = substr($body, 1);
        }
        $canonical = self::DOCUMENT_PREFIX . $body;
        $this->assertSubmissionCompliant($canonical);

        return $canonical;
    }

    public function assertSubmissionCompliant(string $xml): void
    {
        $this->assertUtf8WithoutLeadingBytes($xml);
        if (!str_starts_with($xml, self::DOCUMENT_PREFIX)) {
            throw new \InvalidArgumentException(
                'The Companies House accounts iXBRL must start with exactly '
                . self::XML_DECLARATION . ' followed by a line feed; regenerate the artifact before filing.'
            );
        }
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml) === 1) {
            throw new \InvalidArgumentException(
                'The Companies House accounts iXBRL must not contain a DOCTYPE or entity declaration.'
            );
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
            $errors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            $detail = isset($errors[0]) ? trim((string)$errors[0]->message) : 'unknown XML error';
            throw new \InvalidArgumentException(
                'The Companies House accounts iXBRL is not well-formed XML: ' . $detail
            );
        }
    }

    private function assertUtf8WithoutLeadingBytes(string $xml): void
    {
        if ($xml === '' || preg_match('//u', $xml) !== 1) {
            throw new \InvalidArgumentException('The Companies House accounts iXBRL must contain valid UTF-8 XML.');
        }
        if (str_starts_with($xml, "\xEF\xBB\xBF") || $xml[0] !== '<') {
            throw new \InvalidArgumentException(
                'The Companies House accounts iXBRL must not contain a BOM or bytes before its XML declaration.'
            );
        }
    }
}
