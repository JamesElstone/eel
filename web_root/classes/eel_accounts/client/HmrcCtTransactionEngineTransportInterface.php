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
 * Transport boundary for an HMRC Corporation Tax GovTalk conversation.
 *
 * The callback is invoked after the final request bytes have been built, but
 * before the HTTP transport is called. It lets the submission service make a
 * durable pre-send record without coupling persistence to this client.
 */
interface HmrcCtTransactionEngineTransportInterface
{
    public function configurationStatus(string $environment): array;

    public function submit(
        string $filingBodyXml,
        string $utr,
        string $environment,
        ?string $transactionId = null,
        ?callable $beforeSend = null
    ): array;

    public function poll(
        string $correlationId,
        string $responseEndpoint,
        string $environment,
        ?string $transactionId = null,
        ?callable $beforeSend = null
    ): array;

    public function delete(
        string $correlationId,
        string $responseEndpoint,
        string $environment,
        ?string $transactionId = null,
        ?callable $beforeSend = null
    ): array;
}
