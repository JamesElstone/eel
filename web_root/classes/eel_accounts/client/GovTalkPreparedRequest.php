<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Client;

final class GovTalkPreparedRequest
{
    public readonly string $sha256;
    public readonly int $bytes;

    /** @param array<string,mixed> $transportRequest */
    public function __construct(
        public readonly string $authority,
        public readonly string $operation,
        public readonly string $environment,
        public readonly string $endpoint,
        public readonly string $transactionId,
        public readonly string $correlationId,
        public readonly string $xml,
        public readonly array $transportRequest
    ) {
        if ($xml === '' || trim($transactionId) === '') {
            throw new \InvalidArgumentException(
                'A GovTalk prepared request requires exact XML and a transaction ID.'
            );
        }
        $this->sha256 = hash('sha256', $xml);
        $this->bytes = strlen($xml);
    }

    /** @return array<string,mixed> */
    public function evidence(): array
    {
        return [
            'authority' => $this->authority,
            'operation' => $this->operation,
            'environment' => $this->environment,
            'endpoint' => $this->endpoint,
            'transaction_id' => $this->transactionId,
            'correlation_id' => $this->correlationId,
            'request_xml' => $this->xml,
            'raw_request_xml' => $this->xml,
            'request_sha256' => $this->sha256,
            'request_bytes' => $this->bytes,
        ];
    }
}
