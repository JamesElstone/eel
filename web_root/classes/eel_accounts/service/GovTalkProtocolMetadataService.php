<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

use eel_accounts\Support\Utf8;

final class GovTalkProtocolMetadataService
{
    private const MAX_HEADERS = 100;
    private const MAX_HEADER_NAME_BYTES = 128;
    private const MAX_HEADER_VALUE_BYTES = 4096;
    private const MAX_HEADER_BYTES = 65536;

    /** @var list<string> */
    private const PRIVATE_RESPONSE_HEADERS = [
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
    ];

    /** @var array<string,string> */
    private const MESSAGE_CLASSES = [
        'company_data' => 'CompanyDataRequest',
        'accounts' => 'Accounts',
        'submission_status' => 'GetSubmissionStatus',
        'status_ack' => 'StatusAck',
        'get_document' => 'GetDocument',
    ];

    public function requestMessageClass(string $xml, string $operation = ''): string
    {
        return $this->requestMessageDetails($xml, $operation)['class'];
    }

    /**
     * @return array{
     *   class:string,qualifier:string,function:string,
     *   transaction_id:string,correlation_id:string
     * }
     */
    public function requestMessageDetails(string $xml, string $operation = ''): array
    {
        $details = [
            'class' => $this->messageClassForOperation($operation),
            'qualifier' => '',
            'function' => '',
            'transaction_id' => '',
            'correlation_id' => '',
        ];
        try {
            $document = $this->document($xml);
        } catch (\Throwable) {
            return $details;
        }
        $xpath = new \DOMXPath($document);
        $base = '/*[local-name()="GovTalkMessage"]/*[local-name()="Header"]'
            . '/*[local-name()="MessageDetails"]/*[local-name()="%s"]';
        foreach ([
            'class' => 'Class',
            'qualifier' => 'Qualifier',
            'function' => 'Function',
            'transaction_id' => 'TransactionID',
            'correlation_id' => 'CorrelationID',
        ] as $key => $element) {
            $nodes = $xpath->query(sprintf($base, $element));
            $value = $nodes !== false && $nodes->length > 0
                ? trim((string)$nodes->item(0)?->textContent)
                : '';
            if ($key === 'class') {
                if (preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $value) === 1) {
                    $details[$key] = $value;
                }
                continue;
            }
            $details[$key] = $value;
        }

        return $details;
    }

    public function messageClassForOperation(string $operation): string
    {
        $operation = str_replace('-', '_', strtolower(trim($operation)));
        $operation = match ($operation) {
            'companydata', 'company_data_request' => 'company_data',
            'submit' => 'accounts',
            'status', 'get_submission_status' => 'submission_status',
            'ack', 'get_status_ack' => 'status_ack',
            'document' => 'get_document',
            default => $operation,
        };

        return self::MESSAGE_CLASSES[$operation] ?? '';
    }

    /**
     * @param array<mixed> $headers
     * @return array<string,string>
     */
    public function sanitizeResponseHeaders(array $headers): array
    {
        if (count($headers) > self::MAX_HEADERS) {
            throw new \RuntimeException(
                'The GovTalk endpoint returned too many HTTP response headers.'
            );
        }
        $sanitized = [];
        $totalBytes = 0;
        foreach ($headers as $name => $value) {
            if (!is_string($name)
                || preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D', $name) !== 1) {
                throw new \RuntimeException(
                    'The GovTalk endpoint returned an invalid HTTP response header name.'
                );
            }
            $name = strtolower($name);
            if (strlen($name) > self::MAX_HEADER_NAME_BYTES) {
                throw new \RuntimeException(
                    'A GovTalk HTTP response header name was too long.'
                );
            }
            if (in_array($name, self::PRIVATE_RESPONSE_HEADERS, true)) {
                continue;
            }
            if (array_key_exists($name, $sanitized)) {
                throw new \RuntimeException(
                    'The GovTalk endpoint returned duplicate HTTP response header names.'
                );
            }
            if (is_array($value)) {
                $parts = [];
                foreach ($value as $part) {
                    if (!is_scalar($part) && $part !== null) {
                        throw new \RuntimeException(
                            'The GovTalk endpoint returned an invalid HTTP response header value.'
                        );
                    }
                    $parts[] = trim((string)$part);
                }
                $value = implode(', ', $parts);
            } elseif (!is_scalar($value) && $value !== null) {
                throw new \RuntimeException(
                    'The GovTalk endpoint returned an invalid HTTP response header value.'
                );
            }
            $value = trim((string)$value);
            if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new \RuntimeException(
                    'The GovTalk endpoint returned a prohibited HTTP response header value.'
                );
            }
            if (strlen($value) > self::MAX_HEADER_VALUE_BYTES) {
                throw new \RuntimeException(
                    'A GovTalk HTTP response header value was too long.'
                );
            }
            $totalBytes += strlen($name) + strlen($value);
            if ($totalBytes > self::MAX_HEADER_BYTES) {
                throw new \RuntimeException(
                    'GovTalk HTTP response headers exceeded the evidence limit.'
                );
            }
            $sanitized[$name] = $value;
        }
        ksort($sanitized, SORT_STRING);

        return $sanitized;
    }

    /** @param array<string,string> $headers */
    public function responseHeadersJson(array $headers): string
    {
        return Utf8::json($this->sanitizeResponseHeaders($headers), JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<array{
     *   raised_by:string,number:string,type:string,
     *   texts:list<string>,locations:list<string>
     * }>
     */
    public function govTalkErrors(string $xml): array
    {
        if (trim($xml) === '') {
            return [];
        }
        try {
            $document = $this->document($xml);
        } catch (\Throwable) {
            return [];
        }
        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query(
            '//*[local-name()="GovTalkErrors"]/*[local-name()="Error"]'
        );
        if ($nodes === false) {
            return [];
        }
        $errors = [];
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $errors[] = [
                'raised_by' => $this->relativeText($xpath, $node, 'RaisedBy'),
                'number' => $this->relativeText($xpath, $node, 'Number'),
                'type' => $this->relativeText($xpath, $node, 'Type'),
                'texts' => $this->relativeTexts($xpath, $node, 'Text'),
                'locations' => array_values(array_filter(
                    $this->relativeTexts($xpath, $node, 'Location'),
                    static fn(string $location): bool => $location !== ''
                )),
            ];
        }

        return $errors;
    }

    public function httpStatusLabel(?int $statusCode): string
    {
        if ($statusCode === null || $statusCode <= 0) {
            return '';
        }
        $phrases = [
            100 => 'Continue', 101 => 'Switching Protocols',
            200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content',
            300 => 'Multiple Choices', 301 => 'Moved Permanently', 302 => 'Found',
            303 => 'See Other', 304 => 'Not Modified', 307 => 'Temporary Redirect',
            308 => 'Permanent Redirect',
            400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden',
            404 => 'Not Found', 405 => 'Method Not Allowed', 408 => 'Request Timeout',
            409 => 'Conflict', 410 => 'Gone', 413 => 'Content Too Large',
            415 => 'Unsupported Media Type', 422 => 'Unprocessable Content',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error', 501 => 'Not Implemented',
            502 => 'Bad Gateway', 503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
        ];

        return (string)$statusCode
            . (isset($phrases[$statusCode]) ? ' ' . $phrases[$statusCode] : '');
    }

    private function document(string $xml): \DOMDocument
    {
        if ($xml === ''
            || strlen($xml) > 42000000
            || stripos($xml, '<!DOCTYPE') !== false
            || stripos($xml, '<!ENTITY') !== false) {
            throw new \RuntimeException(
                'The GovTalk protocol XML is unavailable or unsafe.'
            );
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            $loaded = $document->loadXML(
                $xml,
                LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            throw new \RuntimeException('The GovTalk protocol XML is malformed.');
        }

        return $document;
    }

    private function relativeText(
        \DOMXPath $xpath,
        \DOMElement $context,
        string $name
    ): string {
        return $this->relativeTexts($xpath, $context, $name)[0] ?? '';
    }

    /** @return list<string> */
    private function relativeTexts(
        \DOMXPath $xpath,
        \DOMElement $context,
        string $name
    ): array {
        $nodes = $xpath->query('./*[local-name()="' . $name . '"]', $context);
        if ($nodes === false) {
            return [];
        }
        $values = [];
        foreach ($nodes as $node) {
            $values[] = trim((string)$node->textContent);
        }

        return $values;
    }
}
