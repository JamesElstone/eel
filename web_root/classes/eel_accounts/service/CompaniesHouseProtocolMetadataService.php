<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/**
 * Backward-compatible name for callers introduced before the protocol
 * metadata implementation became authority-neutral.
 */
final class CompaniesHouseProtocolMetadataService
{
    public function __construct(
        private readonly ?GovTalkProtocolMetadataService $metadata = null
    ) {
    }

    public function requestMessageClass(string $xml, string $operation = ''): string
    {
        return $this->service()->requestMessageClass($xml, $operation);
    }

    public function messageClassForOperation(string $operation): string
    {
        return $this->service()->messageClassForOperation($operation);
    }

    /** @param array<mixed> $headers */
    public function sanitizeResponseHeaders(array $headers): array
    {
        return $this->service()->sanitizeResponseHeaders($headers);
    }

    /** @param array<string,string> $headers */
    public function responseHeadersJson(array $headers): string
    {
        return $this->service()->responseHeadersJson($headers);
    }

    public function govTalkErrors(string $xml): array
    {
        return $this->service()->govTalkErrors($xml);
    }

    public function httpStatusLabel(?int $statusCode): string
    {
        return $this->service()->httpStatusLabel($statusCode);
    }

    private function service(): GovTalkProtocolMetadataService
    {
        return $this->metadata ?? new GovTalkProtocolMetadataService();
    }
}
