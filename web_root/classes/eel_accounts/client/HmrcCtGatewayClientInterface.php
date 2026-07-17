<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Client;

/**
 * Transport contract for HMRC Corporation Tax GovTalk conversations.
 *
 * Implementations return normalised, persistence-safe arrays. In particular,
 * request_xml must never contain the Government Gateway sender password or ID.
 */
interface HmrcCtGatewayClientInterface
{
    public function configurationStatus(string $environment): array;

    public function submit(
        string $filingBodyXml,
        string $utr,
        string $environment,
        ?string $transactionId = null
    ): array;

    public function poll(
        string $correlationId,
        string $responseEndpoint,
        string $environment,
        ?string $transactionId = null
    ): array;

    public function delete(
        string $correlationId,
        string $responseEndpoint,
        string $environment,
        ?string $transactionId = null
    ): array;

    /**
     * Issue the Transaction Engine DATA_REQUEST used to reconcile uncertain
     * submissions. Supported criteria are start_at, end_at and
     * include_identifiers.
     */
    public function requestData(
        array $criteria,
        string $environment,
        ?string $transactionId = null
    ): array;
}
