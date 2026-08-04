<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Extracts and validates the remote document reference from an HMRC success receipt. */
final class HmrcReceiptReferenceService
{
    private const GOVTALK_NAMESPACE = 'http://www.govtalk.gov.uk/CM/envelope';
    private const SUCCESS_NAMESPACE = 'http://www.inlandrevenue.gov.uk/SuccessResponse';
    private const EXPLICIT_REFERENCE_NAMES = [
        'SubmissionReference',
        'HMRCReference',
        'ReceiptReference',
    ];

    public function normalise(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '' || strlen($value) > 128) {
            return null;
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $value) !== 1) {
            return null;
        }

        return $value;
    }

    public function extract(string $xml): ?string
    {
        if (trim($xml) === '') {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
                return null;
            }
            $xpath = new \DOMXPath($document);
            $successNodes = $xpath->query(
                '/*[local-name()="SuccessResponse" and namespace-uri()="' . self::SUCCESS_NAMESPACE . '"]'
                . ' | /*[local-name()="GovTalkMessage" and namespace-uri()="' . self::GOVTALK_NAMESPACE . '"]'
                . '/*[local-name()="Body" and namespace-uri()="' . self::GOVTALK_NAMESPACE . '"]'
                . '/*[local-name()="SuccessResponse" and namespace-uri()="' . self::SUCCESS_NAMESPACE . '"]'
            );
            if ($successNodes === false || $successNodes->length !== 1) {
                return null;
            }
            $success = $successNodes->item(0);
            if (!$success instanceof \DOMElement) {
                return null;
            }

            $references = [];
            foreach (self::EXPLICIT_REFERENCE_NAMES as $localName) {
                $nodes = $xpath->query(
                    './/*[local-name()="' . $localName . '"'
                    . ' and namespace-uri()="' . self::SUCCESS_NAMESPACE . '" and not(*)]',
                    $success
                );
                if ($nodes === false) {
                    return null;
                }
                foreach ($nodes as $node) {
                    $reference = $this->normalise($node->textContent);
                    if ($reference !== null) {
                        $references[$reference] = true;
                    }
                }
            }

            $messages = $xpath->query(
                './*[local-name()="IRmarkReceipt" and namespace-uri()="' . self::SUCCESS_NAMESPACE . '"]'
                . '/*[local-name()="Message" and namespace-uri()="' . self::SUCCESS_NAMESPACE . '"'
                . ' and @code="0000" and not(*)]',
                $success
            );
            if ($messages === false) {
                return null;
            }
            foreach ($messages as $message) {
                if (preg_match(
                    '/\bdocument\s+ref:\s*([A-Za-z0-9][A-Za-z0-9._-]{0,127})\s+at\b/iu',
                    $message->textContent,
                    $match
                ) !== 1) {
                    continue;
                }
                $reference = $this->normalise($match[1]);
                if ($reference !== null) {
                    $references[$reference] = true;
                }
            }

            return count($references) === 1 ? (string)array_key_first($references) : null;
        } catch (\Throwable) {
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
