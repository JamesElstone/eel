<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Client;

final class CompaniesHousePreparedAccountsRequest
{
    /** @param list<string> $secrets */
    public function __construct(
        private readonly string $environment,
        private readonly string $submissionNumber,
        private readonly string $transactionId,
        private readonly string $requestXml,
        private readonly string $redactedRequestXml,
        private readonly array $secrets,
        private readonly int $schemaSnapshotId,
        private readonly string $schemaManifestSha256,
    ) {
    }

    public function environment(): string { return $this->environment; }
    public function submissionNumber(): string { return $this->submissionNumber; }
    public function transactionId(): string { return $this->transactionId; }
    public function requestXml(): string { return $this->requestXml; }
    public function redactedRequestXml(): string { return $this->redactedRequestXml; }
    /** @return list<string> */
    public function secrets(): array { return $this->secrets; }
    public function schemaSnapshotId(): int { return $this->schemaSnapshotId; }
    public function schemaManifestSha256(): string { return $this->schemaManifestSha256; }
}
